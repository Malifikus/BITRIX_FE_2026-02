<?php
namespace Custom\FlowGroups;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class Handler
{
    public static function onBeforeProlog()
    {
        $request = \Bitrix\Main\Context::getCurrent()->getRequest();
        $uri = $request->getRequestUri();
        
        if (strpos($uri, '/tasks/flow/') === false) {
            return;
        }
        
        // Подключаем CSS и JS через API Bitrix
        $cssFile = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/custom.flowgroups/css/flowgroups.css';
        $jsFile = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/custom.flowgroups/js/flowgroups.js';
        
        if (file_exists($cssFile)) {
            $cssUrl = '/local/modules/custom.flowgroups/css/flowgroups.css?v=' . filemtime($cssFile);
            \Bitrix\Main\Page\Asset::getInstance()->addCss($cssUrl);
        }
        if (file_exists($jsFile)) {
            $jsUrl = '/local/modules/custom.flowgroups/js/flowgroups.js?v=' . filemtime($jsFile);
            \Bitrix\Main\Page\Asset::getInstance()->addJs($jsUrl);
        }
        
        // Добавляем inline CSS для скрытия таблицы до группировки
        $hideCss = '<style>
            .main-grid-table.custom-flowgroups-loading {
                opacity: 0 !important;
                visibility: hidden !important;
                height: 0 !important;
                overflow: hidden !important;
            }
            .main-grid-table.custom-flowgroups-ready {
                opacity: 1 !important;
                visibility: visible !important;
                height: auto !important;
                overflow: visible !important;
                transition: opacity 0.3s ease;
            }
        </style>';
        \Bitrix\Main\Page\Asset::getInstance()->addString($hideCss);
        
        // Загружаем модуль main для работы с файлами
        Loader::includeModule('main');
        
        $connection = Application::getConnection();
        $groups = [];
        
        try {
            $res = $connection->query("SELECT * FROM b_custom_flow_groups WHERE ACTIVE = 'Y' ORDER BY SORT ASC");
            while ($row = $res->fetch()) {
                $row['FLOW_IDS'] = unserialize($row['FLOW_IDS']);
                if (!empty($row['FLOW_IDS'])) {
                    // Получаем данные пользователя
                    if ($row['OWNER_ID'] && $row['OWNER_ID'] > 0) {
                        $userRes = $connection->query("SELECT NAME, LAST_NAME, PERSONAL_PHOTO FROM b_user WHERE ID = " . (int)$row['OWNER_ID']);
                        if ($user = $userRes->fetch()) {
                            $row['OWNER_NAME'] = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                            if (empty($row['OWNER_NAME'])) {
                                $row['OWNER_NAME'] = 'Пользователь ' . $row['OWNER_ID'];
                            }
                            // Получаем аватарку через CFile
                            if ($user['PERSONAL_PHOTO'] && $user['PERSONAL_PHOTO'] > 0) {
                                $file = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
                                if ($file && isset($file['SRC'])) {
                                    $row['OWNER_AVATAR'] = $file['SRC'];
                                }
                            }
                        }
                    }
                    $groups[] = $row;
                }
            }
        } catch (\Exception $e) {
            return;
        }
        
        if (empty($groups)) {
            return;
        }
        
        // Очищаем данные перед JSON
        $cleanGroups = [];
        foreach ($groups as $g) {
            // Обрезаем описание до 26 символов
            $description = $g['DESCRIPTION'];
            if (mb_strlen($description) > 26) {
                $description = mb_substr($description, 0, 26) . '…';
            }
            $cleanGroups[] = [
                'ID' => $g['ID'],
                'NAME' => $g['NAME'],
                'DESCRIPTION' => $description,
                'SORT' => $g['SORT'],
                'FLOW_IDS' => $g['FLOW_IDS'],
                'EXPANDED' => $g['EXPANDED'],
                'ACTIVE' => $g['ACTIVE'],
                'OWNER_ID' => $g['OWNER_ID'],
                'OWNER_NAME' => $g['OWNER_NAME'] ?? '',
                'OWNER_AVATAR' => $g['OWNER_AVATAR'] ?? '',
            ];
        }
        
        // Выводим данные в head страницы и добавляем скрипт инициализации
        $script = '<script>
            window.FlowGroupsData = ' . json_encode($cleanGroups, JSON_UNESCAPED_UNICODE) . ';
            // Флаг, что модуль загружен
            window.FlowGroupsModuleReady = false;
            // Функция для отображения таблицы после группировки
            window.FlowGroupsShowTable = function() {
                var table = document.querySelector(".main-grid-table");
                if (table) {
                    table.classList.remove("custom-flowgroups-loading");
                    table.classList.add("custom-flowgroups-ready");
                }
                window.FlowGroupsModuleReady = true;
            };
            // Fallback: если через 5 секунд таблица не показана, всё равно показываем
            setTimeout(function() {
                if (!window.FlowGroupsModuleReady) {
                    window.FlowGroupsShowTable();
                }
            }, 5000);
        </script>';
        \Bitrix\Main\Page\Asset::getInstance()->addString($script);
    }
    
    public static function onEpilog()
    {
        // Оставляем для обратной совместимости, но можно оставить пустым
        // или дублировать логику, если нужно
    }
}
?>