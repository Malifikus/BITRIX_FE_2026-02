<?php
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\DB\MysqlCommonConnection;

class custom_flowgroups extends CModule
{
    var $MODULE_ID = 'custom.flowgroups';
    var $MODULE_VERSION = '1.0.0';
    var $MODULE_VERSION_DATE = '2026-05-06';
    var $MODULE_NAME = 'Группировка потоков';
    var $MODULE_DESCRIPTION = 'Группировка потоков задач в разделы';
    var $PARTNER_NAME = 'Custom';
    var $PARTNER_URI = '';

    public function DoInstall()
    {
        global $APPLICATION;
        
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallDB();
        $this->InstallEvents();
        $this->InstallFiles();
        
        $APPLICATION->IncludeAdminFile('Установка', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION, $step;
        
        if ($step < 2) {
            $APPLICATION->IncludeAdminFile('Удаление', __DIR__ . '/unstep1.php');
            return;
        }
        
        $this->UnInstallEvents();
        $this->UnInstallDB();
        $this->UnInstallFiles();
        
        ModuleManager::unRegisterModule($this->MODULE_ID);
        
        $APPLICATION->IncludeAdminFile('Удаление', __DIR__ . '/unstep2.php');
    }

    public function InstallDB()
    {
        global $DB;
        
        $DB->Query("
            CREATE TABLE IF NOT EXISTS b_custom_flow_groups (
                ID INT(11) NOT NULL AUTO_INCREMENT,
                NAME VARCHAR(255) NOT NULL,
                DESCRIPTION TEXT NULL,
                SORT INT(11) DEFAULT 500,
                FLOW_IDS TEXT,
                EXPANDED CHAR(1) DEFAULT 'N',
                ACTIVE CHAR(1) DEFAULT 'Y',
                PRIMARY KEY (ID)
            )
        ");
        
        return true;
    }

    public function UnInstallDB()
    {
        global $DB;
        
        $DB->Query("DROP TABLE IF EXISTS b_custom_flow_groups");
        
        return true;
    }

    public function InstallEvents()
    {
        RegisterModuleDependences('main', 'OnBeforeProlog', $this->MODULE_ID, 'Custom\FlowGroups\Handler', 'onBeforeProlog', 100);
        return true;
    }

    public function UnInstallEvents()
    {
        UnRegisterModuleDependences('main', 'OnBeforeProlog', $this->MODULE_ID, 'Custom\FlowGroups\Handler', 'onBeforeProlog');
        return true;
    }

    public function InstallFiles()
    {
        // Копируем админку
        CopyDirFiles(
            __DIR__ . '/../admin',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin',
            true,
            true
        );
        
        // Копируем JS файлы
        CopyDirFiles(
            __DIR__ . '/../js',
            $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->MODULE_ID . '/js',
            true,
            true
        );
        
        // Копируем CSS файлы
        CopyDirFiles(
            __DIR__ . '/../css',
            $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->MODULE_ID . '/css',
            true,
            true
        );
        
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(
            __DIR__ . '/../admin',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin'
        );
        
        $adminFile = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/flow_groups.php';
        if (file_exists($adminFile)) {
            unlink($adminFile);
        }
        
        return true;
    }
}