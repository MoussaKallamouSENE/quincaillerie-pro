<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\NoOpenCashSessionException;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enregistrement des ventes.
 *
 * Le service est le seul point d'ecriture des ventes : il garantit que la
 * vente, ses lignes, ses paiements et le decrement du stock forment un
 * ensemble coherent, ou n'existent pas du tout.
 */
class SaleService
{
    /**
     * Cree une vente et decremente le stock des produits vendus.
     *
     * L'ensemble de l'operation est execute dans une transaction : si une
     * ligne echoue (stock insuffisant, client inconnu...), aucune ecriture
     * n'est conservee. Le stock ne peut donc jamais etre decremente
     * partiellement.
     *
     * @param  array<string, mixed>  $data  Donnees validees de la vente.
     * @param  User  $user  Vendeur, porteur de la session de caisse.
     *
     * @throws NoOpenCashSessionException Si l'utilisateur n'a pas de session ouverte.
     * @throws InsufficientStockException Si le stock d'un produit est insuffisant.
     * @throws ValidationException Si les regles metier ne sont pas respectees.
     */
    public function createSale(array $data, User $user): Sale
    {
        return DB::transaction(function () use ($data, $user): Sale {
            $cashSession = $this->openCashSessionFor($user);

            $lines = $this->prepareLines($data['items'] ?? []);
            $total = round(array_sum(array_column($lines, 'subtotal')), 2);

            $paid = round((float) ($data['payment']['amount'] ?? 0), 2);

            if ($paid > $total) {
                throw ValidationException::withMessages([
                    'payment.amount' => 'Le montant payé ne peut pas dépasser le total de la vente.',
                ]);
            }

            $due = round($total - $paid, 2);

            $customer = $this->resolveCustomer($data['customer_id'] ?? null, $due);

            $sale = $this->createSaleRecord($total, $paid, $due, $customer, $cashSession, $user);

            foreach ($lines as $line) {
                $this->recordLine($sale, $line, $user);
            }

            if ($paid > 0) {
                $this->recordPayment($sale, $paid, $data['payment'] ?? [], $user);
            }

            // Le reliquat de la vente est reporte sur la dette du client.
            if ($due > 0 && $customer instanceof Customer) {
                $customer->outstanding_balance = round((float) $customer->outstanding_balance + $due, 2);
                $customer->save();
            }

            return $sale->load(['customer', 'user', 'items.product', 'payments']);
        });
    }

    /**
     * Session de caisse ouverte de l'utilisateur.
     *
     * @throws NoOpenCashSessionException
     */
    private function openCashSessionFor(User $user): CashSession
    {
        $cashSession = CashSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if (! $cashSession instanceof CashSession) {
            throw new NoOpenCashSessionException;
        }

        return $cashSession;
    }

