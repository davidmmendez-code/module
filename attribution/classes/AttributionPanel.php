<?php
/**
 * HTML of the attribution panel shown in the module configuration page.
 */
class AttributionPanel
{
    /**
     * @param string $formAction  URL the filter form posts to (the module configure page)
     */
    public static function render(string $formAction, string $from, string $to): string
    {
        $channels = AttributionReport::channelSummary($from, $to);
        $totals = AttributionReport::totals($channels);
        $orders = AttributionReport::recentOrders($from, $to);

        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $pct = fn($v) => $v === null ? '–' : number_format($v, 2, ',', '.') . ' %';
        $money = fn($v) => $v === null ? '–' : Tools::displayPrice((float) $v);

        $html = '<form method="post" action="' . $h($formAction) . '" style="margin-bottom:20px">'
            . '<label>Desde&nbsp;<input type="date" name="from" value="' . $h($from) . '"></label>&nbsp; '
            . '<label>Hasta&nbsp;<input type="date" name="to" value="' . $h($to) . '"></label>&nbsp; '
            . '<button type="submit" class="btn btn-default">Filtrar</button>'
            . '</form>';

        $html .= '<div class="row" style="margin-bottom:20px">'
            . self::kpi('Visitas', (string) $totals['sessions'])
            . self::kpi('Pedidos', (string) $totals['orders'])
            . self::kpi('Ingresos', $money($totals['revenue']))
            . self::kpi('Tasa de conversión', $pct($totals['conversion_rate']))
            . '</div>';

        $html .= '<h3>Por canal</h3>'
            . '<p class="help-block">Los pedidos se asignan a la visita que los originó. '
            . 'La tasa de conversión es pedidos / visitas del mismo canal.</p>'
            . '<table class="table"><thead><tr>'
            . '<th>Canal</th><th>Visitas</th><th>Pedidos</th><th>Tasa de conversión</th><th>Ingresos</th><th>Ticket medio</th>'
            . '</tr></thead><tbody>';
        if (!$channels) {
            $html .= '<tr><td colspan="6">No hay datos en este periodo.</td></tr>';
        }
        foreach ($channels as $c) {
            $html .= '<tr>'
                . '<td>' . $h($c['channel']) . '</td>'
                . '<td>' . (int) $c['sessions'] . '</td>'
                . '<td>' . (int) $c['orders'] . '</td>'
                . '<td>' . $pct($c['conversion_rate']) . '</td>'
                . '<td>' . $money($c['revenue']) . '</td>'
                . '<td>' . $money($c['avg_order']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h3>Últimos pedidos</h3>'
            . '<table class="table"><thead><tr>'
            . '<th>Pedido</th><th>Fecha</th><th>Importe</th><th>Canal de la visita</th>'
            . '<th>Primer clic</th><th>Último clic</th><th>Referrer último clic</th>'
            . '</tr></thead><tbody>';
        if (!$orders) {
            $html .= '<tr><td colspan="7">No hay pedidos en este periodo.</td></tr>';
        }
        foreach ($orders as $o) {
            $touches = json_decode((string) ($o['touchpoints'] ?? ''), true);
            $lastTouch = is_array($touches) && $touches ? end($touches) : [];
            $lastReferrer = (string) ($lastTouch['referrer'] ?? '');

            $html .= '<tr>'
                . '<td>#' . (int) $o['id_order'] . '</td>'
                . '<td>' . $h($o['date_add']) . '</td>'
                . '<td>' . $money($o['total_price']) . '</td>'
                . '<td>' . $h($o['visit_channel']) . '</td>'
                . '<td>' . $h($o['first_channel']) . ($o['first_campaign'] !== '' ? ' · ' . $h($o['first_campaign']) : '') . '</td>'
                . '<td>' . $h($o['last_channel']) . ($o['last_campaign'] !== '' ? ' · ' . $h($o['last_campaign']) : '') . '</td>'
                . '<td>' . ($lastReferrer !== '' ? $h($lastReferrer) : '–') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private static function kpi(string $label, string $value): string
    {
        return '<div class="col-lg-3 col-md-6"><div class="panel" style="padding:15px">'
            . '<div style="color:#777">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div style="font-size:24px;font-weight:600">' . $value . '</div>'
            . '</div></div>';
    }
}
