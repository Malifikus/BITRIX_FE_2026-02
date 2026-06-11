<?php
if (!check_bitrix_sessid()) return;
echo CAdminMessage::ShowNote('Модуль установлен');
?>
<form action="/bitrix/admin/partner_modules.php">
    <input type="submit" value="Вернуться">
</form>