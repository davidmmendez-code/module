<?php
/**
 * Maps a request (UTM params, click IDs, referrer) to a marketing channel.
 * Pure logic, no PrestaShop dependencies, so it can be unit-tested on its own.
 */
class ChannelClassifier
{
    /** Click ID parameter => platform channel. */
    const CLICK_IDS = [
        'gclid' => 'google_ads',
        'gbraid' => 'google_ads',
        'wbraid' => 'google_ads',
        'fbclid' => 'meta_ads',
        'msclkid' => 'microsoft_ads',
        'ttclid' => 'tiktok_ads',
    ];

    const SEARCH_ENGINES = ['google', 'bing', 'yahoo', 'duckduckgo', 'ecosia', 'baidu', 'yandex'];

    const SOCIAL_NETWORKS = [
        'facebook', 'instagram', 'twitter', 'x', 't', 'linkedin', 'pinterest',
        'tiktok', 'youtube', 'reddit', 'whatsapp', 'telegram',
    ];

    const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsocial', 'paid_social', 'display', 'cpm', 'paidsearch'];

    /**
     * @return array|null  null when the request carries no acquisition signal
     */
    public static function fromRequest(array $get, string $referrer, string $ownHost): ?array
    {
        $source = self::clean($get['utm_source'] ?? '');
        $medium = self::clean($get['utm_medium'] ?? '');
        $campaign = self::clean($get['utm_campaign'] ?? '');

        // 1. Click IDs are the most reliable paid signal, even without UTMs.
        foreach (self::CLICK_IDS as $param => $channel) {
            if (!empty($get[$param])) {
                return self::touch($channel, $source ?: $channel, $medium ?: 'cpc', $campaign, $referrer);
            }
        }

        // 2. Explicit UTMs.
        if ($source !== '' || $medium !== '' || $campaign !== '') {
            return self::touch(self::fromUtm($source, $medium), $source, $medium, $campaign, $referrer);
        }

        // 3. Referrer-based.
        $host = self::host($referrer);
        if ($host === '' || $host === self::normalizeHost($ownHost)) {
            return null; // Internal navigation or no referrer at all.
        }

        $label = self::domainLabel($host);
        if (in_array($label, self::SEARCH_ENGINES, true)) {
            return self::touch('organic_search', $label, 'organic', '', $referrer);
        }
        if (in_array($label, self::SOCIAL_NETWORKS, true)) {
            return self::touch('organic_social', $label, 'social', '', $referrer);
        }

        return self::touch('referral', $host, 'referral', '', $referrer);
    }

    private static function fromUtm(string $source, string $medium): string
    {
        if (in_array($medium, self::PAID_MEDIUMS, true)) {
            if (str_contains($source, 'google')) {
                return 'google_ads';
            }
            if (str_contains($source, 'facebook') || str_contains($source, 'meta') || str_contains($source, 'instagram')) {
                return 'meta_ads';
            }
            if (str_contains($source, 'tiktok')) {
                return 'tiktok_ads';
            }
            if (str_contains($source, 'bing') || str_contains($source, 'microsoft')) {
                return 'microsoft_ads';
            }
            return 'paid_other';
        }

        if ($medium === 'email' || $medium === 'newsletter' || str_contains($source, 'email')) {
            return 'email';
        }
        if ($medium === 'social' || $medium === 'social-network') {
            return 'organic_social';
        }
        if ($medium === 'affiliate') {
            return 'affiliate';
        }
        if ($medium === 'referral') {
            return 'referral';
        }

        return 'campaign_other';
    }

    private static function touch(string $channel, string $source, string $medium, string $campaign, string $referrer): array
    {
        return [
            'channel' => $channel,
            'source' => substr($source, 0, 255),
            'medium' => substr($medium, 0, 255),
            'campaign' => substr($campaign, 0, 255),
            'referrer' => substr(self::host($referrer), 0, 255),
        ];
    }

    private static function clean(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? self::normalizeHost($host) : '';
    }

    private static function normalizeHost(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /** "www.google.es" -> "google", "m.facebook.com" -> "facebook" */
    private static function domainLabel(string $host): string
    {
        $parts = explode('.', $host);
        return count($parts) >= 2 ? $parts[count($parts) - 2] : $host;
    }
}
