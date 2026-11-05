<?php

namespace SegmentHoldings\SsgPublish\Support;

class Sanitizer
{
    public static function text(?string $message, array $secrets = []): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string) $message)) ?? '');

        foreach ($secrets as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            $clean = str_replace($secret, '[redacted]', $clean);

            $path = parse_url($secret, PHP_URL_PATH);
            if (is_string($path) && strlen($path) > 1) {
                $clean = str_replace($path, '/[redacted]', $clean);
            }
        }

        if (mb_strlen($clean) > 280) {
            $clean = mb_substr($clean, 0, 277).'...';
        }

        return $clean;
    }

    public static function httpsUrl(?string $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }
}
