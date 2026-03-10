<?php
use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('b2b.integration', [
    // Таблица
    'B2b\\Integration\\Table\\SyncStateTable' => 'lib/Table/SyncStateTable.php',
    
    // Хелпер
    'B2b\\Integration\\Helper\\DbHelper' => 'lib/Helper/DbHelper.php',
    'B2b\\Integration\\Helper\\Logger' => 'lib/Helper/Logger.php',
    'B2b\\Integration\\Helper\\BitrixHelper' => 'lib/Helper/BitrixHelper.php',
    
    // Синхронизации
    'B2b\\Integration\\Sync\\OrderSync' => 'lib/Sync/OrderSync.php',
    'B2b\\Integration\\Sync\\CompanyContactSync' => 'lib/Sync/CompanyContactSync.php',
    'B2b\\Integration\\Sync\\ProductSync' => 'lib/Sync/ProductSync.php',
]);