#!/usr/bin/env php
<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use B2b\Integration\Sync\CompanyContactSync;

$sync = new CompanyContactSync();
$sync->run();  // Определение режима синхронизации