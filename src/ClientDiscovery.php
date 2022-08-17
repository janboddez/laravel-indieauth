<?php

namespace janboddez\IndieAuth;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class ClientDiscovery
{
    public static function discoverClientData(string $url): ?array
    {
        $client = new Client([
            'allow_redirects' => true,
        ]);

        /** @todo: Set a proper user agent. */
        $response = $client->request('GET', $url);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            \Log::warning('Could not fetch IndieAuth client data.');
            return null;
        }

        // Look for `h-app`.
        $mf2 = \Mf2\parse((string) $response->getBody(), $url);

        if (! empty($mf2['items'][0]['type']) && in_array('h-app', (array) $mf2['items'][0]['type'], true)) {
            if (! empty($mf2['items'][0]['properties']['name'][0]) &&
                is_string($mf2['items'][0]['properties']['name'][0])) {
                $name = $mf2['items'][0]['properties']['name'][0];
            }

            if (! empty($mf2['items'][0]['properties']['logo'][0]['value']) &&
                filter_var($mf2['items'][0]['properties']['logo'][0]['value'], FILTER_VALIDATE_URL)) {
                $logo = $mf2['items'][0]['properties']['logo'][0]['value'];
            }
        }

        if (empty($name)) {
            // Parse the `title` element instead.
            $crawler = new Crawler((string) $response->getBody());
            $name = $crawler->filterXPath('//title')->text(null);
        }

        if (empty($logo)) {
            if (! $crawler) {
                $crawler = new Crawler((string) $response->getBody());
            }

            // Look for a favicon.
            $logo = filter_var(\Mf2\resolveUrl(
                $url,
                /** @todo: This will often by an ICO, or it may be an SVG, etc. We will want to process these images first. */
                $crawler->filterXPath('//link[@rel="icon" or @rel="shortcut icon"]')->attr('href'),
            ), FILTER_VALIDATE_URL);
        }

        return array_filter([
            'name' => trim($name),
            /** @todo: Download/resize/cache this icon, or run it through a "proxy" on output. */
            'icon' => $logo,
        ]);
    }
}
