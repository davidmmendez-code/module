<?php
/**
 * Upgrade to 0.2.3: creates the cart → visit table. Runs when the module is updated
 * from the back office. Every statement is idempotent, and no data is removed.
 */
function upgrade_module_0_2_3($module)
{
    $queries = require __DIR__ . '/../sql/install.php';
    foreach ($queries as $query) {
        $query = str_replace('PREFIX_', _DB_PREFIX_, $query);
        if (!Db::getInstance()->execute($query)) {
            return false;
        }
    }
    return true;
}
