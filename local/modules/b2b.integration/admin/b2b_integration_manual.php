<?php
// Подключаем административный пролог Битрикс
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

// Подключаем класс для работы с модулями
use Bitrix\Main\Loader;

$moduleId = 'b2b.integration';
// Загружаем наш модуль, чтобы стали доступны его классы
Loader::includeModule($moduleId);

// Устанавливаем заголовок страницы
$APPLICATION->SetTitle('Ручной запуск');

// Проверяем, была ли отправлена форма и валидна ли сессия
if ($_SERVER['REQUEST_METHOD'] == 'POST' && check_bitrix_sessid()) {
    // Получаем выбранный тип синхронизации
    $type = $_POST['sync_type'] ?? 'product';
    // Получаем лимит из формы
    $limit = (int)($_POST['limit'] ?? 100);
    
    try {
        // Преобразуем company_contact в CompanyContact
        if ($type == 'company_contact') {
            $className = 'CompanyContactSync';
        } else {
            $className = ucfirst($type) . 'Sync';
        }
        
        // Формируем имя класса синхронизации
        $syncClass = "\\B2b\\Integration\\Sync\\" . $className;
        // Создаем экземпляр класса
        $sync = new $syncClass();
        
        // Передаём лимит если класс поддерживает
        if (method_exists($sync, 'setLimit')) {
            $sync->setLimit($limit);
        }
        
        // Запускаем синхронизацию
        $sync->run();
        
        // Показываем сообщение о запуске
        if ($limit > 0) {
            CAdminMessage::ShowNote("Запущено: $type (лимит $limit)");
        } else {
            CAdminMessage::ShowNote("Запущено: $type (без лимита)");
        }
        
    } catch (\Exception $e) {
        CAdminMessage::ShowMessage('Ошибка: ' . $e->getMessage());
    }
}
?>

<form method="post">
    <?= bitrix_sessid_post() ?>
    
    <table>
        <tr>
            <td>Тип синхронизации:</td>
            <td>
                <select name="sync_type">
                    <option value="order">Заказы</option>
                    <option value="company_contact">Компании + Контакты</option>
                    <option value="product">Товары</option>
                </select>
            </td>
        </tr>
        <tr>
            <td>Лимит (0 = без лимита):</td>
            <td>
                <input type="text" name="limit" value="100" size="10">
            </td>
        </tr>
        <tr>
            <td></td>
            <td>
                <input type="submit" value="Запустить" class="adm-btn-save">
            </td>
        </tr>
    </table>
</form>

<?php
// Подключаем эпилог административной части
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
?>