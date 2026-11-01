<?php

namespace App\Http\Middleware\Vote;

use App\Models\Organization;
use App\Models\Voter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page of a department's election space (/vote/{slug}): its
 * accounts are the "voter" guard's, route() fills in the department, and
 * the views get $organization. Someone signed in to another department's
 * space is signed out of it here; a paused department shows a notice.
 */
class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = Organization::fromRoute($request->route('organization'));
        abort_unless($organization instanceof Organization, 404);

        // Handed to controllers by type (Organization $organization), not
        // as a route parameter: the setup controllers shared with NACOS
        // (ElectionDesk) would otherwise get it as their first argument.
        app()->instance(Organization::class, $organization);
        $request->route()?->forgetParameter('organization');

        URL::defaults(['organization' => $organization->slug]);
        View::share('organization', $organization);
        Auth::shouldUse('voter');

        $voter = Auth::guard('voter')->user();
        if ($voter instanceof Voter && ($voter->organization_id !== $organization->id || $voter->isSuspended())) {
            Auth::guard('voter')->logout();
        }

        if (! $organization->isActive()) {
            return response()->view('vote.paused', [], 503);
        }

        return $next($request);
    }
}
