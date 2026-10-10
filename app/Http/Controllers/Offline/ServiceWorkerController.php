<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Serves the service worker (resources/sw/sw.js) with the current build's
 * asset lists and a version that changes with every deploy, so phones
 * pick up new CSS and JS and drop the old ones.
 */
class ServiceWorkerController extends Controller
{
    /** Built files every visitor keeps: the app's look and its fonts. */
    private const CORE_ENTRIES = ['resources/css/app.css', 'resources/js/app.js'];

    /** Built files kept only once a book is saved for offline reading. */
    private const READER_ENTRIES = ['resources/js/reader.js'];

    public function __invoke(): Response
    {
        $manifest = $this->manifest();
        $template = File::get(resource_path('sw/sw.js'));

        $core = [
            '/offline',
            '/offline/read',
            '/manifest.webmanifest',
            '/icons/icon-192.png',
            '/images/logo-96.webp',
            '/images/logo-192.webp',
            ...$this->files($manifest, self::CORE_ENTRIES),
            // Fonts are referenced from the CSS, not imported by JS.
            ...collect($manifest)
                ->filter(fn (array $chunk) => str_ends_with($chunk['file'], '.woff2'))
                ->map(fn (array $chunk) => '/build/'.$chunk['file'])
                ->values()
                ->all(),
        ];

        $version = substr(hash('sha256', implode("\n", [
            $template,
            json_encode($manifest),
            File::get(resource_path('views/offline/index.blade.php')),
            File::get(resource_path('views/offline/reader.blade.php')),
        ])), 0, 12);

        $script = strtr($template, [
            "'__VERSION__'" => json_encode($version),
            '__CORE__' => json_encode(array_values(array_unique($core)), JSON_UNESCAPED_SLASHES),
            '__READER__' => json_encode($this->files($manifest, self::READER_ENTRIES), JSON_UNESCAPED_SLASHES),
        ]);

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Browsers check for a new worker on every visit; never cache it.
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * @return array<string, array{file: string, imports?: list<string>, css?: list<string>, assets?: list<string>}>
     */
    private function manifest(): array
    {
        $path = public_path('build/manifest.json');

        if (! File::exists($path)) {
            return [];
        }

        /** @var array<string, array{file: string, imports?: list<string>, css?: list<string>, assets?: list<string>}> $manifest */
        $manifest = json_decode(File::get($path), true) ?: [];

        return $manifest;
    }

    /**
     * The built files of some entries and everything they import directly.
     *
     * @param  array<string, array{file: string, imports?: list<string>, css?: list<string>, assets?: list<string>}>  $manifest
     * @param  list<string>  $entries
     * @return list<string>
     */
    private function files(array $manifest, array $entries): array
    {
        $files = [];
        $queue = $entries;
        $seen = [];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key]) || ! isset($manifest[$key])) {
                continue;
            }

            $seen[$key] = true;
            $chunk = $manifest[$key];
            $files[] = '/build/'.$chunk['file'];

            foreach ([...($chunk['css'] ?? []), ...($chunk['assets'] ?? [])] as $file) {
                $files[] = '/build/'.$file;
            }

            foreach ($chunk['imports'] ?? [] as $import) {
                $queue[] = $import;
            }
        }

        return array_values(array_unique($files));
    }
}
