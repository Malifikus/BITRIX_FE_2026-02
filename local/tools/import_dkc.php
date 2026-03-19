<?php
// Подключение Ядра Битрикс
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Проверка Модуля Highloadblock
if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
    die("Ошибка Модуль Highloadblock Не Подключен");
}

use Bitrix\Highloadblock as HL;

// Настройки Импорта
$hlblockId = 12;
$fileName = 'csvdkcpricewith5category.csv';
$delimiter = ';';

// Путь К Файлу
$filePath = __DIR__ . '/' . $fileName;

// Проверка Существования Файла
if (!file_exists($filePath)) {
    die("Ошибка Файл Не Найден " . $filePath);
}

// Получение Класса Hl Блока
$hlblock = HL\HighloadBlockTable::getById($hlblockId)->fetch();
if (!$hlblock) {
    die("Ошибка Hl Блок С Id " . $hlblockId . " Не Найден");
}

$entity = HL\HighloadBlockTable::compileEntity($hlblock);
$class = $entity->getDataClass();

echo "Старт Импорта В Блок Id " . $hlblockId . "<br>";

// Открытие Файла Для Чтения
$handle = fopen($filePath, "r");
if (!$handle) {
    die("Ошибка Не Удалось Открыть Файл");
}

// Чтение Заголовков Из Первой Строки
$headers = fgetcsv($handle, 0, $delimiter);
if (!$headers) {
    die("Ошибка Файл Пуст");
}

// Вывод Найденных Заголовков Для Отладки
echo "Найденные Заголовки В Файле:<br><b>" . implode(" | ", $headers) . "</b><hr>";

// Поиск Индекса Колонки С Артикулом (Ищем Code Или Первую Колонку)
$articleIndex = -1;
foreach ($headers as $i => $name) {
    $cleanName = trim($name);
    // Ищем точное совпадение или частичное (Code, Артикул, Код)
    if ($cleanName === 'Code' || stripos($cleanName, 'code') !== false || stripos($cleanName, 'артикул') !== false) {
        $articleIndex = $i;
        echo "Найдена Колонка Артикула: " . $cleanName . " (Индекс " . $i . ")<br>";
        break;
    }
}

// Если не нашли по имени, берем первую колонку (индекс 0) как артикул
if ($articleIndex == -1) {
    $articleIndex = 0;
    echo "Колонка Code Не Найдена. Используем Первую Колонку (Индекс 0) Как Артикул.<br>";
}

// Карта Остальных Колонок (Категории Ищем По Порядку Или Имени)
// Предполагаем что после артикула идут 5 категорий подряд
$catIndexes = [];
for ($k = 1; $k <= 5; $k++) {
    $idx = $articleIndex + $k;
    if (isset($headers[$idx])) {
        $catIndexes['UF_CAT_' . $k] = $idx;
        echo "Категория " . $k . ": " . trim($headers[$idx]) . " (Индекс " . $idx . ")<br>";
    } else {
        die("Ошибка Не Хватает Колонок Для Категории " . $k);
    }
}

echo "<hr>Заголовки Распознаны Верно<br>";

$countAdded = 0;
$countSkipped = 0;
$rowNum = 1;

// Цикл Обработки Строк Файла
while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
    $rowNum++;
    
    // Пропуск Пустых Строк
    if (empty($data[0])) continue;

    // Формирование Массива Данных Для Записи
    $fields = [];
    
    // Берем Артикул
    $article = trim($data[$articleIndex], " \"");
    $fields['UF_ARTICLE'] = $article;
    
    // Берем Категории
    foreach ($catIndexes as $ufField => $index) {
        $val = trim($data[$index], " \"");
        $fields[$ufField] = $val;
    }

    // Пропуск Если Артикул Пустой
    if (empty($article)) {
        $countSkipped++;
        continue;
    }

    // Проверка На Дубликат По Артикулу
    $exist = $class::getList([
        'filter' => ['=UF_ARTICLE' => $article],
        'select' => ['ID']
    ])->fetch();

    if ($exist) {
        $countSkipped++;
        continue;
    }

    // Добавление Новой Записи В Блок
    $result = $class::add($fields);
    if ($result->isSuccess()) {
        $countAdded++;
    } else {
        echo "Строка " . $rowNum . " Ошибка Добавления " . $article . "<br>";
    }
}

// Закрытие Файла
fclose($handle);

// Вывод Результатов Импорта
echo "<hr>Готово<br>";
echo "Добавлено " . $countAdded . "<br>";
echo "Пропущено " . $countSkipped . "<br>";

// Завершение Работы Ядра
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");
?>