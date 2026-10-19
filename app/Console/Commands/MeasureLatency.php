<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * How long the things every page waits on take on this server: connecting
 * to the database, one query, the cache and the disk. A page runs 10 to 30
 * queries, so a slow database round trip shows up on every click.
 */
class MeasureLatency extends Command
{
    protected $signature = 'ops:latency {--rounds=20 : How many times each step is repeated}';

    protected $description = 'Measure database, cache and disk round trips on this server';

    public function handle(): int
    {
        $rounds = max(1, (int) $this->option('rounds'));

        // A second connection with the same settings, so the app's own stays.
        config(['database.connections.latency' => config('database.connections.'.DB::getDefaultConnection())]);
        $connect = $this->time(1, function (): void {
            DB::purge('latency');
            DB::connection('latency')->getPdo();
        });
        DB::purge('latency');
        $query = $this->time($rounds, fn () => DB::select('select 1'));

        $cache = [];
        foreach (array_unique([(string) config('cache.default'), 'database', 'file']) as $store) {
            $cache[$store] = $this->cache($store, $rounds);
        }

        $file = storage_path('framework/latency-'.Str::random(8));
        $disk = $this->time($rounds, function () use ($file): void {
            File::put($file, str_repeat('x', 4096));
            File::get($file);
        });
        File::delete($file);

        $rows = [
            ['Connect to the database ('.config('database.connections.'.DB::getDefaultConnection().'.host', 'local').')', $connect],
            ['One database query', $query],
        ];
        foreach ($cache as $store => $ms) {
            $rows[] = ["Cache write + read, {$store} store".($store === config('cache.default') ? ' (in use)' : ''), $ms];
        }
        $rows[] = ['Disk write + read (4 KB)', $disk];
        $this->table(['Step', 'Each (ms)'], $rows);

        $page = $query * 25;
        $this->line(sprintf('A page with 25 queries spends about %d ms waiting on the database.', $page));
        if ($query >= 5) {
            $this->warn('Each query takes over 5 ms: the database server is far from this one. See docs/deployment.md, "The site feels slow".');
        }

        return self::SUCCESS;
    }

    private function cache(string $store, int $rounds): float
    {
        $cache = Cache::store($store);
        $key = 'ops:latency:'.Str::random(8);
        $ms = $this->time($rounds, function () use ($cache, $key): void {
            $cache->put($key, 'x', 60);
            $cache->get($key);
        });
        $cache->forget($key);

        return $ms;
    }

    /**
     * Average milliseconds per run.
     */
    private function time(int $rounds, callable $step): float
    {
        $step(); // warm up: the first run includes connecting
        $start = hrtime(true);
        for ($i = 0; $i < $rounds; $i++) {
            $step();
        }

        return round((hrtime(true) - $start) / 1e6 / $rounds, 2);
    }
}
