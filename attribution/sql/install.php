<?php
return [
    'CREATE TABLE IF NOT EXISTS `PREFIX_attribution_order` (
        `id_order` INT UNSIGNED NOT NULL,
        `id_customer` INT UNSIGNED NOT NULL,
        `first_channel` VARCHAR(64) NOT NULL DEFAULT \'direct\',
        `first_source` VARCHAR(255) NOT NULL DEFAULT \'\',
        `first_medium` VARCHAR(255) NOT NULL DEFAULT \'\',
        `first_campaign` VARCHAR(255) NOT NULL DEFAULT \'\',
        `last_channel` VARCHAR(64) NOT NULL DEFAULT \'direct\',
        `last_source` VARCHAR(255) NOT NULL DEFAULT \'\',
        `last_medium` VARCHAR(255) NOT NULL DEFAULT \'\',
        `last_campaign` VARCHAR(255) NOT NULL DEFAULT \'\',
        `touchpoints` TEXT NULL,
        `total_price` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `date_add` DATETIME NOT NULL,
        PRIMARY KEY (`id_order`),
        KEY `idx_first_channel` (`first_channel`),
        KEY `idx_last_channel` (`last_channel`),
        KEY `idx_date_add` (`date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4',
];
