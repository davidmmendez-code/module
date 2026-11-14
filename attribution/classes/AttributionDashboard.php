<?php
/**
 * HTML of the attribution dashboard shown on the module configuration page.
 * Self-contained: styles are scoped to the .ad-* classes and no external assets are loaded.
 */
class AttributionDashboard
{
    const CHANNEL_NAMES = [
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
        'tiktok_ads' => 'TikTok Ads',
        'microsoft_ads' => 'Microsoft Ads',
        'paid_other' => 'Pago (otros)',
        'organic_search' => 'Búsqueda orgánica',
        'organic_social' => 'Redes sociales',
        'referral' => 'Referencias',
        'email' => 'Email',
        'affiliate' => 'Afiliados',
        'campaign_other' => 'Campañas (otras)',
        'direct' => 'Directo',
        'sin_sesion' => 'Sin visita (cookies)',
    ];

    /**
     * @param string $baseUrl  module configure URL (already contains the token and "?")
     */
    public static function render(string $baseUrl, string $from, string $to): string
    {
        $d = AttributionDashboardData::build($from, $to);
        $r = $d['range'];
        $cur = $d['current'];
        $prev = $d['previous'];

        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = fn($v) => $v === null ? '–' : Tools::displayPrice((float) $v);
        $pct = fn($v) => $v === null ? '–' : number_format((float) $v, 1, ',', '.') . ' %';
        $name = fn($c) => $h(self::CHANNEL_NAMES[$c] ?? $c);

        $html = '<style>' . self::css() . '</style><div class="ad">';

        // Header: title, presets and custom range.
        $html .= '<div class="ad-head"><div><h2 class="ad-title">Analítica de atribución</h2>'
            . '<div class="ad-sub">' . $h(self::fmtDate($r['from'])) . ' – ' . $h(self::fmtDate($r['to'])) . '</div></div>'
            . '<div class="ad-presets">';
        foreach (self::presets() as $label => [$pf, $pt]) {
            $active = ($pf === $r['from'] && $pt === $r['to']) ? ' ad-active' : '';
            $html .= '<a class="ad-preset' . $active . '" href="' . $h($baseUrl . '&from=' . $pf . '&to=' . $pt) . '">' . $h($label) . '</a>';
        }
        $html .= '</div></div>';

        $html .= '<form method="post" action="' . $h($baseUrl) . '" class="ad-range">'
            . '<label>Desde <input type="date" name="from" value="' . $h($r['from']) . '"></label>'
            . '<label>Hasta <input type="date" name="to" value="' . $h($r['to']) . '"></label>'
            . '<button type="submit" class="btn btn-default">Aplicar</button></form>';

        // KPIs with comparison to the previous period.
        $html .= '<div class="ad-grid">'
            . self::kpi('Ingresos totales', $money($cur['revenue']), self::delta($cur['revenue'], $prev['revenue']), 'vs. periodo anterior')
            . self::kpi('Pedidos', (string) $cur['orders'], self::delta($cur['orders'], $prev['orders']), 'vs. periodo anterior')
            . self::kpi('Visitas', (string) $cur['sessions'], self::delta($cur['sessions'], $prev['sessions']), 'vs. periodo anterior')
            . self::kpi('Tasa de conversión', $pct($cur['conversion_rate']), self::delta($cur['conversion_rate'], $prev['conversion_rate'], true), 'puntos vs. periodo anterior')
            . self::kpi('Ticket medio', $money($cur['avg_order']), self::delta($cur['avg_order'], $prev['avg_order']), 'vs. periodo anterior')
            . '</div>';

        // Data quality strip.
        $linkedPct = $d['quality']['orders'] > 0 ? $d['quality']['linked_orders'] / $d['quality']['orders'] * 100 : null;
        $html .= '<div class="ad-quality">'
            . '<span><strong>Pedidos con visita registrada:</strong> ' . $pct($linkedPct) . '</span>'
            . '<span><strong>Tráfico directo:</strong> ' . $pct($d['quality']['direct_share']) . '</span>'
            . '<span class="ad-muted">Pedidos sin visita suelen deberse a cookies rechazadas o a navegación sin cookies.</span>'
            . '</div>';

        // Revenue by channel (bars) and channel table.
        $html .= '<div class="ad-row">'
            . '<div class="ad-card"><h3>Ingresos por canal</h3>';
        if (!$d['channels']) {
            $html .= '<p class="ad-muted">Sin datos en este periodo.</p>';
        }
        foreach ($d['channels'] as $c) {
            $html .= '<div class="ad-bar-row">'
                . '<div class="ad-bar-label"><span>' . $name($c['channel']) . '</span><span>' . $money($c['revenue']) . '</span></div>'
                . '<div class="ad-bar"><span style="width:' . number_format(min(100, $c['share']), 1, '.', '') . '%"></span></div>'
                . '<div class="ad-muted ad-small">' . $pct($c['share']) . ' de los ingresos</div>'
                . '</div>';
        }
        $html .= '</div><div class="ad-card"><h3>Detalle por canal</h3><div class="ad-scroll"><table class="ad-table"><thead><tr>'
            . '<th>Canal</th><th class="r">Visitas</th><th class="r">Pedidos</th><th class="r">Conversión</th><th class="r">Ingresos</th><th class="r">Ticket</th><th class="r">% ingresos</th>'
            . '</tr></thead><tbody>';
        if (!$d['channels']) {
            $html .= '<tr><td colspan="7" class="ad-muted">Sin datos en este periodo.</td></tr>';
        }
        foreach ($d['channels'] as $c) {
            $html .= '<tr><td>' . $name($c['channel']) . '</td>'
                . '<td class="r">' . (int) $c['sessions'] . '</td>'
                . '<td class="r">' . (int) $c['orders'] . '</td>'
                . '<td class="r">' . $pct($c['conversion_rate']) . '</td>'
                . '<td class="r">' . $money($c['revenue']) . '</td>'
                . '<td class="r">' . $money($c['avg_order']) . '</td>'
                . '<td class="r">' . $pct($c['share']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div></div>';

        // Daily revenue chart.
        $max = 0.0;
        foreach ($d['daily'] as $day) {
            $max = max($max, $day['revenue']);
        }
        $first = $d['daily'] ? self::fmtDate($d['daily'][0]['day']) : '';
        $last = $d['daily'] ? self::fmtDate($d['daily'][count($d['daily']) - 1]['day']) : '';
        $html .= '<div class="ad-card"><h3>Ingresos diarios</h3><div class="ad-chart">';
        foreach ($d['daily'] as $day) {
            $height = $max > 0 ? max(2, $day['revenue'] / $max * 100) : 2;
            $cls = $day['revenue'] > 0 ? '' : ' class="zero"';
            $html .= '<div' . $cls . ' style="height:' . number_format($height, 1, '.', '') . '%" title="'
                . $h(self::fmtDate($day['day'])) . ': ' . $h($money($day['revenue'])) . ' · ' . (int) $day['orders'] . ' pedidos"></div>';
        }
        $html .= '</div><div class="ad-axis"><span>' . $h($first) . '</span><span>' . $h($last) . '</span></div></div>';

        // Campaigns.
        $html .= '<div class="ad-card"><h3>Campañas y fuentes con más ingresos</h3><div class="ad-scroll"><table class="ad-table"><thead><tr>'
            . '<th>Canal</th><th>Campaña</th><th class="r">Visitas</th><th class="r">Pedidos</th><th class="r">Conversión</th><th class="r">Ingresos</th>'
            . '</tr></thead><tbody>';
        if (!$d['campaigns']) {
            $html .= '<tr><td colspan="6" class="ad-muted">Sin datos en este periodo.</td></tr>';
        }
        foreach ($d['campaigns'] as $c) {
            $html .= '<tr><td>' . $name($c['channel']) . '</td>'
                . '<td>' . ($c['campaign'] !== '' ? $h($c['campaign']) : '<span class="ad-muted">(sin campaña)</span>') . '</td>'
                . '<td class="r">' . (int) $c['sessions'] . '</td>'
                . '<td class="r">' . (int) $c['orders'] . '</td>'
                . '<td class="r">' . $pct($c['conversion_rate']) . '</td>'
                . '<td class="r">' . $money($c['revenue']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        // Recent orders.
        $html .= '<div class="ad-card"><h3>Últimos pedidos</h3><div class="ad-scroll"><table class="ad-table"><thead><tr>'
            . '<th>Pedido</th><th>Fecha</th><th class="r">Importe</th><th>Canal de la visita</th><th>Primer clic</th><th>Último clic</th><th>Referrer último clic</th>'
            . '</tr></thead><tbody>';
        if (!$d['orders']) {
            $html .= '<tr><td colspan="7" class="ad-muted">No hay pedidos en este periodo.</td></tr>';
        }
        foreach ($d['orders'] as $o) {
            $touches = json_decode((string) ($o['touchpoints'] ?? ''), true);
            $lastTouch = is_array($touches) && $touches ? end($touches) : [];
            $lastReferrer = (string) ($lastTouch['referrer'] ?? '');

            $html .= '<tr><td>#' . (int) $o['id_order'] . '</td>'
                . '<td>' . $h($o['date_add']) . '</td>'
                . '<td class="r">' . $money($o['total_price']) . '</td>'
                . '<td>' . $name($o['visit_channel']) . '</td>'
                . '<td>' . $name($o['first_channel']) . ($o['first_campaign'] !== '' ? ' · ' . $h($o['first_campaign']) : '') . '</td>'
                . '<td>' . $name($o['last_channel']) . ($o['last_campaign'] !== '' ? ' · ' . $h($o['last_campaign']) : '') . '</td>'
                . '<td>' . ($lastReferrer !== '' ? $h($lastReferrer) : '<span class="ad-muted">–</span>') . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        return $html . '</div>';
    }

    private static function kpi(string $label, string $value, string $delta, string $hint): string
    {
        return '<div class="ad-card ad-kpi"><div class="ad-kpi-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="ad-kpi-value">' . $value . '</div>'
            . '<div class="ad-kpi-delta">' . $delta . ' <span class="ad-muted">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</span></div></div>';
    }

    /**
     * Change vs. previous period. $points = true shows the difference in percentage points
     * (used for conversion rate, which is already a percentage).
     */
    private static function delta($current, $previous, bool $points = false): string
    {
        if ($current === null || $previous === null) {
            return '<span class="flat">–</span>';
        }
        $current = (float) $current;
        $previous = (float) $previous;

        if ($points) {
            $diff = $current - $previous;
            $cls = $diff > 0.005 ? 'up' : ($diff < -0.005 ? 'down' : 'flat');
            $arrow = $diff > 0.005 ? '▲' : ($diff < -0.005 ? '▼' : '■');
            return '<span class="' . $cls . '">' . $arrow . ' ' . number_format(abs($diff), 2, ',', '.') . ' pp</span>';
        }

        if ($previous == 0.0) {
            return $current == 0.0 ? '<span class="flat">■ 0 %</span>' : '<span class="up">▲ nuevo</span>';
        }
        $change = ($current - $previous) / $previous * 100;
        $cls = $change > 0.05 ? 'up' : ($change < -0.05 ? 'down' : 'flat');
        $arrow = $change > 0.05 ? '▲' : ($change < -0.05 ? '▼' : '■');
        return '<span class="' . $cls . '">' . $arrow . ' ' . number_format(abs($change), 1, ',', '.') . ' %</span>';
    }

    /** Quick ranges as [label => [from, to]]. */
    private static function presets(): array
    {
        $today = date('Y-m-d');
        return [
            'Hoy' => [$today, $today],
            '7 días' => [date('Y-m-d', strtotime('-6 days')), $today],
            '30 días' => [date('Y-m-d', strtotime('-29 days')), $today],
            '90 días' => [date('Y-m-d', strtotime('-89 days')), $today],
            'Este mes' => [date('Y-m-01'), $today],
            'Mes anterior' => [
                date('Y-m-01', strtotime('first day of last month')),
                date('Y-m-t', strtotime('last day of last month')),
            ],
        ];
    }

    private static function fmtDate(string $ymd): string
    {
        $t = strtotime($ymd);
        return $t ? date('d/m/Y', $t) : $ymd;
    }

    private static function css(): string
    {
        return <<<'CSS'
.ad{color:#1f2937;font-size:14px;max-width:1200px}
.ad h2.ad-title{font-size:20px;font-weight:600;margin:0}
.ad h3{font-size:15px;font-weight:600;margin:0 0 12px}
.ad-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px}
.ad-sub,.ad-muted{color:#6b7280}
.ad-small{font-size:12px}
.ad-presets{display:flex;flex-wrap:wrap;gap:6px}
.ad-preset{padding:5px 10px;border:1px solid #d1d5db;border-radius:999px;color:#374151;text-decoration:none;font-size:12px;background:#fff}
.ad-preset:hover{border-color:#4f46e5;color:#4f46e5}
.ad-preset.ad-active{background:#4f46e5;border-color:#4f46e5;color:#fff}
.ad-range{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.ad-range input[type=date]{padding:4px 6px;border:1px solid #d1d5db;border-radius:6px}
.ad-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:12px}
.ad-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:12px;min-width:0}
.ad-kpi-label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ad-kpi-value{font-size:26px;font-weight:600;margin:6px 0 4px}
.ad-kpi-delta{font-size:12px}
.up{color:#047857}.down{color:#b91c1c}.flat{color:#6b7280}
.ad-quality{display:flex;flex-wrap:wrap;gap:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13px}
.ad-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:12px}
@media (max-width:900px){.ad-row{grid-template-columns:1fr}}
.ad-bar-row{margin-bottom:14px}
.ad-bar-label{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px}
.ad-bar{height:8px;background:#eef2ff;border-radius:4px;overflow:hidden}
.ad-bar span{display:block;height:100%;background:#4f46e5;border-radius:4px}
.ad-scroll{overflow-x:auto}
.ad-table{width:100%;border-collapse:collapse;font-size:13px}
.ad-table th,.ad-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;text-align:left;white-space:nowrap}
.ad-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:600}
.ad-table .r{text-align:right}
.ad-chart{display:flex;align-items:flex-end;gap:3px;height:160px;border-bottom:1px solid #e5e7eb}
.ad-chart div{flex:1;background:#4f46e5;border-radius:3px 3px 0 0;min-height:2px}
.ad-chart div.zero{background:#e5e7eb}
.ad-axis{display:flex;justify-content:space-between;font-size:11px;color:#6b7280;margin-top:6px}
CSS;
    }
}
