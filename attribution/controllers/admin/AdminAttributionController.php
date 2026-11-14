<?php
/**
 * Back-office page: Stats > Attribution.
 */
class AdminAttributionController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();
        $this->content = $this->renderReport();
        $this->context->smarty->assign('content', $this->content);
    }

    private function renderReport(): string
    {
        $from = (string) Tools::getValue('from', date('Y-m-d', strtotime('-30 days')));
        $to = (string) Tools::getValue('to', date('Y-m-d'));

        $channels = AttributionReport::channelSummary($from, $to);
        $totals = AttributionReport::totals($channels);
        $orders = AttributionReport::recentOrders($from, $to);
        $action = $this->context->link->getAdminLink($this->controller_name);

        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $pct = fn($v) => $v === null ? '–' : number_format($v, 2, ',', '.') . ' %';
        $money = fn($v) => $v === null ? '–' : Tools::displayPrice((float) $v);

        $html = '<form method="get" action="' . $h($action) . '" class="form-inline" style="margin-bottom:20px">'
            . '<input type="hidden" name="controller" value="' . $h($this->controller_name) . '">'
            . '<label>Desde&nbsp;<input type="date" name="from" value="' . $h($from) . '"></label>&nbsp; '
            . '<label>Hasta&nbsp;<input type="date" name="to" value="' . $h($to) . '"></label>&nbsp; '
            . '<button type="submit" class="btn btn-default">Filtrar</button>'
            . '</form>';

        $html .= '<div class="row" style="margin-bottom:20px">'
            . $this->kpi('Visitas', (string) $totals['sessions'])
            . $this->kpi('Pedidos', (string) $totals['orders'])
            . $this->kpi('Ingresos', $money($totals['revenue']))
            . $this->kpi('Tasa de conversión', $pct($totals['conversion_rate']))
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
            . '<th>Primer clic</th><th>Último clic</th>'
            . '</tr></thead><tbody>';
        if (!$orders) {
            $html .= '<tr><td colspan="6">No hay pedidos en este periodo.</td></tr>';
        }
        foreach ($orders as $o) {
            $html .= '<tr>'
                . '<td>#' . (int) $o['id_order'] . '</td>'
                . '<td>' . $h($o['date_add']) . '</td>'
                . '<td>' . $money($o['total_price']) . '</td>'
                . '<td>' . $h($o['visit_channel']) . '</td>'
                . '<td>' . $h($o['first_channel']) . ($o['first_campaign'] !== '' ? ' · ' . $h($o['first_campaign']) : '') . '</td>'
                . '<td>' . $h($o['last_channel']) . ($o['last_campaign'] !== '' ? ' · ' . $h($o['last_campaign']) : '') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private function kpi(string $label, string $value): string
    {
        return '<div class="col-lg-3 col-md-6"><div class="panel" style="padding:15px">'
            . '<div style="color:#777">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div style="font-size:24px;font-weight:600">' . $value . '</div>'
            . '</div></div>';
    }
}
