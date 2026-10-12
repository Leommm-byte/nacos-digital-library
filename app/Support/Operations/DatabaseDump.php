<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Writes a gzipped copy of the database. MySQL through `mysqldump` (the
 * password goes in a private options file, never on the command line, where
 * other users of a shared server could see it); SQLite by copying the file.
 */
class DatabaseDump
{
    public function write(string $path): void
    {
        $connection = (string) config('database.default');
        /** @var array<string, mixed> $config */
        $config = (array) config("database.connections.{$connection}");

        $raw = $path.'.raw';

        try {
            match ($config['driver'] ?? null) {
                'mysql', 'mariadb' => $this->mysql($config, $raw),
                'sqlite' => File::copy((string) $config['database'], $raw),
                default => throw new RuntimeException('Backups support MySQL and SQLite only.'),
            };

            $this->gzip($raw, $path);
        } finally {
            File::delete($raw);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mysql(array $config, string $raw): void
    {
        $options = dirname($raw).'/.mysqldump-'.bin2hex(random_bytes(4)).'.cnf';
        $quote = fn (mixed $value) => '"'.addcslashes((string) $value, '"\\').'"';

        File::put($options, implode("\n", [
            '[client]',
            'user='.$quote($config['username'] ?? ''),
            'password='.$quote($config['password'] ?? ''),
            'host='.$quote($config['host'] ?? '127.0.0.1'),
            'port='.(int) ($config['port'] ?? 3306),
        ])."\n");
        chmod($options, 0600);

        try {
            $result = Process::timeout(1800)->run([
                (string) config('operations.backups.mysqldump', 'mysqldump'),
                '--defaults-extra-file='.$options,
                // A consistent copy without locking tables while students use the site.
                '--single-transaction',
                '--quick',
                '--no-tablespaces',
                '--routines',
                '--default-character-set=utf8mb4',
                '--result-file='.$raw,
                (string) $config['database'],
            ]);
        } finally {
            File::delete($options);
        }

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }
    }

    private function gzip(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb6');

        if ($in === false || $out === false) {
            throw new RuntimeException("Couldn't write the backup file {$to}.");
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);
    }
}
