<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_update_only_their_own_profile(): void
    {
        $cashier = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.test', 'role' => 'cashier', 'is_active' => true]);
        $other = User::factory()->create(['email' => 'other@example.test', 'role' => 'cashier']);

        $this->actingAs($cashier)->putJson('/api/profile', [
            'name' => 'New Name',
            'email' => 'new@example.test',
            'role' => 'admin',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.test')
            ->assertJsonPath('data.role', 'cashier')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'name' => 'New Name', 'email' => 'new@example.test', 'role' => 'cashier', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'email' => 'other@example.test']);
    }

    public function test_cashier_can_change_password_with_current_password_confirmation(): void
    {
        $cashier = User::factory()->create(['password' => 'old-password', 'role' => 'cashier', 'is_active' => true]);

        $this->actingAs($cashier)->postJson('/api/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonMissingPath('password');

        $this->assertTrue(Hash::check('new-password', $cashier->fresh()->password));
    }

    public function test_profile_password_change_rejects_wrong_current_password(): void
    {
        $cashier = User::factory()->create(['password' => 'old-password', 'role' => 'cashier', 'is_active' => true]);

        $this->actingAs($cashier)->postJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $cashier->fresh()->password));
    }

    public function test_guest_cannot_update_profile_credentials(): void
    {
        $this->putJson('/api/profile', [])->assertUnauthorized();
        $this->postJson('/api/profile/password', [])->assertUnauthorized();
    }
}
