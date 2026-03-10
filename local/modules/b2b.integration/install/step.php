<?php
if(!check_bitrix_sessid()) return;
IncludeModuleLangFile(__FILE__);

if($ex = $APPLICATION->GetException())
    echo CAdminMessage::ShowMessage(array(
        "TYPE" => "ERROR",
        "MESSAGE" => $ex->GetString(),
        "HTML" => true
    ));
else
    echo CAdminMessage::ShowNote('Модуль успешно установлен');
?>
<form action="<?=$APPLICATION->GetCurPage()?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>