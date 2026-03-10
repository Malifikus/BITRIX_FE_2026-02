<?php
// Устанавливаем корневую директорию
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
}

// Отключаем проверки и статистику для cron
define("NOT_CHECK_PERMISSIONS", true);
define("NO_KEEP_STATISTIC", true);
define("STOP_STATISTICS", true);
define("DisableEventsCheck", true);
define("NO_AGENT_CHECK", true);
define("BX_CRONTAB", true);
define("BX_NO_ACCELERATOR_RESET", true);

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Очищаем буферы
while (ob_get_level()) {
    ob_end_clean();
}

// Подключаем ядро Битрикс
$_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../../../..');
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

// Загружаем конфигурацию из внешнего файла
$CONFIG = require('/home/vp/.b2b/.b2b_config.php');
// Устанавливаем путь к файлу лога
$logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/tools/integrations/b2b/import.log';

// Функция для записи сообщений в лог и вывода на экран
function logMessage($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo $logEntry;
}

global $USER;

// Получаем логин и пароль из конфигурации
$userLogin = $CONFIG['bitrix_auth']['login'];
$userPassword = $CONFIG['bitrix_auth']['password'];

logMessage("Авторизация пользователя...: " . $userLogin);

// Авторизуемся в Битрикс для CLI
if (!$USER->IsAuthorized()) {
    $authResult = $USER->Login($userLogin, $userPassword);
    
    if (!$USER->IsAuthorized()) {
        $rsUser = CUser::GetByLogin($userLogin);
        $arUser = $rsUser->Fetch();
        
        if ($arUser) {
            logMessage("Найден пользователь ID: " . $arUser['ID'] . ", пробуем авторизацию по ID");
            
            if ($USER->Authorize($arUser['ID'])) {
                logMessage("Авторизация по ID успешна");
            } else {
                logMessage("Ошибка авторизации по ID");
                die("Ошибка авторизации: не удалось авторизовать пользователя\n");
            }
        } else {
            logMessage("Пользователь не найден в системе");
            die("Ошибка авторизации: пользователь не найден\n");
        }
    }
}

if (!$USER->IsAuthorized()) {
    die("Ошибка авторизации: пользователь не авторизован\n");
}

$userId = $USER->GetID();
logMessage("Успешная авторизация. ID пользователя: " . $userId);

// Подключаем модули
if (!CModule::IncludeModule('crm')) {
    die('Ошибка загрузки CRM модуля');
}

if (!CModule::IncludeModule('iblock')) {
    die('Ошибка загрузки модуля инфоблоков');
}

// Настройки скрипта
define('TEST_MODE', false);
define('TEST_LIMIT', 100);

// Получение заказов из B2B API
function getB2BOrders($config, $fromDate, $limit = 100)
{
    $baseUrl = $config['b2b']['base_url'] . '/api/v2/orders';
    $allOrders = [];
    $page = 1;
    $perPage = 100;
    
    do {
        $url = $baseUrl . '?criteria[from]=' . urlencode($fromDate) . '&perPage=' . $perPage . '&page=' . $page;
        
        logMessage("Запрос к B2B API: " . $url);
        
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'version' => '1.1',
            'redirect' => true,
            'redirectMax' => 5,
            'waitResponse' => true,
            'socketTimeout' => 30,
            'streamTimeout' => 30,
        ]);
        
        $httpClient->setHeader('Authorization', 'Bearer ' . $config['b2b']['api_token']);
        $httpClient->setHeader('Accept', 'application/json');
        $httpClient->setHeader('Content-Type', 'application/json');
        
        $response = $httpClient->get($url);
        $httpCode = $httpClient->getStatus();
        $error = $httpClient->getError();
        
        if (!empty($error)) {
            logMessage('HTTP клиент ошибка: ' . print_r($error, true));
            break;
        }
        
        if ($httpCode !== 200) {
            logMessage('Ошибка HTTP ' . $httpCode);
            logMessage('Ответ сервера: ' . substr($response, 0, 500));
            break;
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage('Ошибка парсинга JSON: ' . json_last_error_msg());
            break;
        }
        
        $orders = $data['items'] ?? [];
        logMessage("Получено заказов на странице $page: " . count($orders));
        
        foreach ($orders as $order) {
            $allOrders[] = $order;
            if (count($allOrders) >= $limit) {
                break 2;
            }
        }
        
        $page++;
        $hasMore = count($orders) >= $perPage && count($allOrders) < $limit;
        
    } while ($hasMore);
    
    return $allOrders;
}

