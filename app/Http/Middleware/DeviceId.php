<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives each browser an anonymous id in a long-lived cookie, written to the
 * audit log with sign-ups, sign-ins and votes, so one phone acting for many
 * accounts can be seen (Admin → Shared devices). It says nothing about who
 * the person is, and clearing cookies or a private window gets a new one:
 * it finds the careless, not the determined.
 */
class DeviceId
{
    public const COOKIE = 'nacos_device';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::from($request) === null) {
            $id = bin2hex(random_bytes(16));
            // Read by the audit log during this same request.
            $request->cookies->set(self::COOKIE, $id);
            Cookie::queue(Cookie::make(self::COOKIE, $id, 60 * 24 * 730, httpOnly: true, sameSite: 'lax'));
        }

        return $next($request);
    }

    public static function from(Request $request): ?string
    {
        $id = $request->cookies->get(self::COOKIE);

        return is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $id : null;
    }
}
