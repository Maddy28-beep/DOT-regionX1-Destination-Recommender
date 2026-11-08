<?php

namespace App\Support;

/**
 * Makes the smaller "card" copy of a listing photograph.
 *
 * The listing grids show a photo about 300 pixels wide, but some originals are several megabytes. A card copy is a WebP no wider
 * than the card needs, stored next to the original as "<name>.card.webp". The original is never changed or replaced: the
 * detail pages and galleries keep using it, and a card falls back to it whenever no copy exists
 * (ListingPhoto::cardUrl()).
 *
 * Phone photographs often store their rotation as a flag instead of rotating the pixels. A browser obeys that flag on the
 * original, so the copy has to be turned the same way or it would show sideways. PHP's exif extension is not always installed,
 * so the flag is read here directly.
 */
final class PhotoCardCopy
{
    public const SUFFIX = '.card.webp';

    /** "listings/verified/destination-4.png" becomes "listings/verified/destination-4.card.webp". */
    public static function variantPath(string $path): string
    {
        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');
        $base = ($dot !== false && ($slash === false || $dot > $slash)) ? substr($path, 0, $dot) : $path;

        return $base.self::SUFFIX;
    }

    public static function isVariant(string $path): bool
    {
        return str_ends_with(strtolower($path), self::SUFFIX);
    }

    /** True when this server's PHP can read the picture formats we store and write WebP. */
    public static function supported(): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }

        $info = gd_info();

        return ! empty($info['WebP Support']) && (! empty($info['JPEG Support']) || ! empty($info['PNG Support']));
    }

    /**
     * The picture's pixel width and height as the browser shows it (after the rotation flag), or null if it cannot be read.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function displaySize(string $bytes): ?array
    {
        $size = @getimagesizefromstring($bytes);
        if (! $size) {
            return null;
        }

        [$w, $h] = $size;

        return in_array(self::jpegOrientation($bytes), [5, 6, 7, 8], true) ? [$h, $w] : [$w, $h];
    }

    /**
     * Encode a WebP copy no wider than $maxWidth. Returns the WebP bytes, or null when the picture cannot be read.
     * A picture already narrower than $maxWidth keeps its size and is only re-encoded; nothing is ever enlarged.
     */
    public static function encode(string $bytes, int $maxWidth = 800, int $quality = 80): ?string
    {
        if (! self::supported()) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }

        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $source = self::orient($source, self::jpegOrientation($bytes));

        $w = imagesx($source);
        $h = imagesy($source);
        $newW = min($w, max(1, $maxWidth));
        $newH = (int) max(1, round($h * ($newW / $w)));

        $copy = imagecreatetruecolor($newW, $newH);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopyresampled($copy, $source, 0, 0, 0, 0, $newW, $newH, $w, $h);

        ob_start();
        $ok = imagewebp($copy, null, max(1, min(100, $quality)));
        $out = ob_get_clean();

        return $ok && is_string($out) && $out !== '' ? $out : null;
    }

    /**
     * The EXIF orientation of a JPEG (1 to 8), or 1 when there is none or it cannot be read.
     * 1 = as stored, 3 = turned 180, 6 = turn 90 clockwise to display, 8 = turn 90 anticlockwise; 2, 4, 5 and 7 are mirrored.
     */
    public static function jpegOrientation(string $bytes): int
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $pos = 2;
        $len = strlen($bytes);
        while ($pos + 4 <= $len) {
            if ($bytes[$pos] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1; // start of image data: no EXIF segment came first
            }
            $segLen = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            if ($segLen < 2) {
                return 1;
            }
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::orientationFromTiff(substr($bytes, $pos + 10, $segLen - 8));
            }
            $pos += 2 + $segLen;
        }

        return 1;
    }

    private static function orientationFromTiff(string $tiff): int
    {
        if (strlen($tiff) < 14) {
            return 1;
        }

        $little = substr($tiff, 0, 2) === 'II';
        if (! $little && substr($tiff, 0, 2) !== 'MM') {
            return 1;
        }

        $u16 = fn (int $o) => $little ? (ord($tiff[$o]) | (ord($tiff[$o + 1]) << 8)) : ((ord($tiff[$o]) << 8) | ord($tiff[$o + 1]));
        $u32 = fn (int $o) => $little
            ? (ord($tiff[$o]) | (ord($tiff[$o + 1]) << 8) | (ord($tiff[$o + 2]) << 16) | (ord($tiff[$o + 3]) << 24))
            : ((ord($tiff[$o]) << 24) | (ord($tiff[$o + 1]) << 16) | (ord($tiff[$o + 2]) << 8) | ord($tiff[$o + 3]));

        $ifd = $u32(4);
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $count = $u16($ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return ($value >= 1 && $value <= 8) ? $value : 1;
            }
        }

        return 1;
    }

    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        $rotate = function (\GdImage $img, float $deg): \GdImage {
            $turned = imagerotate($img, $deg, 0);

            return $turned ?: $img;
        };

        switch ($orientation) {
            case 2: imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 3: $image = $rotate($image, 180); break;
            case 4: imageflip($image, IMG_FLIP_VERTICAL); break;
            case 5: $image = $rotate($image, -90); imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 6: $image = $rotate($image, -90); break;
            case 7: $image = $rotate($image, -90); imageflip($image, IMG_FLIP_VERTICAL); break;
            case 8: $image = $rotate($image, 90); break;
        }

        return $image;
    }
}
