<?php

namespace App\Http\Middleware\Vote;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in and registration are for people not signed in yet.
 */
class RedirectIfVoter
{
    public function handle(Request $request, Closure $next): Response
    {
        return $request->user('voter') !== null ? redirect()->route('vote.home') : $next($request);
    }
}
