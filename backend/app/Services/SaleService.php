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
use Illuminate\Database\UniqueConstraintViolationException;
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
        $idempotencyKey = $this->idempotencyKey($data);

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);

            if ($existing instanceof Sale) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($data, $user, $idempotencyKey): Sale {
                $cashSession = $this->openCashSessionFor($user);

                $this->assertPriceOverridesAreAuthorized($data['items'] ?? [], $user);

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

                $this->assertCreditLimit($customer, $due);

                $sale = $this->createSaleRecord($total, $paid, $due, $customer, $cashSession, $user, $idempotencyKey);

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
        } catch (UniqueConstraintViolationException $exception) {
            // Deux requetes simultanees avec la meme cle : la contrainte
            // unique departage, la seconde renvoie la vente deja creee.
            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($user, $idempotencyKey);

                if ($existing instanceof Sale) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    /**
     * Cle d'idempotence normalisee, ou null si elle est absente.
     *
     * @param  array<string, mixed>  $data
     */
    private function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return trim($key);
    }

    /**
     * Vente deja enregistree pour cette cle et ce vendeur.
     */
    private function findByIdempotencyKey(User $user, string $idempotencyKey): ?Sale
    {
        return Sale::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['customer', 'user', 'items.product', 'payments'])
            ->first();
    }

    /**
     * Enregistre un règlement ultérieur sur une vente à crédit.
     *
     * La vente est verrouillée pendant l'opération : deux encaissements
     * concurrents ne peuvent pas dépasser ensemble le reste à payer. La dette
     * du client est diminuée du montant encaissé et le statut de la vente est
     * recalculé à partir des paiements réellement enregistrés.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function addPayment(Sale $sale, array $data, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $data, $user): Sale {
            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $paidBefore = $lockedSale->paidAmount();
            $due = round((float) $lockedSale->total_amount - $paidBefore, 2);

            if ($due <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Cette vente est déjà entièrement réglée.',
                ]);
            }

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Le montant doit être supérieur à zéro.',
                ]);
            }

            if ($amount > $due) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Le montant dépasse le reste à payer (%s).',
                        number_format($due, 2, '.', ''),
                    ),
                ]);
            }

            Payment::create([
                'sale_id' => $lockedSale->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
            ]);

            if ($lockedSale->customer_id !== null) {
                $customer = Customer::query()
                    ->lockForUpdate()
                    ->find($lockedSale->customer_id);

                if ($customer instanceof Customer) {
                    $customer->outstanding_balance = max(
                        0,
                        round((float) $customer->outstanding_balance - $amount, 2),
                    );
                    $customer->save();
                }
            }

            $newPaid = round($paidBefore + $amount, 2);
            $newDue = round((float) $lockedSale->total_amount - $newPaid, 2);

            $lockedSale->payment_status = $newDue <= 0 ? 'paid' : 'partial';
            $lockedSale->save();

            return $lockedSale->load(['customer', 'user', 'items.product', 'payments']);
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
     * Verifie que le vendeur est autorise a appliquer un prix personnalise.
     *
     * Le prix du catalogue reste la reference pour une vente ordinaire. Seuls
     * les responsables designes peuvent negocier un prix ou appliquer une
     * remise, afin qu'un vendeur ne puisse pas fixer arbitrairement le montant
     * encaisse.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    private function assertPriceOverridesAreAuthorized(array $items, User $user): void
    {
        if ($user->hasAnyRole(['Admin', 'Gérant'])) {
            return;
        }

        foreach (array_values($items) as $index => $item) {
            if (array_key_exists('unit_price', $item) && $item['unit_price'] !== null) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => 'Seuls un administrateur ou un gérant peuvent appliquer un prix personnalisé.',
                ]);
            }
        }
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
            // different ne peut etre impose que par un role autorise.
            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== null
                ? round((float) $item['unit_price'], 2)
                : (float) $product->sale_price;

            if ($unitPrice <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => 'Le prix unitaire doit être supérieur à zéro.',
                ]);
            }

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
     * Verifie que la dette du client ne depasse pas son plafond de credit.
     *
     * Un plafond a zero est traite comme "non configure" : le client peut
     * acheter a credit sans limite. Un plafond positif est un montant maximal
     * de dette cumulee, reliquat de la vente en cours compris.
     *
     * @throws ValidationException
     */
    private function assertCreditLimit(?Customer $customer, float $due): void
    {
        if (! $customer instanceof Customer || $due <= 0) {
            return;
        }

        $limit = (float) $customer->credit_limit;

        if ($limit <= 0) {
            return;
        }

        $balance = (float) $customer->outstanding_balance;
        $projected = round($balance + $due, 2);

        if ($projected > $limit) {
            throw ValidationException::withMessages([
                'customer_id' => sprintf(
                    'Le plafond de crédit de « %s » est dépassé : %s en cours, %s demandé(s), plafond %s.',
                    $customer->name,
                    number_format($balance, 2, '.', ''),
                    number_format($due, 2, '.', ''),
                    number_format($limit, 2, '.', ''),
                ),
            ]);
        }
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
        ?string $idempotencyKey = null,
    ): Sale {
        $sale = new Sale([
            'customer_id' => $customer?->id,
            'user_id' => $user->id,
            'cash_session_id' => $cashSession->id,
            // Reference provisoire : la reference lisible est derivee de
            // l'identifiant, obtenu apres l'insertion.
            'reference' => 'TMP-'.Str::uuid()->toString(),
            'idempotency_key' => $idempotencyKey,
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
            // Quantite signee : une sortie reduit le stock. Les entrees sont
            // positives et les ajustements portent leur delta signe.
            'quantity' => -$line['quantity'],
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
