<?php
// /home/bitrix/www/local/php_interface/s1/BotManager.php

class BotManager {
    
    private static $config = [
        'group_id' => 708,       // ID Группы
        'trigger_stage' => 6791, // Стадия ответа от Бота
        'bot_user_id' => 2395,   // ID Бота
        'human_stage' => 6787,   // Стадия "Позвать человека"
        'done_status' => 5,      // Статус "Завершена"
    ];

    public static function init() {
        AddEventHandler("tasks", "OnTaskUpdate", [self::class, 'onTaskUpdate']);
        global $DB;
        if (!$DB->Query("SELECT ID FROM b_agent WHERE NAME='BotManager::processButtonsAgent();' AND ACTIVE='Y' LIMIT 1")->Fetch()) {
            CAgent::AddAgent("BotManager::processButtonsAgent();", "main", "N", 30);
        }
    }

    public static function onTaskUpdate($taskId, $arFields, $arOriginalFields) {
        $newStage = $arFields['STAGE_ID'] ?? null;
        $oldStage = $arOriginalFields['STAGE_ID'] ?? null;
        $groupId = (int)($arOriginalFields['GROUP_ID'] ?? 0);

        if ($groupId !== self::$config['group_id']) return;
        if ($newStage == self::$config['trigger_stage'] && $newStage != $oldStage) {
            self::sendHelpMessage($taskId, $arFields, $arOriginalFields);
        }
    }

