<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie un utilisateur avec le role demande.
     */
    private function authenticateAs(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Charge utile valide pour une categorie.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Outillage',
            'description' => 'Outils manuels et électriques',
        ], $overrides);
    }

    public function test_guests_cannot_write_categories(): void
    {
        $category = Category::factory()->create();

        $this->postJson('/api/categories', $this->payload())->assertUnauthorized();
        $this->getJson("/api/categories/{$category->id}")->assertUnauthorized();
        $this->putJson("/api/categories/{$category->id}", $this->payload())->assertUnauthorized();
        $this->deleteJson("/api/categories/{$category->id}")->assertUnauthorized();
    }

    public function test_an_employee_can_read_but_not_manage_categories(): void
    {
        $this->authenticateAs('Employé');

        $category = Category::factory()->create();

        $this->getJson('/api/categories')->assertOk();
        $this->getJson("/api/categories/{$category->id}")->assertOk();

        $this->postJson('/api/categories', $this->payload())->assertForbidden();
        $this->putJson("/api/categories/{$category->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/categories/{$category->id}")->assertForbidden();
    }

    public function test_an_admin_can_create_a_category(): void
    {
        $this->authenticateAs('Admin');

        $this->postJson('/api/categories', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Outillage')
            ->assertJsonPath('data.description', 'Outils manuels et électriques')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', [
            'name' => 'Outillage',
            'description' => 'Outils manuels et électriques',
        ]);
    }

    public function test_a_manager_can_update_a_category(): void
    {
        $this->authenticateAs('Gérant');

        $category = Category::factory()->create(['name' => 'Ancien nom']);

        $this->putJson("/api/categories/{$category->id}", [
            'name' => 'Nouveau nom',
            'description' => 'Description mise à jour',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Nouveau nom',
        ]);
    }

    public function test_a_category_name_must_be_unique(): void
    {
        $this->authenticateAs('Admin');

        Category::factory()->create(['name' => 'Outillage']);

        $this->postJson('/api/categories', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_category_parent_must_exist(): void
    {
        $this->authenticateAs('Admin');

        $this->postJson('/api/categories', $this->payload([
            'parent_id' => 99999,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $this->authenticateAs('Admin');

        $category = Category::factory()->create();

        $this->putJson("/api/categories/{$category->id}", [
            'parent_id' => $category->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_a_category_cannot_have_one_of_its_children_as_parent(): void
    {
        $this->authenticateAs('Admin');

        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->putJson("/api/categories/{$parent->id}", [
            'parent_id' => $child->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $this->authenticateAs('Admin');

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->deleteJson("/api/categories/{$category->id}")
            ->assertConflict();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_category_with_children_cannot_be_deleted(): void
    {
        $this->authenticateAs('Admin');

        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->deleteJson("/api/categories/{$parent->id}")
            ->assertConflict();

        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id]);
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $this->authenticateAs('Admin');

        $category = Category::factory()->create();

        $this->deleteJson("/api/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Catégorie supprimée.');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_category_is_displayed_with_its_product_count(): void
    {
        $this->authenticateAs('Admin');

        $category = Category::factory()->create(['name' => 'Peinture']);
        Product::factory()->create(['category_id' => $category->id]);

        $this->getJson("/api/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Peinture')
            ->assertJsonPath('data.products_count', 1);
    }

    public function test_the_category_list_exposes_the_product_count(): void
    {
        $this->authenticateAs('Admin');

        $category = Category::factory()->create(['name' => 'Peinture']);
        Product::factory()->create(['category_id' => $category->id]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peinture',
                'products_count' => 1,
            ]);
    }
}
