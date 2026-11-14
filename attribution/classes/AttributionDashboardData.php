<?php
/**
 * Data for the attribution dashboard: KPIs with previous-period comparison,
 * channel and campaign breakdowns, daily revenue and data-quality indicators.
 */
class AttributionDashboardData
{
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

    /** Everything the dashboard needs for one date range. */
    public static function build(string $from, string $to): array
    {
        $range = self::range($from, $to);
        $days = (int) ((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;

        // Previous period of the same length, right before the selected one.
        $prevTo = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

        $current = self::totals($range['from'], $range['to']);
        $channels = AttributionReport::channelSummary($range['from'], $range['to']);

        foreach ($channels as &$c) {
            $c['share'] = $current['revenue'] > 0 ? $c['revenue'] / $current['revenue'] * 100 : 0.0;
        }
        unset($c);

        $sessionsTotal = array_sum(array_column($channels, 'sessions'));
        $directSessions = 0;
        foreach ($channels as $c) {
            if ($c['channel'] === 'direct') {
                $directSessions = (int) $c['sessions'];
            }
        }

        return [
            'range' => $range,
            'previous_range' => ['from' => $prevFrom, 'to' => $prevTo],
            'current' => $current,
            'previous' => self::totals($prevFrom, $prevTo),
            'channels' => $channels,
            'campaigns' => self::campaigns($range['from'], $range['to']),
            'daily' => self::daily($range['from'], $range['to']),
            'orders' => AttributionReport::recentOrders($range['from'], $range['to'], 50),
            'quality' => [
                'orders' => $current['orders'],
                'linked_orders' => self::linkedOrders($range['from'], $range['to']),
                'direct_share' => $sessionsTotal > 0 ? $directSessions / $sessionsTotal * 100 : null,
            ],
        ];
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

    private static function bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }
}
