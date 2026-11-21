<?php
/**
 * Run with: php tests/ChannelClassifierTest.php
 * Pure PHP, no PrestaShop needed.
 */
require __DIR__ . '/../attribution/classes/AttributionChannelClassifier.php';

$failures = 0;
$check = function (string $name, $expected, $actual) use (&$failures) {
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    } else {
        echo "ok:   $name\n";
    }
};

$own = 'mitienda.com';

$t = AttributionChannelClassifier::fromRequest(['gclid' => 'abc'], '', $own);
$check('gclid => google_ads', 'google_ads', $t['channel']);

$t = AttributionChannelClassifier::fromRequest(['utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'verano'], '', $own);
$check('utm facebook cpc => meta_ads', 'meta_ads', $t['channel']);
$check('utm campaign kept', 'verano', $t['campaign']);

$t = AttributionChannelClassifier::fromRequest(['utm_source' => 'newsletter', 'utm_medium' => 'email'], '', $own);
$check('utm email => email', 'email', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://www.google.es/search?q=x', $own);
$check('google referrer => organic_search', 'organic_search', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://m.facebook.com/', $own);
$check('facebook referrer => organic_social', 'organic_social', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://blog.ejemplo.com/post', $own);
$check('other referrer => referral', 'referral', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://www.mitienda.com/categoria', $own);
$check('own domain referrer => null', null, $t);

$t = AttributionChannelClassifier::fromRequest([], '', $own);
$check('no signal => null', null, $t);

$t = AttributionChannelClassifier::fromRequest([], 'https://dev.mitienda.com/categoria', $own);
$check('dev. subdomain of own shop => null', null, $t);

$t = AttributionChannelClassifier::fromRequest([], 'https://www.paypal.com/checkoutnow', $own);
$check('payment gateway referrer => null', null, $t);

$t = AttributionChannelClassifier::fromRequest([], 'https://www.google.co.uk/search?q=x', $own);
$check('google.co.uk referrer => organic_search', 'organic_search', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://www.otra.co.uk/', 'mitienda.co.uk');
$check('other .co.uk site is not own domain', 'referral', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://shop.mitienda.co.uk/', 'mitienda.co.uk');
$check('subdomain of own .co.uk shop => null', null, $t);

$t = AttributionChannelClassifier::fromRequest(['utm_source' => 'GOOGLE', 'utm_medium' => 'CPC'], '', $own);
$check('utm is case-insensitive', 'google_ads', $t['channel']);

$t = AttributionChannelClassifier::fromRequest([], 'https://blog.ejemplo.com/post/12?x=1#top', $own);
$check('referrer keeps the path, drops the query', 'blog.ejemplo.com/post/12', $t['referrer']);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
