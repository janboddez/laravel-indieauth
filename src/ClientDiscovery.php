<?php

namespace janboddez\IndieAuth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ClientDiscovery
{
    /**
     * Parse the page at the IndieAuth client's ID (i.e., URL), and return a name and image URL representing the client.
     */
    public static function discoverClientData(string $url): ?array
    {
        /** @todo: Set a proper user agent. */
        $response = Http::get($url);

        if (! $response->successful()) {
            Log::warning('Could not fetch IndieAuth client data.');
            return null;
        }

        if ($response->header('content-type') === 'application/json') {
            $data = $response->json();
            $name = $data['client_name'] ?? null;
            $logo = $data['logo_uri'] ?? null;
        } else {
            // Look for microformats2 (i.e., `h-app`).
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
                // Parse the `title` element instead.
                $crawler = new Crawler((string) $response->getBody());
                $nodes = $crawler->filterXPath('//title');
                if ($nodes->count() > 0) {
                    $name = $nodes->text(null);
                }
            }

            if (empty($logo)) {
                if (! $crawler) {
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
            'name' => ! empty($name) && is_string($name)
                ? trim($name) // We should probably sanitize client names, even though we escape on output.
                : null,
            'icon' => static::cacheThumbnail($logo ?? null),
        ]);
    }

    /**
     * Attempt to download and resize an image file, then return its new, local URL. Images are cached for a month.
     *
     * @todo This might be an ICO, or SVG, etc. file, which we may not support.
     */
    protected static function cacheThumbnail(?string $thumbnailUrl, int $size = 150): ?string
    {
        if (is_null($thumbnailUrl)) {
            return null;
        }

        // Generate filename.
        $hash = md5($thumbnailUrl);
        $relativeThumbnailPath = 'indieauth-clients/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $fullThumbnailPath = Storage::disk('public')->path($relativeThumbnailPath);

        // Look for existing files (without or with extension).
        foreach (glob("$fullThumbnailPath.*") as $match) {
            if ((time() - filectime($match)) < 60 * 60 * 24 * 30) {
                // Found one that's under a month old. Return its URL.
                return Storage::disk('public')->url(static::getRelativePath($match));
            }

            break; // Stop after the first match.
        }

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

        try {
            // Resize and crop.
            $imagick = new \Imagick();
            $imagick->readImageBlob($blob);
            $imagick->cropThumbnailImage($size, $size);
            $imagick->setImagePage(0, 0, 0, 0);

            $image = $imagick->getImageBlob();

            if ($image) {
                // Save image.
                Storage::disk('public')->put(
                    $relativeThumbnailPath,
                    $image
                );
            }

            $imagick->destroy();

            if (! $image || ! file_exists($fullThumbnailPath)) {
                Log::error('[IndieAuth] Something went wrong saving the thumbnail');
                return null;
            }

            // Try and grab a meaningful file extension.
            $finfo = new \finfo(FILEINFO_EXTENSION);
            $extension = $finfo->file($fullThumbnailPath); // Returns string or `false`.
            $extension = is_string($extension) && $extension !== '???'
                ? explode('/', $extension)[0] // For types that have multiple possible extensions, return the first one.
                : null;

            if ($extension) {
                // Rename.
                Storage::disk('public')->move($relativeThumbnailPath, $relativeThumbnailPath . '.' . $extension);

                // Return new local URL.
                return Storage::disk('public')->url($relativeThumbnailPath . '.' . $extension);
            }

            // Return the local thumbnail URL.
            return Storage::disk('public')->url($relativeThumbnailPath);
        } catch (\Exception $exception) {
            Log::error('[IndieAuth] Something went wrong: ' . $exception->getMessage());
        }

        return null;
    }

    protected static function getRelativePath(string $absolutePath, string $disk = 'public'): string
    {
        return Str::replaceStart(Storage::disk($disk)->path(''), '', $absolutePath);
    }
}
