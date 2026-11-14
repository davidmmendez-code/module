<?php
/**
 * Read-only queries behind the back-office report.
 */
class AttributionReport
{
    /**
     * Per-channel summary for a date range (inclusive, Y-m-d).
     *
     * Sessions are grouped by their entry channel. Orders are joined to the
     * visit that produced them, so conversion rate = orders / sessions for the same channel.
     * Orders without a visit (cookies disabled) are grouped under "sin_sesion".
     */
    public static function channelSummary(string $from, string $to): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::range($from, $to);

        $sessions = $db->executeS(
            "SELECT channel, COUNT(*) AS sessions
             FROM {$p}attribution_session
             WHERE date_add BETWEEN '$start' AND '$end'
             GROUP BY channel"
        ) ?: [];

        $orders = $db->executeS(
            "SELECT COALESCE(s.channel, 'sin_sesion') AS channel,
                    COUNT(o.id_order) AS orders,
                    SUM(o.total_price) AS revenue
             FROM {$p}attribution_order o
             LEFT JOIN {$p}attribution_session s ON s.id_session = o.id_session
             WHERE o.date_add BETWEEN '$start' AND '$end'
             GROUP BY channel"
        ) ?: [];

        $rows = [];
        foreach ($sessions as $row) {
            $rows[$row['channel']] = ['channel' => $row['channel'], 'sessions' => (int) $row['sessions'], 'orders' => 0, 'revenue' => 0.0];
        }
        foreach ($orders as $row) {
            $ch = $row['channel'];
            $rows[$ch] = $rows[$ch] ?? ['channel' => $ch, 'sessions' => 0, 'orders' => 0, 'revenue' => 0.0];
            $rows[$ch]['orders'] = (int) $row['orders'];
            $rows[$ch]['revenue'] = (float) $row['revenue'];
        }

        foreach ($rows as &$r) {
            $r['conversion_rate'] = $r['sessions'] > 0 ? $r['orders'] / $r['sessions'] * 100 : null;
            $r['avg_order'] = $r['orders'] > 0 ? $r['revenue'] / $r['orders'] : null;
        }
        unset($r);

        usort($rows, fn($a, $b) => $b['sessions'] <=> $a['sessions'] ?: $b['orders'] <=> $a['orders']);

        return array_values($rows);
    }

    /** Totals for the date range: visits, orders, revenue and overall conversion rate. */
    public static function totals(array $channelRows): array
    {
        $sessions = array_sum(array_column($channelRows, 'sessions'));
        $orders = array_sum(array_column($channelRows, 'orders'));
        $revenue = array_sum(array_column($channelRows, 'revenue'));
        return [
            'sessions' => $sessions,
            'orders' => $orders,
            'revenue' => $revenue,
            'conversion_rate' => $sessions > 0 ? $orders / $sessions * 100 : null,
        ];
    }

    /** Latest orders with their first/last touch and the visit channel. */
    public static function recentOrders(string $from, string $to, int $limit = 200): array
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        [$start, $end] = self::range($from, $to);

        return $db->executeS(
            "SELECT o.id_order, o.date_add, o.total_price,
                    COALESCE(s.channel, 'sin_sesion') AS visit_channel,
                    o.first_channel, o.first_campaign,
                    o.last_channel, o.last_campaign
             FROM {$p}attribution_order o
             LEFT JOIN {$p}attribution_session s ON s.id_session = o.id_session
             WHERE o.date_add BETWEEN '$start' AND '$end'
             ORDER BY o.date_add DESC
             LIMIT " . (int) $limit
        ) ?: [];
    }

    /** Validates the dates and returns a full-day datetime range. */
    private static function range(string $from, string $to): array
    {
        $valid = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        if (!$valid($from)) {
            $from = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$valid($to)) {
            $to = date('Y-m-d');
        }
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }
}
