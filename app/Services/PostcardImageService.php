<?php

namespace App\Services;

use App\Models\PostcardSlide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns an uploaded carousel photo into what the page serves: WebP at three
 * widths for srcset, plus a small thumbnail for the strip beneath the stage.
 *
 * The thumbnail is generated rather than letting the browser shrink the
 * full-size photo, which would make every visitor download the 1920px image
 * four times over just to draw the strip.
 */
class PostcardImageService
{
    public const WIDTHS = [640, 1280, 1920];
    public const THUMB = [192, 120];
    public const MIN_WIDTH = 1920;
    public const MIN_HEIGHT = 1080;

    /**
     * @return array{image_path: string, image_srcset: array<int, array{w: int, path: string}>, thumb_path: string}
     */
    public function process(UploadedFile $file): array
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $dir = 'postcards/'.Str::uuid();
        $disk = Storage::disk('public');

        $srcset = [];
        foreach (self::WIDTHS as $target) {
            $w = min($target, $width);
            $path = "{$dir}/w{$w}.webp";
            $disk->put($path, $this->encode($w === $width ? $source : imagescale($source, $w, -1, IMG_BICUBIC)));
            $srcset[] = ['w' => $w, 'path' => 'storage/'.$path];
        }
        $srcset = collect($srcset)->unique('w')->values()->all();

        $disk->put("{$dir}/thumb.webp", $this->encode($this->coverCrop($source, $width, $height, ...self::THUMB), 80));

        $largest = end($srcset);

        return [
            'image_path' => $largest['path'],
            'image_srcset' => $srcset,
            'thumb_path' => "storage/{$dir}/thumb.webp",
        ];
    }

    /** Remove an uploaded slide's files. Files shipped with the app are never touched. */
    public function delete(PostcardSlide $slide): void
    {
        if ($dir = $slide->uploadDirectory()) {
            Storage::disk('public')->deleteDirectory($dir);
        }
    }

    private function coverCrop($source, int $width, int $height, int $targetW, int $targetH)
    {
        $ratio = $targetW / $targetH;
        $cropW = $width / $height > $ratio ? (int) round($height * $ratio) : $width;
        $cropH = $width / $height > $ratio ? $height : (int) round($width / $ratio);

        $out = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($out, $source, 0, 0, (int) (($width - $cropW) / 2), (int) (($height - $cropH) / 2), $targetW, $targetH, $cropW, $cropH);

        return $out;
    }

    private function encode($image, int $quality = 82): string
    {
        ob_start();
        imagewebp($image, null, $quality);

        return (string) ob_get_clean();
    }
}
