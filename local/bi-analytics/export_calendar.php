<?php
# Подключаем ядро Битрикс24
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

global $DB;

# Настройки
$DATE_FROM = '2015-01-01';
$OUTPUT_FILE = __DIR__ . '/b24_calendar_events.csv';
$DEPARTMENT_IBLOCK_ID = 1;

# Проверка прав
if (!is_writable(__DIR__)) {
    echo "Нет прав на запись в папку: " . __DIR__ . "\n";
    echo "Выполните на сервере:\n";
    echo "chmod 775 " . __DIR__ . "\n";
    echo "или:\n";
    echo "chown vp:bitrix " . __DIR__ . "\n";
    exit;
}

# Поля для CSV-заголовка
$csv_headers = [
    'EVENT_ID',
    'EVENT_NAME',
    'OWNER_ID',
    'OWNER_LAST_NAME',
    'OWNER_NAME',
    'OWNER_SECOND_NAME',
    'OWNER_FULL_NAME',
    'OWNER_EMAIL',
    'OWNER_WORK_POSITION',
    'OWNER_DEPARTMENT_ID',
    'OWNER_DEPARTMENT_NAME',
    'CREATED_BY_ID',
    'CREATED_BY_FULL_NAME',
    'DATE_FROM',
    'DATE_TO',
    'DT_SKIP_TIME',
    'LOCATION',
    'DESCRIPTION',
    'CAL_TYPE',
    'IS_MEETING',
    'MEETING_STATUS',
    'ACCESSIBILITY',
    'IMPORTANCE',
    'PRIVATE_EVENT',
    'SECTION_ID',
    'DATE_CREATE',
    'TIMESTAMP_X'
];

# Поля для SQL-запроса
$sql_fields = "
    e.ID as EVENT_ID,
    e.NAME as EVENT_NAME,
    e.OWNER_ID,
    u.LAST_NAME as OWNER_LAST_NAME,
    u.NAME as OWNER_NAME,
    u.SECOND_NAME as OWNER_SECOND_NAME,
    u.EMAIL as OWNER_EMAIL,
    u.WORK_POSITION as OWNER_WORK_POSITION,
    u.WORK_DEPARTMENT as OWNER_DEPARTMENT_ID,
    s.NAME as OWNER_DEPARTMENT_NAME,
    e.CREATED_BY as CREATED_BY_ID,
    cu.NAME as CREATED_BY_NAME,
    cu.LAST_NAME as CREATED_BY_LAST_NAME,
    e.DATE_FROM,
    e.DATE_TO,
    e.DT_SKIP_TIME,
    e.LOCATION,
    e.DESCRIPTION,
    e.CAL_TYPE,
    e.IS_MEETING,
    e.MEETING_STATUS,
    e.ACCESSIBILITY,
    e.IMPORTANCE,
    e.PRIVATE_EVENT,
    e.SECTION_ID,
    e.DATE_CREATE,
    e.TIMESTAMP_X
";

# SQL-запрос (без JOIN на пользовательские поля)
$strSql = "
    SELECT 
        $sql_fields
    FROM b_calendar_event e
    LEFT JOIN b_user u ON e.OWNER_ID = u.ID
    LEFT JOIN b_user cu ON e.CREATED_BY = cu.ID
    LEFT JOIN b_iblock_section s ON u.WORK_DEPARTMENT = s.ID AND s.IBLOCK_ID = $DEPARTMENT_IBLOCK_ID
    WHERE e.DELETED = 'N'
    AND e.DATE_FROM >= '" . $DB->ForSql($DATE_FROM) . "'
    ORDER BY e.DATE_FROM DESC
";

$res = $DB->Query($strSql);

# Запись в CSV
$fp = fopen($OUTPUT_FILE, 'w');
if ($fp === false) {
    echo "Не удалось создать файл: " . $OUTPUT_FILE . "\n";
    exit;
}

# Записываем заголовки
fputcsv($fp, $csv_headers, ';');

$count = 0;
while ($row = $res->Fetch()) {
    # Собираем полные имена
    $row['OWNER_FULL_NAME'] = trim($row['OWNER_LAST_NAME'] . ' ' . $row['OWNER_NAME'] . ' ' . $row['OWNER_SECOND_NAME']);
    $row['CREATED_BY_FULL_NAME'] = trim($row['CREATED_BY_LAST_NAME'] . ' ' . $row['CREATED_BY_NAME']);
    
    # Формируем строку по заголовкам
    $csv_row = [];
    foreach ($csv_headers as $field) {
        $csv_row[] = $row[$field] ?? '';
    }
    
    fputcsv($fp, $csv_row, ';');
    $count++;
}

fclose($fp);

# Вывод результата
echo "Экспорт завершен\n";
echo "Выгружено событий: " . $count . "\n";
echo "Файл: b24_calendar_events.csv\n";
echo "Путь: " . __DIR__ . "\n";
echo "Скачать: " . $OUTPUT_FILE . "\n";
?>