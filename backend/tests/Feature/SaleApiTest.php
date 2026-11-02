<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie l'utilisateur de la session avec le rôle demandé.
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
     * Produit du catalogue, disponible en stock.
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
     * Charge utile d'une vente d'une ligne, entierement reglee en especes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Product $product, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
            'payment' => [
                'method' => 'cash',
                'amount' => 1000 * $quantity,
            ],
        ], $overrides);
    }

    public function test_guests_cannot_access_the_sale_endpoints(): void
    {
        $this->getJson('/api/sales')->assertUnauthorized();

        $this->postJson('/api/sales', ['items' => []])->assertUnauthorized();
    }

    public function test_a_sale_can_be_created(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);
        $product = $this->product();

        $response = $this->postJson('/api/sales', $this->payload($product, 2));

        $response->assertCreated()
            ->assertJsonPath('data.cash_session_id', $cashSession->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.total_amount', '2000.00')
            ->assertJsonPath('data.paid_amount', '2000.00')
            ->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_name', 'Perceuse à percussion 800W')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '1000.00')
            ->assertJsonPath('data.items.0.subtotal', '2000.00')
            ->assertJsonPath('data.payments.0.amount', '2000.00')
            ->assertJsonPath('data.payments.0.method', 'cash');

        $this->assertMatchesRegularExpression('/^VTE-\d{6}$/', $response->json('data.reference'));

        $this->assertDatabaseHas('sales', [
            'cash_session_id' => $cashSession->id,
            'user_id' => $user->id,
            'total_amount' => 2000,
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $response->json('data.id'),
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'subtotal' => 2000,
        ]);

        $this->assertDatabaseHas('payments', [
            'sale_id' => $response->json('data.id'),
            'user_id' => $user->id,
            'amount' => 2000,
            'method' => 'cash',
        ]);
    }

    public function test_replaying_the_same_idempotency_key_returns_the_same_sale_without_duplicate_stock(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product(['stock_quantity' => 10]);

        $payload = $this->payload($product, 2, [
            'idempotency_key' => 'caisse-essai-0001',
        ]);

        $first = $this->postJson('/api/sales', $payload)->assertCreated();
        $second = $this->postJson('/api/sales', $payload)->assertCreated();

        $second->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.reference', $first->json('data.reference'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 8]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_an_absent_idempotency_key_still_allows_two_distinct_sales(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);
        $product = $this->product(['stock_quantity' => 10]);

        $payload = $this->payload($product, 2);

        $this->postJson('/api/sales', $payload)->assertCreated();
        $this->postJson('/api/sales', $payload)->assertCreated();

        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 6]);
    }

    public function test_creating_a_sale_decrements_the_stock_of_each_product(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $perceuse = $this->product(['name' => 'Perceuse', 'stock_quantity' => 10]);
        $marteau = $this->product(['name' => 'Marteau', 'stock_quantity' => 4, 'sale_price' => 500]);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $perceuse->id, 'quantity' => 3],
                ['product_id' => $marteau->id, 'quantity' => 2],
            ],
            'payment' => ['method' => 'cash', 'amount' => 4000],
        ])->assertCreated();

        $this->assertDatabaseHas('products', ['id' => $perceuse->id, 'stock_quantity' => 7]);
        $this->assertDatabaseHas('products', ['id' => $marteau->id, 'stock_quantity' => 2]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $perceuse->id,
            'user_id' => $user->id,
            'type' => 'out',
            'quantity' => -3,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $marteau->id,
            'user_id' => $user->id,
            'type' => 'out',
            'quantity' => -2,
        ]);
    }

    public function test_a_sale_cannot_exceed_the_available_stock(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product(['stock_quantity' => 3]);

        $response = $this->postJson('/api/sales', $this->payload($product, 5));

        $response->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Stock insuffisant pour « Perceuse à percussion 800W » : 3 disponible(s), 5 demandé(s).',
            );

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 3]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_failed_sale_does_not_decrement_the_stock_of_the_previous_lines(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $perceuse = $this->product(['name' => 'Perceuse', 'stock_quantity' => 10]);
        $marteau = $this->product(['name' => 'Marteau', 'stock_quantity' => 1, 'sale_price' => 500]);

        // La seconde ligne echoue : la transaction annule la decrementation de
        // la premiere ligne.
        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $perceuse->id, 'quantity' => 2],
                ['product_id' => $marteau->id, 'quantity' => 5],
            ],
            'payment' => ['method' => 'cash', 'amount' => 4000],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('products', ['id' => $perceuse->id, 'stock_quantity' => 10]);
        $this->assertDatabaseHas('products', ['id' => $marteau->id, 'stock_quantity' => 1]);
    }

    public function test_the_same_product_cannot_be_sold_beyond_the_available_stock(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product(['stock_quantity' => 6]);

        // Les deux lignes se cumulent : 5 + 5 depasse le stock de 6.
        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
                ['product_id' => $product->id, 'quantity' => 5],
            ],
            'payment' => ['method' => 'cash', 'amount' => 10000],
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Stock insuffisant pour « Perceuse à percussion 800W » : 1 disponible(s), 5 demandé(s).',
            );

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 6]);
    }

    public function test_the_same_product_can_be_sold_on_several_lines(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product(['stock_quantity' => 10]);

        $response = $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
                ['product_id' => $product->id, 'quantity' => 4],
            ],
            'payment' => ['method' => 'cash', 'amount' => 7000],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_amount', '7000.00')
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 3]);
    }

    public function test_a_sale_requires_an_open_cash_session(): void
    {
        $user = $this->authenticate();
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 1))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant de vendre.',
            );

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_sale_is_attached_to_the_open_cash_session_of_the_vendor(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 1))
            ->assertCreated()
            ->assertJsonPath('data.cash_session_id', $cashSession->id);
    }

    public function test_a_credit_sale_increases_the_customer_outstanding_balance(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'credit')
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.balance_due', '3000.00')
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.customer_name', $customer->name);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_partial_payment_leaves_the_remainder_on_the_customer(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create(['outstanding_balance' => 0]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => ['method' => 'cash', 'amount' => 1000],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.paid_amount', '1000.00')
            ->assertJsonPath('data.balance_due', '2000.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 2000,
        ]);
    }

    public function test_a_fully_paid_sale_leaves_the_customer_balance_untouched(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create(['outstanding_balance' => 12000]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 2, [
            'customer_id' => $customer->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 12000,
        ]);
    }

    public function test_a_credit_sale_requires_a_customer(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_the_paid_amount_cannot_exceed_the_total_of_the_sale(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 1, [
            'payment' => ['method' => 'cash', 'amount' => 5000],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.amount']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 10]);
    }

    public function test_creating_a_sale_requires_valid_lines(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $this->postJson('/api/sales', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->postJson('/api/sales', ['items' => [['product_id' => 999, 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id', 'items.0.quantity']);
    }

    public function test_the_catalogue_price_is_used_when_no_unit_price_is_given(): void
    {
        $user = $this->authenticate('Employé');
        $this->openCashSession($user);

        $product = $this->product(['sale_price' => 1250.50]);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'payment' => ['method' => 'cash', 'amount' => 2501],
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '1250.50')
            ->assertJsonPath('data.items.0.subtotal', '2501.00')
            ->assertJsonPath('data.total_amount', '2501.00');
    }

    public function test_a_manager_can_apply_a_custom_unit_price(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product(['sale_price' => 1000]);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 900],
            ],
            'payment' => ['method' => 'cash', 'amount' => 1800],
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '900.00')
            ->assertJsonPath('data.total_amount', '1800.00');
    }

    public function test_an_employee_cannot_impose_a_unit_price(): void
    {
        $user = $this->authenticate('Employé');
        $this->openCashSession($user);

        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1],
            ],
            'payment' => ['method' => 'cash', 'amount' => 1],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.unit_price']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 10,
        ]);
    }

    public function test_a_zero_unit_price_is_rejected(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 0],
            ],
            'payment' => ['method' => 'cash', 'amount' => 0],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.unit_price']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_negative_unit_price_is_rejected(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => -100],
            ],
            'payment' => ['method' => 'cash', 'amount' => 0],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.unit_price']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_product_with_a_zero_sale_price_cannot_be_sold(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product(['sale_price' => 0]);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'payment' => ['method' => 'cash', 'amount' => 0],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.unit_price']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 10,
        ]);
    }

    public function test_a_payment_reference_is_stored(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 1, [
            'payment' => ['method' => 'orange_money', 'amount' => 1000, 'reference' => 'OM-778899'],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payments.0.method', 'orange_money')
            ->assertJsonPath('data.payments.0.reference', 'OM-778899');
    }

    public function test_sales_are_listed_with_their_details(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);

        $customer = Customer::factory()->create(['name' => 'Client Passage']);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 2, ['customer_id' => $customer->id]))
            ->assertCreated();

        $response = $this->getJson('/api/sales');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cash_session_id', $cashSession->id)
            ->assertJsonPath('data.0.customer_name', 'Client Passage')
            ->assertJsonPath('data.0.total_amount', '2000.00')
            ->assertJsonPath('data.0.items.0.product_name', 'Perceuse à percussion 800W')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'reference',
                    'customer_id',
                    'customer_name',
                    'user_id',
                    'user_name',
                    'cash_session_id',
                    'total_amount',
                    'paid_amount',
                    'balance_due',
                    'payment_status',
                    'items',
                    'payments',
                    'created_at',
                    'updated_at',
                ]],
                'links',
                'meta',
            ]);
    }

    public function test_the_sale_history_can_be_searched_and_filtered(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();

        $paid = $this->postJson('/api/sales', $this->payload($product, 1))->assertCreated();
        $credit = $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'customer_id' => Customer::factory()->create()->id,
        ])->assertCreated();

        $this->getJson('/api/sales?search='.$credit->json('data.reference'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $credit->json('data.id'));

        $this->getJson('/api/sales?payment_status=credit')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $credit->json('data.id'));

        $this->getJson('/api/sales?payment_status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paid->json('data.id'));
    }

    public function test_a_sale_can_be_displayed_in_the_history(): void
    {
        $this->authenticate();

        Sale::factory()->create(['reference' => 'VTE-000123', 'total_amount' => 15000]);

        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'VTE-000123')
            ->assertJsonPath('data.0.total_amount', '15000.00');
    }

    public function test_a_single_sale_can_be_displayed(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);

        $customer = Customer::factory()->create(['name' => 'Client Passage']);
        $product = $this->product();

        $sale = $this->postJson('/api/sales', $this->payload($product, 2, ['customer_id' => $customer->id]))
            ->assertCreated();

        $this->getJson('/api/sales/'.$sale->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.id', $sale->json('data.id'))
            ->assertJsonPath('data.reference', $sale->json('data.reference'))
            ->assertJsonPath('data.cash_session_id', $cashSession->id)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.customer_name', 'Client Passage')
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.user_name', $user->name)
            ->assertJsonPath('data.total_amount', '2000.00')
            ->assertJsonPath('data.paid_amount', '2000.00')
            ->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_name', 'Perceuse à percussion 800W')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', '2000.00')
            ->assertJsonPath('data.payments.0.amount', '2000.00')
            ->assertJsonPath('data.payments.0.method', 'cash');
    }

    public function test_an_unknown_sale_returns_a_not_found_response(): void
    {
        $this->authenticate();

        $this->getJson('/api/sales/9999')->assertNotFound();
    }

    public function test_guests_cannot_display_a_single_sale(): void
    {
        $sale = Sale::factory()->create();

        $this->getJson('/api/sales/'.$sale->id)->assertUnauthorized();
    }

    public function test_a_credit_sale_cannot_exceed_the_customer_credit_limit(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create([
            'credit_limit' => 2000,
            'outstanding_balance' => 0,
        ]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 10]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 0,
        ]);
    }

    public function test_the_credit_limit_check_includes_the_existing_balance(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create([
            'credit_limit' => 5000,
            'outstanding_balance' => 4000,
        ]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 2, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 4000,
        ]);
    }

    public function test_a_credit_sale_at_the_credit_limit_is_allowed(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create([
            'credit_limit' => 3000,
            'outstanding_balance' => 0,
        ]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'credit');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
    }

    public function test_a_customer_without_configured_credit_limit_can_buy_on_credit(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create([
            'credit_limit' => 0,
            'outstanding_balance' => 0,
        ]);
        $product = $this->product();

        $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'credit');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
    }

    public function test_a_credit_sale_can_be_settled_in_several_payments(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create([
            'credit_limit' => 10000,
            'outstanding_balance' => 0,
        ]);
        $product = $this->product();

        $sale = $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'credit');

        $saleId = $sale->json('data.id');

        $this->postJson("/api/sales/{$saleId}/payments", [
            'amount' => 2000,
            'method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.paid_amount', '2000.00')
            ->assertJsonPath('data.balance_due', '1000.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 1000,
        ]);
        $this->assertDatabaseHas('payments', [
            'sale_id' => $saleId,
            'user_id' => $user->id,
            'amount' => 2000,
            'method' => 'cash',
        ]);

        $this->postJson("/api/sales/{$saleId}/payments", [
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-0001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.paid_amount', '3000.00')
            ->assertJsonPath('data.balance_due', '0.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 0,
        ]);
        $this->assertSame(2, Sale::findOrFail($saleId)->payments()->count());
    }

    public function test_a_payment_cannot_exceed_the_remaining_balance(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $customer = Customer::factory()->create([
            'credit_limit' => 10000,
            'outstanding_balance' => 0,
        ]);
        $product = $this->product();

        $sale = $this->postJson('/api/sales', $this->payload($product, 3, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))->assertCreated();

        $this->postJson("/api/sales/{$sale->json('data.id')}/payments", [
            'amount' => 4000,
            'method' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'outstanding_balance' => 3000,
        ]);
    }

    public function test_a_fully_paid_sale_cannot_receive_a_new_payment(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $product = $this->product();
        $sale = $this->postJson('/api/sales', $this->payload($product, 1))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->postJson("/api/sales/{$sale->json('data.id')}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_an_employee_cannot_settle_a_credit_sale(): void
    {
        $employee = $this->authenticate('Employé');
        $this->openCashSession($employee);

        $customer = Customer::factory()->create();
        $product = $this->product();

        $sale = $this->postJson('/api/sales', $this->payload($product, 1, [
            'customer_id' => $customer->id,
            'payment' => null,
        ]))->assertCreated();

        $this->postJson("/api/sales/{$sale->json('data.id')}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertForbidden();
    }

    public function test_guests_cannot_settle_a_sale(): void
    {
        $sale = Sale::factory()->create();

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertUnauthorized();
    }

    public function test_an_employee_cannot_browse_the_sales_history(): void
    {
        $sale = Sale::factory()->create();

        $this->authenticate('Employé');

        $this->getJson('/api/sales')->assertForbidden();
        $this->getJson("/api/sales/{$sale->id}")->assertForbidden();
    }

    public function test_a_seller_can_download_the_invoice_of_their_own_sale(): void
    {
        $employee = $this->authenticate('Employé');
        $this->openCashSession($employee);

        $product = $this->product();

        $sale = $this->postJson('/api/sales', $this->payload($product, 1))
            ->assertCreated();

        $this->get("/api/sales/{$sale->json('data.id')}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_employee_cannot_download_another_sellers_invoice(): void
    {
        $otherSeller = User::factory()->create();
        $sale = Sale::factory()->create(['user_id' => $otherSeller->id]);

        $this->authenticate('Employé');

        $this->get("/api/sales/{$sale->id}/invoice")->assertForbidden();
    }

    public function test_an_admin_can_download_any_invoice(): void
    {
        $this->authenticate();

        $sale = Sale::factory()->create();

        $this->get("/api/sales/{$sale->id}/invoice")->assertOk();
    }

    public function test_guests_cannot_download_an_invoice(): void
    {
        $sale = Sale::factory()->create();

        $this->get("/api/sales/{$sale->id}/invoice")->assertUnauthorized();
    }
}
