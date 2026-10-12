<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emails the admin (ALERT_EMAIL) when something breaks on the live site:
 * an error, a failed backup, the disk filling up.
 *
 * Sent at once, not through the queue, and throttled with small files
 * rather than the database: the database may be what failed. The same
 * error is emailed at most once an hour, with how many times it happened
 * since; and no more than 10 alerts go out in an hour, so one bug on a busy
 * page can't fill an inbox (or use up the host's sending limit).
 */
class Alerts
{
    public static function enabled(): bool
    {
        $to = config('operations.alert_email');

        return is_string($to) && $to !== '' && app()->isProduction();
    }

    /**
     * An error from anywhere in the app (bootstrap/app.php). Never throws.
     */
    public static function error(Throwable $error): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $key = sha1($error::class.'|'.$error->getFile().'|'.$error->getLine());
            $repeats = self::throttle($key, (int) config('operations.alerts.same_error_minutes'));

            if ($repeats === null) {
                return;
            }

            $where = app()->runningInConsole()
                ? 'Command: '.implode(' ', array_slice((array) ($_SERVER['argv'] ?? []), 0, 4))
                : request()->method().' '.self::safePath(request()->path());

            self::send(
                class_basename($error).': '.Str::limit($error->getMessage(), 90),
                implode("\n", array_filter([
                    $error::class,
                    $error->getMessage(),
                    '',
                    'Where: '.$where,
                    'File: '.str_replace(base_path().'/', '', $error->getFile()).':'.$error->getLine(),
                    'User: '.(auth()->id() ?? 'signed out'),
                    'Release: '.self::release(),
                    'Time: '.now()->timezone((string) config('app.display_timezone'))->format('j M Y, g:i:s a'),
                    $repeats > 0 ? "It also happened {$repeats} more times since the last email." : null,
                    '',
                    'Trace:',
                    collect(explode("\n", $error->getTraceAsString()))->take(15)->map(fn (string $line) => str_replace(base_path().'/', '', $line))->implode("\n"),
                ], fn (?string $line) => $line !== null)),
            );
        } catch (Throwable) {
            // An alert must never cause another error.
        }
    }

    /**
     * Any other alert (backups, disk space), throttled by its subject.
     */
    public static function notice(string $subject, string $body, int $everyMinutes = 720): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            if (self::throttle(sha1($subject), $everyMinutes) !== null) {
                self::send($subject, $body);
            }
        } catch (Throwable) {
            // Logged by the caller; never throw from here.
        }
    }

    private static function send(string $subject, string $body): void
    {
        Mail::raw($body."\n\n— ".config('app.url'), function ($message) use ($subject) {
            $message->to((string) config('operations.alert_email'))
                ->subject('['.config('app.name').'] '.$subject);
        });
    }

    /**
     * Null when this alert was sent too recently (counting it); otherwise
     * how many times it was held back since the last one.
     */
    private static function throttle(string $key, int $minutes): ?int
    {
        $directory = storage_path('framework/alerts');
        File::ensureDirectoryExists($directory);

        // The hourly cap across all alerts.
        $hour = $directory.'/hour-'.now()->format('YmdH');
        $sentThisHour = (int) @file_get_contents($hour);
        if ($sentThisHour >= (int) config('operations.alerts.per_hour')) {
            return null;
        }

        $file = $directory.'/'.$key;
        $last = File::exists($file) ? (int) File::lastModified($file) : 0;

        if ($last > now()->subMinutes($minutes)->getTimestamp()) {
            File::put($file, (string) ((int) File::get($file) + 1));
            touch($file, $last);

            return null;
        }

        $repeats = File::exists($file) ? (int) File::get($file) : 0;
        File::put($file, '0');
        File::put($hour, (string) ($sentThisHour + 1));

        // Old counters go.
        foreach (File::glob($directory.'/hour-*') as $old) {
            if ($old !== $hour) {
                File::delete($old);
            }
        }

        return $repeats;
    }

    /**
     * The path with long, link-like parts (invitation and reset tokens)
     * hidden.
     */
    private static function safePath(string $path): string
    {
        return '/'.ltrim((string) preg_replace('#[A-Za-z0-9_\-]{24,}#', '…', $path), '/');
    }

    /**
     * The deployed commit (written by the deploy), or "unknown".
     */
    public static function release(): string
    {
        $file = base_path('REVISION');

        return File::exists($file) ? trim((string) File::get($file)) : 'unknown';
    }
}
