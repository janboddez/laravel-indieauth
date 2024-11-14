<?php

namespace janboddez\IndieAuth;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ClientDiscovery
{
    public static function discoverClientData(string $url): ?array
    {
        /** @todo: Set a proper user agent. */
        $response = Http::get($url);

        if (! $response->successful()) {
            \Log::warning('Could not fetch IndieAuth client data.');
            return null;
        }

        if ($response->header('content-type') === 'application/json') {
            $data = $response->json();
            $name = $data['client_name'] ?? null;
            $logo = $data['logo_uri'] ?? null;
        } else {
            // Look for `h-app`.
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
            'name' => ! empty($name) && is_string($name) ? trim($name) : null,
            'icon' => $logo ?? null, /** @todo Download/cache this icon, or run it through a "proxy" on output. */
        ]);
    }
}
