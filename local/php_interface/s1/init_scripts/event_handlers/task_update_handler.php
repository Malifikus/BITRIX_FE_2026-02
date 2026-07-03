<?php
// Файл: /local/php_interface/init_scripts/event_handlers/task_update_handler.php

$logFile = __DIR__ . '/handler_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " Файл обработчика загружен (init)\n", FILE_APPEND);

AddEventHandler("tasks", "OnTaskUpdate", "MyTaskUpdateHandler");

function MyTaskUpdateHandler($taskId, $arFields, $arOriginalFields) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " Вызван обработчик для задачи {$taskId}\n", FILE_APPEND);

    // Проверка изменения описания
    if (!isset($arFields['DESCRIPTION']) || !isset($arOriginalFields['DESCRIPTION'])) {
        file_put_contents($logFile, " -> Нет DESCRIPTION\n", FILE_APPEND);
        return;
    }

    $oldDesc = trim($arOriginalFields['DESCRIPTION']);
    $newDesc = trim($arFields['DESCRIPTION']);

    if ($oldDesc === $newDesc) {
        file_put_contents($logFile, " -> Описание не изменилось\n", FILE_APPEND);
        return;
    }

    file_put_contents($logFile, " -> Описание изменено: '$oldDesc' -> '$newDesc'\n", FILE_APPEND);

    // Подключаем модули
    if (!CModule::IncludeModule('tasks') || !CModule::IncludeModule('im')) {
        file_put_contents($logFile, " -> Модули tasks/im не подключены\n", FILE_APPEND);
        return;
    }

    global $USER;
    $userId = $USER->GetID() ?: 1;
    file_put_contents($logFile, " -> ID пользователя: {$userId}\n", FILE_APPEND);

    $message = "**Изменение описания задачи**\n\n";
    $message .= "**Было:**\n" . ($oldDesc ?: '(пусто)') . "\n\n";
    $message .= "**Стало:**\n" . ($newDesc ?: '(пусто)');

    try {
        // Ищем CHAT_ID
        $chatId = null;
        
        // В PREV_FIELDS
        if (isset($arFields['META:PREV_FIELDS']['CHAT_ID']) && $arFields['META:PREV_FIELDS']['CHAT_ID'] > 0) {
            $chatId = $arFields['META:PREV_FIELDS']['CHAT_ID'];
            file_put_contents($logFile, " -> CHAT_ID найден в META:PREV_FIELDS: {$chatId}\n", FILE_APPEND);
        }
        // В arOriginalFields
        elseif (isset($arOriginalFields['CHAT_ID']) && $arOriginalFields['CHAT_ID'] > 0) {
            $chatId = $arOriginalFields['CHAT_ID'];
            file_put_contents($logFile, " -> CHAT_ID найден в arOriginalFields: {$chatId}\n", FILE_APPEND);
        }
        // В БД
        else {
            file_put_contents($logFile, " -> CHAT_ID не найден в переданных данных, лезем в БД...\n", FILE_APPEND);
            $taskData = \Bitrix\Tasks\Internals\TaskTable::getById($taskId)->fetch();
            if ($taskData && !empty($taskData['CHAT_ID'])) {
                $chatId = $taskData['CHAT_ID'];
                file_put_contents($logFile, " -> CHAT_ID найден в БД: {$chatId}\n", FILE_APPEND);
            }
        }

        if (!$chatId) {
            file_put_contents($logFile, " -> CHAT_ID НЕ НАЙДЕН НИГДЕ. Выход.\n", FILE_APPEND);
            return;
        }

        // Отправляем сообщение в чат задачи
        $result = CIMChat::AddMessage([
            'TO_CHAT_ID' => $chatId,
            'FROM_USER_ID' => $userId,
            'MESSAGE' => $message,
            'SYSTEM' => 'N',
            'SKIP_COMMAND' => 'Y',
        ]);

        file_put_contents($logFile, " -> Результат отправки: " . ($result ?: 'false') . "\n", FILE_APPEND);

    } catch (\Exception $e) {
        file_put_contents($logFile, " -> ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n", FILE_APPEND);
    } catch (\Throwable $e) {
        file_put_contents($logFile, " -> ОШИБКА: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}