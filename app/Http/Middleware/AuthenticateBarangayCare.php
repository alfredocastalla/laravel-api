<?php

namespace App\Http\Middleware;

use App\Services\BarangayCareAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBarangayCare
{
    public function __construct(private readonly BarangayCareAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->authenticate($request);
        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'Please sign in again.'], 401);
        }

        $request->attributes->set('barangayUser', $user);

        if ((bool) $user->must_change_password && ! $request->routeIs('api.password.change', 'api.logout')) {
            return response()->json([
                'ok' => false,
                'error' => 'Change your temporary password before continuing.',
                'password_change_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