// Проверка сделки по номеру заказа
function checkDealExists($orderNumber)
{
    global $DB;
    $orderNumber = $DB->ForSQL($orderNumber);
    $res = $DB->Query("SELECT ID, TITLE FROM b_crm_deal WHERE ORIGIN_ID = '$orderNumber' LIMIT 1");
    return $res->Fetch();
}

// Проверка сотрудника на автора
function isAuthorEmployee($authorName)
{
    if (empty($authorName)) {
        return false;
    }
    
    global $DB;
    
    $nameParts = explode(' ', trim($authorName));
    if (count($nameParts) < 2) {
        return false;
    }
    
    $firstName = $DB->ForSQL($nameParts[0]);
    $lastName = $DB->ForSQL($nameParts[1]);
    
    $res = $DB->Query("SELECT ID FROM b_user WHERE NAME = '$firstName' AND LAST_NAME = '$lastName' LIMIT 1");
    if ($user = $res->Fetch()) {
        logMessage("Автор $authorName найден как сотрудник, ID: " . $user['ID']);
        return $user['ID'];
    }
    
    return false;
}

// Создание контакта из автора
function createContactFromAuthor($authorName)
{
    if (empty($authorName)) {
        return null;
    }
    
    $nameParts = explode(' ', trim($authorName));
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
    $secondName = $nameParts[2] ?? '';
    
    if (empty($firstName) || empty($lastName)) {
        logMessage("Не удалось разобрать имя автора: $authorName");
        return null;
    }
    
    $contactFields = [
        'NAME' => $firstName,
        'LAST_NAME' => $lastName,
        'SECOND_NAME' => $secondName,
        'SOURCE_ID' => 'B2B_IMPORT',
        'OPENED' => 'Y',
        'ASSIGNED_BY_ID' => $GLOBALS['USER']->GetID(),
    ];
    
    $contact = new CCrmContact();
    $contactId = $contact->Add($contactFields);
    
    if ($contactId) {
        logMessage("Создан новый контакт из автора: $authorName, ID: " . $contactId);
        return $contactId;
    }
    
    return null;
}

// Поиск компании по ИНН
function findOrCreateCompanyByInn($inn, $companyName, $kpp = '')
{
    if (empty($inn)) {
        return null;
    }
    
    global $DB;
    
    $innEscaped = $DB->ForSQL($inn);
    
    // Поиск пользовательского поля ИНН
    $res = $DB->Query("SELECT VALUE_ID FROM b_uts_crm_company WHERE UF_CRM_1728386018808 = '$innEscaped' LIMIT 1");
    if ($company = $res->Fetch()) {
        logMessage("Найдена существующая компания с ИНН $inn по пользовательскому полю, ID: " . $company['VALUE_ID']);
        return $company['VALUE_ID'];
    }
    
    // Поиск реквизитов
    $res = $DB->Query("SELECT ENTITY_ID FROM b_crm_requisite WHERE ENTITY_TYPE_ID = 4 AND RQ_INN = '$innEscaped' LIMIT 1");
    if ($company = $res->Fetch()) {
        $companyId = $company['ENTITY_ID'];
        logMessage("Найдена существующая компания с ИНН $inn по реквизитам, ID: " . $companyId);
        
        // Обновлениеи пользовательского поля ИНН
        $DB->Query("UPDATE b_uts_crm_company SET UF_CRM_1728386018808 = '$innEscaped' WHERE VALUE_ID = $companyId");
        
        return $companyId;
    }
    
    // Создаем новую компанию
    $companyFields = [
        'TITLE' => $companyName ?: 'Компания из B2B',
        'COMPANY_TYPE' => 'CUSTOMER',
        'SOURCE_ID' => 'B2B_IMPORT',
        'OPENED' => 'Y',
        'ASSIGNED_BY_ID' => $GLOBALS['USER']->GetID(),
        'UF_CRM_1728386018808' => $inn,
    ];
    
    $company = new CCrmCompany();
    $companyId = $company->Add($companyFields);
    
    if ($companyId) {
        logMessage("Создана новая компания с ИНН $inn, ID: " . $companyId);
        
        // Создаем реквизиты компании
        $requisiteFields = [
            'ENTITY_TYPE_ID' => CCrmOwnerType::Company,
            'ENTITY_ID' => $companyId,
            'PRESET_ID' => 1,
            'NAME' => $companyName ?: 'Компания из B2B',
            'ACTIVE' => 'Y',
            'RQ_INN' => $inn,
        ];
        
        if (!empty($kpp)) {
            $requisiteFields['RQ_KPP'] = $kpp;
        }
        
        $requisite = new \Bitrix\Crm\Requisite();
        $result = $requisite->add($requisiteFields);
        
        if ($result->isSuccess()) {
            logMessage("Реквизиты для компании ID $companyId успешно созданы");
        } else {
            logMessage("Ошибка при создании реквизитов: " . implode('; ', $result->getErrorMessages()));
        }
        
        return $companyId;
    }
    
    return null;
}

