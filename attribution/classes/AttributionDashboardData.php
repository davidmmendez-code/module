<?php
/**
 * Data for the attribution dashboard: KPIs with previous-period comparison,
 * channel and campaign breakdowns, new vs returning customers, daily revenue
 * and data-quality indicators.
 */
class AttributionDashboardData
{
    /** Attribution models: which channel(s) get the credit for each order. */
    const MODELS = [
        'last' => 'Último clic',
        'first' => 'Primer clic',
        'linear' => 'Lineal (reparto entre clics)',
    ];

    /** Validates the requested range (Y-m-d). Defaults to the last 30 days. */
    public static function range(string $from, string $to): array
    {
        $valid = fn(string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        if (!$valid($from)) {
            $from = date('Y-m-d', strtotime('-29 days'));
        }
        if (!$valid($to)) {
            $to = date('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to];
    }

    /** Everything the dashboard needs for one date range, model and visit channel filter. */
    public static function build(string $from, string $to, string $model = 'last', string $channel = ''): array
    {
        $range = self::range($from, $to);
        $model = array_key_exists($model, self::MODELS) ? $model : 'last';
        $days = (int) ((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;

        // Previous period of the same length, right before the selected one.
        $prevTo = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

        $current = self::totals($range['from'], $range['to']);
        $sessionMap = self::sessionsByChannel($range['from'], $range['to']);
        $channels = self::channelRows($range['from'], $range['to'], $model, $sessionMap);

        foreach ($channels as &$c) {
            $c['share'] = $current['revenue'] > 0 ? $c['revenue'] / $current['revenue'] * 100 : 0.0;
        }
        unset($c);

        $linked = self::linkedOrders($range['from'], $range['to']);
        $unlinked = max(0, $current['orders'] - $linked);

        // Visit channels available as filters for the orders list.
        arsort($sessionMap);
        $channelOptions = array_keys($sessionMap);
        if ($unlinked > 0) {
            $channelOptions[] = 'sin_sesion';
        }

        $sessionsTotal = array_sum($sessionMap);
        $directSessions = $sessionMap['direct'] ?? 0;

        return [
            'range' => $range,
            'model' => $model,
            'channel' => $channel,
            'previous_range' => ['from' => $prevFrom, 'to' => $prevTo],
            'current' => $current,
            'previous' => self::totals($prevFrom, $prevTo),
            'channels' => $channels,
            'channel_options' => $channelOptions,
            'campaigns' => self::campaigns($range['from'], $range['to']),
            'daily' => self::daily($range['from'], $range['to']),
            'orders' => self::orders($range['from'], $range['to'], $channel, 50),
            'quality' => [
                'orders' => $current['orders'],
                'linked_orders' => $linked,
                'direct_share' => $sessionsTotal > 0 ? $directSessions / $sessionsTotal * 100 : null,
            ],
        ];
    }

    /**
     * How much of one order each channel gets under a model. Weights sum to 1.
     *
     * @param array $order  row with first_channel, last_channel and touchpoints (JSON)
     */
    public static function weights(array $order, string $model): array
    {
        if ($model === 'first') {
            return [($order['first_channel'] ?: 'direct') => 1.0];
        }
        if ($model === 'last') {
            return [($order['last_channel'] ?: 'direct') => 1.0];
        }

        // Linear: every touchpoint gets the same share of the order.
        $touches = json_decode((string) ($order['touchpoints'] ?? ''), true);
        $channels = array_values(array_filter(is_array($touches) ? array_column($touches, 'channel') : []));
        if (!$channels) {
            return ['direct' => 1.0];
        }
        $share = 1 / count($channels);
        $weights = [];
        foreach ($channels as $ch) {
            $weights[$ch] = ($weights[$ch] ?? 0) + $share;
        }
        return $weights;
    }

    /** Sessions, orders, revenue, conversion and average ticket for a range. */
    public static function totals(string $from, string $to): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        $sessions = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$p}attribution_session WHERE date_add BETWEEN '$start' AND '$end'"
        );
        $row = $db->getRow(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total_price), 0) AS revenue
             FROM {$p}attribution_order WHERE date_add BETWEEN '$start' AND '$end'"
        ) ?: [];
        $orders = (int) ($row['orders'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);

        return [
            'sessions' => $sessions,
            'orders' => $orders,
            'revenue' => $revenue,
            'conversion_rate' => $sessions > 0 ? $orders / $sessions * 100 : null,
            'avg_order' => $orders > 0 ? $revenue / $orders : null,
        ];
    }

    /** Top campaigns by revenue, grouped by the channel and campaign of the visit. */
    public static function campaigns(string $from, string $to, int $limit = 10): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        $rows = $db->executeS(
            "SELECT s.channel, s.campaign,
                    COUNT(DISTINCT s.id_session) AS sessions,
                    COUNT(o.id_order) AS orders,
                    COALESCE(SUM(o.total_price), 0) AS revenue
             FROM {$p}attribution_session s
             LEFT JOIN {$p}attribution_order o ON o.id_session = s.id_session
             WHERE s.date_add BETWEEN '$start' AND '$end'
             GROUP BY s.channel, s.campaign
             ORDER BY revenue DESC, sessions DESC
             LIMIT " . (int) $limit
        ) ?: [];

        foreach ($rows as &$r) {
            $r['sessions'] = (int) $r['sessions'];
            $r['orders'] = (int) $r['orders'];
            $r['revenue'] = (float) $r['revenue'];
            $r['conversion_rate'] = $r['sessions'] > 0 ? $r['orders'] / $r['sessions'] * 100 : null;
        }
        unset($r);

        return $rows;
    }

