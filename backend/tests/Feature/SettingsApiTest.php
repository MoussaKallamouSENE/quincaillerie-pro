<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(string $role = 'Admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate([
            'name' => $role,
            'guard_name' => 'web',
        ]));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_only_admins_can_read_and_update_company_settings(): void
    {
        $this->authenticate('Gérant');
        $this->getJson('/api/settings/company')->assertForbidden();
        $this->putJson('/api/settings/company', ['business_name' => 'Quincaillerie Test'])->assertForbidden();

        $this->authenticate('Admin');
        $this->putJson('/api/settings/company', [
            'business_name' => 'Quincaillerie Test', 'phone' => '+221 70 000 00 00',
            'email' => 'contact@example.test', 'address' => 'Dakar', 'invoice_footer' => 'Merci.',
        ])->assertOk()->assertJsonPath('settings.currency', 'XOF');
        $this->assertSame('Quincaillerie Test', Setting::query()->where('key', 'company.profile')->first()->value['business_name']);
    }

    public function test_company_settings_validate_fields_and_logo_type(): void
    {
        $this->authenticate();
        $this->putJson('/api/settings/company', ['business_name' => '', 'email' => 'pas-un-email'])->assertUnprocessable();
        $this->postJson('/api/settings/company/logo', [])->assertUnprocessable();
    }

    public function test_users_can_update_only_their_own_profile(): void
    {
        $user = $this->authenticate('Employé');
        $this->putJson('/api/profile', [
            'name' => 'Nouveau Nom', 'email' => $user->email, 'phone' => '+221 77 000 00 00',
        ])->assertOk()->assertJsonPath('user.name', 'Nouveau Nom');
        $this->assertSame('Nouveau Nom', $user->fresh()->name);
    }

    public function test_password_change_requires_current_password_and_confirmation(): void
    {
        $user = $this->authenticate();
        $this->putJson('/api/profile/password', [
            'current_password' => 'incorrect', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable();
        $this->putJson('/api/profile/password', [
            'current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'different',
        ])->assertUnprocessable();
        $this->putJson('/api/profile/password', [
            'current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertOk();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }
}
