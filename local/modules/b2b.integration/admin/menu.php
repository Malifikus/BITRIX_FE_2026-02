<?php
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $APPLICATION;
if ($APPLICATION->GetGroupRight('b2b.integration') < 'R')
    return false;

// Создаем пункт в разделе "Сервисы"
$aMenu = [
    [
        'parent_menu' => 'global_menu_services',
        'sort' => 100,
        'text' => 'B2B Интеграция',
        'title' => 'Интеграция с порталом b2b motion',
        'icon' => 'clouds_menu_icon',
        'page_icon' => 'clouds_page_icon',
        'items_id' => 'menu_b2b_integration_root',
        'items' => [
            [
                'text' => 'Настройки подключения',
                'title' => 'Настройки MySQL и синхронизации',
                'url' => 'b2b_integration_options.php?lang=' . LANGUAGE_ID,
                'icon' => 'settings_menu_icon',
            ],
            [
                'text' => 'Ручной запуск',
                'title' => 'Запустить синхронизацию вручную',
                'url' => 'b2b_integration_manual.php?lang=' . LANGUAGE_ID,
                'icon' => 'start_menu_icon',
            ],
            [
                'text' => 'Журнал синхронизации',
                'title' => 'Просмотр логов работы модуля',
                'url' => 'b2b_integration_log.php?lang=' . LANGUAGE_ID,
                'icon' => 'logging_menu_icon',
            ],
        ]
    ]
];

return $aMenu;