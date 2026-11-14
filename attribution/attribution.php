<?php
/**
 * Attribution – captures the acquisition source of each visit and links it to orders.
 * Target: PrestaShop 8.x (PS 1.7 adaptation to follow).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/ChannelClassifier.php';

class Attribution extends Module
{
    const COOKIE_KEY = 'attr_touch';
    const COOKIE_TTL_DAYS = 90;
    const MAX_TOUCHPOINTS = 20;

    public function __construct()
    {
        $this->name = 'attribution';
        $this->tab = 'analytics_stats';
        $this->version = '0.1.0';
        $this->author = 'TBD';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Attribution', [], 'Modules.Attribution.Admin');
        $this->description = $this->trans('Tracks traffic sources and links them to orders.', [], 'Modules.Attribution.Admin');
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
        return $this->runSql(__DIR__ . '/sql/uninstall.php') && parent::uninstall();
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
     * Runs on every front-office page: records the touchpoint when the visitor
     * arrives with campaign parameters or from an external referrer.
     */
    public function hookDisplayHeader($params)
    {
        if (Context::getContext()->controller === null) {
            return;
        }

        $request = $_GET;
        $referrer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $ownHost = parse_url(Tools::getShopDomainSsl(false), PHP_URL_HOST);

        $touch = ChannelClassifier::fromRequest($request, $referrer, (string) $ownHost);
        if ($touch === null) {
            return; // Direct/internal navigation: keep existing attribution as is.
        }

        $touch['landing_page'] = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
        $touch['ts'] = time();

        $this->storeTouchpoint($touch);
    }

    /**
     * Links the attribution captured during the session to the new order.
     */
    public function hookActionValidateOrder($params)
    {
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }

        $touchpoints = $this->readTouchpoints();
        $first = $touchpoints[0] ?? null;
        $last = $touchpoints ? end($touchpoints) : null;

        Db::getInstance()->insert('attribution_order', [
            'id_order' => (int) $order->id,
            'id_customer' => (int) $order->id_customer,
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

        $cookie->{self::COOKIE_KEY} = json_encode($list);
        $cookie->write();
    }

    private function readTouchpoints(): array
    {
        $cookie = Context::getContext()->cookie;
        $raw = $cookie->{self::COOKIE_KEY} ?? '';
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }
}
