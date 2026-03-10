<?php
namespace B2b\Integration\Sync;

use B2b\Integration\Helper\DbHelper;
use B2b\Integration\Helper\Logger;
use B2b\Integration\Helper\BitrixHelper;
use B2b\Integration\Table\SyncStateTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class OrderSync
{
    private $db;
    private $moduleId = 'b2b.integration';

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
    }

    public function run()
    {
        $fullImportDone = Option::get($this->moduleId, 'full_import_done', 'N');
        
        if ($fullImportDone != 'Y') {
            Logger::write('Первичный импорт не выполнен. Запускаем полный импорт...');
            $this->fullImport();
            Option::set($this->moduleId, 'full_import_done', 'Y');
        } else {
            Logger::write('Запуск инкрементальной синхронизации');
            $this->incrementalImport();
        }
    }

    public function fullImport()
    {
        Logger::write('Начало ПОЛНОГО импорта заказов');
        
        $page = 0;
        $limit = 500;
        $totalProcessed = 0;
        
        do {
            $offset = $page * $limit;
            
            $sql = "
                SELECT o.id, o.number, o.created_at, o.updated_at, o.status,
                       o.cancellation_status, o.comment,
                       c.name as company_name, le.inn, le.kpp,
                       CONCAT(u.lastname, ' ', u.firstname) as author_name,
                       u.phone, u.email,
                       dp.address as delivery_address,
                       SUM(oi.price * oi.quantity) as total_price,
                       COUNT(DISTINCT oi.id) as items_count
                FROM `order` o
                LEFT JOIN company c ON o.company_id = c.id
                LEFT JOIN legal_entity le ON c.id = le.company_id
                LEFT JOIN user u ON c.id = u.company_id
                LEFT JOIN delivery_point dp ON o.delivery_point_id = dp.id
                LEFT JOIN order_item oi ON o.id = oi.order_id
                WHERE o.is_test = 0
                GROUP BY o.id
                ORDER BY o.created_at ASC
                LIMIT $limit OFFSET $offset";
            
            $result = $this->db->query($sql);
            if (!$result) break;
            
            $rows = $this->db->fetchAll($result);
            $processed = 0;
            
            foreach ($rows as $row) {
                if ($this->processOrder($row)) {
                    $processed++;
                }
            }
            
            $totalProcessed += $processed;
            Logger::write("Страница $page: обработано $processed заказов");
            
            $page++;
            
        } while (count($rows) == $limit);
        
        SyncStateTable::updateLastSync('order');
        Logger::success("Полный импорт завершен. Всего обработано: $totalProcessed заказов");
    }

    public function incrementalImport()
    {
        $lastSync = SyncStateTable::getLastSync('order');
        $sqlDate = $lastSync->format('Y-m-d H:i:s');
        
        $sql = "
            SELECT o.id, o.number, o.created_at, o.updated_at, o.status,
                   o.cancellation_status, o.comment,
                   c.name as company_name, le.inn, le.kpp,
                   CONCAT(u.lastname, ' ', u.firstname) as author_name,
                   u.phone, u.email,
                   dp.address as delivery_address,
                   SUM(oi.price * oi.quantity) as total_price,
                   COUNT(DISTINCT oi.id) as items_count
            FROM `order` o
            LEFT JOIN company c ON o.company_id = c.id
            LEFT JOIN legal_entity le ON c.id = le.company_id
            LEFT JOIN user u ON c.id = u.company_id
            LEFT JOIN delivery_point dp ON o.delivery_point_id = dp.id
            LEFT JOIN order_item oi ON o.id = oi.order_id
            WHERE (o.created_at > '$sqlDate' OR o.updated_at > '$sqlDate')
            AND o.is_test = 0
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT 100";
        
        $result = $this->db->query($sql);
        if (!$result) return false;
        
        $processed = 0;
        foreach ($this->db->fetchAll($result) as $row) {
            if ($this->processOrder($row)) {
                $processed++;
            }
        }
        
        if ($processed) {
            SyncStateTable::updateLastSync('order');
            Logger::success("Инкрементальная синхронизация: обработано $processed заказов");
        }
        
        return true;
    }

    private function processOrder($row)
    {
        global $DB, $USER;
        
        $orderNumber = $DB->ForSQL($row['number']);
        $exists = $DB->Query("SELECT ID FROM b_crm_deal WHERE ORIGIN_ID = '$orderNumber'")->Fetch();
        if ($exists) {
            return true;
        }
        
        // Маппинг статусов b2b и б24
        $statusMap = [
            1  => 'C35:NEW',        // Новый
            2  => 'C35:WON',        // Завершен успешно
            3  => 'C35:LOSE',       // Завершен неуспешно
            4  => 'C35:1',          // Отменен, другая причина
            5  => 'C35:6',          // Выполнен
            6  => 'C35:7',          // TEST
            7  => 'C35:5',          // ОПЛАЧЕН
            8  => 'C35:4',          // Частично отгружен
            9  => 'C35:3',          // ОТГРУЖЕН
            10 => 'C35:2',          // Подтвержденный
            11 => 'C35:UC_OVX6VQ'   // В работе
        ];
        
        // Формируем комментарий
        $comments = "Заказ #{$row['number']}\n";
        $comments .= "Компания: {$row['company_name']}\n";
        if ($row['inn']) $comments .= "ИНН: {$row['inn']}\n";
        if ($row['author_name']) $comments .= "Автор: {$row['author_name']}\n";
        $comments .= "Товаров: {$row['items_count']}\n";
        if ($row['comment']) $comments .= "Комментарий: {$row['comment']}\n";
        
        $dealFields = [
            'TITLE' => "Заказ B2B #{$row['number']}",
            'OPPORTUNITY' => (float)$row['total_price'],
            'CURRENCY_ID' => 'RUB',
            'STAGE_ID' => $statusMap[$row['status']] ?? 'C35:NEW',
            'CATEGORY_ID' => (int)Option::get($this->moduleId, 'deal_category_id', 35),
            'ORIGIN_ID' => $row['number'],
            'ORIGINATOR_ID' => 'b2b_import',
            'COMMENTS' => $comments,
            'ASSIGNED_BY_ID' => $USER->GetID(),
            'OPENED' => 'Y',
            'UF_CRM_B2B_ORDER_ID' => $row['id'],
            'UF_CRM_B2B_ORDER_NUM' => $row['number'],
            'UF_CRM_B2B_ADDRESS_DELIVERY' => $row['delivery_address'],
        ];
        
        // Наименование статусов
        $statusNames = [
            1  => 'Новый',
            2  => 'Завершен успешно',
            3  => 'Завершен неуспешно',
            4  => 'Отменен, другая причина',
            5  => 'Выполнен',
            6  => 'TEST',
            7  => 'ОПЛАЧЕН',
            8  => 'Частично отгружен',
            9  => 'ОТГРУЖЕН',
            10 => 'Подтвержденный',
            11 => 'В работе'
        ];
        
        $dealFields['UF_CRM_B2B_STATUS'] = $statusNames[$row['status']] ?? 'Неизвестный';
        
        // Дата создания
        if ($row['created_at']) {
            $dealFields['BEGINDATE'] = ConvertTimeStamp(strtotime($row['created_at']), 'FULL');
            $dealFields['UF_CRM_B2B_ORDER_DATE'] = DateTime::createFromTimestamp(strtotime($row['created_at']));
        }
        
        // Отмена
        if ($row['cancellation_status'] == 2) {
            $dealFields['UF_CRM_B2B_CANCEL'] = 'Заказ отменен';
        }
        
        // Комментарий
        $comment = '';
        if ($row['inn']) $comment .= "ИНН: {$row['inn']}\n";
        if ($row['phone']) $comment .= "Тел: {$row['phone']}\n";
        if ($row['email']) $comment .= "Email: {$row['email']}\n";
        if ($comment) $dealFields['UF_CRM_B2B_COMMENT'] = trim($comment);
        
        if ($dealId = BitrixHelper::createDeal($dealFields)) {
            Logger::success("Сделка $dealId создана для заказа {$row['number']}");
            return true;
        }
        
        return false;
    }
}