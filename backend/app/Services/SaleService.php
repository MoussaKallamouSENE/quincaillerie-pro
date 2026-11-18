<?php

namespace App\Services;

use App\Exceptions\CashSessionClosedException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\NoOpenCashSessionException;
use App\Models\AuditLog;
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
    public const WARNING_LEGACY_SALE = 'legacy_sale_fingerprint_missing';

    public const WARNING_LEGACY_PAYMENT = 'legacy_payment_fingerprint_missing';

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
        $saleFingerprint = $this->saleFingerprint($data);
        $paymentData = is_array($data['payment'] ?? null) ? $data['payment'] : null;
        $method = (string) ($paymentData['method'] ?? 'cash');
        $reference = $this->normalizeReference($paymentData['reference'] ?? null);
        $amount = round((float) ($paymentData['amount'] ?? 0), 2);

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);

            if ($existing instanceof Sale) {
                return $this->resolveExistingSale($existing, $saleFingerprint);
            }
        }

        try {
            return DB::transaction(function () use ($data, $user, $idempotencyKey, $saleFingerprint, $paymentData, $method, $reference, $amount): Sale {
                $cashSession = $this->openCashSessionFor($user);

                $this->assertPriceOverridesAreAuthorized($data['items'] ?? [], $user);

                $lines = $this->prepareLines($data['items'] ?? []);
                $total = round(array_sum(array_column($lines, 'subtotal')), 2);

                if ($amount > $total) {
                    throw ValidationException::withMessages([
                        'payment.amount' => 'Le montant payé ne peut pas dépasser le total de la vente.',
                    ]);
                }

                $this->assertPaymentReferenceIsValid($paymentData, $method, $amount, $reference, 'payment.reference');

                if ($reference !== null) {
                    $this->assertReferenceAvailable($method, $reference, 'payment.reference');
                }

                $requiresVerification = $paymentData !== null
                    && $amount > 0
                    && Payment::requiresManualVerification($method);

                // Un paiement Wave ou Orange Money n'est pas encaisse tant
                // qu'un responsable ne l'a pas confirme : la vente reste due
                // et exige donc un client, même si le montant pending couvre
                // tout le total.
                $confirmedPaid = $requiresVerification ? 0.0 : $amount;
                $due = round($total - $confirmedPaid, 2);

                $customer = $this->resolveCustomer($data['customer_id'] ?? null, $due);

                $this->assertCreditLimit($customer, $due);

                $sale = $this->createSaleRecord($total, $confirmedPaid, $due, $customer, $cashSession, $user, $idempotencyKey, $saleFingerprint);

                foreach ($lines as $line) {
                    $this->recordLine($sale, $line, $user);
                }

                if ($paymentData !== null && $amount > 0) {
                    $this->recordPayment(
                        $sale,
                        $amount,
                        $paymentData,
                        $user,
                        $cashSession,
                        $requiresVerification ? Payment::STATUS_PENDING : Payment::STATUS_CONFIRMED,
                    );
                }

                // Le reliquat de la vente est reporte sur la dette du client.
                // Un paiement en attente fait partie de ce reliquat tant qu'il
                // n'est pas confirme.
                if ($due > 0 && $customer instanceof Customer) {
                    $customer->outstanding_balance = round((float) $customer->outstanding_balance + $due, 2);
                    $customer->save();
                }

                return $this->loadSale($sale);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Deux requetes simultanees avec la meme cle : la contrainte
            // unique departage, la seconde renvoie la vente deja creee.
            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($user, $idempotencyKey);

                if ($existing instanceof Sale) {
                    return $this->resolveExistingSale($existing, $saleFingerprint);
                }
            }

            if ($paymentData !== null && $reference !== null && $this->referenceExists($method, $reference)) {
                throw ValidationException::withMessages([
                    'payment.reference' => 'Cette référence de transaction est déjà utilisée.',
                ]);
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

        if (! is_string($key)) {
            return null;
        }

        $canonical = $this->canonicalText($key);

        if ($canonical !== null && mb_strlen($canonical, 'UTF-8') > 64) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clé d’idempotence normalisée ne peut pas dépasser 64 caractères.',
            ]);
        }

        return $canonical;
    }

    /**
     * Vente deja enregistree pour cette cle et ce vendeur.
     *
     * La recherche exacte est privilegiee ; un repli compare les anciennes
     * cles non normalisees (casse, accents, espaces externes).
     */
    private function findByIdempotencyKey(User $user, string $idempotencyKey): ?Sale
    {
        $sale = Sale::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($sale instanceof Sale) {
            return $this->loadSale($sale);
        }

        $sale = Sale::query()
            ->where('user_id', $user->id)
            ->whereNotNull('idempotency_key')
            ->get()
            ->first(fn (Sale $candidate): bool => is_string($candidate->idempotency_key)
                && $this->canonicalText($candidate->idempotency_key) === $idempotencyKey);

        return $sale instanceof Sale ? $this->loadSale($sale) : null;
    }

    /**
     * Paiement deja enregistre pour cette cle d'idempotence.
     */
    private function findPaymentByIdempotencyKey(Sale $sale, string $idempotencyKey): ?Payment
    {
        $payment = Payment::query()
            ->where('sale_id', $sale->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($payment instanceof Payment) {
            return $payment;
        }

        return Payment::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('idempotency_key')
            ->get()
            ->first(fn (Payment $candidate): bool => is_string($candidate->idempotency_key)
                && $this->canonicalText($candidate->idempotency_key) === $idempotencyKey);
    }

    /**
     * Retourne une vente deja creee, ou refuse une charge utile differente.
     */
    private function resolveExistingSale(Sale $sale, string $fingerprint): Sale
    {
        if ($this->fingerprintIsMissing($sale->idempotency_fingerprint)) {
            $sale->setAttribute('idempotency_warning', self::WARNING_LEGACY_SALE);
        } elseif (! hash_equals((string) $sale->idempotency_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException;
        }

        return $this->loadSale($sale);
    }

    /**
     * Retourne la vente d'un paiement deja cree, ou refuse une charge utile differente.
     */
    private function resolveExistingPayment(Sale $sale, Payment $payment, string $fingerprint): Sale
    {
        $sale = Sale::query()->findOrFail($sale->id);

        if ($this->fingerprintIsMissing($payment->idempotency_fingerprint)) {
            $sale->setAttribute('idempotency_warning', self::WARNING_LEGACY_PAYMENT);
        } elseif (! hash_equals((string) $payment->idempotency_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException;
        }

        return $this->loadSale($sale);
    }

    /**
     * Empreinte stable de la demande initiale d'une vente.
     *
     * Le prix est inclus tel qu'il a ete fourni : un prix absent et un prix
     * explicite ne sont pas interchangeables.
     *
     * @param  array<string, mixed>  $data
     */
    private function saleFingerprint(array $data): string
    {
        $items = [];
        $dataItems = $data['items'] ?? [];

        if (is_array($dataItems)) {
            foreach (array_values($dataItems) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $items[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                        ? $this->money((float) $item['unit_price'])
                        : null,
                ];
            }
        }

        $payment = $data['payment'] ?? null;
        $paymentPayload = null;

        if (is_array($payment)) {
            $method = is_string($payment['method'] ?? null) ? $payment['method'] : 'cash';
            $reference = is_string($payment['reference'] ?? null) ? $payment['reference'] : null;
            $amount = is_numeric($payment['amount'] ?? null) ? (float) $payment['amount'] : 0.0;

            $paymentPayload = [
                'method' => mb_strtolower(trim($method)),
                'amount' => $this->money($amount),
                'reference_hash' => $this->referenceHash($reference),
            ];
        }

        $customerId = $data['customer_id'] ?? null;
        $customerId = $customerId === null || $customerId === '' ? null : (int) $customerId;

        return $this->fingerprint([
            'customer_id' => $customerId,
            'items' => $items,
            'payment' => $paymentPayload,
        ]);
    }

    /**
     * Empreinte stable d'un paiement ajoute a une vente.
     */
    private function paymentFingerprint(string $method, float $amount, ?string $reference): string
    {
        return $this->fingerprint([
            'amount' => $this->money($amount),
            'method' => mb_strtolower(trim($method)),
            'reference_hash' => $this->referenceHash($reference),
        ]);
    }

    /**
     * Forme canonique commune aux cles et aux references.
     */
    private function canonicalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);

            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * Empreinte de reference, insensible aux accents, a la casse et aux espaces externes.
     */
    private function referenceHash(?string $reference): ?string
    {
        $canonical = $this->canonicalText($reference);

        return $canonical === null ? null : hash('sha256', $canonical);
    }

    /**
     * Serialise une charge utile canonique avant hachage.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Indique si une empreinte historique est absente.
     */
    private function fingerprintIsMissing(mixed $fingerprint): bool
    {
        return ! is_string($fingerprint) || $fingerprint === '';
    }

    /**
     * Formate un montant canonique pour une empreinte.
     */
    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    /**
     * Normalise une reference de paiement.
     */
    private function normalizeReference(mixed $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);

        return $reference === '' ? null : $reference;
    }

    /**
     * Verifie la coherence de la reference avec le mode de paiement.
     *
     * @param  array<string, mixed>|null  $payment
     *
     * @throws ValidationException
     */
    private function assertPaymentReferenceIsValid(
        ?array $payment,
        string $method,
        float $amount,
        ?string $reference,
        string $field,
    ): void {
        if (! Payment::requiresManualVerification($method)) {
            return;
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                $field === 'reference' ? 'amount' : 'payment.amount' => 'Le montant du paiement doit être supérieur à zéro.',
            ]);
        }

        if ($reference === null) {
            throw ValidationException::withMessages([
                $field => 'La référence de transaction est obligatoire pour Wave et Orange Money.',
            ]);
        }

        if ($payment === null) {
            throw ValidationException::withMessages([
                $field => 'La référence de transaction est obligatoire pour Wave et Orange Money.',
            ]);
        }
    }

    /**
     * Verifie qu'une reference n'est pas deja utilisee pour ce mode.
     *
     * @throws ValidationException
     */
    private function assertReferenceAvailable(string $method, string $reference, string $field): void
    {
        if ($this->referenceExists($method, $reference)) {
            throw ValidationException::withMessages([
                $field => 'Cette référence de transaction est déjà utilisée.',
            ]);
        }
    }

    /**
     * Indique si une reference existe deja pour ce mode de paiement.
     *
     * La comparaison est canonique : accents, casse et espaces externes sont
     * ignores, tout en conservant la reference d'origine en base.
     */
    private function referenceExists(string $method, string $reference): bool
    {
        $canonical = $this->canonicalText($reference);

        if ($canonical === null) {
            return false;
        }

        if (Payment::query()
            ->where('method', $method)
            ->where('reference', trim($reference))
            ->exists()) {
            return true;
        }

        $payments = Payment::query()
            ->where('method', $method)
            ->whereNotNull('reference')
            ->cursor();

        foreach ($payments as $payment) {
            if ($this->canonicalText($payment->reference) === $canonical) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifie qu'un paiement peut encore etre confirme ou rejete.
     *
     * @throws ValidationException
     */
    private function assertPendingManualPayment(Payment $payment): void
    {
        if (! $payment->isManual()) {
            throw ValidationException::withMessages([
                'payment' => 'Seuls les paiements Wave et Orange Money peuvent être vérifiés.',
            ]);
        }

        if ($payment->status !== Payment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'payment' => 'Ce paiement a déjà été traité.',
            ]);
        }
    }

    /**
     * Verrouille la vente et le paiement d'une decision manuelle.
     *
     * La vente est toujours verrouillee avant le paiement, comme dans les
     * autres ecritures de paiement : l'ordre est stable et evite les
     * interblocages entre deux decisions concurrentes.
     *
     * @return array{0: Sale, 1: Payment}
     *
     * @throws ValidationException
     */
    private function lockPendingManualPayment(Payment $payment): array
    {
        $lockedSale = Sale::query()
            ->lockForUpdate()
            ->findOrFail($payment->sale_id);

        $lockedPayment = Payment::query()
            ->lockForUpdate()
            ->findOrFail($payment->id);

        if ((int) $lockedPayment->sale_id !== (int) $lockedSale->id) {
            throw ValidationException::withMessages([
                'payment' => 'Le paiement ne correspond pas à la vente associée.',
            ]);
        }

        $this->assertPendingManualPayment($lockedPayment);

        return [$lockedSale, $lockedPayment];
    }

    /**
     * Trace une decision dans le journal d'audit existant.
     *
     * Le paiement reste la source de verite de son etat courant ; le journal
     * conserve la transition ancien statut / nouveau statut et, pour un rejet,
     * son motif.
     */
    private function recordManualPaymentDecision(
        Payment $payment,
        User $manager,
        string $action,
        string $oldStatus,
        ?string $rejectionReason = null,
    ): void {
        $newValues = ['status' => $payment->status];

        if ($rejectionReason !== null) {
            $newValues['rejection_reason'] = $rejectionReason;
        }

        AuditLog::create([
            'user_id' => $manager->id,
            'action' => $action,
            'model_type' => Payment::class,
            'model_id' => $payment->id,
            'old_values' => ['status' => $oldStatus],
            'new_values' => $newValues,
        ]);
    }

    /**
     * Charge les relations publiques d'une vente.
     */
    private function loadSale(Sale $sale): Sale
    {
        return $sale->load([
            'customer',
            'user',
            'items.product',
            'payments.confirmedBy',
            'payments.rejectedBy',
        ]);
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
        $idempotencyKey = $this->idempotencyKey($data);
        $method = (string) $data['method'];
        $reference = $this->normalizeReference($data['reference'] ?? null);
        $amount = round((float) $data['amount'], 2);
        $requiresVerification = Payment::requiresManualVerification($method);
        $paymentFingerprint = $this->paymentFingerprint($method, $amount, $reference);

        if ($reference !== null || $requiresVerification) {
            $this->assertPaymentReferenceIsValid($data, $method, $amount, $reference, 'reference');
        }

        if ($idempotencyKey !== null) {
            $existing = $this->findPaymentByIdempotencyKey($sale, $idempotencyKey);

            if ($existing instanceof Payment) {
                return $this->resolveExistingPayment($sale, $existing, $paymentFingerprint);
            }
        }

        try {
            return DB::transaction(function () use ($sale, $user, $idempotencyKey, $paymentFingerprint, $method, $reference, $amount, $requiresVerification): Sale {
                $originSessionId = Sale::query()
                    ->whereKey($sale->id)
                    ->value('cash_session_id');

                if ($originSessionId === null) {
                    throw new CashSessionClosedException(
                        'La session de caisse d’origine de cette vente est introuvable.',
                    );
                }

                $originSessionId = (int) $originSessionId;

                $actorSessionId = CashSession::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'open')
                    ->latest('opened_at')
                    ->value('id');

                if ($actorSessionId === null) {
                    throw new NoOpenCashSessionException(
                        'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’encaisser ce règlement.',
                    );
                }

                $actorSessionId = (int) $actorSessionId;
                $sessionIds = array_values(array_unique([$originSessionId, $actorSessionId]));
                sort($sessionIds);

                $lockedSessions = [];

                foreach ($sessionIds as $sessionId) {
                    $lockedSession = CashSession::query()
                        ->whereKey($sessionId)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedSession instanceof CashSession) {
                        throw new CashSessionClosedException(
                            'La session de caisse d’origine de cette vente est introuvable.',
                        );
                    }

                    $lockedSessions[$sessionId] = $lockedSession;
                }

                $originSession = $lockedSessions[$originSessionId];
                $actorSession = $lockedSessions[$actorSessionId];

                if ($originSession->status !== 'open') {
                    throw new CashSessionClosedException;
                }

                if ($actorSession->status !== 'open' || (int) $actorSession->user_id !== (int) $user->id) {
                    throw new NoOpenCashSessionException(
                        'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’encaisser ce règlement.',
                    );
                }

                $lockedSale = Sale::query()
                    ->lockForUpdate()
                    ->findOrFail($sale->id);

                if ((int) $lockedSale->cash_session_id !== $originSessionId) {
                    throw new CashSessionClosedException(
                        'La session de caisse d’origine de cette vente a changé. Réessayez.',
                    );
                }

                if ($idempotencyKey !== null) {
                    $existing = $this->findPaymentByIdempotencyKey($lockedSale, $idempotencyKey);

                    if ($existing instanceof Payment) {
                        return $this->resolveExistingPayment($lockedSale, $existing, $paymentFingerprint);
                    }
                }

                $paidBefore = $lockedSale->paidAmount();
                $pendingBefore = $lockedSale->pendingAmount();
                $due = round((float) $lockedSale->total_amount - $paidBefore, 2);

                if ($due <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Cette vente est déjà entièrement réglée.',
                    ]);
                }

                $amount = round((float) $amount, 2);

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Le montant doit être supérieur à zéro.',
                    ]);
                }

                $available = round(max(0, $due - $pendingBefore), 2);

                if ($available <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Le reste à payer est déjà réservé par un paiement en attente de vérification.',
                    ]);
                }

                if ($amount > $available) {
                    $message = $pendingBefore > 0
                        ? sprintf(
                            'Le montant dépasse le solde disponible après paiements en attente (%s).',
                            number_format($available, 2, '.', ''),
                        )
                        : sprintf(
                            'Le montant dépasse le reste à payer (%s).',
                            number_format($available, 2, '.', ''),
                        );

                    throw ValidationException::withMessages([
                        'amount' => $message,
                    ]);
                }

                if ($reference !== null) {
                    $this->assertReferenceAvailable($method, $reference, 'reference');
                }

                Payment::create([
                    'sale_id' => $lockedSale->id,
                    'user_id' => $user->id,
                    'cash_session_id' => $method === 'cash' ? $actorSession->id : null,
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => $reference,
                    'status' => $requiresVerification ? Payment::STATUS_PENDING : Payment::STATUS_CONFIRMED,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $paymentFingerprint,
                ]);

                // Un paiement en attente ne reduit ni la dette du client ni le
                // montant paye : seule la confirmation produira ces effets.
                if (! $requiresVerification && $lockedSale->customer_id !== null) {
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

                $lockedSale->recalculatePaymentStatus();
                $lockedSale->save();

                return $this->loadSale($lockedSale);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null) {
                $existing = $this->findPaymentByIdempotencyKey($sale, $idempotencyKey);

                if ($existing instanceof Payment) {
                    return $this->resolveExistingPayment($sale, $existing, $paymentFingerprint);
                }
            }

            if ($reference !== null && $this->referenceExists($method, $reference)) {
                throw ValidationException::withMessages([
                    'reference' => 'Cette référence de transaction est déjà utilisée.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Confirme un paiement Wave ou Orange Money en attente.
     *
     * La confirmation est serialisee par un verrou sur la vente puis sur le
     * paiement : deux responsables ne peuvent pas valider deux fois le meme
     * reglement. Le montant n'entre dans le solde qu'a ce moment.
     *
     * @throws ValidationException
     */
    public function confirmManualPayment(Payment $payment, User $manager): Sale
    {
        return DB::transaction(function () use ($payment, $manager): Sale {
            [$lockedSale, $lockedPayment] = $this->lockPendingManualPayment($payment);

            $oldStatus = $lockedPayment->status;
            $amount = round((float) $lockedPayment->amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Le montant du paiement doit être supérieur à zéro.',
                ]);
            }

            $due = $lockedSale->balanceDue();

            if ($amount > $due) {
                throw ValidationException::withMessages([
                    'payment' => 'Le montant du paiement dépasse le reste à payer de la vente.',
                ]);
            }

            $lockedPayment->forceFill([
                'status' => Payment::STATUS_CONFIRMED,
                'confirmed_by_id' => $manager->id,
                'confirmed_at' => now(),
                'rejected_by_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

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

            $lockedSale->recalculatePaymentStatus();
            $lockedSale->save();

            $this->recordManualPaymentDecision(
                $lockedPayment,
                $manager,
                'manual_payment.confirmed',
                $oldStatus,
            );

            return $this->loadSale($lockedSale);
        });
    }

    /**
     * Rejette un paiement Wave ou Orange Money en attente.
     *
     * Le rejet conserve la trace du paiement et de son auteur, sans jamais
     * l'ajouter aux encaissements de la vente.
     *
     * @throws ValidationException
     */
    public function rejectManualPayment(Payment $payment, User $manager, string $reason): Sale
    {
        return DB::transaction(function () use ($payment, $manager, $reason): Sale {
            [$lockedSale, $lockedPayment] = $this->lockPendingManualPayment($payment);

            $oldStatus = $lockedPayment->status;
            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Le motif du rejet est obligatoire.',
                ]);
            }

            $lockedPayment->forceFill([
                'status' => Payment::STATUS_REJECTED,
                'rejected_by_id' => $manager->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'confirmed_by_id' => null,
                'confirmed_at' => null,
            ])->save();

            $this->recordManualPaymentDecision(
                $lockedPayment,
                $manager,
                'manual_payment.rejected',
                $oldStatus,
                $reason,
            );

            $lockedSale->recalculatePaymentStatus();
            $lockedSale->save();

            return $this->loadSale($lockedSale);
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
        ?string $idempotencyFingerprint = null,
    ): Sale {
        $sale = new Sale([
            'customer_id' => $customer?->id,
            'user_id' => $user->id,
            'cash_session_id' => $cashSession->id,
            // Reference provisoire : la reference lisible est derivee de
            // l'identifiant, obtenu apres l'insertion.
            'reference' => 'TMP-'.Str::uuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => $idempotencyKey === null ? null : $idempotencyFingerprint,
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
    private function recordPayment(
        Sale $sale,
        float $paid,
        array $payment,
        User $user,
        CashSession $cashSession,
        string $status,
    ): void {
        $method = (string) ($payment['method'] ?? 'cash');
        $reference = $this->normalizeReference($payment['reference'] ?? null);

        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'cash_session_id' => $method === 'cash' ? $cashSession->id : null,
            'amount' => $paid,
            'method' => $method,
            'reference' => $reference,
            'status' => $status,
            'idempotency_fingerprint' => $this->paymentFingerprint($method, $paid, $reference),
        ]);
    }
}
