<?

include_once(__DIR__ . '/init_scripts/constants.php');

include_once(__DIR__ . '/init_scripts/event_handlers/edit_user_options_on_prolog.php');
include_once(__DIR__ . '/init_scripts/event_handlers/hide_view_elements_on_prolog.php');
include_once(__DIR__ . '/init_scripts/event_handlers/on_mail_message_new.php');
include_once(__DIR__ . '/init_scripts/event_handlers/on_after_message_add.php');
include_once(__DIR__ . '/init_scripts/event_handlers/on_after_iblock_element_add.php');
include_once(__DIR__ . '/init_scripts/event_handlers/field_dependency.php');
include_once(__DIR__ . '/init_scripts/event_handlers/change_styles_on_prolog.php');
include_once(__DIR__ . '/init_scripts/event_handlers/company_requisites_required.php');
include_once(__DIR__ . '/init_scripts/event_handlers/hide_pick_element_button.php');
include_once(__DIR__ . '/init_scripts/event_handlers/on_epilog_change_styles.php');
include_once(__DIR__ . '/init_scripts/event_handlers/on_task_add.php');

include_once(__DIR__ . '/init_scripts/services/crm_service_container.php');

include_once(__DIR__ . '/init_scripts/event_handlers/edit_user_options_list_view.php');

// Отключение всех уведомлений портала
// include_once(__DIR__ . '/init_scripts/event_handlers/disable_notifications.php');

// Отключение уведомлений типа: "добавил комментарий к процессу"
include_once(__DIR__ . '/init_scripts/event_handlers/disable_specific_notifications.php');

// Меню-слайдер в левом меню Битрикс24 на пункте "CRM"
// include_once(__DIR__ . '/init_scripts/crm_menu/connect_menu.php');

// Кнопка Платежи в цифровом рабочем месте
// include_once(__DIR__ . '/init_scripts/event_handlers/invoiceButton.php');

// Валидация длины отчета weekly с переопределением js файла
include_once(__DIR__ . '/init_scripts/event_handlers/report_validator_on_epilog.php');

?>