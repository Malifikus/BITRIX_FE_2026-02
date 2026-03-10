<?

// Подключение общих для всех скриптов констант
include_once(__DIR__ . '/init_scripts/constants.php');


// Изменение настроек пользователей при загрузке страницы
// Используется для задания колонок по умолчанию в списочном представлении СП Проекты
include_once(__DIR__ . '/init_scripts/event_handlers/edit_user_options_on_prolog.php');

// Скрытие элементов при загрузке страницы
// Используется для скрытия:
// - некоторых полей и разделов в карточках CRM
// - "шестерёнок" в списочных представлениях CRM
// - кнопки создания задач в группах
include_once(__DIR__ . '/init_scripts/event_handlers/hide_view_elements_on_prolog.php');

// Обработка новых почтовых сообщений
// Внутри закомментирован (использовался раньше для обработки писем в техническую поддержку)
// include_once(__DIR__ . '/init_scripts/event_handlers/on_mail_message_new.php');

// Обработка нового сообщения форума
// Используется для кастомных уведомлений по комментариям БП и добавления упоминаемых в наблюдатели СП
include_once(__DIR__ . '/init_scripts/event_handlers/on_after_message_add.php');

// Обработка события после добавления элемента инфоблока
// Используется для авто-запуска БП по элементам Истории состояний
include_once(__DIR__ . '/init_scripts/event_handlers/on_after_iblock_element_add.php');

// Зависимые поля в СRM и Инфоблоках
include_once(__DIR__ . '/init_scripts/event_handlers/field_dependency.php');

// Изменение стилей и логики при загрузке страницы
// Используется для:
// - расширения select на странице элемента инфоблока
// - двойного ряда вкладок в СП Проекты
// - скрытия кнопки обсуждения в таймлайне некоторых СП
// - доработки для запуска БП при создании задачи в Потоках
include_once(__DIR__ . '/init_scripts/event_handlers/change_styles_on_prolog.php');

// Обязательность заполнения реквизитов Компании CRM
include_once(__DIR__ . '/init_scripts/event_handlers/company_requisites_required.php');

// Скрытие кнопки "Выбрать элемент" во вкладках карточки CRM
include_once(__DIR__ . '/init_scripts/event_handlers/hide_pick_element_button.php');

// Изменение стилей и логики после загрузки страницы
// Используется для части доработки по запуску БП при создании задачи в Потоках
include_once(__DIR__ . '/init_scripts/event_handlers/on_epilog_change_styles.php');

// Обработка создания новой задачи
// Внутри закомментирован (пока непонятно, для чего использовался)
// include_once(__DIR__ . '/init_scripts/event_handlers/on_task_add.php');


// Изменение настроек списочных представлений при загрузке страницы
// Используется для изменения списочных представлений CRM через список "Настройки отображения списков"
include_once(__DIR__ . '/init_scripts/event_handlers/edit_user_options_list_view.php');

// Отключение всех уведомлений портала
// include_once(__DIR__ . '/init_scripts/event_handlers/disable_notifications.php');

// Отключение уведомлений типа: "добавил комментарий к процессу"
include_once(__DIR__ . '/init_scripts/event_handlers/disable_specific_notifications.php');

// Кнопка Платежи в цифровом рабочем месте
// include_once(__DIR__ . '/init_scripts/event_handlers/invoiceButton.php');

// Валидация длины отчета weekly с переопределением js файла
include_once(__DIR__ . '/init_scripts/event_handlers/report_validator_on_epilog.php');


// Меню-слайдер в левом меню Битрикс24 на пункте "CRM"
// include_once(__DIR__ . '/init_scripts/crm_menu/connect_menu.php');


// Переопределение сервиса crm.service.container
// Используется для проверки зависимых полей при создании/изменении СП
include_once(__DIR__ . '/init_scripts/services/crm_service_container.php');

?>