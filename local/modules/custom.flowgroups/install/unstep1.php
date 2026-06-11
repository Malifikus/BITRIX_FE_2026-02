<?php
if (!check_bitrix_sessid()) return;
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="custom.flowgroups">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <?= CAdminMessage::ShowMessage('Внимание! Модуль будет полностью удалён') ?>
    <input type="submit" name="inst" value="Удалить модуль">
</form>