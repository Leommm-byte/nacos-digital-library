<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Baseline security headers for every response, including a strict
 * Content-Security-Policy. Every asset is self-hosted, so only this origin is
 * allowed; the few inline scripts and styles carry the per-request nonce
 * (`Vite::cspNonce()` in views).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generated before the view renders so templates and Vite's tags can
        // use it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $this->isDebugExceptionPage($response)) {
            $headers->set('Content-Security-Policy', $this->policy($nonce, $request->isSecure()));
        }

        return $response;
    }

    public function policy(string $nonce, bool $secure = false): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];

        // `npm run dev`: assets come from the Vite dev server, which injects
        // <style> tags without a nonce. Browsers ignore 'unsafe-inline' when
        // a nonce is present, so the style nonce is dropped in that mode.
        if ($hot = $this->viteDevServer()) {
            $script[] = $hot;
            $style = ["'self'", "'unsafe-inline'", $hot];
            $connect[] = $hot;
            $connect[] = Str::replaceStart('http', 'ws', $hot);
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $script,
            'style-src' => $style,
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'"],
            'connect-src' => $connect,
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'media-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        $policy = collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', $sources))
            ->implode('; ');

        return $secure ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    /**
     * Laravel's debug exception page relies on inline scripts and styles.
     * It is only shown with APP_DEBUG on, so it is left without a policy.
     * HTTP errors (404, 403…) still use the app's own error pages.
     */
    private function isDebugExceptionPage(Response $response): bool
    {
        return config('app.debug')
            && $response instanceof IlluminateResponse
            && $response->exception !== null
            && ! $response->exception instanceof HttpExceptionInterface;
    }

    private function viteDevServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $url = trim(File::get(Vite::hotFile()));

        return rtrim($url, '/');
    }
}