    /** One entry per day of the range (days without orders have zero revenue). */
    public static function daily(string $from, string $to): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        $rows = $db->executeS(
            "SELECT DATE(date_add) AS day, COUNT(*) AS orders, SUM(total_price) AS revenue
             FROM {$p}attribution_order
             WHERE date_add BETWEEN '$start' AND '$end'
             GROUP BY DATE(date_add)"
        ) ?: [];

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day']] = ['orders' => (int) $r['orders'], 'revenue' => (float) $r['revenue']];
        }

        $series = [];
        $cursor = strtotime($from);
        $last = strtotime($to);
        for (; $cursor <= $last; $cursor += 86400) {
            $day = date('Y-m-d', $cursor);
            $series[] = [
                'day' => $day,
                'orders' => $byDay[$day]['orders'] ?? 0,
                'revenue' => $byDay[$day]['revenue'] ?? 0.0,
            ];
        }
        return $series;
    }

    /** Latest orders, optionally filtered by the channel of their visit. */
    public static function orders(string $from, string $to, string $channel = '', int $limit = 50): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        $where = "o.date_add BETWEEN '$start' AND '$end'";
        if ($channel !== '') {
            $where .= " AND COALESCE(s.channel, 'sin_sesion') = '" . pSQL($channel) . "'";
        }

        return $db->executeS(
            "SELECT o.id_order, o.date_add, o.total_price, o.touchpoints,
                    COALESCE(s.channel, 'sin_sesion') AS visit_channel,
                    o.first_channel, o.first_campaign, o.last_channel, o.last_campaign
             FROM {$p}attribution_order o
             LEFT JOIN {$p}attribution_session s ON s.id_session = o.id_session
             WHERE $where
             ORDER BY o.date_add DESC
             LIMIT " . (int) $limit
        ) ?: [];
    }

    /** Orders that could be linked to a recorded visit (the rest had cookies disabled). */
    public static function linkedOrders(string $from, string $to): int
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        return (int) $db->getValue(
            "SELECT COUNT(o.id_order)
             FROM {$p}attribution_order o
             INNER JOIN {$p}attribution_session s ON s.id_session = o.id_session
             WHERE o.date_add BETWEEN '$start' AND '$end'"
        );
    }

    /** Visits per entry channel for the range. */
    private static function sessionsByChannel(string $from, string $to): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        $map = [];
        foreach ($db->executeS(
            "SELECT channel, COUNT(*) AS sessions
             FROM {$p}attribution_session
             WHERE date_add BETWEEN '$start' AND '$end'
             GROUP BY channel"
        ) ?: [] as $row) {
            $map[$row['channel']] = (int) $row['sessions'];
        }
        return $map;
    }

    /**
     * One row per channel with visits, attributed orders (under the model),
     * new vs returning customers and revenue.
     */
    private static function channelRows(string $from, string $to, string $model, array $sessionMap): array
    {
        $attributed = self::attributedByChannel($from, $to, $model);
        $keys = array_unique(array_merge(array_keys($sessionMap), array_keys($attributed)));

        $rows = [];
        foreach ($keys as $ch) {
            $sessions = $sessionMap[$ch] ?? 0;
            $a = $attributed[$ch] ?? ['orders' => 0.0, 'revenue' => 0.0, 'new' => 0.0, 'returning' => 0.0];
            $rows[] = [
                'channel' => $ch,
                'sessions' => $sessions,
                'orders' => $a['orders'],
                'new' => $a['new'],
                'returning' => $a['returning'],
                'revenue' => $a['revenue'],
                'conversion_rate' => $sessions > 0 ? $a['orders'] / $sessions * 100 : null,
                'avg_order' => $a['orders'] > 0 ? $a['revenue'] / $a['orders'] : null,
            ];
        }

        usort($rows, fn($x, $y) => $y['revenue'] <=> $x['revenue'] ?: $y['sessions'] <=> $x['sessions']);
        return $rows;
    }

    /**
     * Orders and revenue per channel under the chosen model.
     * "New" means the customer had no earlier order in the shop (any status).
     */
    private static function attributedByChannel(string $from, string $to, string $model): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::bounds($from, $to);

        $rows = $db->executeS(
            "SELECT o.id_order, o.id_customer, o.total_price, o.first_channel, o.last_channel, o.touchpoints,
                    (SELECT COUNT(*) FROM {$p}orders x
                     WHERE x.id_customer = o.id_customer AND x.id_order < o.id_order) AS prior_orders
             FROM {$p}attribution_order o
             WHERE o.date_add BETWEEN '$start' AND '$end'"
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $isNew = (int) $r['prior_orders'] === 0;
            $amount = (float) $r['total_price'];
            foreach (self::weights($r, $model) as $channel => $w) {
                $out[$channel] = $out[$channel] ?? ['orders' => 0.0, 'revenue' => 0.0, 'new' => 0.0, 'returning' => 0.0];
                $out[$channel]['orders'] += $w;
                $out[$channel]['revenue'] += $amount * $w;
                $out[$channel][$isNew ? 'new' : 'returning'] += $w;
            }
        }
        return $out;
    }

    private static function bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }
}
