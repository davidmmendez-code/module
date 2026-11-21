<?php
/**
 * Run with: php tests/HooksSafetyTest.php
 *
 * Loads the module with minimal PrestaShop stubs and checks that the storefront
 * hooks never throw, even when the database is down, and that they record data
 * when it is up. It does not replace testing in a real shop.
 */

// ---- Minimal PrestaShop stubs -------------------------------------------------
define('_PS_VERSION_', '8.2.0');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

class Module
{
    public $name;
    public $context;
    public function __construct() {}
    public function trans($s) { return $s; }
    public function registerHook($h) { return true; }
    public function install() { return true; }
}

class Order
{
    public $id = 1;
    public $id_customer = 7;
    public $total_paid_tax_incl = 50.0;
    public $conversion_rate = 1.0;
    public $id_cart = 0;
}

class Tools
{
    public static function getValue($k, $d = null) { return $_GET[$k] ?? $d; }
    public static function isSubmit($k) { return isset($_POST[$k]); }
    public static function getShopDomainSsl($ssl = false) { return 'shop.test'; } // bare host, as PrestaShop returns it
}

function pSQL($s, $htmlOK = false) { return addslashes((string) $s); }

class Db
{
    const INSERT = 0;
    const REPLACE = 1;
    const INSERT_IGNORE = 2;

    public static $fail = false;
    public static $calls = 0;
    public static $log = [];
    private static $instance;

    public static function getInstance()
    {
        return self::$instance ??= new self();
    }

    private function hit($what = '')
    {
        self::$calls++;
        self::$log[] = $what;
        if (self::$fail) {
            throw new RuntimeException('Database unavailable (simulated)');
        }
    }

    public function insert($t, $d, $n = false, $c = true, $type = 0, $p = true) { $this->hit($t); return true; }
    public function update($t, $d, $w = '', $l = 0, $n = false, $c = false) { $this->hit($t); return true; }
    public function execute($q) { $this->hit($q); return true; }
    public function getValue($q) { $this->hit($q); return false; }
    public function getRow($q) { $this->hit($q); return []; }
    public function executeS($q) { $this->hit($q); return []; }
}

class FakeCookie
{
    public $vars = [];
    public function __get($k) { return $this->vars[$k] ?? null; }
    public function __set($k, $v) { $this->vars[$k] = $v; }
    public function __isset($k) { return isset($this->vars[$k]); }
    public function write() { return true; }
}

class Context
{
    public $cookie;
    public $controller = 'index';
    public $cart = null;
    private static $instance;
    public static function getContext() { return self::$instance ??= new self(); }
}

// ---- Load the module -----------------------------------------------------------
require __DIR__ . '/../attribution/attribution.php';

// ---- Cases ----------------------------------------------------------------------
$failures = 0;
$check = function (string $name, bool $ok) use (&$failures) {
    echo ($ok ? 'ok:   ' : 'FAIL: ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
};

$visit = function (array $get, string $referrer = '') {
    $_GET = $get;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh)';
    $_SERVER['HTTP_REFERER'] = $referrer;
    $_SERVER['REQUEST_URI'] = '/?' . http_build_query($get);
    Context::getContext()->cookie = new FakeCookie();
};

$run = function (callable $fn) {
    try {
        $fn();
        return true;
    } catch (Throwable $e) {
        echo '      exception: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        return false;
    }
};

$module = new Attribution();

// 1. Visit with campaign, database working: no exception and data is written.
Db::$fail = false; Db::$calls = 0;
$visit(['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'verano']);
$check('visit with campaign, DB up: no exception', $run(fn() => $module->hookDisplayHeader([])));
$check('visit with campaign, DB up: data written', Db::$calls > 0);

// 2. Visit, database down: the page must still render.
Db::$fail = true; Db::$calls = 0;
$visit(['utm_source' => 'google', 'utm_medium' => 'cpc']);
$check('visit, DB down: no exception reaches the page', $run(fn() => $module->hookDisplayHeader([])));

// 3. Visit with no cookie object at all (CLI, webservice): must return quietly.
Db::$fail = false;
$_GET = []; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
Context::getContext()->cookie = null;
$check('no cookie object: no exception', $run(fn() => $module->hookDisplayHeader([])));

// 4. Bot traffic: ignored, no database work.
Db::$fail = false; Db::$calls = 0;
$visit(['utm_source' => 'google']);
$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
$check('bot visit: no exception', $run(fn() => $module->hookDisplayHeader([])));
$check('bot visit: no database work', Db::$calls === 0);

// 5. Order, database working: linked and written.
Db::$fail = false; Db::$calls = 0;
$visit(['utm_source' => 'google', 'utm_medium' => 'cpc']);
$module->hookDisplayHeader([]);
$check('order, DB up: no exception', $run(fn() => $module->hookActionValidateOrder(['order' => new Order()])));
$check('order, DB up: data written', Db::$calls > 0);

// 6. Order, database down: the order must not fail because of attribution.
Db::$fail = true; Db::$calls = 0;
$check('order, DB down: no exception reaches checkout', $run(fn() => $module->hookActionValidateOrder(['order' => new Order()])));

// 7. Order hook called without an order object: ignored quietly.
Db::$fail = false;
$check('missing order object: no exception', $run(fn() => $module->hookActionValidateOrder([])));

// 8. Visit with a cart: the cart is linked to the visit.
Db::$fail = false; Db::$calls = 0; Db::$log = [];
$visit(['utm_source' => 'google', 'utm_medium' => 'cpc']);
Context::getContext()->cart = (object) ['id' => 55];
$module->hookDisplayHeader([]);
$check('visit with cart: cart linked to visit', in_array('attribution_cart', Db::$log, true));

// 9. Order without cookie (gateway callback): found through the cart link.
Db::$fail = false; Db::$calls = 0; Db::$log = [];
Context::getContext()->cookie = new FakeCookie();
$order = new Order();
$order->id_cart = 55;
$check('order without cookie, DB up: no exception', $run(fn() => $module->hookActionValidateOrder(['order' => $order, 'cart' => (object) ['id' => 55]])));
$check('order without cookie: cart lookup performed', (bool) array_filter(Db::$log, fn($q) => is_string($q) && str_contains($q, 'attribution_cart')));
Context::getContext()->cart = null;

// 10. Visit from the shop's own pages: not a referral and no touchpoint stored.
Db::$fail = false;
$visit([], 'https://www.shop.test/categoria');
$module->hookDisplayHeader([]);
$check('own-domain referrer: no referral touchpoint', !isset(Context::getContext()->cookie->vars['attr_touch']));

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
