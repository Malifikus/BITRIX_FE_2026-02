<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

$moduleId = 'b2b.integration';
Loader::includeModule($moduleId);

$APPLICATION->SetTitle('Ручной запуск синхронизации');

// Обработка запуска
if ($_SERVER['REQUEST_METHOD'] == 'POST' && check_bitrix_sessid()) {
    $type = $_POST['sync_type'] ?? 'orders';
    $mode = $_POST['sync_mode'] ?? 'incremental';
    $date = $_POST['sync_date'] ?? date('Y-m-d', strtotime('-7 days'));
    
    $result = '';
    
    try {
        switch ($type) {
            case 'orders':
                $sync = new \B2b\Integration\Sync\OrderSync();
                break;
            case 'products':
                $sync = new \B2b\Integration\Sync\ProductSync();
                break;
            case 'company_contact':
                $sync = new \B2b\Integration\Sync\CompanyContactSync();
                break;
        }
        
        if ($mode == 'full') {
            $sync->fullImport();
            $result = 'Полный импорт запущен';
        } else {
            $sync->run();
            $result = 'Инкрементальная синхронизация запущена с даты ' . $date;
        }
        
        CAdminMessage::ShowNote('Синхронизация успешно запущена');
    } catch (\Exception $e) {
        CAdminMessage::ShowMessage('Ошибка: ' . $e->getMessage());
    }
}
?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    
    <table class="adm-detail-content-table edit-table">
        <tr>
            <td width="40%">Тип синхронизации:</td>
            <td width="60%">
                <select name="sync_type">
                    <option value="orders">Заказы</option>
                    <option value="company_contact">Компании + Контакты</option>
                    <option value="products">Товары</option>
                </select>
            </td>
        </tr>
        <tr>
            <td>Режим:</td>
            <td>
                <select name="sync_mode">
                    <option value="incremental">Инкрементальный</option>
                    <option value="full">Полный импорт</option>
                </select>
            </td>
        </tr>
        <tr>
            <td>Дата начала (для инкрементального):</td>
            <td>
                <input type="text" name="sync_date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" size="10">
                <span style="color:#666">Формат: ГГГГ-ММ-ДД</span>
            </td>
        </tr>
    </table>
    
    <br>
    <input type="submit" value="Запустить синхронизацию" class="adm-btn-save">
</form>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>