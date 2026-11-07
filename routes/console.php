<?php

use App\Support\AccountRole;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('barangaycare:prepare-database', function () {
    $connection = config('database.connections.mysql');
    $database = $connection['database'] ?? '';

    if (config('database.default') !== 'mysql' || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        $this->error('The default database must be MySQL and DB_DATABASE must contain only letters, numbers, or underscores.');

        return 1;
    }

    config([
        'database.connections.barangaycare_server' => array_merge($connection, [
            'database' => null,
            'url' => null,
        ]),
    ]);
    DB::purge('barangaycare_server');

    DB::connection('barangaycare_server')->statement(
        'CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $this->info("Database [{$database}] is ready.");

    return 0;
})->purpose('Create the configured BarangayCare MySQL database when it does not exist');

Artisan::command('barangaycare:bootstrap-admin', function () {
    $hasAdmin = DB::table('users')->get(['role'])->contains(
        fn (object $user): bool => AccountRole::normalize((string) $user->role) === 'admin'
    );
    if ($hasAdmin) {
        $this->info('An Admin account already exists. No changes were made.');

        return 0;
    }

    $name = trim((string) $this->ask('Name for the Admin'));
    if ($name === '' || mb_strlen($name) > 150) {
        $this->error('Enter a name that is between 1 and 150 characters. No changes were made.');

        return 1;
    }

    $username = Str::lower(trim((string) $this->ask('Username for the Admin')));
    if (! preg_match('/\A[a-z0-9_-]{3,80}\z/', $username)
        || DB::table('users')->whereRaw('LOWER(username) = ?', [$username])->exists()) {
        $this->error('Enter a unique username that is 3 to 80 characters and contains only letters, numbers, underscores, or hyphens. No changes were made.');

        return 1;
    }

    $password = (string) $this->secret('New password for the Admin (at least 12 characters)');
    if (mb_strlen($password) < 12) {
        $this->error('The password must have at least 12 characters. No changes were made.');

        return 1;
    }

    if ($password !== (string) $this->secret('Type the new password again')) {
        $this->error('The passwords do not match. No changes were made.');

        return 1;
    }

    DB::transaction(function () use ($name, $password, $username): void {
        DB::table('users')->insert([
            'name' => $name,
            'role' => AccountRole::storedName('admin'),
            'beneficiary_name' => '',
            'username' => $username,
            'password' => Hash::make($password),
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => 'Admin',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
            'must_change_password' => false,
        ]);
        DB::table('logs')->insert([
            'event_time' => now()->format('Y-m-d H:i'),
            'actor' => 'System',
            'action' => "Created the initial Admin account {$username} from the server console",
        ]);
    });

    $this->info("Admin account [{$username}] created. Keep the password private and sign in over HTTPS.");

    return 0;
})->purpose('Create the initial Admin account with a privately entered password');

Artisan::command('barangaycare:bootstrap-captain', function () {
    if (DB::table('users')->exists()) {
        $this->error('This database already has user accounts. No changes were made. Use an existing account or follow the account recovery procedure.');

        return 1;
    }

    $name = trim((string) $this->ask('Name for the Barangay Captain'));
    if ($name === '' || mb_strlen($name) > 150) {
        $this->error('Enter a name that is between 1 and 150 characters. No changes were made.');

        return 1;
    }

    $username = Str::lower(trim((string) $this->ask('Username for the Barangay Captain')));
    if (! preg_match('/\A[a-z0-9_-]{3,80}\z/', $username)) {
        $this->error('The username must be 3 to 80 characters and contain only letters, numbers, underscores, or hyphens. No changes were made.');

        return 1;
    }

    $password = (string) $this->secret('New password for the Barangay Captain (at least 12 characters)');
    if (mb_strlen($password) < 12) {
        $this->error('The password must have at least 12 characters. No changes were made.');

        return 1;
    }

    if ($password !== (string) $this->secret('Type the new password again')) {
        $this->error('The passwords do not match. No changes were made.');

        return 1;
    }

    DB::transaction(function () use ($name, $password, $username): void {
        DB::table('users')->insert([
            'name' => $name,
            'role' => AccountRole::storedName('barangay'),
            'beneficiary_name' => '',
            'username' => $username,
            'password' => Hash::make($password),
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => 'Barangay Captain',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
            'must_change_password' => false,
        ]);
        DB::table('logs')->insert([
            'event_time' => now()->format('Y-m-d H:i'),
            'actor' => 'System',
            'action' => "Created the initial Barangay Captain account {$username} from the server console",
        ]);
    });

    $this->info("Barangay Captain account [{$username}] created. Keep the password private and sign in over HTTPS.");

    return 0;
})->purpose('Create the first secure Barangay Captain account in an empty database');

Artisan::command('barangaycare:reset-admin {username=fred}', function (string $username) {
    $admin = DB::table('users')->whereRaw('LOWER(username) = ?', [strtolower(trim($username))])->first();
    if (! $admin || AccountRole::normalize((string) $admin->role) !== 'admin') {
        $this->error("No Admin account uses the username [{$username}].");

        return 1;
    }

    $password = (string) $this->secret('New password for '.$admin->username.' (at least 8 characters)');
    if (strlen($password) < 8) {
        $this->error('The password must have at least 8 characters.');

        return 1;
    }
    if ($password !== (string) $this->secret('Type the new password again')) {
        $this->error('The two passwords do not match. Nothing was changed.');

        return 1;
    }

    DB::table('users')->where('id', $admin->id)->update([
        'password' => Hash::make($password),
        'status' => 'Active',
        'must_change_password' => false,
    ]);
    DB::table('auth_tokens')->where('user_id', $admin->id)->delete();
    DB::table('logs')->insert([
        'event_time' => now()->format('Y-m-d H:i'),
        'actor' => 'System',
        'action' => "Reset the Admin password for {$admin->username} from the server console",
    ]);

    $this->info("Password updated. Sign in as {$admin->username} to open Admin Overview.");

    return 0;
})->purpose('Reset the password of the built-in Admin account (default: fred)');
