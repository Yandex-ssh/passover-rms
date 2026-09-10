<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'admin', 'is_active' => true], $attributes));
    }

    private function cashier(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'cashier', 'is_active' => true], $attributes));
    }

    public function test_guest_cannot_manage_staff(): void
    {
        $target = $this->cashier();

        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->postJson('/api/admin/users', [])->assertUnauthorized();
        $this->patchJson("/api/admin/users/{$target->id}", [])->assertUnauthorized();
        $this->postJson("/api/admin/users/{$target->id}/activate")->assertUnauthorized();
        $this->postJson("/api/admin/users/{$target->id}/deactivate")->assertUnauthorized();
        $this->postJson("/api/admin/users/{$target->id}/password", [])->assertUnauthorized();
        $this->deleteJson("/api/admin/users/{$target->id}")->assertUnauthorized();
    }

    public function test_cashier_cannot_manage_staff(): void
    {
        $cashier = $this->cashier();
        $target = $this->cashier();
        $payload = ['name' => 'Changed', 'email' => 'changed@example.test', 'role' => 'admin'];

        $this->actingAs($cashier)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($cashier)->postJson('/api/admin/users', [])->assertForbidden();
        $this->actingAs($cashier)->patchJson("/api/admin/users/{$target->id}", $payload)->assertForbidden();
        $this->actingAs($cashier)->postJson("/api/admin/users/{$target->id}/activate")->assertForbidden();
        $this->actingAs($cashier)->postJson("/api/admin/users/{$target->id}/deactivate")->assertForbidden();
        $this->actingAs($cashier)->postJson("/api/admin/users/{$target->id}/password", [])->assertForbidden();
        $this->actingAs($cashier)->deleteJson("/api/admin/users/{$target->id}")->assertForbidden();
    }

    public function test_admin_can_list_staff_without_sensitive_fields(): void
    {
        $admin = $this->admin();
        $cashier = $this->cashier();

        $response = $this->actingAs($admin)->getJson('/api/admin/users');
        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at']]])
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.email_verified_at');

        $this->assertStringNotContainsString($admin->password, $response->getContent());
        $this->assertStringNotContainsString($cashier->password, $response->getContent());
    }

    public function test_admin_can_create_cashier_with_hashed_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'New Cashier',
            'email' => 'new.cashier@example.test',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
            'role' => 'cashier',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'cashier')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $created = User::where('email', 'new.cashier@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('new-secret-password', $created->password));
        $this->assertNotSame('new-secret-password', $created->password);

        $this->postJson('/api/login', ['email' => $created->email, 'password' => 'new-secret-password'])->assertOk();
    }

    public function test_admin_can_create_another_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Second Admin',
            'email' => 'second.admin@example.test',
            'password' => 'second-admin-password',
            'password_confirmation' => 'second-admin-password',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.role', 'admin');
    }

    public function test_invalid_role_and_duplicate_email_are_rejected(): void
    {
        $admin = $this->admin();
        $existing = $this->cashier(['email' => 'existing@example.test']);
        $payload = ['name' => 'User', 'email' => 'new@example.test', 'password' => 'valid-password', 'password_confirmation' => 'valid-password'];

        $this->actingAs($admin)->postJson('/api/admin/users', array_merge($payload, ['role' => 'superadmin']))->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/admin/users', array_merge($payload, ['email' => $existing->email, 'role' => 'cashier']))->assertUnprocessable();
    }

    public function test_admin_can_update_safe_fields_without_clearing_password(): void
    {
        $admin = $this->admin();
        $target = $this->cashier(['password' => 'original-password']);
        $hash = $target->fresh()->password;

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", [
            'name' => 'Updated Cashier',
            'email' => 'updated@example.test',
            'role' => 'cashier',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Cashier');

        $target->refresh();
        $this->assertSame($hash, $target->password);
        $this->assertSame('updated@example.test', $target->email);
    }

    public function test_admin_can_change_password_without_returning_it(): void
    {
        $admin = $this->admin();
        $target = $this->cashier(['password' => 'old-password']);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/password", [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonMissingPath('data.password');

        $target->refresh();
        $this->assertFalse(Hash::check('old-password', $target->password));
        $this->assertTrue(Hash::check('new-password', $target->password));
    }

    public function test_admin_can_delete_another_staff_account(): void
    {
        $admin = $this->admin();
        $target = $this->cashier();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Staff account deleted successfully.');

        $this->assertModelMissing($target);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertModelExists($admin);
    }

    public function test_admin_can_deactivate_and_reactivate_cashier(): void
    {
        $admin = $this->admin();
        $target = $this->cashier(['email' => 'managed@example.test', 'password' => 'managed-password']);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/deactivate")
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'managed-password'])->assertUnauthorized();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'managed-password'])->assertOk();
    }

    public function test_deactivated_existing_session_is_rejected_and_api_user_is_protected(): void
    {
        $target = $this->cashier();
        $this->actingAs($target)->getJson('/api/cashier/orders')->assertOk();

        $target->update(['is_active' => false]);

        $this->getJson('/api/cashier/orders')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/categories')->assertOk();
    }

    public function test_admin_cannot_deactivate_or_demote_the_last_active_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/deactivate")
            ->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", ['role' => 'cashier'])
            ->assertUnprocessable();

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertSame('admin', $admin->role);
    }

    public function test_admin_cannot_self_deactivate_or_self_demote_when_another_admin_exists(): void
    {
        $admin = $this->admin();
        $other = $this->admin(['email' => 'other-admin@example.test']);

        $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/deactivate")->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", ['role' => 'cashier'])->assertUnprocessable();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_public_staff_registration_does_not_exist(): void
    {
        $this->postJson('/api/register', [])->assertNotFound();
    }
}