// Создание сделки в Битрикс24
function createB24Deal($config, $order)
{
    global $USER, $APPLICATION;
    
    if (TEST_MODE) {
        return ['ID' => 'TEST_MODE', 'SUCCESS' => true];
    }
    
    $statusMap = $config['import']['status_map'] ?? [];
    $stageId = $statusMap[$order['status']['id'] ?? 1] ?? 'C35:NEW';
    $categoryId = $config['import']['category_id'] ?? 35;
    
    global $DB;
    $categoryCheck = $DB->Query("SELECT ID FROM b_crm_deal_category WHERE ID = " . (int)$categoryId)->Fetch();
    if (!$categoryCheck) {
        logMessage("Ошибка: Категория с ID $categoryId не найдена");
        return ['ID' => null, 'SUCCESS' => false, 'ERROR' => "Категория $categoryId не найдена"];
    }
    
    // Извлекаем все данные из заказа
    $orderId = $order['id'] ?? '';
    $orderNumber = $order['number'] ?? '';
    $orderStatus = $order['status'] ?? [];
    $statusName = $orderStatus['name'] ?? '';
    $createdAt = $order['createdAt'] ?? '';
    $updatedAt = $order['updatedAt'] ?? '';
    $deliveryAddress = $order['deliveryAddress'] ?? '';
    $deliveryType = $order['deliveryType'] ?? '';
    $comment = $order['comment'] ?? '';
    $cancellationStatus = $order['cancellationStatus'] ?? 0;
    $orderItemsCount = $order['orderItemsCount'] ?? 0;
    $orderItemsTotalPrice = $order['orderItemsTotalPrice'] ?? 0;
    $customerName = $order['customerName'] ?? '';
    $authorName = $order['author'] ?? '';
    
    $legalEntity = $order['legalEntity'] ?? [];
    $legalEntityInn = $legalEntity['inn'] ?? '';
    $legalEntityKpp = $legalEntity['kpp'] ?? '';
    $legalEntityName = $legalEntity['name'] ?? '';
    $legalEntityShortName = $legalEntity['shortName'] ?? '';
    
    $additionalFields = $order['additionalFields'] ?? [];
    $phoneFromFields = '';
    $emailFromFields = '';
    $contactPerson = '';
    
    // Ищем телефон и email
    foreach ($additionalFields as $field) {
        if (isset($field['name']) && isset($field['value'])) {
            $fieldNameLower = mb_strtolower($field['name']);
            if (mb_strpos($fieldNameLower, 'телефон') !== false || mb_strpos($fieldNameLower, 'phone') !== false) {
                $phoneFromFields = $field['value'];
            }
            if (mb_strpos($fieldNameLower, 'email') !== false || mb_strpos($fieldNameLower, 'почта') !== false) {
                $emailFromFields = $field['value'];
            }
            if (mb_strpos($fieldNameLower, 'контакт') !== false || mb_strpos($fieldNameLower, 'contact') !== false) {
                $contactPerson = $field['value'];
            }
        }
    }
    
    $isCancelled = ($cancellationStatus == 2);
    
    // Обработка компании по ИНН
    $companyId = null;
    if (!empty($legalEntityInn)) {
        $companyTitle = $customerName ?: $legalEntityName ?: $legalEntityShortName ?: 'Компания из B2B';
        $companyId = findOrCreateCompanyByInn($legalEntityInn, $companyTitle, $legalEntityKpp);
    }
    
    // Обработка автора заказа
    $contactId = null;
    $managerId = null;
    
    if (!empty($authorName)) {
        // Проверка сотрудника на автора
        $employeeId = isAuthorEmployee($authorName);
        
        if ($employeeId) {
            $managerId = $employeeId;
            logMessage("Автор $authorName является сотрудником, ID менеджера: $managerId");
        } else {
            // Автор - не сотрудник
            $contactId = createContactFromAuthor($authorName);
        }
    }
    
    // Комментарий к сделке
    $comments = "Информация о заказе:\n";
    $comments .= "ID заказа: " . $orderId . "\n";
    $comments .= "Номер заказа: " . $orderNumber . "\n";
    $comments .= "Дата создания: " . $createdAt . "\n";
    $comments .= "Дата обновления: " . $updatedAt . "\n";
    $comments .= "Статус: " . $statusName . "\n";
    $comments .= "Сумма заказа: " . number_format((float)$orderItemsTotalPrice, 2, '.', ' ') . " руб.\n";
    $comments .= "Количество позиций: " . $orderItemsCount . "\n";
    
    if ($isCancelled) {
        $comments .= "Заказ отменен\n";
    }
    
    $comments .= "\nИнформация о доставке:\n";
    $comments .= "Тип доставки: " . ($deliveryType ?: 'Не указан') . "\n";
    $comments .= "Адрес доставки: " . ($deliveryAddress ?: 'Не указан') . "\n";
    
    $comments .= "\nИнформация о клиенте:\n";
    $comments .= "Наименование компании: " . ($customerName ?: 'Не указано') . "\n";
    
    if (!empty($legalEntityName)) {
        $comments .= "Юр. лицо: " . $legalEntityName . "\n";
    }
    if (!empty($legalEntityShortName)) {
        $comments .= "Краткое наименование: " . $legalEntityShortName . "\n";
    }
    if (!empty($legalEntityInn)) {
        $comments .= "ИНН: " . $legalEntityInn . "\n";
    }
    if (!empty($legalEntityKpp)) {
        $comments .= "КПП: " . $legalEntityKpp . "\n";
    }
    if (!empty($phoneFromFields)) {
        $comments .= "Телефон: " . $phoneFromFields . "\n";
    }
    if (!empty($emailFromFields)) {
        $comments .= "Email: " . $emailFromFields . "\n";
    }
    if (!empty($contactPerson)) {
        $comments .= "Контактное лицо: " . $contactPerson . "\n";
    }
    if (!empty($authorName)) {
        $comments .= "Автор заказа: " . $authorName . "\n";
    }
    
    if (!empty($comment)) {
        $comments .= "\nКомментарий к заказу: " . $comment . "\n";
    }
    
    // Поля сделки
    $fields = [
        'TITLE' => 'Заказ B2B #' . $orderNumber,
        'OPPORTUNITY' => (float)$orderItemsTotalPrice,
        'CURRENCY_ID' => 'RUB',
        'STAGE_ID' => $stageId,
        'CATEGORY_ID' => (int)$categoryId,
        'ORIGINATOR_ID' => 'b2b_import',
        'ORIGIN_ID' => (string)$orderNumber,
        'COMMENTS' => $comments,
        'OPENED' => 'Y',
        'BEGINDATE' => !empty($createdAt) ? ConvertTimeStamp(strtotime($createdAt), 'FULL') : ConvertTimeStamp(time(), 'FULL'),
    ];
    
    // Устанавливаем ответственного
    if ($managerId) {
        $fields['ASSIGNED_BY_ID'] = $managerId;
    } else {
        $fields['ASSIGNED_BY_ID'] = $USER->GetID();
    }
    
    // Привязываем компанию
    if ($companyId) {
        $fields['COMPANY_ID'] = $companyId;
    }
    
    // Привязываем контакт
    if ($contactId) {
        $fields['CONTACT_ID'] = $contactId;
    }
    
    // Заполняем пользовательские поля
    if (!empty($orderId)) {
        $fields['UF_CRM_B2B_ORDER_ID'] = (string)$orderId;
    }
    
    if (!empty($orderNumber)) {
        $fields['UF_CRM_B2B_ORDER_NUM'] = (string)$orderNumber;
    }
    
    if (!empty($createdAt)) {
        $timestamp = strtotime($createdAt);
        if ($timestamp) {
            $fields['UF_CRM_B2B_ORDER_DATE'] = \Bitrix\Main\Type\DateTime::createFromTimestamp($timestamp);
        }
    }
    
    if (!empty($statusName)) {
        $fields['UF_CRM_B2B_STATUS'] = (string)$statusName;
    }
    
    if (!empty($deliveryAddress)) {
        $fields['UF_CRM_B2B_ADDRESS_DELIVERY'] = (string)$deliveryAddress;
    }
    
    // Заполняем поле менеджера
    if ($managerId) {
        $fields['UF_CRM_1753800868'] = $managerId;
    }
    
    // Дополнительный комментарий
    $additionalComment = '';
    if (!empty($deliveryType)) {
        $additionalComment .= "Тип доставки: $deliveryType\n";
    }
    if (!empty($legalEntityInn)) {
        $additionalComment .= "ИНН: $legalEntityInn\n";
    }
    if (!empty($phoneFromFields)) {
        $additionalComment .= "Телефон: $phoneFromFields\n";
    }
    if (!empty($emailFromFields)) {
        $additionalComment .= "Email: $emailFromFields\n";
    }
    if (!empty($contactPerson)) {
        $additionalComment .= "Контактное лицо: $contactPerson\n";
    }
    
    if (!empty($additionalComment)) {
        $fields['UF_CRM_B2B_COMMENT'] = trim($additionalComment);
    }
    
    if ($isCancelled) {
        $fields['UF_CRM_B2B_CANCEL'] = 'Заказ отменен';
    }
    
    logMessage('Поля для создания сделки (заказ #' . $orderNumber . '):');
    
    // Создаем сделку
    $deal = new CCrmDeal();
    $APPLICATION->ResetException();
    
    $dealId = $deal->Add($fields);
    
    if ($dealId && $dealId > 0) {
        logMessage('Сделка успешно создана с ID: ' . $dealId);
        return ['ID' => $dealId, 'SUCCESS' => true];
    }
    
    // Обработка ошибок
    $error = '';
    if (!empty($deal->LAST_ERROR)) {
        $error = $deal->LAST_ERROR;
    }
    if ($ex = $APPLICATION->GetException()) {
        $error .= '; ' . $ex->GetString();
    }
    if (empty($error)) {
        $error = 'Неизвестная ошибка';
    }
    
    logMessage('Ошибка создания сделки для заказа #' . $orderNumber . ': ' . $error);
    
    return ['ID' => null, 'SUCCESS' => false, 'ERROR' => $error];
}

