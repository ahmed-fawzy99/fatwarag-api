<?php

namespace App\Content;

use Illuminate\Http\Request;

/**
 * Recognises search-engine and social crawlers by user agent. They never trigger a paid translation.
 */
class Crawlers
{
    private const PATTERN = '/bot\b|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora link preview|pinterest|whatsapp|telegram|discord|preview|headlesschrome|lighthouse|gtmetrix|ia_archiver|yandex|baidu|duckduck|semrush|ahrefs|mj12|python-requests|curl|wget|go-http-client/i';

    public static function isCrawler(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        // A request with no user agent at all is not a browser either.
        return $agent === '' || preg_match(self::PATTERN, $agent) === 1;
    }
}
