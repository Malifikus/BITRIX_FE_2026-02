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
    private $iblockId = 24; // ID инфоблока товаров
    private $targetSectionId = 17390; // папка для товаров B2B
    private $techUserId = 1668; // техпользователь

    // Кэши
    private $companyCache = [];
    private $productCache = [];
    private $contactCache = [];

    // Режим
    private $isFirstImport = false;
    private $limit = 10; // общее количество для обработки (0 = всё)

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
        $this->detectImportMode();
    }

    private function detectImportMode()
    {
        $firstImportDone = Option::get($this->moduleId, 'first_import_orders_done', 'N');
        $this->isFirstImport = ($firstImportDone != 'Y');
    }

    public function setLimit($limit)
    {
        $this->limit = (int)$limit;
    }

    public function run()
    {
        Logger::write('Начало синхронизации заказов');
        if ($this->isFirstImport) {
            $this->runFirstImport();
        } else {
            $this->runRegularUpdate();
        }
        Logger::write('Синхронизация заказов завершена');
    }

    private function runFirstImport()
    {
        Logger::write('Режим: первичный импорт заказов');
        $batchSize = 200; // фиксированный размер пачки для производительности
        $offset = 0;
        $totalProcessed = 0;

        do {
            // Вычисляем сколько реально взять в этой пачке, не превышая общий лимит
            $currentBatch = $this->limit > 0 ? min($batchSize, $this->limit - $totalProcessed) : $batchSize;
            if ($currentBatch <= 0) break;

            $sql = $this->buildOrdersQuery($currentBatch, $offset);
            $rows = $this->fetchOrders($sql);
            if (empty($rows)) break;

            $processed = 0;
            foreach ($rows as $row) {
                if ($this->processOrder($row)) {
                    $processed++;
                }
            }
            $totalProcessed += $processed;
            Logger::write("Пачка offset $offset обработана: $processed заказов, всего $totalProcessed");

            $offset += $batchSize;

            // Если достигли общего лимита, выходим
            if ($this->limit > 0 && $totalProcessed >= $this->limit) break;

            // Небольшая пауза между пачками
            sleep(2);
            gc_collect_cycles();

        } while (count($rows) == $batchSize);

        // Если обработано меньше лимита (конец данных) или лимит не задан, считаем импорт завершённым
        if ($this->limit == 0 || $totalProcessed < $this->limit) {
            Option::set($this->moduleId, 'first_import_orders_done', 'Y');
            SyncStateTable::updateLastSync('order', $this->getMaxOrderId());
        }
    }

    private function runRegularUpdate()
    {
        Logger::write('Режим: регулярное обновление заказов');
        $lastSync = SyncStateTable::getLastSync('order');
        $sqlDate = $lastSync->format('Y-m-d H:i:s');

        // При регулярном обновлении лимит работает как максимальное количество за один запуск
        $limit = $this->limit > 0 ? $this->limit : 100;
        $sql = "
            SELECT 
                o.id,
                o.number,
                o.created_at,
                o.updated_at,
                o.status,
                o.cancellation_status,
                o.comment,
                o.delivery_address,
                o.delivery_type,
                c.id as company_id,
                c.name as company_name,
                le.inn,
                le.kpp,
                le.name as legal_name,
                le.short_name as legal_short_name,
                u.id as user_id,
                u.firstname,
                u.lastname,
                u.middlename,
                u.phone,
                u.email,
                (SELECT SUM(oi.price * oi.quantity) FROM order_item oi WHERE oi.order_id = o.id) as total_price
            FROM `order` o
            LEFT JOIN company c ON o.company_id = c.id
            LEFT JOIN legal_entity le ON c.id = le.company_id
            LEFT JOIN user u ON o.customerId = u.id
            WHERE o.updated_at > '$sqlDate'
            ORDER BY o.updated_at ASC
            LIMIT $limit
        ";

        $rows = $this->fetchOrders($sql);
        if (empty($rows)) {
            Logger::write('Нет изменённых заказов');
            return;
        }

        $processed = 0;
        $maxUpdatedAt = $lastSync;
        foreach ($rows as $row) {
            if ($this->processOrder($row)) {
                $processed++;
            }
            $rowDate = new DateTime($row['updated_at']);
            if ($rowDate->getTimestamp() > $maxUpdatedAt->getTimestamp()) {
                $maxUpdatedAt = $rowDate;
            }
        }
        SyncStateTable::updateLastSync('order', 0, $maxUpdatedAt);
        Logger::write("Регулярное обновление обработало $processed заказов");
    }

    private function buildOrdersQuery($limit, $offset)
    {
        return "
            SELECT 
                o.id,
                o.number,
                o.created_at,
                o.updated_at,
                o.status,
                o.cancellation_status,
                o.comment,
                o.delivery_address,
                o.delivery_type,
                c.id as company_id,
                c.name as company_name,
                le.inn,
                le.kpp,
                le.name as legal_name,
                le.short_name as legal_short_name,
                u.id as user_id,
                u.firstname,
                u.lastname,
                u.middlename,
                u.phone,
                u.email,
                (SELECT SUM(oi.price * oi.quantity) FROM order_item oi WHERE oi.order_id = o.id) as total_price
            FROM `order` o
            LEFT JOIN company c ON o.company_id = c.id
            LEFT JOIN legal_entity le ON c.id = le.company_id
            LEFT JOIN user u ON o.customerId = u.id
            ORDER BY o.id ASC
            LIMIT $limit OFFSET $offset
        ";
    }

    private function fetchOrders($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            Logger::error('Ошибка запроса заказов');
            return [];
        }
        return $this->db->fetchAll($result);
    }

    private function processOrder($row)
    {
        // 1. Компания
        $companyId = null;
        if (!empty($row['inn'])) {
            $companyId = $this->getOrCreateCompany($row);
        }

        // 2. Контакт (автор)
        $contactId = null;
        $assignedById = $this->techUserId;
        if (!empty($row['firstname']) && !empty($row['lastname'])) {
            $contactData = $this->getOrCreateContact($row);
            $contactId = $contactData['id'];
            if ($contactData['assigned']) {
                $assignedById = $contactData['assigned'];
            }
        }

        // 3. Проверяем, не создана ли уже сделка по этому заказу
        $existingDealId = $this->findDealByOrderNumber($row['number']);
        if ($existingDealId) {
            Logger::write("Заказ {$row['number']} уже импортирован (сделка ID $existingDealId), пропускаем");
            return false;
        }

        // 4. Товары из заказа
        $productRows = $this->fetchOrderItems($row['id']);
        $productBindings = [];
        foreach ($productRows as $item) {
            $productId = $this->getOrCreateProduct($item);
            if ($productId) {
                $productBindings[] = [
                    'PRODUCT_ID' => $productId,
                    'QUANTITY' => $item['quantity'],
                    'PRICE' => $item['price'],
                ];
            }
        }

        // 5. Создаём сделку
        $dealId = $this->createDeal($row, $companyId, $contactId, $assignedById, $productBindings);
        if ($dealId) {
            Logger::success("Создана сделка ID $dealId для заказа {$row['number']}");
            return true;
        }
        return false;
    }

    private function getOrCreateCompany($row)
    {
        if (isset($this->companyCache[$row['company_id']])) {
            return $this->companyCache[$row['company_id']];
        }
        $companyId = BitrixHelper::findCompanyByInn($row['inn']);
        if (!$companyId) {
            $title = $row['legal_name'] ?: $row['legal_short_name'] ?: $row['company_name'] ?: 'Компания из B2B';
            $companyData = [
                'TITLE' => $title,
                'COMPANY_TYPE' => 'CUSTOMER',
                'SOURCE_ID' => 'B2B_IMPORT',
                'OPENED' => 'Y',
                'ASSIGNED_BY_ID' => $this->techUserId,
                'UF_CRM_1728386018808' => $row['inn'],
            ];
            $companyId = BitrixHelper::createCompany($companyData);
            if ($companyId) {
                Logger::write("Создана компания ID $companyId по ИНН {$row['inn']}");
            }
        } else {
            Logger::write("Найдена компания ID $companyId по ИНН {$row['inn']}");
        }
        $this->companyCache[$row['company_id']] = $companyId;
        return $companyId;
    }

    private function getOrCreateContact($row)
    {
        if (isset($this->contactCache[$row['user_id']])) {
            $contactId = $this->contactCache[$row['user_id']];
            return ['id' => $contactId, 'assigned' => null];
        }

        // Проверяем, является ли автор сотрудником
        $employeeId = $this->findEmployeeByName($row['firstname'], $row['lastname']);
        if ($employeeId) {
            $this->contactCache[$row['user_id']] = null;
            return ['id' => null, 'assigned' => $employeeId];
        }

        $contactId = null;
        if (!empty($row['email'])) {
            $contactId = BitrixHelper::findContactByEmail($row['email']);
        }
        if (!$contactId && !empty($row['phone'])) {
            $contactId = BitrixHelper::findContactByPhone($row['phone']);
        }
        if (!$contactId) {
            $contactFields = [
                'NAME' => $row['firstname'],
                'LAST_NAME' => $row['lastname'],
                'SECOND_NAME' => $row['middlename'] ?? '',
                'SOURCE_ID' => 'B2B_IMPORT',
                'OPENED' => 'Y',
                'ASSIGNED_BY_ID' => $this->techUserId,
            ];
            if (!empty($row['phone'])) {
                $contactFields['FM']['PHONE'] = [['VALUE' => $row['phone'], 'VALUE_TYPE' => 'WORK']];
            }
            if (!empty($row['email'])) {
                $contactFields['FM']['EMAIL'] = [['VALUE' => $row['email'], 'VALUE_TYPE' => 'WORK']];
            }
            $contactId = BitrixHelper::createContact($contactFields);
            if ($contactId) {
                Logger::write("Создан контакт ID $contactId для автора {$row['firstname']} {$row['lastname']}");
            }
        } else {
            Logger::write("Найден контакт ID $contactId для автора {$row['firstname']} {$row['lastname']}");
        }
        $this->contactCache[$row['user_id']] = $contactId;
        return ['id' => $contactId, 'assigned' => null];
    }

    private function findEmployeeByName($firstname, $lastname)
    {
        global $DB;
        $first = $DB->ForSQL($firstname);
        $last = $DB->ForSQL($lastname);
        $res = $DB->Query("SELECT ID FROM b_user WHERE NAME = '$first' AND LAST_NAME = '$last' LIMIT 1");
        if ($user = $res->Fetch()) {
            return $user['ID'];
        }
        return null;
    }

    private function fetchOrderItems($orderId)
    {
        $sql = "
            SELECT 
                oi.id,
                oi.product_id,
                oi.article,
                oi.name,
                oi.price,
                oi.quantity,
                p.manufacturer_code
            FROM order_item oi
            LEFT JOIN product p ON oi.product_id = p.id
            WHERE oi.order_id = $orderId
        ";
        $result = $this->db->query($sql);
        if (!$result) return [];
        return $this->db->fetchAll($result);
    }

    private function getOrCreateProduct($item)
    {
        if (isset($this->productCache[$item['product_id']])) {
            return $this->productCache[$item['product_id']];
        }
        $code = $item['manufacturer_code'] ?? $item['article'];
        if (empty($code)) {
            Logger::error("Товар без артикула в заказе, пропускаем");
            return null;
        }
        $productId = $this->findProductByCode($code);
        if (!$productId) {
            $productFields = [
                'NAME' => $item['name'] ?: 'Товар из B2B',
                'IBLOCK_ID' => $this->iblockId,
                'IBLOCK_SECTION_ID' => $this->targetSectionId,
                'ACTIVE' => 'Y',
                'CODE' => \CUtil::translit($item['name'] ?: $code, 'ru'),
                'PROPERTY_VALUES' => [
                    'ARTNUMBER' => $code,
                ]
            ];
            $el = new \CIBlockElement();
            $productId = $el->Add($productFields);
            if ($productId) {
                Logger::write("Создан товар ID $productId, артикул $code");
                \Bitrix\Main\Loader::includeModule('catalog');
                \Bitrix\Catalog\Model\Product::add(['ID' => $productId, 'QUANTITY' => 0, 'VAT_ID' => 1, 'MEASURE' => 5]);
            } else {
                Logger::error("Ошибка создания товара: " . $el->LAST_ERROR);
                return null;
            }
        } else {
            Logger::write("Найден товар ID $productId по артикулу $code");
        }
        $this->productCache[$item['product_id']] = $productId;
        return $productId;
    }

    private function findProductByCode($code)
    {
        $res = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $this->iblockId, '=PROPERTY_ARTNUMBER' => $code],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        if ($item = $res->Fetch()) {
            return $item['ID'];
        }
        return null;
    }

    private function findDealByOrderNumber($orderNumber)
    {
        global $DB;
        $orderNumber = $DB->ForSQL($orderNumber);
        $res = $DB->Query("SELECT ID FROM b_crm_deal WHERE ORIGIN_ID = '$orderNumber' LIMIT 1");
        if ($deal = $res->Fetch()) {
            return $deal['ID'];
        }
        return null;
    }

    private function createDeal($order, $companyId, $contactId, $assignedById, $products)
    {
        $statusMap = [
            1 => 'C35:NEW',
            2 => 'C35:WON',
            3 => 'C35:LOSE',
            4 => 'C35:1',
            5 => 'C35:6',
            6 => 'C35:7',
            7 => 'C35:5',
            8 => 'C35:4',
            9 => 'C35:3',
            10 => 'C35:2',
            11 => 'C35:UC_OVX6VQ',
        ];
        $stageId = $statusMap[$order['status']] ?? 'C35:NEW';

        $fields = [
            'TITLE' => 'Заказ B2B #' . $order['number'],
            'OPPORTUNITY' => (float)$order['total_price'],
            'CURRENCY_ID' => 'RUB',
            'STAGE_ID' => $stageId,
            'CATEGORY_ID' => 35,
            'ORIGINATOR_ID' => 'b2b_import',
            'ORIGIN_ID' => $order['number'],
            'ASSIGNED_BY_ID' => $assignedById,
            'OPENED' => 'Y',
            'BEGINDATE' => new DateTime($order['created_at']),
            'CLOSEDATE' => $order['status'] == 2 ? new DateTime() : null,
            'COMMENTS' => $this->buildDealComment($order),
            'UF_CRM_B2B_ORDER_ID' => $order['id'],
            'UF_CRM_B2B_ORDER_NUM' => $order['number'],
            'UF_CRM_B2B_ORDER_DATE' => new DateTime($order['created_at']),
            'UF_CRM_B2B_STATUS' => $this->getStatusName($order['status']),
            'UF_CRM_B2B_ADDRESS_DELIVERY' => $order['delivery_address'] ?? '',
            'UF_CRM_B2B_CANCEL' => ($order['cancellation_status'] == 2) ? 'Заказ отменен' : '',
        ];
        if ($companyId) $fields['COMPANY_ID'] = $companyId;
        if ($contactId) $fields['CONTACT_ID'] = $contactId;

        $deal = new \CCrmDeal();
        $dealId = $deal->Add($fields);
        if (!$dealId) {
            Logger::error('Ошибка создания сделки: ' . $deal->LAST_ERROR);
            return null;
        }

        if (!empty($products)) {
            \CCrmDeal::SaveProductRows($dealId, $products);
        }
        return $dealId;
    }

    private function buildDealComment($order)
    {
        $lines = [];
        $lines[] = "Заказ из B2B #{$order['number']}";
        $lines[] = "Дата создания: {$order['created_at']}";
        if (!empty($order['delivery_address'])) {
            $lines[] = "Адрес доставки: {$order['delivery_address']}";
        }
        if (!empty($order['comment'])) {
            $lines[] = "Комментарий: {$order['comment']}";
        }
        return implode("\n", $lines);
    }

    private function getStatusName($statusId)
    {
        $names = [
            1 => 'Новый',
            2 => 'Завершен успешно',
            3 => 'Завершен неуспешно',
            4 => 'Отменен, другая причина',
            5 => 'Выполнен',
            6 => 'TEST',
            7 => 'ОПЛАЧЕН',
            8 => 'Частично отгружен',
            9 => 'ОТГРУЖЕН',
            10 => 'Подтвержденный',
            11 => 'В работе',
        ];
        return $names[$statusId] ?? 'Неизвестный';
    }

    private function getMaxOrderId()
    {
        $sql = "SELECT MAX(id) as max_id FROM `order`";
        $result = $this->db->query($sql);
        if ($row = $result->fetch_assoc()) {
            return (int)$row['max_id'];
        }
        return 0;
    }
}