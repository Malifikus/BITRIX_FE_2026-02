<?php
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $APPLICATION;
if ($APPLICATION->GetGroupRight('custom.flowgroups') < 'R')
    return false;

$aMenu = [
    [
        'parent_menu' => 'global_menu_services',
        'sort' => 100,
        'text' => 'Группировка потоков',
        'title' => 'Управление группировкой потоков',
        'icon' => 'util_menu_icon',
        'page_icon' => 'util_page_icon',
        'items_id' => 'menu_flow_groups',
        'items' => [
            [
                'text' => 'Настройки групп',
                'title' => 'Создание и редактирование групп',
                'url' => 'flow_groups.php?lang=' . LANGUAGE_ID,
                'icon' => 'settings_menu_icon',
            ],
        ]
    ]
];

return $aMenu;