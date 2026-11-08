<?php

namespace App\Support;

/**
 * Address of a stylesheet, as the page should link it.
 *
 * `php artisan styles:minify` writes name.min.css beside name.css, with the fingerprint of the source on its first line.
 * The smaller copy is used only while that fingerprint still matches the source, so a stylesheet edited without
 * re-minifying is served as it is (larger, never out of date).
 */
class StyleAsset
{
    public const HEADER = '/*src:';

    /** @var array<string, string> */
    private static array $cache = [];

    public static function url(string $path): string
    {
        return self::$cache[$path] ??= asset(self::choose($path)).'?v='.filemtime(public_path(self::choose($path)));
    }

    public static function choose(string $path): string
    {
        $min = preg_replace('/\.css$/', '.min.css', $path);

        if ($min !== $path && is_file(public_path($min)) && self::fingerprintOf($min) === sha1_file(public_path($path))) {
            return $min;
        }

        return $path;
    }

    public static function fingerprintOf(string $minPath): ?string
    {
        $handle = @fopen(public_path($minPath), 'rb');
        if (! $handle) {
            return null;
        }
        $first = (string) fgets($handle, 120);
        fclose($handle);

        return preg_match('#^/\*src:([0-9a-f]{40})\*/#', $first, $m) ? $m[1] : null;
    }
}
