<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogReferenceApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie l'utilisateur de la session.
     */
    private function authenticate(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_categories_are_listed_alphabetically(): void
    {
        $this->authenticate();

        Category::factory()->create(['name' => 'Peinture']);
        Category::factory()->create(['name' => 'Outillage']);

        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Outillage')
            ->assertJsonPath('data.1.name', 'Peinture')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'description',
                    'is_active',
                ]],
            ]);
    }

    public function test_units_are_listed_with_their_symbol(): void
    {
        $this->authenticate();

        Unit::factory()->create(['name' => 'Pièce', 'symbol' => 'pcs']);
        Unit::factory()->create(['name' => 'Mètre', 'symbol' => 'm']);

        $response = $this->getJson('/api/units');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Mètre')
            ->assertJsonPath('data.0.symbol', 'm')
            ->assertJsonPath('data.1.name', 'Pièce')
            ->assertJsonPath('data.1.symbol', 'pcs')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'symbol',
                    'description',
                    'is_active',
                ]],
            ]);
    }

    public function test_categories_require_authentication(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
    }

    public function test_units_require_authentication(): void
    {
        $this->getJson('/api/units')->assertUnauthorized();
    }
}
