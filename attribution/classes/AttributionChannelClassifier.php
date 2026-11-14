<?php
/**
 * Maps a request (UTM params, click IDs, referrer) to a marketing channel.
 * Pure logic, no PrestaShop dependencies, so it can be unit-tested on its own.
 */
class AttributionChannelClassifier
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

    /** Payment gateways redirect back to the shop; they must not replace the visit's real source. */
    const PAYMENT_GATEWAYS = ['paypal', 'redsys', 'stripe', 'bizum', 'sermepa', 'sequra', 'klarna'];

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
        if ($host === '' || self::isOwnDomain($host, $ownHost)) {
            return null; // Internal navigation (including dev./www. variants) or no referrer.
        }

        $label = self::domainLabel($host);
        if (in_array($label, self::PAYMENT_GATEWAYS, true)) {
            return null; // Keep the campaign that brought the visit.
        }
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

    /** True when both hosts share the same registrable domain (dev.shop.com and shop.com). */
    private static function isOwnDomain(string $host, string $ownHost): bool
    {
        $own = self::normalizeHost($ownHost);
        if ($own === '') {
            return false;
        }
        return self::baseDomain($host) === self::baseDomain($own);
    }

    /** "dev.shop.com" -> "shop.com" */
    private static function baseDomain(string $host): string
    {
        return self::registrableDomain($host);
    }

    /** "www.google.es" -> "google", "m.facebook.com" -> "facebook" */
    private static function domainLabel(string $host): string
    {
        return explode('.', self::registrableDomain($host))[0];
    }

    /** Second-level suffixes like .co.uk or .com.mx need one more label. */
    const TWO_PART_SUFFIXES = ['co', 'com', 'org', 'net', 'gob', 'edu', 'gov', 'ac'];

    /** "www.google.co.uk" -> "google.co.uk", "dev.shop.com" -> "shop.com" */
    public static function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n >= 3 && strlen($parts[$n - 1]) === 2 && in_array($parts[$n - 2], self::TWO_PART_SUFFIXES, true)) {
            return implode('.', array_slice($parts, -3));
        }
        return implode('.', array_slice($parts, -2));
    }
}
