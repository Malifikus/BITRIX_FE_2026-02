# Разработка кастомного активити для бизнес-процессов в Битрикс24

## Структура кастомного активити

### Путь размещения
Поиск активити происходит в следующем порядке:
1. `/local/activities`
2. `/local/activities/custom`
3. `/bitrix/activities/custom`
4. `/bitrix/activities/bitrix`
5. `/bitrix/modules/bizproc/activities`

### Минимальная структура папки

/local/activities/custom/{название_активности}/
├── .description.php # Описание активности
├── {название}.php # Логика активности
└── lang/ # (опционально) Файлы локализации
└── ru/
└── .description.php


---

## Файл .description.php

### Базовый пример

```php
<?php
$arActivityDescription = [
    'NAME' => 'Получаем элементы списка',
    'DESCRIPTION' => 'Получаем все элементы списка из информационного блока',
    'TYPE' => 'activity',
    'CLASS' => 'GetListActivity',
    'JSCLASS' => 'BizProActivity',  // можно указать свой JS обработчик
    'CATEGORY' => [
        'ID' => 'otusedu',
        'OWN_ID' => 'otusedu',
        'OWN_NAME' => 'Собственные компоненты',
    ],
    // Ограничение по сущностям (опционально)
    // 'FILTER' => [
    //     'INCLUDE' => [
    //         ['crm', 'CCrmDocumentCompany'],
    //     ],
    // ],
    // Возвращаемые значения (опционально)
    // 'RETURN' => [
    //     'LIST' => [
    //         'NAME' => 'Список элементов',
    //         'TYPE' => FieldType::ARRAY,
    //     ],
    // ],
];
?>

Готовые категории для CATEGORY['ID']
interaction

document

logic

other

rest

robot_settings

service

settings

web_service

Файл активности (логика)
Базовая структура класса
php
<?php
class [НазваниеПапкиСБольшойБуквы] extends CBPActivity
{
    public function __construct($name)
    {
        parent::__construct($name);
    }
    
    /**
     * Начинает выполнение действия
     */
    public function Execute()
    {
        // Основная логика
        return CBPActivityExecutionStatus::Closed;
    }
    
    /**
     * Обработчик ошибки выполнения БП
     * Вызывается, если ошибка произошла во время выполнения действия
     */
    public function HandleFault(Exception $exception)
    {
        // Логика обработки ошибки
    }
    
    /**
     * Обработчик остановки БП
     * Вызывается, если остановка произошла во время выполнения действия
     */
    public function Cancel()
    {
        // Логика при остановке
    }
}
?>
Пример с параметрами
php
<?php
class CompanySearchSberActivity extends CBPActivity
{
    public function __construct($name)
    {
        parent::__construct($name);
        
        $this->arProperties = [
            "Title" => "",
            "INN" => "",      // Входной параметр
            "Result" => null  // Выходной параметр
        ];
    }
    
    public function Execute()
    {
        $inn = $this->INN;
        
        // Логика поиска через API
        
        $this->Result = "Результат для ИНН: " . $inn;
        
        return CBPActivityExecutionStatus::Closed;
    }
}
?>
Файл локализации
lang/ru/.description.php
php
<?php
$MESS['ACTIVITY_NAME'] = 'Название активности';
$MESS['ACTIVITY_DESCRIPTION'] = 'Описание активности';
$MESS['PARAM_NAME'] = 'Название параметра';
$MESS['RESULT_NAME'] = 'Название результата';
?>
Калькулятор выражений
Вызывается нажатием = в любом поле активности.

Полезные функции
Функция	Описание	Пример
touserdate	Приводит время к часовому поясу пользователя	{{=touserdate('user_3', '18.07.2023 16:47:01')}}
numberformat	Форматирует число	{{=numberformat(1300500.5, 2, ',', ' ')}}
substr	Обрезает строку	{{=substr("0123456789", 3, 4)}}
locdate	Аналог PHP функции	{{=locdate('1, j F Y, H:i:s')}}
Полезные ссылки
Документация по активностям
Создание своей активности

Более подробное руководство

Создание action box в B24

Работа с документами в БП
Получение полей документа из БП

Обновление значений полей документа

Дополнительно
Создание компании и сделки через API

Калькулятор выражений

Типичные ошибки и их решение
Ошибка	Решение
ENOENT: no such file or directory для .description.php	Создать файл и папку lang/ru/
Активность не отображается в дизайнере	Очистить кеш Bitrix и проверить структуру папок
Ошибка класса активности	Проверить соответствие имени класса и папки
Не работают переводы	Проверить наличие файлов локализации в lang/ru/