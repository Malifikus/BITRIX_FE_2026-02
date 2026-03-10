<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

class b2b_integration extends CModule
{
    public $MODULE_ID = 'b2b.integration';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'Интеграция с B2B';
    public $MODULE_DESCRIPTION = 'Синхронизация заказов, компаний, контактов и товаров с порталом b2b motion';
    public $PARTNER_NAME = 'Влад Малиф';
    public $PARTNER_URI = 'https://github.com/Malifikus';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $APPLICATION;
        
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallDB();
        $this->InstallFiles();
        
        $APPLICATION->IncludeAdminFile('Установка модуля', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        
        // Показываем страницу с подтверждением удаления модуля
        $APPLICATION->IncludeAdminFile('Удаление модуля', __DIR__ . '/unstep.php');
    }

    public function InstallDB()
    {
        require_once __DIR__ . '/../lib/Table/SyncStateTable.php';
        
        $entity = Base::getInstance('\B2b\Integration\Table\SyncStateTable');
        if (!$entity->getConnection()->isTableExists($entity->getDBTableName())) {
            $entity->createDbTable();
        }
    
        $connection = Application::getConnection();
    
        $check = $connection->query("SELECT COUNT(*) as CNT FROM b2b_sync_state")->fetch();
        if ($check['CNT'] == 0) {
            $sql = "INSERT INTO b2b_sync_state (ENTITY_TYPE, LAST_SYNC) VALUES 
                    ('order', '2000-01-01 00:00:00'),
                    ('company', '2000-01-01 00:00:00'),
                    ('contact', '2000-01-01 00:00:00')";
            $connection->queryExecute($sql);
        }
    
        // Три типа синхронизации
        Option::set($this->MODULE_ID, 'sync_company_contact', 'Y');
        Option::set($this->MODULE_ID, 'sync_orders', 'Y');
        Option::set($this->MODULE_ID, 'sync_products', 'Y');

        // Флаги первичного импорта
        Option::set($this->MODULE_ID, 'full_import_done', 'N');
        Option::set($this->MODULE_ID, 'full_import_products', 'N');

        // Общие настройки
        Option::set($this->MODULE_ID, 'deal_category_id', '35');
        Option::set($this->MODULE_ID, 'sync_mode', 'incremental');
        Option::set($this->MODULE_ID, 'sync_date_from', '');
        Option::set($this->MODULE_ID, 'sync_date_to', '');
    
        return true;
    }

    public function UnInstallDB()
    {
        // Получаем значение savedata из запроса
        $saveTables = isset($_REQUEST['savedata']) && $_REQUEST['savedata'] == 'Y';
        
        // Удаляем таблицу из бд если не стоит чек-бокс
        if (!$saveTables) {
            try {
                require_once __DIR__ . '/../lib/Table/SyncStateTable.php';
                
                if (class_exists('\B2b\Integration\Table\SyncStateTable')) {
                    $entity = Base::getInstance('\B2b\Integration\Table\SyncStateTable');
                    if ($entity && $entity->getConnection()->isTableExists($entity->getDBTableName())) {
                        $entity->getConnection()->dropTable($entity->getDBTableName());
                    }
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки
            }
        }
    
        Option::delete($this->MODULE_ID);
    
        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
        return true;
    }
}