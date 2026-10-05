<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Loads icons from `resources/icons`, a curated subset of Lucide. Rendered by
 * the `<x-icon>` component as inline SVG: no extra request, and the icon
 * takes the text colour.
 *
 * To add an icon, copy its file from `node_modules/lucide-static/icons`.
 */
class Icons
{
    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * The markup inside the icon's <svg> element.
     */
    public static function body(string $name): string
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $path = resource_path("icons/{$name}.svg");

        if (! preg_match('/^[a-z0-9-]+$/', $name) || ! is_file($path)) {
            throw new InvalidArgumentException("Unknown icon [{$name}].");
        }

        $svg = (string) file_get_contents($path);
        $body = preg_match('#<svg[^>]*>(.*)</svg>#s', $svg, $match) ? $match[1] : '';

        return self::$cache[$name] = trim((string) preg_replace('/\s+/', ' ', $body));
    }
}