// Начало выполнения скрипта
logMessage('Запуск импорта заказов B2B -> Битрикс24');
logMessage('Пользователь ID: ' . $userId . ' (Логин: ' . $CONFIG['bitrix_auth']['login'] . ')');
logMessage('Лимит: ' . TEST_LIMIT . ' заказов');

$fromDate = date('d.m.Y', strtotime('-' . $CONFIG['import']['days_back'] . ' days'));
logMessage('Период: с ' . $fromDate);

// Получаем заказы из API
$orders = getB2BOrders($CONFIG, $fromDate, TEST_LIMIT);

if (empty($orders)) {
    logMessage('Заказов за период не найдено');
    logMessage('Импорт завершен');
    die();
}

logMessage('Получено заказов: ' . count($orders));

$imported = 0;
$errors = 0;
$skipped = 0;

// Обрабатываем заказы
foreach ($orders as $index => $order) {
    logMessage('Обработка заказа ' . ($index + 1) . ' из ' . count($orders));
    
    if (empty($order['number'])) {
        logMessage('Пропуск: отсутствует номер заказа');
        $errors++;
        continue;
    }
    
    // Проверяем заказ на импорт
    $existing = checkDealExists($order['number']);
    
    if ($existing) {
        logMessage('Заказ #' . $order['number'] . ' уже импортирован (ID: ' . $existing['ID'] . ')');
        $skipped++;
        continue;
    }
    
    // Создаем сделку
    $result = createB24Deal($CONFIG, $order);
    
    if ($result['SUCCESS']) {
        logMessage('Заказ #' . $order['number'] . ' успешно создан (Сделка ID: ' . $result['ID'] . ')');
        $imported++;
    } else {
        logMessage('Заказ #' . $order['number'] . ' ошибка: ' . $result['ERROR']);
        $errors++;
    }
    
    // Ставим задержку
    usleep(100000);
}

// Выводим итоги
logMessage('Итоги импорта:');
logMessage('Всего обработано: ' . count($orders));
logMessage('Импортировано: ' . $imported);
logMessage('Пропущено (уже есть): ' . $skipped);
logMessage('Ошибок: ' . $errors);
logMessage('Импорт завершен');