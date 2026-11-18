<?php

namespace App\Support\Elections;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accounts that signed up, signed in or voted from the same device, from
 * the audit log (docs/vote-integrity.md).
 *
 * - "device": the anonymous id in each browser's cookie (DeviceId). Strong:
 *   one browser on one phone. Only from when it was added.
 * - "network": the same address and the same browser details. Weaker:
 *   campus Wi-Fi gives a whole class one address, and many phones send
 *   identical browser details, so a group can be classmates who share
 *   nothing else. It covers entries from before device ids existed.
 *
 * Leads for a person to check, never proof.
 */
class SharedDevices
{
    public const ACTIONS = ['user_registered', 'login', 'election_voted'];

    public const KINDS = ['device' => 'device_id', 'network' => 'network_key'];

    /**
     * Groups of accounts seen on the same device, most suspicious first:
     * the most accounts signed up there, then voted there, then seen.
     *
     * @return Collection<int, array{key: string, label: string, ip: string|null, agent: string, accounts: Collection<int, array{user: User, registered_at: mixed, voted_at: mixed, logins: int, first_seen: mixed, locked: bool}>, registered: int, locked: int, voted: int}>
     */
    public static function groups(string $kind, Carbon $from, Carbon $to, int $min = 2, int $limit = 40): Collection
    {
        $column = self::KINDS[$kind] ?? 'device_id';

        $keys = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull($column)
            ->whereNotNull('user_id')
            ->groupBy($column)
            ->havingRaw('count(distinct user_id) >= ?', [$min])
            ->orderByRaw("count(distinct case when action = 'user_registered' then user_id end) desc")
            ->orderByRaw("count(distinct case when action = 'election_voted' then user_id end) desc")
            ->orderByRaw('count(distinct user_id) desc')
            ->limit($limit)
            ->pluck($column)
            ->map(fn ($key) => (string) $key)
            ->all();

        if ($keys === []) {
            return collect();
        }

        $entries = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn($column, $keys)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'action', 'ip_address', 'user_agent', 'created_at', $column]);

        $users = User::query()
            ->whereIn('id', $entries->pluck('user_id')->unique())
            ->get(['id', 'fullname', 'matric_number', 'email', 'email_verified_at', 'level', 'programme', 'vote_held_at', 'created_at'])
            ->keyBy('id');

        $locked = self::lockedToClassListEmail($users->keys()->all());

        return collect($keys)->map(function (string $key) use ($entries, $users, $column, $locked) {
            $here = $entries->where($column, $key);

            $accounts = $here->groupBy('user_id')->map(function (Collection $events, $userId) use ($users, $locked) {
                $user = $users->get($userId);

                return [
                    'user' => $user,
                    'registered_at' => $events->firstWhere('action', 'user_registered')?->created_at,
                    'voted_at' => $events->where('action', 'election_voted')->min('created_at'),
                    'logins' => $events->where('action', 'login')->count(),
                    'first_seen' => $events->min('created_at'),
                    // Whoever signed up can't use it (SignupController::inviteFromRoll).
                    'locked' => isset($locked[(int) $userId]),
                ];
            })->filter(fn (array $account) => $account['user'] !== null)
                ->sortBy('first_seen')
                ->values();

            $sample = $here->first();
            $agent = (string) $sample?->user_agent;

            return [
                'key' => $key,
                'label' => $column === 'device_id'
                    ? 'Device '.substr($key, 0, 8)
                    : ($sample->ip_address ?? '?').' · '.self::browser($agent),
                'ip' => $sample?->ip_address,
                'agent' => $agent,
                'accounts' => $accounts,
                'registered' => $accounts->whereNotNull('registered_at')->count(),
                'locked' => $accounts->where('locked', true)->count(),
                'voted' => $accounts->whereNotNull('voted_at')->count(),
            ];
        })->values();
    }

    /**
     * Other accounts seen on a device this account used, by kind.
     *
     * @return array{device: Collection<int, User>, network: Collection<int, User>}
     */
    public static function sameAs(User $user): array
    {
        $result = [];

        foreach (self::KINDS as $kind => $column) {
            $keys = AuditLog::query()
                ->where('user_id', $user->id)
                ->whereIn('action', self::ACTIONS)
                ->whereNotNull($column)
                ->distinct()
                ->pluck($column);

            $ids = $keys->isEmpty() ? collect() : AuditLog::query()
                ->whereIn('action', self::ACTIONS)
                ->whereIn($column, $keys)
                ->whereNotNull('user_id')
                ->where('user_id', '!=', $user->id)
                ->select('user_id', DB::raw('max(created_at) as seen'))
                ->groupBy('user_id')
                ->orderByDesc('seen')
                ->limit(25)
                ->pluck('user_id');

            $result[$kind] = $ids->isEmpty() ? collect() : User::query()
                ->whereIn('id', $ids)
                ->orderBy('fullname')
                ->get(['id', 'fullname', 'matric_number', 'vote_held_at']);
        }

        return $result;
    }

    /**
     * Accounts signed up with a matric number whose class-list row has an
     * email: they were made with no usable password, and only the link sent
     * to that email sets one (SignupController::inviteFromRoll). Whoever
     * filled in the form can't sign in, so the account is no risk; holding
     * it would only make its owner queue on election day.
     *
     * @param  list<int|string>  $userIds
     * @return array<int, bool>
     */
    public static function lockedToClassListEmail(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return AuditLog::query()
            ->where('action', 'user_registered')
            ->where('meta->via', 'class_list_email')
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Whether an email address's domain can receive mail (it has mail or
     * address records in DNS): a made-up or mistyped domain can't. No email
     * is sent. Null when there's no address. Each domain is looked up once.
     */
    public static function domainReceivesMail(?string $email): ?bool
    {
        static $domains = [];

        $domain = $email !== null && str_contains($email, '@') ? strtolower(trim((string) substr($email, (int) strrpos($email, '@') + 1))) : '';

        if ($domain === '') {
            return null;
        }

        return $domains[$domain] ??= preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1
            && (checkdnsrr($domain.'.', 'MX') || checkdnsrr($domain.'.', 'A'));
    }

    /**
     * "Chrome 131 on Android" from a user agent, for people to read.
     */
    public static function browser(string $agent): string
    {
        $browser = match (true) {
            preg_match('#(?:OPR|Opera)/(\d+)#', $agent, $m) === 1 => 'Opera '.$m[1],
            preg_match('#Edg[A-Z]?/(\d+)#', $agent, $m) === 1 => 'Edge '.$m[1],
            preg_match('#SamsungBrowser/(\d+)#', $agent, $m) === 1 => 'Samsung Internet '.$m[1],
            preg_match('#(?:Chrome|CriOS)/(\d+)#', $agent, $m) === 1 => 'Chrome '.$m[1],
            preg_match('#(?:Firefox|FxiOS)/(\d+)#', $agent, $m) === 1 => 'Firefox '.$m[1],
            preg_match('#Version/(\d+).*Safari#', $agent, $m) === 1 => 'Safari '.$m[1],
            default => null,
        };

        if ($browser === null) {
            // A script or app, not a phone's browser: say what it called itself.
            return trim($agent) === '' ? 'No browser details (a script or tool, not a phone browser)' : 'Not a browser: '.Str::limit($agent, 60);
        }

        $system = match (true) {
            preg_match('#Android ([\d.]+)#', $agent, $m) === 1 => 'Android '.$m[1],
            preg_match('#(?:iPhone|iPad).*OS (\d+)#', $agent, $m) === 1 => 'iOS '.$m[1],
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown system',
        };

        return "{$browser} on {$system}";
    }
}
