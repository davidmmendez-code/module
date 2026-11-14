<?php
/**
 * Attribution – tracks visits (sessions), their acquisition channel and the orders they produce.
 * Target: PrestaShop 8.x (PS 1.7 adaptation to follow).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/ChannelClassifier.php';
require_once __DIR__ . '/classes/AttributionReport.php';
require_once __DIR__ . '/classes/AttributionDashboardData.php';
require_once __DIR__ . '/classes/AttributionDashboard.php';

class Attribution extends Module
{
    const COOKIE_TOUCH = 'attr_touch';
    const COOKIE_SESSION = 'attr_sid';
    const COOKIE_SESSION_SEEN = 'attr_sid_seen';
    const SESSION_TIMEOUT = 1800; // 30 min of inactivity starts a new visit
    const MAX_TOUCHPOINTS = 20;
    const TAB_CLASS = 'AdminAttribution';

    public function __construct()
    {
        $this->name = 'attribution';
        $this->tab = 'analytics_stats';
        $this->version = '0.2.0';
        $this->author = 'TBD';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Attribution', [], 'Modules.Attribution.Admin');
        $this->description = $this->trans('Tracks traffic sources, visits and conversions.', [], 'Modules.Attribution.Admin');
    }

    public function install()
    {
        return parent::install()
            && $this->runSql(__DIR__ . '/sql/install.php')
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionValidateOrder');
    }

    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->runSql(__DIR__ . '/sql/uninstall.php')
            && parent::uninstall();
    }

    private function runSql(string $file): bool
    {
        $queries = require $file;
        foreach ($queries as $query) {
            $query = str_replace('PREFIX_', _DB_PREFIX_, $query);
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Module configuration page (Módulos → Configurar). Shows the attribution panel.
     */
    public function getContent()
    {
        $title = '<div class="panel-heading">Attribution</div>';
        try {
            $from = (string) Tools::getValue('from', date('Y-m-d', strtotime('-30 days')));
            $to = (string) Tools::getValue('to', date('Y-m-d'));
            $formAction = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);

            return '<div class="panel">' . $title . AttributionDashboard::render($formAction, $from, $to) . '</div>';
        } catch (Throwable $e) {
            // Show the real reason instead of a generic 500 page.
            return '<div class="alert alert-danger"><strong>Attribution: error al cargar el panel.</strong><br>'
                . htmlspecialchars(get_class($e) . ': ' . $e->getMessage(), ENT_QUOTES, 'UTF-8')
                . '<br><small>' . htmlspecialchars(basename($e->getFile()) . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8')
                . '</small></div>';
        }
    }

    /** Removes the Stats menu entry created by v0.2.0 (kept so uninstall cleans it up). */
    private function uninstallTab(): bool
    {
        $id = (int) Tab::getIdFromClassName(self::TAB_CLASS);
        if (!$id) {
            return true;
        }
        return (bool) (new Tab($id))->delete();
    }

    /**
     * Runs on every front-office page. Starts a new visit when there is none
     * (or the last one timed out) and records campaign touchpoints.
     */
    public function hookDisplayHeader($params)
    {
        if ($this->isBot()) {
            return;
        }

        $cookie = Context::getContext()->cookie;
        $referrer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $ownHost = (string) parse_url(Tools::getShopDomainSsl(false), PHP_URL_HOST);
        $now = time();

        $touch = ChannelClassifier::fromRequest($_GET, $referrer, $ownHost);
        if ($touch !== null) {
            $touch['landing_page'] = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
            $touch['ts'] = $now;
        }

        $sid = (string) ($cookie->{self::COOKIE_SESSION} ?? '');
        $seen = (int) ($cookie->{self::COOKIE_SESSION_SEEN} ?? 0);
        if ($sid === '' || ($now - $seen) > self::SESSION_TIMEOUT) {
            $sid = bin2hex(random_bytes(16));
            $this->createSession($sid, $touch, $referrer);
        }

        $cookie->{self::COOKIE_SESSION} = $sid;
        $cookie->{self::COOKIE_SESSION_SEEN} = $now;

        if ($touch !== null) {
            $this->storeTouchpoint($touch);
        }

        $cookie->write();
    }

    /**
     * Links the visit and its attribution to the new order.
     */
    public function hookActionValidateOrder($params)
    {
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }

        $cookie = Context::getContext()->cookie;
        $sid = (string) ($cookie->{self::COOKIE_SESSION} ?? '');
        $touchpoints = $this->readTouchpoints();
        $first = $touchpoints[0] ?? null;
        $last = $touchpoints ? end($touchpoints) : null;

        Db::getInstance()->insert('attribution_order', [
            'id_order' => (int) $order->id,
            'id_customer' => (int) $order->id_customer,
            'id_session' => pSQL($sid),
            'first_channel' => pSQL($first['channel'] ?? 'direct'),
            'first_source' => pSQL($first['source'] ?? ''),
            'first_medium' => pSQL($first['medium'] ?? ''),
            'first_campaign' => pSQL($first['campaign'] ?? ''),
            'last_channel' => pSQL($last['channel'] ?? 'direct'),
            'last_source' => pSQL($last['source'] ?? ''),
            'last_medium' => pSQL($last['medium'] ?? ''),
            'last_campaign' => pSQL($last['campaign'] ?? ''),
            'touchpoints' => pSQL(json_encode($touchpoints), true),
            'total_price' => (float) $order->total_paid_tax_incl,
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, Db::REPLACE);

        if ($sid !== '') {
            // Only the first order of a visit is linked to that visit.
            Db::getInstance()->update(
                'attribution_session',
                ['id_order' => (int) $order->id],
                'id_session = "' . pSQL($sid) . '" AND id_order = 0'
            );
        }
    }

    private function createSession(string $sid, ?array $touch, string $referrer): void
    {
        Db::getInstance()->insert('attribution_session', [
            'id_session' => pSQL($sid),
            'channel' => pSQL($touch['channel'] ?? 'direct'),
            'source' => pSQL($touch['source'] ?? ''),
            'medium' => pSQL($touch['medium'] ?? ''),
            'campaign' => pSQL($touch['campaign'] ?? ''),
            'referrer' => pSQL($touch['referrer'] ?? ''),
            'landing_page' => pSQL(substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500)),
            'id_order' => 0,
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
    }

    private function storeTouchpoint(array $touch): void
    {
        $cookie = Context::getContext()->cookie;
        $list = $this->readTouchpoints();

        // Avoid duplicating the same channel consecutively (page reloads, etc.).
        $lastItem = $list ? end($list) : null;
        if ($lastItem && $lastItem['channel'] === $touch['channel'] && $lastItem['campaign'] === $touch['campaign']) {
            return;
        }

        $list[] = $touch;
        $list = array_slice($list, -self::MAX_TOUCHPOINTS);

        $cookie->{self::COOKIE_TOUCH} = json_encode($list);
    }

    private function readTouchpoints(): array
    {
        $cookie = Context::getContext()->cookie;
        $raw = $cookie->{self::COOKIE_TOUCH} ?? '';
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    private function isBot(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|preview|headless|monitor/i', $ua);
    }
}
