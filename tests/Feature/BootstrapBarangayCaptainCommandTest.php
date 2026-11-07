<?php

namespace Tests\Feature;

use Database\Seeders\BarangayCareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapBarangayCaptainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_the_initial_admin_with_a_private_password_prompt(): void
    {
        $this->artisan('barangaycare:bootstrap-admin')
            ->expectsQuestion('Name for the Admin', 'Taft Barangay Admin')
            ->expectsQuestion('Username for the Admin', 'taft_admin')
            ->expectsQuestion('New password for the Admin (at least 12 characters)', 'BarangayStrongPass2026')
            ->expectsQuestion('Type the new password again', 'BarangayStrongPass2026')
            ->expectsOutputToContain('Admin account [taft_admin] created.')
            ->assertExitCode(0);

        $admin = DB::table('users')->where('username', 'taft_admin')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Admin', $admin->role);
        $this->assertSame('Active', $admin->status);
        $this->assertTrue(Hash::check('BarangayStrongPass2026', $admin->password));
        $this->assertDatabaseHas('logs', [
            'actor' => 'System',
            'action' => 'Created the initial Admin account taft_admin from the server console',
        ]);
    }

    public function test_bootstrap_admin_rejects_a_username_already_used_by_another_role(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Captain',
            'role' => 'Barangay',
            'beneficiary_name' => '',
            'username' => 'taft_admin',
            'password' => Hash::make('ExistingCaptainPassword'),
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => 'Barangay Captain',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
        ]);

        $this->artisan('barangaycare:bootstrap-admin')
            ->expectsQuestion('Name for the Admin', 'Taft Barangay Admin')
            ->expectsQuestion('Username for the Admin', 'taft_admin')
            ->expectsOutput('Enter a unique username that is 3 to 80 characters and contains only letters, numbers, underscores, or hyphens. No changes were made.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('logs', 0);
    }

    public function test_bootstrap_admin_is_idempotent_when_an_admin_already_exists(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Admin',
            'role' => 'Admin',
            'beneficiary_name' => '',
            'username' => 'existing_admin',
            'password' => Hash::make('ExistingAdminPassword'),
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => 'Admin',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
        ]);

        $this->artisan('barangaycare:bootstrap-admin')
            ->expectsOutput('An Admin account already exists. No changes were made.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('logs', 0);
    }

    public function test_production_seeding_does_not_create_shared_privileged_accounts(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertSame('production', app()->environment());
        $this->assertDatabaseCount('users', 0);

        $this->app->make(BarangayCareSeeder::class)->run();

        $this->assertDatabaseMissing('users', ['username' => 'admin']);
        $this->assertDatabaseMissing('users', ['username' => 'barangay']);
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_creates_the_first_barangay_captain_with_a_private_password_prompt(): void
    {
        $this->artisan('barangaycare:bootstrap-captain')
            ->expectsQuestion('Name for the Barangay Captain', 'Taft Barangay Captain')
            ->expectsQuestion('Username for the Barangay Captain', 'taft_captain')
            ->expectsQuestion('New password for the Barangay Captain (at least 12 characters)', 'BarangayStrongPass2026')
            ->expectsQuestion('Type the new password again', 'BarangayStrongPass2026')
            ->expectsOutputToContain('Barangay Captain account [taft_captain] created.')
            ->assertExitCode(0);

        $captain = DB::table('users')->where('username', 'taft_captain')->first();

        $this->assertNotNull($captain);
        $this->assertSame('Barangay', $captain->role);
        $this->assertSame('Active', $captain->status);
        $this->assertTrue(Hash::check('BarangayStrongPass2026', $captain->password));
        $this->assertDatabaseHas('logs', [
            'actor' => 'System',
            'action' => 'Created the initial Barangay Captain account taft_captain from the server console',
        ]);
    }

    public function test_refuses_to_add_a_bootstrap_account_when_accounts_already_exist(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Administrator',
            'role' => 'Admin',
            'beneficiary_name' => '',
            'username' => 'existing_admin',
            'password' => Hash::make('ExistingAdminPassword'),
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => '',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
            'must_change_password' => false,
        ]);

        $this->artisan('barangaycare:bootstrap-captain')
            ->expectsOutput('This database already has user accounts. No changes were made. Use an existing account or follow the account recovery procedure.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('logs', [
            'action' => 'Created the initial Barangay Captain account taft_captain from the server console',
        ]);
    }

    public function test_does_not_create_an_account_when_password_confirmation_fails(): void
    {
        $this->artisan('barangaycare:bootstrap-captain')
            ->expectsQuestion('Name for the Barangay Captain', 'Taft Barangay Captain')
            ->expectsQuestion('Username for the Barangay Captain', 'taft_captain')
            ->expectsQuestion('New password for the Barangay Captain (at least 12 characters)', 'BarangayStrongPass2026')
            ->expectsQuestion('Type the new password again', 'DifferentStrongPass2026')
            ->expectsOutput('The passwords do not match. No changes were made.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('logs', 0);
    }
}
