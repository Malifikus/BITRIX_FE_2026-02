<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', 'Y');

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

global $DB;

# Настройки
$DATE_FROM = '2015-01-01';
$OUTPUT_FILE = '/tmp/b24_calendar_events.csv';  # Временная папка (права не нужны)
$DEPARTMENT_IBLOCK_ID = 1;

# Проверка прав
if (!is_writable(dirname($OUTPUT_FILE))) {
    echo "Нет прав на запись в папку: " . dirname($OUTPUT_FILE) . "\n";
    exit;
}

# Поля для CSV-заголовка (ВСЕ старые + новые добавлены в конец)
$csv_headers = [
    # === СТАРЫЕ ПОЛЯ (без изменений) ===
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
    'TIMESTAMP_X',
    
    # === НОВЫЕ ПОЛЯ (добавлены) ===
    'OWNER_UF_POSITIONS',      # Должность из UF
    'OWNER_UF_DEPARTMENT',     # Департамент из UF (массив ID)
    'OWNER_UF_DEPARTMENT_NAMES', # Департамент из UF (названия, если нужно)
    'OWNER_OFFICE_NAME',       # Офис из смарт-процесса (UF_USR_OFFICE)
    'OWNER_UF_OFICE'           # Офис из текстового поля UF_OFICE
];

# Поля для SQL-запроса (ВСЕ старые + новые)
$sql_fields = "
    # === СТАРЫЕ ПОЛЯ ===
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
    e.TIMESTAMP_X,
    
    # === НОВЫЕ ПОЛЯ из b_uts_user ===
    uf.UF_USR_POSITIONS as OWNER_UF_POSITIONS,
    uf.UF_DEPARTMENT as OWNER_UF_DEPARTMENT,
    uf.UF_OFICE as OWNER_UF_OFICE,
    
    # === Офис из смарт-процесса ===
    office.TITLE as OWNER_OFFICE_NAME
";

# SQL-запрос
$strSql = "
    SELECT 
        $sql_fields
    FROM b_calendar_event e
    LEFT JOIN b_user u ON e.OWNER_ID = u.ID
    LEFT JOIN b_user cu ON e.CREATED_BY = cu.ID
    LEFT JOIN b_iblock_section s ON u.WORK_DEPARTMENT = s.ID AND s.IBLOCK_ID = $DEPARTMENT_IBLOCK_ID
    LEFT JOIN b_uts_user uf ON u.ID = uf.VALUE_ID
    LEFT JOIN b_crm_dynamic_items_1250 office ON uf.UF_USR_OFFICE = office.ID
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

fputcsv($fp, $csv_headers, ';');

$count = 0;
while ($row = $res->Fetch()) {
    # Собираем полные имена
    $row['OWNER_FULL_NAME'] = trim($row['OWNER_LAST_NAME'] . ' ' . $row['OWNER_NAME'] . ' ' . $row['OWNER_SECOND_NAME']);
    $row['CREATED_BY_FULL_NAME'] = trim($row['CREATED_BY_LAST_NAME'] . ' ' . $row['CREATED_BY_NAME']);
    
    # Если нужно распарсить UF_DEPARTMENT в названия (опционально)
    $uf_dept_names = '';
    if (!empty($row['OWNER_UF_DEPARTMENT'])) {
        $dept_ids = @unserialize($row['OWNER_UF_DEPARTMENT']);
        if (is_array($dept_ids)) {
            $names = [];
            foreach ($dept_ids as $dept_id) {
                $dept_res = $DB->Query("SELECT NAME FROM b_iblock_section WHERE ID = ".intval($dept_id));
                $dept_row = $dept_res->Fetch();
                if ($dept_row) $names[] = $dept_row['NAME'];
            }
            $uf_dept_names = implode(', ', $names);
        }
    }
    $row['OWNER_UF_DEPARTMENT_NAMES'] = $uf_dept_names;
    
    # Формируем строку по заголовкам
    $csv_row = [];
    foreach ($csv_headers as $field) {
        $csv_row[] = $row[$field] ?? '';
    }
    
    fputcsv($fp, $csv_row, ';');
    $count++;
}

fclose($fp);

echo "Экспорт завершен\n";
echo "Выгружено событий: " . $count . "\n";
echo "Файл: " . $OUTPUT_FILE . "\n";
echo "Скопировать себе: cp " . $OUTPUT_FILE . " /home/vp/\n";
?>