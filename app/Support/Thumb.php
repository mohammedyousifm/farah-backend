<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Small versions of uploaded photos for cards and lists. Made once on first use and kept on the
 * public disk under thumbs/{width}/; if anything goes wrong the original photo is served instead.
 */
class Thumb
{
    public const CARD = 720;

    public const LOGO = 240;

    /** Deletes a photo's small versions (call when the photo itself is deleted or replaced). */
    public static function forget(?string $path): void
    {
        if (! $path) {
            return;
        }

        $disk = Storage::disk('public');
        foreach ([self::CARD, self::LOGO] as $width) {
            $disk->delete("thumbs/{$width}/".preg_replace('/\.\w+$/', '.webp', $path));
        }
    }

    public static function url(?string $path, int $width = self::CARD): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! function_exists('imagewebp') || ! preg_match('/\.(jpe?g|png|webp)$/i', $path)) {
            return $disk->url($path);
        }

        $target = "thumbs/{$width}/".preg_replace('/\.\w+$/', '.webp', $path);

        try {
            if (! $disk->exists($target)) {
                if (! $disk->exists($path) || ! ($image = @imagecreatefromstring($disk->get($path)))) {
                    return $disk->url($path);
                }

                if (imagesx($image) > $width) {
                    $image = imagescale($image, $width) ?: $image;
                }
                imagepalettetotruecolor($image);

                ob_start();
                imagewebp($image, null, 78);
                $disk->put($target, (string) ob_get_clean());
            }

            return $disk->url($target);
        } catch (Throwable) {
            return $disk->url($path);
        }
    }
}
