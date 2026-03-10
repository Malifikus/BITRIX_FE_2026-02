<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

use Bitrix\Main\Localization\Loc;

$APPLICATION->SetTitle('Журнал синхронизации');

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/b2b.integration/logs/sync.log';

// Обработка очистки лога
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['clear'] == 'Y' && check_bitrix_sessid()) {
    file_put_contents($logFile, '');
    CAdminMessage::ShowNote('Лог очищен');
}

// Чтение лога
$logContent = file_exists($logFile) ? file_get_contents($logFile) : 'Лог-файл не найден';
$logLines = explode("\n", $logContent);
$logLines = array_reverse($logLines); // последние записи сверху
$logContent = implode("\n", array_slice($logLines, 0, 1000)); // последние 1000 строк
?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="clear" value="Y">
    <input type="submit" value="Очистить лог" class="adm-btn" onclick="return confirm('Очистить лог?')">
</form>

<br>

<div style="background: #f5f5f5; border: 1px solid #ccc; padding: 10px; font-family: monospace; white-space: pre; overflow: auto; max-height: 600px;">
<?= htmlspecialchars($logContent) ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>