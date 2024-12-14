<?php

namespace janboddez\IndieAuth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Symfony\Component\DomCrawler\Crawler;

class ClientDiscovery
{
    /**
     * Parse the page at the IndieAuth client's ID (i.e., URL), and return a name and image URL representing the client.
     */
    public static function discoverClientData(string $url): ?array
    {
        /** @todo Set a proper (?) user agent. */
        $response = Http::get($url);

        if (! $response->successful()) {
            Log::warning('Could not fetch IndieAuth client data.');
            return null;
        }

        /** @link https://indieauth.net/source/#client-metadata */
        if ($response->header('content-type') === 'application/json') {
            $data = $response->json();

            $clientId = $data['client_id'] ?? null;
            $clientUri = $data['client_uri'] ?? null;
            /**
             * @todo Verify that `client_id` the URL. `client_uri` MUST be a prefix of `client_id`. Warn the user if
             *       the hostname of `client_uri` is different from the hostname of `client_id`.
             */

            $name = $data['client_name'] ?? null;
            $logo = $data['logo_uri'] ?? null;
        } else {
            // Look for h-app microformats.
            $mf2 = \Mf2\parse((string) $response->getBody(), $url);

            if (! empty($mf2['items'][0]['type']) && in_array('h-app', (array) $mf2['items'][0]['type'], true)) {
                if (
                    ! empty($mf2['items'][0]['properties']['name'][0]) &&
                    is_string($mf2['items'][0]['properties']['name'][0])
                ) {
                    $name = $mf2['items'][0]['properties']['name'][0];
                }

                if (
                    ! empty($mf2['items'][0]['properties']['logo'][0]['value']) &&
                    filter_var($mf2['items'][0]['properties']['logo'][0]['value'], FILTER_VALIDATE_URL)
                ) {
                    // This should already be an absolute URL.
                    $logo = $mf2['items'][0]['properties']['logo'][0]['value'];
                }
            }

            if (empty($name)) {
                // If we got a JSON document nor h-app, parse the `title` element instead.
                $crawler = new Crawler((string) $response->getBody());
                $nodes = $crawler->filterXPath('//title');
                if ($nodes->count() > 0) {
                    $name = $nodes->text(null);
                }
            }

            if (empty($logo)) {
                if (! $crawler) {
                    // We may not yet have needed a `Crawler` instance. If so, initialize one.
                    $crawler = new Crawler((string) $response->getBody());
                }

                // Look for a favicon.
                $nodes = $crawler->filterXPath('//link[@rel="icon" or @rel="shortcut icon"]');
                if ($nodes->count() > 0) {
                    $logo = $nodes->attr('href');

                    if (filter_var($logo, FILTER_VALIDATE_URL)) {
                        // "Absolutize," then sanitize.
                        $logo = filter_var(\Mf2\resolveUrl(
                            $url,
                            /** @todo This will often by an ICO, or it may be an SVG, etc. We will want to process these images first. */
                            $logo,
                        ), FILTER_SANITIZE_URL);
                    }
                }
            }
        }

        return array_filter([
            'name' => ! empty($name) && is_string($name) ? trim(strip_tags($name)) : null,
            'icon' => static::cacheThumbnail($logo ?? null),
            'uri' => $clientUrl ?? $clientId ?? null, // Not actually used, yet.
        ]);
    }

    /**
     * Attempt to download and resize an image file, then return its new, local URL. Images are cached for a month.
     *
     * @todo This might be an ICO, or SVG, etc. file, which we may not support.
     */
    protected static function cacheThumbnail(?string $thumbnailUrl, int $size = 150, $disk = 'public'): ?string
    {
        if (is_null($thumbnailUrl)) {
            return null;
        }

        // Generate filename.
        $hash = md5($thumbnailUrl);
        $relativeThumbnailPath = 'indieauth-clients/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $fullThumbnailPath = Storage::disk($disk)->path($relativeThumbnailPath);

        // Look for existing files (with or without extension).
        foreach (glob("$fullThumbnailPath.*") as $match) {
            if ((time() - filectime($match)) < 60 * 60 * 24 * 30) {
                // Found one that's under a month old. Return its URL.
                return Storage::disk($disk)->url(static::getRelativePath($match));
            }

            break; // Stop after the first match.
        }

        if (extension_loaded('imagick') && class_exists('Imagick')) {
            // Using Imagick when we can.
            $manager = new ImageManager(new ImagickDriver());
        } elseif (extension_loaded('gd') && function_exists('gd_info')) {
            // Fall back to GD if we have to.
            $manager = new ImageManager(new GdDriver());
        } else {
            // No image driver found. Quit.
            Log::error('[IndieAuth] Imagick nor GD installed');
            return null;
        }

        // Download the image into memory.
        $response = Http::get($thumbnailUrl);
        if (! $response->successful()) {
            Log::error('[IndieAuth] Something went wrong fetching the image at ' . $thumbnailUrl);
            return null;
        }

        $blob = $response->body();
        if (empty($blob)) {
            Log::error('[IndieAuth] Missing image data');
            return null;
        }

        // Load image data and crop.
        $image = $manager->read($blob);
        $image->cover($size, $size);

        if (! Storage::disk($disk)->has($dir = dirname($relativeThumbnailPath))) {
            // Recursively create directory if it doesn't exist, yet.
            Storage::disk($disk)->makeDirectory($dir);
        }

        // Save image.
        $image->save($fullThumbnailPath);

        unset($image);

        if (! Storage::disk($disk)->has($relativeThumbnailPath)) {
            Log::warning('[IndieAuth] Something went wrong saving the thumbnail');
            return null;
        }

        // Try and apply a meaningful file extension.
        $finfo = new \finfo(FILEINFO_EXTENSION);
        $extension = explode('/', $finfo->file($fullThumbnailPath))[0];
        if (
            ! empty($extension) &&
            $extension !== '???' &&
            Storage::disk($disk)->move($relativeThumbnailPath, $relativeThumbnailPath . ".$extension")
        ) {
            return Storage::disk($disk)->url($relativeThumbnailPath . ".$extension");
        }

        return Storage::disk($disk)->url($relativeThumbnailPath);
    }

    protected static function getRelativePath(string $absolutePath, string $disk = 'public'): string
    {
        return Str::replaceStart(Storage::disk($disk)->path(''), '', $absolutePath);
    }
}
