<?php

namespace App\Http\Middleware\Vote;

use App\Models\Voter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The department's admin pages: its roll, voters and elections.
 */
class EnsureDepartmentAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $voter = $request->user('voter');
        abort_unless($voter instanceof Voter && $voter->isAdmin(), 403);

        return $next($request);
    }
}
