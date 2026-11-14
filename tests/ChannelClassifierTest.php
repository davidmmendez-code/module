<?php
/**
 * Run with: php tests/ChannelClassifierTest.php
 * Pure PHP, no PrestaShop needed.
 */
require __DIR__ . '/../attribution/classes/ChannelClassifier.php';

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

$t = ChannelClassifier::fromRequest(['gclid' => 'abc'], '', $own);
$check('gclid => google_ads', 'google_ads', $t['channel']);

$t = ChannelClassifier::fromRequest(['utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'verano'], '', $own);
$check('utm facebook cpc => meta_ads', 'meta_ads', $t['channel']);
$check('utm campaign kept', 'verano', $t['campaign']);

$t = ChannelClassifier::fromRequest(['utm_source' => 'newsletter', 'utm_medium' => 'email'], '', $own);
$check('utm email => email', 'email', $t['channel']);

$t = ChannelClassifier::fromRequest([], 'https://www.google.es/search?q=x', $own);
$check('google referrer => organic_search', 'organic_search', $t['channel']);

$t = ChannelClassifier::fromRequest([], 'https://m.facebook.com/', $own);
$check('facebook referrer => organic_social', 'organic_social', $t['channel']);

$t = ChannelClassifier::fromRequest([], 'https://blog.ejemplo.com/post', $own);
$check('other referrer => referral', 'referral', $t['channel']);

$t = ChannelClassifier::fromRequest([], 'https://www.mitienda.com/categoria', $own);
$check('own domain referrer => null', null, $t);

$t = ChannelClassifier::fromRequest([], '', $own);
$check('no signal => null', null, $t);

$t = ChannelClassifier::fromRequest(['utm_source' => 'GOOGLE', 'utm_medium' => 'CPC'], '', $own);
$check('utm is case-insensitive', 'google_ads', $t['channel']);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
