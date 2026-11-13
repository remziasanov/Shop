<?php

declare(strict_types=1);

namespace App\Services\Promo;

/**
 * PM-25: clicks of bots are not counted — an empty User-Agent, search engine crawlers, social network and messenger
 * link scanners, monitoring and generic HTTP libraries.
 */
final class BotDetector
{
    /** Lower-case fragments of the User-Agent of known robots. */
    private const SIGNATURES = [
        'bot', 'crawl', 'spider', 'slurp', 'yandex', 'mail.ru', 'facebookexternalhit', 'facebot', 'meta-externalagent',
        'whatsapp', 'telegram', 'twitter', 'vkshare', 'skypeuripreview', 'slack', 'discord', 'linkedin', 'pinterest',
        'embedly', 'preview', 'headlesschrome', 'phantomjs', 'lighthouse', 'pingdom', 'uptime', 'monitor',
        'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp', 'libwww', 'httpclient',
        'scrapy', 'axios/', 'node-fetch', 'guzzlehttp', 'apache-httpclient', 'postmanruntime',
    ];

    public static function isBot(?string $userAgent): bool
    {
        $ua = $userAgent === null ? '' : mb_strtolower(trim($userAgent));

        if ($ua === '') {
            return true;
        }

        foreach (self::SIGNATURES as $signature) {
            if (str_contains($ua, $signature)) {
                return true;
            }
        }

        return false;
    }
}
