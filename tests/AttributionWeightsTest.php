<?php
/**
 * Run with: php tests/AttributionWeightsTest.php
 * Checks how each attribution model splits an order between channels. Pure PHP.
 */
require __DIR__ . '/../attribution/classes/AttributionDashboardData.php';

$failures = 0;
$check = function (string $name, $expected, $actual) use (&$failures) {
    $ok = is_float($expected) || is_float($actual)
        ? abs((float) $expected - (float) $actual) < 0.0001
        : $expected === $actual;
    if (!$ok) {
        $failures++;
        echo "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    } else {
        echo "ok:   $name\n";
    }
};

$order = [
    'first_channel' => 'meta_ads',
    'last_channel' => 'google_ads',
    'touchpoints' => json_encode([
        ['channel' => 'meta_ads'],
        ['channel' => 'google_ads'],
        ['channel' => 'google_ads'],
    ]),
];

$check('last model => last channel', ['google_ads' => 1.0], AttributionDashboardData::weights($order, 'last'));
$check('first model => first channel', ['meta_ads' => 1.0], AttributionDashboardData::weights($order, 'first'));

$linear = AttributionDashboardData::weights($order, 'linear');
$check('linear => google gets 2/3', 2 / 3, $linear['google_ads']);
$check('linear => meta gets 1/3', 1 / 3, $linear['meta_ads']);
$check('linear => weights sum to 1', 1.0, array_sum($linear));

$noTouches = ['first_channel' => '', 'last_channel' => '', 'touchpoints' => ''];
$check('no touchpoints, last => direct', ['direct' => 1.0], AttributionDashboardData::weights($noTouches, 'last'));
$check('no touchpoints, linear => direct', ['direct' => 1.0], AttributionDashboardData::weights($noTouches, 'linear'));

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
