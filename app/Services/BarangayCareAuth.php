<?php

namespace App\Services;

use App\Support\AccountRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BarangayCareAuth
{
    public function issue(object $user): string
    {
        $token = Str::random(64);

        DB::table('auth_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return $token;
    }

    public function authenticate(Request $request): ?object
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $row = DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || ($row->expires_at && now()->greaterThan($row->expires_at))) {
            return null;
        }

        $user = DB::table('users')->where('id', $row->user_id)->first();
        if (! $user || $user->status !== 'Active') {
            return null;
        }

        $normalized = AccountRole::normalize((string) $user->role);
        if ($normalized === null) {
            return null;
        }

        $user->normalized_role = $normalized;

        return $user;
    }

    public function revoke(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token) {
            DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->delete();
        }
    }
}
