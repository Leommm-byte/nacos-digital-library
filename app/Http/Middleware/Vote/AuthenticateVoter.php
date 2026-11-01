<?php

namespace App\Http\Middleware\Vote;

use App\Models\Voter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages for people signed in to the department's space. A temporary
 * password (a slip from an admin) must be changed before anything else.
 */
class AuthenticateVoter
{
    public function handle(Request $request, Closure $next): Response
    {
        $voter = $request->user('voter');

        if (! $voter instanceof Voter) {
            return redirect()->guest(route('vote.login'));
        }

        if ($voter->must_change_password && ! $request->routeIs('vote.password.*', 'vote.logout')) {
            return redirect()->route('vote.password.edit');
        }

        return $next($request);
    }
}
