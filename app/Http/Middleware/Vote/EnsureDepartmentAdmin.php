<?php

namespace App\Http\Middleware\Vote;

use App\Models\Voter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The department's Manage pages (roll, voters, elections): for its own
 * admins, and for NACOS admins, who set departments up and can do the
 * work for them (signed in to the NACOS app, not to the space).
 */
class EnsureDepartmentAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $voter = $request->user('voter');

        if ($voter instanceof Voter) {
            if ($voter->must_change_password) {
                return redirect()->route('vote.password.edit');
            }
            if ($voter->isAdmin()) {
                return $next($request);
            }
        }

        if (self::nacosAdmin()) {
            return $next($request);
        }

        abort_if($voter instanceof Voter, 403);

        return redirect()->guest(route('vote.login'));
    }

    /** A NACOS admin, signed in to the NACOS app, managing this space. */
    public static function nacosAdmin(): bool
    {
        return Gate::forUser(auth('web')->user())->allows('manage-departments');
    }
}
