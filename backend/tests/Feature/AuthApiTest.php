<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_current_user_hides_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Admin User', 'email' => 'admin@example.test',
            'password' => 'admin-password', 'role' => 'admin', 'is_active' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'admin-password',
        ])->assertOk()->assertJsonPath('data.role', 'admin')->assertJsonMissingPath('data.password');

        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.email', $user->email)->assertJsonMissingPath('data.password');
    }

    public function test_cashier_can_login(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'password' => 'cashier-password', 'is_active' => true]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'cashier-password'])
            ->assertOk()->assertJsonPath('data.role', 'cashier');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-password', 'role' => 'cashier', 'is_active' => true]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
    }

    public function test_inactive_account_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'password', 'role' => 'cashier', 'is_active' => false]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $this->actingAs($user)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_unauthenticated_cashier_route_is_rejected(): void
    {
        $this->getJson('/api/cashier/orders')->assertUnauthorized();
    }

    public function test_cashier_can_use_cashier_routes_but_not_admin_routes(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $this->actingAs($user)->getJson('/api/cashier/orders')->assertOk();
        $this->actingAs($user)->getJson('/api/kitchen-tickets')->assertOk();
        $this->actingAs($user)->getJson('/api/dashboard')->assertForbidden();
        $this->actingAs($user)->getJson('/api/inventories')->assertForbidden();
        $this->actingAs($user)->getJson('/api/reports/sales')->assertForbidden();
        $this->actingAs($user)->postJson('/api/menu-items', [])->assertForbidden();
    }

    public function test_admin_can_use_admin_routes_but_not_cashier_mutations(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
        $this->actingAs($user)->getJson('/api/restaurant-tables')->assertOk();
        $this->actingAs($user)->getJson('/api/inventories')->assertOk();
        $this->actingAs($user)->getJson('/api/reports/sales')->assertOk();
        $this->actingAs($user)->getJson('/api/cashier/orders')->assertForbidden();
        $this->actingAs($user)->getJson('/api/kitchen-tickets')->assertForbidden();
    }

    public function test_customer_routes_remain_public(): void
    {
        $this->getJson('/api/categories')->assertOk();
        $this->getJson('/api/menu-items')->assertOk();
        $this->postJson('/api/customer/chatbot', ['question' => 'What is available?'])->assertStatus(503);
    }

    public function test_role_cannot_be_spoofed_in_login_request(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'cashier');
    }
}
