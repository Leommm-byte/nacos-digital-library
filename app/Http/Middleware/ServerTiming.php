<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Says where each request's time went, so slowness on the live site can be
 * measured rather than guessed: a `Server-Timing` header (browser devtools,
 * Network tab, Timing) and a log line for slow requests.
 *
 *   boot  starting PHP and Laravel, before this middleware ran
 *   db    time waiting on the database, and how many queries
 *   app   everything from the start of the request to the response
 */
class ServerTiming
{
    private int $queries = 0;

    private float $databaseMs = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
        $bootMs = (microtime(true) - $start) * 1000;

        DB::listen(function (QueryExecuted $query): void {
            $this->queries++;
            $this->databaseMs += $query->time;
        });

        $response = $next($request);

        $totalMs = (microtime(true) - $start) * 1000;

        $response->headers->set('Server-Timing', sprintf(
            'boot;dur=%.1f, db;dur=%.1f;desc="%d queries", app;dur=%.1f',
            $bootMs, $this->databaseMs, $this->queries, $totalMs,
        ));

        $slowMs = (int) config('operations.slow_request_ms');
        if ($slowMs > 0 && $totalMs >= $slowMs) {
            Log::warning('Slow request', [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'ms' => (int) round($totalMs),
                'boot_ms' => (int) round($bootMs),
                'db_ms' => (int) round($this->databaseMs),
                'queries' => $this->queries,
            ]);
        }

        return $response;
    }
}