    /**
     * Prepare les lignes de la vente et verifie la disponibilite du stock.
     *
     * Les produits sont charges avec `lockForUpdate` : deux ventes du meme
     * produit sont donc serialisees par la base, ce qui evite de solder un
     * stock deja consomme par une autre session.
     *
     * Un meme produit peut apparaitre sur plusieurs lignes : les quantites
     * sont alors cumulees, car le stock disponible se reduit des la premiere
     * ligne. Le produit n'est charge qu'une fois, afin que le decrement
     * s'applique a la meme instance.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{product: Product, quantity: int, unit_price: float, subtotal: float}>
     *
     * @throws ValidationException
     * @throws InsufficientStockException
     */
    private function prepareLines(array $items): array
    {
        $lines = [];
        $products = [];
        $reserved = [];

        foreach (array_values($items) as $index => $item) {
            $productId = (int) $item['product_id'];

            $product = $products[$productId] ?? null;

            if ($product === null) {
                $product = Product::query()
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->first();

                if (! $product instanceof Product) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => 'Ce produit n’existe pas.',
                    ]);
                }

                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => sprintf(
                            'Le produit « %s » n’est plus disponible à la vente.',
                            $product->name,
                        ),
                    ]);
                }

                $products[$productId] = $product;
            }

            $quantity = (int) $item['quantity'];

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'La quantité doit être supérieure à zéro.',
                ]);
            }

            // Le stock deja reserve par les lignes precedentes du meme produit
            // n'est plus disponible.
            $alreadyReserved = $reserved[$productId] ?? 0;
            $available = $product->stock_quantity - $alreadyReserved;

            if ($available < $quantity) {
                throw new InsufficientStockException($product, $quantity, $available);
            }

            $reserved[$productId] = $alreadyReserved + $quantity;

            // Le prix de vente du catalogue est utilise par defaut ; un prix
            // different peut etre impose a la caisse (negociation, remise).
            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== null
                ? round((float) $item['unit_price'], 2)
                : (float) $product->sale_price;

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $quantity, 2),
            ];
        }

        return $lines;
    }

    /**
     * Client de la vente, verrouille pour eviter les ecritures concurrentes.
     *
     * @throws ValidationException
     */
    private function resolveCustomer(mixed $customerId, float $due): ?Customer
    {
        if ($customerId === null || $customerId === '') {
            if ($due > 0) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Un client est requis pour une vente partielle ou à crédit.',
                ]);
            }

            return null;
        }

        $customer = Customer::query()
            ->whereKey($customerId)
            ->lockForUpdate()
            ->first();

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages([
                'customer_id' => 'Ce client n’existe pas.',
            ]);
        }

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => sprintf('Le client « %s » est désactivé.', $customer->name),
            ]);
        }

        return $customer;
    }

    /**
     * Enregistre la vente et sa reference.
     *
     * @param  float  $total  Montant total de la vente.
     * @param  float  $paid  Montant regle au moment de la vente.
     * @param  float  $due  Reliquat a encaisser.
     */
    private function createSaleRecord(
        float $total,
        float $paid,
        float $due,
        ?Customer $customer,
        CashSession $cashSession,
        User $user,
    ): Sale {
        $sale = new Sale([
            'customer_id' => $customer?->id,
            'user_id' => $user->id,
            'cash_session_id' => $cashSession->id,
            // Reference provisoire : la reference lisible est derivee de
            // l'identifiant, obtenu apres l'insertion.
            'reference' => 'TMP-'.Str::uuid()->toString(),
            'total_amount' => $total,
            'payment_status' => $this->paymentStatus($paid, $due),
        ]);

        $sale->save();

        $sale->reference = 'VTE-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT);
        $sale->save();

        return $sale;
    }

    /**
     * Statut de reglement de la vente.
     *
     * @param  float  $paid  Montant regle.
     * @param  float  $due  Reliquat a encaisser.
     */
    private function paymentStatus(float $paid, float $due): string
    {
        if ($due <= 0) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'credit';
    }

    /**
     * Enregistre une ligne de vente, decremente le stock et trace le mouvement.
     *
     * @param  array{product: Product, quantity: int, unit_price: float, subtotal: float}  $line
     */
    private function recordLine(Sale $sale, array $line, User $user): void
    {
        /** @var Product $product */
        $product = $line['product'];

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'subtotal' => $line['subtotal'],
        ]);

        $product->stock_quantity -= $line['quantity'];
        $product->save();

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'out',
            // La quantite est toujours positive : le type porte le sens du
            // mouvement (sortie de stock pour une vente).
            'quantity' => $line['quantity'],
            'reference' => $sale->reference,
            'note' => sprintf('Vente %s', $sale->reference),
        ]);
    }

    /**
     * Enregistre le paiement encaisse au moment de la vente.
     *
     * @param  array<string, mixed>  $payment
     */
    private function recordPayment(Sale $sale, float $paid, array $payment, User $user): void
    {
        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'amount' => $paid,
            'method' => $payment['method'] ?? 'cash',
            'reference' => $payment['reference'] ?? null,
        ]);
    }
}
