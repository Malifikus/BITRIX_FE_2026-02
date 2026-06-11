<?php
use Bitrix\Main\Loader;

$arClasses = [
    'Custom\\FlowGroups\\FlowGroupsTable' => 'classes/general/FlowGroupsTable.php',
    'Custom\\FlowGroups\\Handler' => 'classes/general/Handler.php',
];

Loader::registerAutoLoadClasses('custom.flowgroups', $arClasses);