<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie l'utilisateur de la session.
     */
    private function authenticate(): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Vente complete : un client, une ligne de deux marteaux et un paiement.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createSale(array $overrides = [], bool $withPayment = true): Sale
    {
        $seller = User::factory()->create(['name' => 'Awa Diop']);

        $customer = Customer::factory()->create([
            'name' => 'Boutique Chez Ali',
            'phone' => '+221 77 123 45 67',
        ]);

        $cashSession = CashSession::factory()->create([
            'user_id' => $seller->id,
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $sale = Sale::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'user_id' => $seller->id,
            'cash_session_id' => $cashSession->id,
            'reference' => 'VTE-000123',
            'total_amount' => 2500,
            'payment_status' => 'paid',
        ], $overrides));

        $product = Product::factory()->create([
            'name' => 'Marteau 500 g',
            'sku' => 'PRD-0042',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 1250,
            'subtotal' => 2500,
        ]);

        if ($withPayment) {
            Payment::create([
                'sale_id' => $sale->id,
                'user_id' => $seller->id,
                'amount' => 2500,
                'method' => 'cash',
            ]);
        }

        return $sale->load(['customer', 'user', 'items.product', 'payments']);
    }

    public function test_guests_cannot_download_an_invoice(): void
    {
        $this->getJson('/api/sales/1/invoice')->assertUnauthorized();
    }

    public function test_a_sale_invoice_can_be_downloaded_as_a_pdf(): void
    {
        $this->authenticate();
        $sale = $this->createSale();

        $response = $this->get("/api/sales/{$sale->id}/invoice");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'facture-VTE-000123.pdf',
            $response->headers->get('content-disposition'),
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_an_invoice_cannot_be_downloaded_for_an_unknown_sale(): void
    {
        $this->authenticate();

        $this->getJson('/api/sales/999999/invoice')->assertNotFound();
    }

    public function test_the_invoice_view_lists_the_sale_details(): void
    {
        $sale = $this->createSale();

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('Quincaillerie Pro', $html);
        $this->assertStringContainsString('VTE-000123', $html);
        $this->assertStringContainsString('Boutique Chez Ali', $html);
        $this->assertStringContainsString('+221 77 123 45 67', $html);
        $this->assertStringContainsString('Awa Diop', $html);
        $this->assertStringContainsString('Marteau 500 g', $html);
        $this->assertStringContainsString('PRD-0042', $html);
        $this->assertStringContainsString('1 250,00 FCFA', $html);
        $this->assertStringContainsString('2 500,00 FCFA', $html);
        $this->assertStringContainsString('Espèces', $html);
        $this->assertStringContainsString('Payée', $html);
    }

    public function test_the_invoice_view_shows_the_credit_balance_when_nothing_is_paid(): void
    {
        $sale = $this->createSale(['payment_status' => 'credit'], withPayment: false);

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('Aucun paiement enregistré', $html);
        $this->assertStringContainsString('Reste à payer', $html);
        $this->assertStringContainsString('2 500,00 FCFA', $html);
        $this->assertStringContainsString('À crédit', $html);
    }

    public function test_the_invoice_view_names_a_walk_in_customer(): void
    {
        $sale = $this->createSale(['customer_id' => null]);

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('Client de passage', $html);
    }
}
