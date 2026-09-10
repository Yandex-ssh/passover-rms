<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffProvisioningCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_can_be_provisioned_interactively(): void
    {
        $this->artisan('staff:create-admin', [
            '--name' => 'First Admin',
            '--email' => 'admin@example.test',
        ])
            ->expectsQuestion('Password (minimum 8 characters)', 'secure-admin-password')
            ->assertExitCode(0);

        $user = User::firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secure-admin-password', $user->password));
        $this->assertNotSame('secure-admin-password', $user->password);
    }

    public function test_provisioning_rejects_duplicate_email_without_creating_another_user(): void
    {
        User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin', 'is_active' => true]);

        $this->artisan('staff:create-admin', [
            '--name' => 'Duplicate Admin',
            '--email' => 'admin@example.test',
        ])
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
    }
}
