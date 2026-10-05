<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard by minimum role, e.g. `->middleware('role:governor')`.
 * Higher roles pass too (an admin can do anything a governor can).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasRole(Role::from($role)), 403);

        return $next($request);
    }
}
