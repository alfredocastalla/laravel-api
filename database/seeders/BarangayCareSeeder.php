<?php

namespace Database\Seeders;

use App\Services\BarangayCareStore;
use App\Support\AccountRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BarangayCareSeeder extends Seeder
{
    public function run(): void
    {
        $store = app(BarangayCareStore::class);
        if (app()->environment('testing')
            && ! DB::table('beneficiaries')->exists()
            && ! DB::table('users')->exists()) {
            $payload = Schema::hasTable('app_state')
                ? DB::table('app_state')->where('id', 1)->value('payload')
                : null;
            $legacyState = $payload ? json_decode($payload, true) : null;

            $state = is_array($legacyState) ? $legacyState : $store->defaultState();
            if (app()->environment('testing')) {
                $state['users'] = [
                    ['name' => 'Admin', 'role' => 'Admin', 'username' => 'admin', 'password' => 'password', 'status' => 'Active', 'email' => ''],
                ];
            }

            $store->writeState($state);
        }

        if (app()->environment('testing')) {
            $this->provisionTestAccount('admin', 'admin', 'Admin');
            $this->provisionTestAccount('barangay', 'barangay', 'Barangay Staff');
        }

        $this->deactivateSharedDemoResidentLogin();
    }

    private function provisionTestAccount(string $role, string $username, string $name): void
    {
        $hasRoleAccount = DB::table('users')->get(['role'])->contains(
            fn (object $user): bool => AccountRole::normalize((string) $user->role) === $role
        );
        if ($hasRoleAccount) {
            return;
        }

        if (DB::table('users')->whereRaw('LOWER(username) = ?', [$username])->exists()) {
            throw new RuntimeException("Cannot provision the {$role} account because the username \"{$username}\" is already assigned to another role.");
        }

        DB::table('users')->insert([
            'name' => $name,
            'role' => AccountRole::storedName($role),
            'beneficiary_name' => '',
            'username' => $username,
            'password' => Hash::make('password'),
            'status' => 'Active',
            'contact' => '',
            'email' => '',
            'position' => '',
            'address' => '',
            'profile_image' => null,
            'emergency_contact' => '',
        ]);
    }

    private function deactivateSharedDemoResidentLogin(): void
    {
        $account = DB::table('users')
            ->whereRaw('LOWER(username) = ?', ['user'])
            ->where('name', 'Resident Account')
            ->where('beneficiary_name', 'Maria Santos')
            ->where('email', '')
            ->first(['id', 'role', 'password', 'status']);

        if (! $account || AccountRole::normalize((string) $account->role) !== 'user' || $account->status !== 'Active') {
            return;
        }

        $password = (string) $account->password;
        $isSharedDemoPassword = password_get_info($password)['algo'] !== null
            ? Hash::check('password', $password)
            : hash_equals('password', $password);

        if ($isSharedDemoPassword) {
            DB::table('users')->where('id', $account->id)->update(['status' => 'Inactive']);
        }
    }
}
