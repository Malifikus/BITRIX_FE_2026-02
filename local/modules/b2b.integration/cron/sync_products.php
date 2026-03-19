#!/usr/bin/env php
<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use B2b\Integration\Sync\ProductSync;

// Запуск
$sync = new ProductSync();
$sync->run();