#!/usr/bin/env php
<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use B2b\Integration\Sync\CompanyContactSync;

if (!Loader::includeModule('b2b.integration')) {
    die('Модуль b2b.integration не установлен');
}

$sync = new CompanyContactSync();
$sync->run();