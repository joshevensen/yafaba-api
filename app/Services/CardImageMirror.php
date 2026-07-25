<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CardImageMirror
{
    public const MAX_IMAGE_WIDTH = 480;

    public const WEBP_QUALITY = 80;

    /**
     * Download, resize, and re-encode the given source image as WebP, then
     * upload it to the `spaces` disk. Returns the public Spaces URL, or null
     * if the download failed or the bytes could not be decoded as an image.
     */
    public function mirror(string $sourceUrl, string $cardvaultPrintId): ?string
    {
        $response = Http::timeout(60)->get($sourceUrl);

        if (! $response->successful()) {
            return null;
        }

        $webpBytes = $this->encodeWebp($response->body());

        if ($webpBytes === null) {
            return null;
        }

        $path = "card-printings/{$cardvaultPrintId}.webp";

        Storage::disk('spaces')->put($path, $webpBytes, 'public');

        return Storage::disk('spaces')->url($path);
    }

    /**
     * Resize the given image bytes to at most self::MAX_IMAGE_WIDTH wide
     * (never upscaling) and re-encode as WebP. Returns null if the bytes
     * could not be decoded as an image or WebP encoding failed.
     */
    private function encodeWebp(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $sourceWidth = imagesx($image);
        $targetWidth = min($sourceWidth, self::MAX_IMAGE_WIDTH);

        $scaledImage = $image;

        if ($targetWidth !== $sourceWidth) {
            $scaledImage = imagescale($image, $targetWidth);

            if ($scaledImage === false) {
                $scaledImage = $image;
            }
        }

        ob_start();
        imagewebp($scaledImage, null, self::WEBP_QUALITY);
        $webpBytes = ob_get_clean();

        imagedestroy($image);

        if ($scaledImage !== $image) {
            imagedestroy($scaledImage);
        }

        return $webpBytes === false ? null : $webpBytes;
    }
}