    private static function sendHelpMessage($taskId, $arFields, $arOriginalFields) {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/s1/bot_manager.log';
        
        if (!CModule::IncludeModule('tasks') || !CModule::IncludeModule('im')) return;

        try {
            $chatId = (int)($arFields['META:PREV_FIELDS']['CHAT_ID'] ?? $arOriginalFields['CHAT_ID'] ?? 0);
            if (!$chatId) {
                $t = \Bitrix\Tasks\Internals\TaskTable::getById($taskId)->fetch();
                if ($t) $chatId = (int)($t['CHAT_ID'] ?? 0);
            }
            if (!$chatId) return;

            // Фикс связи CHAT_ID
            $curTask = \Bitrix\Tasks\Internals\TaskTable::getById($taskId)->fetch();
            if ($curTask && (int)($curTask['FORUM_TOPIC_ID'] ?? 0) !== $chatId) {
                global $DB;
                $DB->Query("UPDATE b_tasks SET FORUM_TOPIC_ID = {$chatId} WHERE ID = {$taskId}");
            }

            $botId = self::$config['bot_user_id'];
            $acc = $curTask['ACCOMPLICES'] ?? [];
            if (!is_array($acc)) $acc = [];
            if (!in_array($botId, $acc)) {
                $acc[] = $botId;
                $taskObj = new CTasks;
                $taskObj->Update($taskId, ['ACCOMPLICES' => $acc]);
            }

            // Поиск по БЗ
            global $DB;
            $kbPaths = []; 
            $resSites = $DB->Query("SELECT ID, CODE FROM b_landing_site WHERE TYPE='KNOWLEDGE'");
            while ($site = $resSites->Fetch()) {
                $code = $site['CODE'];
                $path = (strpos($code, '/knowledge/') === false) ? '/knowledge' . $code : $code;
                $kbPaths[$site['ID']] = $path;
            }
            $defaultPath = !empty($kbPaths) ? reset($kbPaths) : '/kb/';
            
            $foundBlocks = [];
            $rawText = trim(($curTask['DESCRIPTION'] ?? '') . ' ' . ($curTask['TITLE'] ?? ''));
            if (!empty($rawText)) {
                try {
                    $cleanText = strip_tags($rawText);
                    $cleanText = preg_replace('/\[.*?\]/', '', $cleanText);
                    $cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));
                    try { $DB->Query("ALTER TABLE b_landing_block ADD FULLTEXT INDEX ft_s (SEARCH_CONTENT)"); } catch (\Throwable $e) {}
                    
                    $safeQuery = $DB->ForSql(mb_substr($cleanText, 0, 50));
                    $sql = "SELECT B.ANCHOR, B.SEARCH_CONTENT, B.LID, MATCH(B.SEARCH_CONTENT) AGAINST ('{$safeQuery}' IN BOOLEAN MODE) as relevance
                            FROM b_landing_block B
                            WHERE MATCH(B.SEARCH_CONTENT) AGAINST ('{$safeQuery}' IN BOOLEAN MODE)
                            AND B.DELETED = 'N' AND B.ANCHOR IS NOT NULL 
                            ORDER BY relevance DESC LIMIT 3";
                    
                    $res = $DB->Query($sql);
                    while ($row = $res->Fetch()) {
                        $lid = $row['LID'];
                        $path = isset($kbPaths[$lid]) ? $kbPaths[$lid] : $defaultPath;
                        $content = strip_tags($row['SEARCH_CONTENT']);
                        $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 100);
                        if (mb_strlen($content) > 100) $snippet .= "...";
                        $foundBlocks[] = ['snippet' => $snippet, 'link' => "{$path}#{$row['ANCHOR']}"];
                    }
                } catch (\Throwable $e) { /* ignore */ }
            }

            $text = "🤖 **Привет! Я Робот Электро, Бот Поддержки.**\nВот что нашел:\n\n";
            if ($foundBlocks) {
                foreach ($foundBlocks as $i => $b) {
                    $text .= ($i+1) . ". {$b['snippet']}\n   👉 [url={$b['link']}]подробно[/url]\n\n";
                }
            } else {
                $text .= "Ничего не найдено. [url={$defaultPath}]В базу знаний[/url]\n\n";
            }
            $text .= "Нажмите кнопку:";

            $keyboard = [
                'BUTTONS' => [
                    ['TEXT' => 'Помогло', 'ACTION' => 'SEND', 'ACTION_VALUE' => '/like'],
                    ['TEXT' => 'Позвать Битриксолога', 'ACTION' => 'SEND', 'ACTION_VALUE' => '/human']
                ]
            ];

            CIMChat::AddMessage([
                'TO_CHAT_ID' => $chatId,
                'FROM_USER_ID' => $botId,
                'MESSAGE' => $text,
                'SYSTEM' => 'N',
                'KEYBOARD' => $keyboard
            ]);

        } catch (\Throwable $e) {
            file_put_contents($logFile, date('H:i:s') . " [TASK ERR] " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    public static function processButtonsAgent() {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/s1/bot_manager.log';
        $flagFile = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/s1/processed_buttons.log';
        
        $lockFile = $flagFile . '.lock';
        $fp = fopen($lockFile, 'w');
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            return "BotManager::processButtonsAgent();";
        }

        try {
            global $DB;
            if (!CModule::IncludeModule('im') || !CModule::IncludeModule('tasks')) {
                return "BotManager::processButtonsAgent();";
            }

            $processedIds = [];
            if (file_exists($flagFile)) {
                $content = trim(file_get_contents($flagFile));
                if (!empty($content)) $processedIds = array_map('intval', explode(',', $content));
            }

            $sqlMsg = "SELECT M.ID, M.CHAT_ID, M.AUTHOR_ID, M.MESSAGE 
                       FROM b_im_message M
                       WHERE M.MESSAGE IN ('/like', '/human') 
                       AND M.DATE_CREATE >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                       ORDER BY M.ID DESC LIMIT 50";
                    
            $resMsg = $DB->Query($sqlMsg);
            $newProcessedIds = $processedIds;

            while ($row = $resMsg->Fetch()) {
                $msgId = intval($row['ID']);
                if (in_array($msgId, $processedIds)) continue;

                $chatId = intval($row['CHAT_ID']);
                $authorId = intval($row['AUTHOR_ID']);
                $command = trim($row['MESSAGE']);

                $sqlTask = "SELECT ID, GROUP_ID, STATUS, STAGE_ID FROM b_tasks WHERE FORUM_TOPIC_ID = {$chatId} LIMIT 1";
                $resTask = $DB->Query($sqlTask);
                $taskData = $resTask->Fetch();

                if (!$taskData || intval($taskData['GROUP_ID']) !== self::$config['group_id']) {
                    $newProcessedIds[] = $msgId;
                    continue;
                }

                $taskId = intval($taskData['ID']);
                $currentStatus = intval($taskData['STATUS']);
                $currentStage = intval($taskData['STAGE_ID']);

                // Если закрыто или уже вызван специалист - игнорируем
                if ($currentStatus == self::$config['done_status'] || $currentStage == self::$config['human_stage']) {
                    $newProcessedIds[] = $msgId;
                    continue;
                }

                $taskObj = new CTasks;
                $replyMsg = "";
                $success = false;

                if ($command === '/like') {
                    if ($taskObj->Update($taskId, ['STATUS' => self::$config['done_status'], 'MODIFIED_BY' => $authorId])) {
                        $replyMsg = "Отлично! Задача закрыта. 👍";
                        $success = true;
                    }
                } elseif ($command === '/human') {
                    if ($taskObj->Update($taskId, ['STAGE_ID' => self::$config['human_stage'], 'MODIFIED_BY' => $authorId])) {
                        $replyMsg = "Принято. Передаю задачу специалисту.";
                        $success = true;
                    }
                }

                if ($success && $replyMsg) {
                    CIMChat::AddMessage([
                        'TO_CHAT_ID' => $chatId, 
                        'FROM_USER_ID' => self::$config['bot_user_id'], 
                        'MESSAGE' => $replyMsg, 
                        'SYSTEM' => 'N'
                    ]);
                    file_put_contents($logFile, date('H:i:s') . " [AGENT] OK Task {$taskId}: {$replyMsg}\n", FILE_APPEND);
                }

                $newProcessedIds[] = $msgId;
            }

            if (count($newProcessedIds) > 1000) $newProcessedIds = array_slice($newProcessedIds, -1000);
            file_put_contents($flagFile, implode(',', $newProcessedIds));

        } catch (\Throwable $e) {
            file_put_contents($logFile, date('H:i:s') . " [AGENT ERR] " . $e->getMessage() . "\n", FILE_APPEND);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return "BotManager::processButtonsAgent();";
    }
}

BotManager::init();