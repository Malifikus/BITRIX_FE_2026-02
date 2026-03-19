<?php
// Определяем где находится модуль и подключаем файл
if (is_dir($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/b2b.integration/")) {
    require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/b2b.integration/admin/b2b_integration_manual.php");
} elseif (is_dir($_SERVER["DOCUMENT_ROOT"] . "/local/modules/b2b.integration/")) {
    require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/b2b.integration/admin/b2b_integration_manual.php");
}