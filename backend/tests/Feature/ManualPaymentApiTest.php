<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie un utilisateur avec le rôle demandé.
     */
    private function authenticate(string $roleName = 'Admin'): User
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Session de caisse ouverte du vendeur.
     */
    private function openCashSession(User $user): CashSession
    {
        return CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 0,
            'status' => 'open',
        ]);
    }

    /**
     * Produit disponible en stock, à 1 000 FCFA.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Perceuse à percussion 800W',
            'sale_price' => 1000,
            'stock_quantity' => 10,
        ], $overrides));
    }

    /**
     * Vente à crédit de trois produits, soit 3 000 FCFA.
     */
    private function createCreditSale(User $seller, ?Customer $customer = null): Sale
    {
        $customer ??= Customer::factory()->create([
            'credit_limit' => 0,
            'outstanding_balance' => 0,
        ]);

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $this->product()->id, 'quantity' => 3],
            ],
        ])->assertCreated()->assertJsonPath('data.payment_status', 'credit');

        return Sale::query()->findOrFail($response->json('data.id'));
    }

    /**
     * Ajoute un paiement manuel en attente et renvoie le paiement créé.
     */
    private function addPendingPayment(
        Sale $sale,
        string $method = 'wave',
        float $amount = 1000,
        string $reference = 'WAVE-0001',
        ?string $idempotencyKey = null,
    ): Payment {
        $payload = [
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
        ];

        if ($idempotencyKey !== null) {
            $payload['idempotency_key'] = $idempotencyKey;
        }

        $response = $this->postJson("/api/sales/{$sale->id}/payments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.pending_amount', number_format($amount, 2, '.', ''));

        $payments = collect($response->json('data.payments'));

        return Payment::query()->findOrFail(
            $payments->firstWhere('reference', $reference)['id'],
        );
    }

    public function test_cash_payments_are_confirmed_immediately_and_feed_the_cash_drawer(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);
        $product = $this->product();

        $response = $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount' => 1000],
        ])->assertCreated()
            ->assertJsonPath('data.paid_amount', '1000.00')
            ->assertJsonPath('data.pending_amount', '0.00')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payments.0.status', 'confirmed');

        $this->assertSame(1000.0, $cashSession->fresh()->cashReceived());
        $this->assertDatabaseHas('payments', [
            'sale_id' => $response->json('data.id'),
            'method' => 'cash',
            'status' => 'confirmed',
        ]);
    }

    public function test_a_wave_payment_is_pending_and_never_counted_as_paid(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $sale = $this->createCreditSale($user, $customer);

        $payment = $this->addPendingPayment($sale, 'wave', 1000, 'WAVE-PENDING-1');

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(0.0, $sale->fresh()->paidAmount());
        $this->assertSame(1000.0, $sale->fresh()->pendingAmount());
        $this->assertSame(3000.0, $sale->fresh()->balanceDue());
        $this->assertSame('credit', $sale->fresh()->payment_status);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
        $this->assertSame(0.0, $cashSession->fresh()->cashReceived());

        $this->getJson("/api/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.pending_amount', '1000.00')
            ->assertJsonPath('data.balance_due', '3000.00')
            ->assertJsonPath('data.payment_status', 'credit')
            ->assertJsonPath('data.payments.0.status', 'pending');
    }

    public function test_a_full_manual_payment_without_customer_is_rejected(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payment' => [
                'method' => 'orange_money',
                'amount' => 3000,
                'reference' => 'OM-FULL-0001',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 10,
        ]);
    }

    public function test_a_full_manual_payment_with_customer_stays_pending_then_can_be_confirmed(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $product = $this->product();

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payment' => [
                'method' => 'orange_money',
                'amount' => 3000,
                'reference' => 'OM-FULL-0002',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.pending_amount', '3000.00')
            ->assertJsonPath('data.balance_due', '3000.00')
            ->assertJsonPath('data.payment_status', 'credit')
            ->assertJsonPath('data.payments.0.status', 'pending');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);

        $this->postJson('/api/manual-payments/'.$response->json('data.payments.0.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.paid_amount', '3000.00')
            ->assertJsonPath('data.pending_amount', '0.00')
            ->assertJsonPath('data.balance_due', '0.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 0,
        ]);
    }

    public function test_a_full_pending_payment_with_customer_remains_credit_when_rejected(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $product = $this->product();

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payment' => [
                'method' => 'wave',
                'amount' => 3000,
                'reference' => 'WAVE-FULL-REJECT-1',
            ],
        ])->assertCreated();

        $paymentId = $response->json('data.payments.0.id');

        $this->postJson('/api/manual-payments/'.$paymentId.'/reject', [
            'reason' => 'Référence introuvable chez Wave.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'credit')
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.pending_amount', '0.00')
            ->assertJsonPath('data.balance_due', '3000.00')
            ->assertJsonPath('data.payments.0.status', 'rejected');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $response->json('data.id'),
            'payment_status' => 'credit',
        ]);
    }

    public function test_a_partial_manual_payment_without_customer_is_rejected(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payment' => [
                'method' => 'wave',
                'amount' => 1000,
                'reference' => 'WAVE-PARTIAL-1',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manual_payments_require_a_reference_on_creation_and_on_settlement(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => ['method' => 'wave', 'amount' => 1000],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.reference']);

        $sale = $this->createCreditSale($user);

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'orange_money',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reference']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_manual_payment_reference_must_be_unique(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $firstSale = $this->createCreditSale($user);
        $this->addPendingPayment($firstSale, 'wave', 1000, 'WAVE-DUPLICATE-1');

        $product = $this->product(['stock_quantity' => 10]);

        $this->postJson('/api/sales', [
            'customer_id' => Customer::factory()->create()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => [
                'method' => 'wave',
                'amount' => 1000,
                'reference' => 'WAVE-DUPLICATE-1',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.reference']);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 10]);

        $secondSale = $this->createCreditSale($user);

        $this->postJson("/api/sales/{$secondSale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'wave-duplicate-1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reference']);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reference_duplicates_are_rejected_ignoring_case_and_surrounding_spaces(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product();
        $customer = Customer::factory()->create();

        $firstSale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => [
                'method' => 'wave',
                'amount' => 1000,
                'reference' => '  WAVE-Mixed-100  ',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.payments.0.reference', 'WAVE-Mixed-100');

        $firstPaymentId = $firstSale->json('data.payments.0.id');

        foreach (['wave-mixed-100', '  WAVE-MIXED-100  '] as $duplicate) {
            $this->postJson('/api/sales', [
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment' => [
                    'method' => 'wave',
                    'amount' => 1000,
                    'reference' => $duplicate,
                ],
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['payment.reference']);
        }

        $creditSale = $this->createCreditSale($user);

        foreach (['wave-mixed-100', '  WAVE-MIXED-100  '] as $duplicate) {
            $this->postJson("/api/sales/{$creditSale->id}/payments", [
                'amount' => 1000,
                'method' => 'wave',
                'reference' => $duplicate,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['reference']);
        }

        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $firstPaymentId,
            'reference' => 'WAVE-Mixed-100',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 9,
        ]);
    }

    public function test_reference_duplicates_are_rejected_ignoring_accents(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product();
        $customer = Customer::factory()->create();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => [
                'method' => 'wave',
                'amount' => 1000,
                'reference' => 'WAVE-Café-900',
            ],
        ])->assertCreated();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => [
                'method' => 'wave',
                'amount' => 1000,
                'reference' => 'wave-cafe-900',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.reference']);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_legacy_reference_with_surrounding_spaces_is_preserved_and_still_unique(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $sale = $this->createCreditSale($user);
        $legacy = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'amount' => 1000,
            'method' => 'wave',
            'reference' => '  WAVE-Legacy-001  ',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->getJson("/api/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.reference', '  WAVE-Legacy-001  ');

        $product = $this->product();
        $customer = Customer::factory()->create();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => [
                'method' => 'wave',
                'amount' => 1000,
                'reference' => 'wave-legacy-001',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.reference']);

        $otherSale = $this->createCreditSale($user);

        $this->postJson("/api/sales/{$otherSale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => '  WAVE-LEGACY-001  ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reference']);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $legacy->id,
            'reference' => '  WAVE-Legacy-001  ',
        ]);
    }

    public function test_payment_references_are_protected_by_a_unique_database_index(): void
    {
        $index = collect(Schema::getIndexes('payments'))
            ->firstWhere('name', 'payments_method_reference_unique');

        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
        $this->assertSame(['method', 'reference'], $index['columns']);
    }

    public function test_an_admin_can_confirm_a_pending_payment(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $sale = $this->createCreditSale($admin, $customer);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-CONFIRM-1');

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.paid_amount', '3000.00')
            ->assertJsonPath('data.pending_amount', '0.00')
            ->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.payments.0.status', 'confirmed')
            ->assertJsonPath('data.payments.0.confirmed_by_id', $admin->id)
            ->assertJsonPath('data.payments.0.confirmed_by_name', $admin->name);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertSame($admin->id, $payment->confirmed_by_id);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 0,
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'total_amount' => 3000,
        ]);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseHas('products', [
            'stock_quantity' => 7,
        ]);
    }

    public function test_a_manager_can_confirm_a_pending_payment(): void
    {
        $manager = $this->authenticate('Gérant');
        $this->openCashSession($manager);
        $sale = $this->createCreditSale($manager);
        $payment = $this->addPendingPayment($sale, 'orange_money', 3000, 'OM-CONFIRM-1');

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payments.0.status', 'confirmed');
    }

    public function test_a_rejection_requires_a_reason_and_keeps_the_amount_unpaid(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $sale = $this->createCreditSale($admin, $customer);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-REJECT-1');

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Référence introuvable chez Wave.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'credit')
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.pending_amount', '0.00')
            ->assertJsonPath('data.balance_due', '3000.00')
            ->assertJsonPath('data.payments.0.status', 'rejected')
            ->assertJsonPath('data.payments.0.rejection_reason', 'Référence introuvable chez Wave.');

        $payment->refresh();

        $this->assertSame(Payment::STATUS_REJECTED, $payment->status);
        $this->assertSame($admin->id, $payment->rejected_by_id);
        $this->assertNotNull($payment->rejected_at);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'total_amount' => 3000,
            'payment_status' => 'credit',
        ]);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseHas('products', [
            'stock_quantity' => 7,
        ]);

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);
    }

    public function test_a_pending_payment_cannot_be_confirmed_twice(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $sale = $this->createCreditSale($admin, $customer);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-DOUBLE-1');

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")->assertOk();

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 0,
        ]);
    }

    public function test_a_non_manual_payment_cannot_be_confirmed_through_the_manual_endpoint(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $product = $this->product();

        $sale = $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount' => 1000],
        ])->assertCreated();

        $this->postJson('/api/manual-payments/'.$sale->json('data.payments.0.id').'/confirm')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseHas('payments', [
            'id' => $sale->json('data.payments.0.id'),
            'status' => 'confirmed',
        ]);
    }

    public function test_an_employee_cannot_confirm_reject_or_list_manual_payments(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-ROLE-1');

        $employee = $this->authenticate('Employé');

        $this->getJson('/api/manual-payments')->assertForbidden();
        $this->postJson("/api/manual-payments/{$payment->id}/confirm")->assertForbidden();
        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Tentative employé.',
        ])->assertForbidden();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'confirmed_by_id' => null,
            'rejected_by_id' => null,
        ]);
    }

    public function test_an_idempotent_payment_retry_does_not_create_a_duplicate(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);

        $first = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-IDEMPOTENT-1',
            'idempotency_key' => 'payment-key-0001',
        ])->assertCreated();

        $second = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-IDEMPOTENT-1',
            'idempotency_key' => 'payment-key-0001',
        ])->assertCreated();

        $second->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.pending_amount', '1000.00');

        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale->id,
            'idempotency_key' => 'payment-key-0001',
            'status' => 'pending',
        ]);
    }

    public function test_an_idempotent_payment_key_with_different_data_is_rejected(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);

        $first = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-CONFLICT-1',
            'idempotency_key' => 'payment-conflict-0001',
        ])->assertCreated();

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 2000,
            'method' => 'wave',
            'reference' => 'WAVE-CONFLICT-1',
            'idempotency_key' => 'payment-conflict-0001',
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_key_conflict');

        $payment = Payment::query()
            ->where('sale_id', $sale->id)
            ->where('reference', 'WAVE-CONFLICT-1')
            ->sole();

        $this->assertSame($first->json('data.payments.0.id'), $payment->id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payment->idempotency_fingerprint);
        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->where('idempotency_key', 'payment-conflict-0001')->count());
        $this->assertSame(1000.0, $sale->fresh()->pendingAmount());
    }

    public function test_payment_keys_and_references_are_normalized_for_retries(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);

        $first = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => '  Wave-Café-001  ',
            'idempotency_key' => '  Paiement-Été-0001  ',
        ])->assertCreated();

        $second = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'wave-cafe-001',
            'idempotency_key' => 'paiement-ete-0001',
        ])->assertCreated();

        $second->assertJsonPath('data.id', $first->json('data.id'));

        $payment = Payment::query()
            ->where('sale_id', $sale->id)
            ->where('reference', 'Wave-Café-001')
            ->sole();

        $this->assertSame($first->json('data.payments.0.id'), $payment->id);
        $this->assertSame('paiement-ete-0001', $payment->idempotency_key);
        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->count());
    }

    public function test_a_legacy_payment_without_fingerprint_returns_the_sale_with_a_warning(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);

        $legacy = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $admin->id,
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-LEGACY-FINGERPRINT-1',
            'status' => Payment::STATUS_PENDING,
            'idempotency_key' => 'Legacy-Paiement-Été-0001',
            'idempotency_fingerprint' => null,
        ]);

        $response = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 2000,
            'method' => 'orange_money',
            'reference' => 'OM-DIFFERENT-0001',
            'idempotency_key' => 'legacy-paiement-ete-0001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', $sale->id)
            ->assertJsonPath('data.idempotency_warning', SaleService::WARNING_LEGACY_PAYMENT);

        $legacy->refresh();

        $this->assertNull($legacy->idempotency_fingerprint);
        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->count());
        $this->assertSame(1000.0, $sale->fresh()->pendingAmount());
    }

    public function test_a_rejected_payment_is_returned_on_an_identical_replay(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment(
            $sale,
            'wave',
            3000,
            'WAVE-REPLAY-REJECT-1',
            'payment-reject-replay-0001',
        );

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Référence introuvable.',
        ])->assertOk();

        $response = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 3000,
            'method' => 'wave',
            'reference' => 'WAVE-REPLAY-REJECT-1',
            'idempotency_key' => 'payment-reject-replay-0001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', $sale->id)
            ->assertJsonPath('data.payment_status', 'credit')
            ->assertJsonPath('data.payments.0.status', 'rejected');

        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->count());
        $this->assertSame(Payment::STATUS_REJECTED, $payment->fresh()->status);
    }

    public function test_payment_idempotency_keys_are_scoped_to_a_sale(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $firstSale = $this->createCreditSale($admin);
        $secondSale = $this->createCreditSale($admin);

        $this->postJson("/api/sales/{$firstSale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-SCOPE-1',
            'idempotency_key' => 'payment-scope-0001',
        ])->assertCreated();

        $this->postJson("/api/sales/{$secondSale->id}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-SCOPE-2',
            'idempotency_key' => 'payment-scope-0001',
        ])->assertCreated();

        $this->assertSame(1, Payment::query()->where('sale_id', $firstSale->id)->count());
        $this->assertSame(1, Payment::query()->where('sale_id', $secondSale->id)->count());
    }

    public function test_a_pending_payment_cannot_exceed_the_available_balance(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $this->addPendingPayment($sale, 'wave', 2000, 'WAVE-RESERVE-1');

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1500,
            'method' => 'orange_money',
            'reference' => 'OM-OVER-1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->count());

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'orange_money',
            'reference' => 'OM-RESERVE-2',
        ])->assertCreated()
            ->assertJsonPath('data.pending_amount', '3000.00')
            ->assertJsonPath('data.available_due', '0.00');

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1,
            'method' => 'cash',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_a_partial_pending_payment_can_coexist_with_a_confirmed_cash_payment(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $sale = $this->createCreditSale($admin, $customer);
        $payment = $this->addPendingPayment($sale, 'wave', 1000, 'WAVE-MIXED-1');

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 2000,
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.paid_amount', '2000.00')
            ->assertJsonPath('data.pending_amount', '1000.00')
            ->assertJsonPath('data.balance_due', '1000.00')
            ->assertJsonPath('data.available_due', '0.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 1000,
        ]);

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.paid_amount', '3000.00')
            ->assertJsonPath('data.balance_due', '0.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 0,
        ]);
    }

    public function test_a_rejected_payment_can_be_replaced_with_a_new_reference(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $first = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-REPLACE-1');

        $this->postJson("/api/manual-payments/{$first->id}/reject", [
            'reason' => 'Référence erronée.',
        ])->assertOk();

        $second = $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 3000,
            'method' => 'wave',
            'reference' => 'WAVE-REPLACE-2',
        ])->assertCreated()
            ->assertJsonPath('data.pending_amount', '3000.00');

        $this->postJson('/api/manual-payments/'.$second->json('data.payments.1.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertSame(Payment::STATUS_REJECTED, $first->fresh()->status);
        $this->assertSame(2, Payment::query()->where('sale_id', $sale->id)->count());
    }

    public function test_a_pending_payment_cannot_be_rejected_twice(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-DOUBLE-REJECT-1');

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Référence introuvable.',
        ])->assertOk();

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Deuxième tentative.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_REJECTED, $payment->status);
        $this->assertSame('Référence introuvable.', $payment->rejection_reason);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'manual_payment.rejected')
            ->where('model_id', $payment->id)
            ->count());
    }

    public function test_a_confirmed_payment_cannot_be_rejected(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-CONFIRMED-REJECT-1');

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")->assertOk();

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Tentative après confirmation.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertNull($payment->rejected_by_id);
        $this->assertNull($payment->rejected_at);
        $this->assertNull($payment->rejection_reason);
        $this->assertSame(1, AuditLog::query()
            ->where('model_id', $payment->id)
            ->count());
    }

    public function test_a_stale_second_decision_cannot_override_the_first(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-STALE-1');
        $stalePayment = Payment::query()->findOrFail($payment->id);
        $service = app(SaleService::class);

        $service->confirmManualPayment($payment, $admin);

        try {
            $service->rejectManualPayment($stalePayment, $admin, 'Décision concurrente.');
            $this->fail('Une décision obsolète aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertNull($payment->rejection_reason);
        $this->assertSame(1, AuditLog::query()
            ->where('model_id', $payment->id)
            ->count());
    }

    public function test_manual_payment_decisions_are_recorded_in_the_audit_log(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);

        $confirmedSale = $this->createCreditSale($admin);
        $confirmed = $this->addPendingPayment($confirmedSale, 'wave', 3000, 'WAVE-AUDIT-1');

        $this->postJson("/api/manual-payments/{$confirmed->id}/confirm")->assertOk();

        $confirmLog = AuditLog::query()
            ->where('action', 'manual_payment.confirmed')
            ->where('model_type', Payment::class)
            ->where('model_id', $confirmed->id)
            ->sole();

        $this->assertSame($admin->id, $confirmLog->user_id);
        $this->assertSame(['status' => Payment::STATUS_PENDING], $confirmLog->old_values);
        $this->assertSame(['status' => Payment::STATUS_CONFIRMED], $confirmLog->new_values);
        $this->assertNotNull($confirmLog->created_at);

        $rejectedSale = $this->createCreditSale($admin);
        $rejected = $this->addPendingPayment($rejectedSale, 'orange_money', 3000, 'OM-AUDIT-2');
        $reason = 'Référence introuvable chez Orange Money.';

        $this->postJson("/api/manual-payments/{$rejected->id}/reject", [
            'reason' => $reason,
        ])->assertOk();

        $rejectLog = AuditLog::query()
            ->where('action', 'manual_payment.rejected')
            ->where('model_type', Payment::class)
            ->where('model_id', $rejected->id)
            ->sole();

        $this->assertSame($admin->id, $rejectLog->user_id);
        $this->assertSame(['status' => Payment::STATUS_PENDING], $rejectLog->old_values);
        $this->assertSame([
            'status' => Payment::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ], $rejectLog->new_values);
        $this->assertNotNull($rejectLog->created_at);
    }

    public function test_a_confirmation_is_refused_when_the_amount_exceeds_the_remaining_due(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $sale = $this->createCreditSale($admin, $customer);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-OVERDUE-1');

        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $admin->id,
            'amount' => 2000,
            'method' => 'cash',
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
        $this->assertSame(0, AuditLog::query()
            ->where('model_id', $payment->id)
            ->count());
    }

    public function test_a_zero_amount_manual_payment_cannot_be_confirmed(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $admin->id,
            'amount' => 0,
            'method' => 'wave',
            'reference' => 'WAVE-ZERO-1',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(0, AuditLog::query()
            ->where('model_id', $payment->id)
            ->count());
    }

    public function test_manual_payments_can_be_filtered_by_status_method_and_reference(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);

        $confirmedSale = $this->createCreditSale($admin);
        $confirmed = $this->addPendingPayment($confirmedSale, 'wave', 3000, 'WAVE-FILTER-OK');
        $this->postJson("/api/manual-payments/{$confirmed->id}/confirm")->assertOk();

        $rejectedSale = $this->createCreditSale($admin);
        $rejected = $this->addPendingPayment($rejectedSale, 'orange_money', 3000, 'OM-FILTER-KO');
        $this->postJson("/api/manual-payments/{$rejected->id}/reject", [
            'reason' => 'Référence introuvable.',
        ])->assertOk();

        $pendingSale = $this->createCreditSale($admin);
        $pending = $this->addPendingPayment($pendingSale, 'wave', 3000, 'WAVE-FILTER-PENDING');

        $this->getJson('/api/manual-payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/manual-payments?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $confirmed->id);

        $this->getJson('/api/manual-payments?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rejected->id);

        $this->getJson('/api/manual-payments?status=all&method=orange_money')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rejected->id);

        $waveIds = collect($this->getJson('/api/manual-payments?status=all&method=wave')
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$confirmed->id, $pending->id], $waveIds);

        $this->getJson('/api/manual-payments?status=all&search=filter-ko')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rejected->id);

        $this->getJson('/api/manual-payments?status=all&method=wave&search=WAVE-FILTER')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_manual_payment_filters_are_validated(): void
    {
        $this->authenticate('Admin');

        $this->getJson('/api/manual-payments?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/manual-payments?method=cash')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->getJson('/api/manual-payments?search='.str_repeat('a', 256))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);

        $this->getJson('/api/manual-payments?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/manual-payments?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);

        $this->getJson('/api/manual-payments?status=&method=&search=&page=&per_page=')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_manual_payment_routes_require_authentication(): void
    {
        $seller = User::factory()->create();
        $sale = Sale::factory()->create([
            'user_id' => $seller->id,
            'total_amount' => 3000,
            'payment_status' => 'credit',
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $seller->id,
            'amount' => 3000,
            'method' => 'wave',
            'reference' => 'WAVE-UNAUTH-1',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->getJson('/api/manual-payments')->assertUnauthorized();
        $this->postJson("/api/manual-payments/{$payment->id}/confirm")->assertUnauthorized();
        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Tentative non authentifiée.',
        ])->assertUnauthorized();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_deactivated_manager_cannot_manage_manual_payments(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-INACTIVE-1');

        $admin->forceFill(['is_active' => false])->save();
        $admin->refresh();

        $this->getJson('/api/manual-payments')->assertForbidden();
        $this->postJson("/api/manual-payments/{$payment->id}/confirm")->assertForbidden();
        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => 'Tentative désactivée.',
        ])->assertForbidden();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_rejection_reason_is_trimmed_and_limited(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'wave', 3000, 'WAVE-REASON-1');

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => '   ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => str_repeat('a', 501),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/manual-payments/{$payment->id}/reject", [
            'reason' => '  Référence invalide.  ',
        ])->assertOk();

        $payment->refresh();

        $this->assertSame('Référence invalide.', $payment->rejection_reason);

        $log = AuditLog::query()
            ->where('action', 'manual_payment.rejected')
            ->where('model_id', $payment->id)
            ->sole();

        $this->assertSame('Référence invalide.', $log->new_values['rejection_reason']);
    }

    public function test_a_non_manual_payment_cannot_be_rejected_through_the_manual_endpoint(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $product = $this->product();

        $sale = $this->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount' => 1000],
        ])->assertCreated();

        $paymentId = $sale->json('data.payments.0.id');

        $this->postJson("/api/manual-payments/{$paymentId}/reject", [
            'reason' => 'Tentative sur un paiement espèces.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'confirmed',
            'rejection_reason' => null,
        ]);
    }

    public function test_manual_payments_are_listed_with_pending_first_by_default(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);
        $sale = $this->createCreditSale($admin);
        $payment = $this->addPendingPayment($sale, 'orange_money', 3000, 'OM-LIST-1');

        $this->getJson('/api/manual-payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.sale_reference', $sale->reference)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.sale.balance_due', '3000.00');

        $this->postJson("/api/manual-payments/{$payment->id}/confirm")->assertOk();

        $this->getJson('/api/manual-payments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/manual-payments?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payment->id);
    }

    public function test_manual_payment_listing_is_paginated_only_when_requested(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);

        $first = $this->addPendingPayment(
            $this->createCreditSale($admin),
            'wave',
            3000,
            'WAVE-PAGE-1',
        );
        $second = $this->addPendingPayment(
            $this->createCreditSale($admin),
            'orange_money',
            3000,
            'OM-PAGE-2',
        );
        $third = $this->addPendingPayment(
            $this->createCreditSale($admin),
            'wave',
            3000,
            'WAVE-PAGE-3',
        );

        $firstPage = $this->getJson('/api/manual-payments?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 2);

        $secondPage = $this->getJson('/api/manual-payments?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3);

        $firstPageIds = collect($firstPage->json('data'))->pluck('id')->all();
        $secondPageIds = collect($secondPage->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], [...$firstPageIds, ...$secondPageIds]);
        $this->assertEmpty(array_intersect($firstPageIds, $secondPageIds));

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $third->id],
            collect($this->getJson('/api/manual-payments')->json('data'))->pluck('id')->all(),
        );
    }

    public function test_manual_payment_search_treats_percent_and_underscore_literally(): void
    {
        $admin = $this->authenticate('Admin');
        $this->openCashSession($admin);

        $percent = $this->addPendingPayment(
            $this->createCreditSale($admin),
            'wave',
            3000,
            'WAVE-100%',
        );
        $this->addPendingPayment(
            $this->createCreditSale($admin),
            'wave',
            3000,
            'WAVE-100X',
        );
        $underscore = $this->addPendingPayment(
            $this->createCreditSale($admin),
            'wave',
            3000,
            'WAVE_100',
        );

        $this->getJson('/api/manual-payments?status=all&search='.urlencode('WAVE-100%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $percent->id);

        $this->getJson('/api/manual-payments?status=all&search='.urlencode('WAVE_100'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underscore->id);
    }
}
