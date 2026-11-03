<?php

namespace App\Support\Departments;

use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Models\Voter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes to a department's activity log (organization_activity), never to
 * NACOS's audit log. Who acted is worked out as the Manage pages do: the
 * department's own signed-in account, else a NACOS admin signed in to the
 * NACOS app, else the system (the scheduler closing voting). Pass
 * `voter: false` for nobody (a failed sign-in to an unknown account).
 *
 * Keep `meta` free of secrets (passwords, tokens) and of anyone's choices
 * on a ballot.
 */
class Activity
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(Organization|int $organization, string $action, ?Model $subject = null, array $meta = [], Voter|false|null $voter = null): OrganizationActivity
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $user = null;

        if ($voter === null) {
            $signedIn = auth('voter')->user();
            $voter = $signedIn instanceof Voter && $signedIn->organization_id === $organizationId ? $signedIn : null;
            $web = $voter === null ? auth('web')->user() : null;
            $user = $web instanceof User ? $web : null;
        }

        $voter = $voter instanceof Voter ? $voter : null;

        return OrganizationActivity::create([
            'organization_id' => $organizationId,
            'voter_id' => $voter?->id,
            'user_id' => $user?->id,
            'actor_type' => $voter !== null ? 'voter' : ($user !== null ? 'nacos' : null),
            'actor_name' => $voter->fullname ?? $user?->fullname,
            'actor_login' => $voter !== null ? ($voter->matric_number ?? $voter->email) : $user?->matric_number,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'meta' => $meta ?: null,
        ]);
    }
}
