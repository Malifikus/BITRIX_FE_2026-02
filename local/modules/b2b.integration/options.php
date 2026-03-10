<?php
// Подключение страниц настроек модуля
define('ADMIN_MODULE_NAME', 'b2b.integration');
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$moduleId = 'b2b.integration';
Loader::includeModule($moduleId);

$request = HttpApplication::getInstance()->getContext()->getRequest();

// Убираем права
$showRightsTab = false;

// Сброс флагов импорта
if ($request->isPost() && $request['reset_full_import'] == 'Y' && check_bitrix_sessid()) {
    Option::set($moduleId, 'full_import_done', 'N');
    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . $moduleId . '&lang=' . LANG);
}

$aTabs = [
    // Вкладка 1: Подключение к MySQL
    [
        'DIV' => 'edit1',
        'TAB' => 'Подключение',
        'TITLE' => 'Настройки подключения к MySQL',
        'OPTIONS' => [
            ['mysql_host', 'Хост MySQL:', '', ['text', 20]],
            ['mysql_port', 'Порт:', '', ['text', 6]],
            ['mysql_database', 'База данных:', '', ['text', 30]],
            ['mysql_user', 'Пользователь:', '', ['text', 30]],
            ['mysql_password', 'Пароль:', '', ['password', 30]],
        ]
    ],
    // Вкладка 2: Синхронизация
    [
        'DIV' => 'edit2',
        'TAB' => 'Синхронизация',
        'TITLE' => 'Настройки синхронизации',
        'OPTIONS' => [
            ['sync_company_contact', 'Синхронизировать компании и контакты:', 'Y', ['checkbox']],
            ['sync_orders', 'Синхронизировать заказы:', 'Y', ['checkbox']],
            ['sync_products', 'Синхронизировать товары:', 'Y', ['checkbox']],
    
            ['deal_category_id', 'ID воронки сделок:', '35', ['text', 5]],
    
            ['sync_mode', 'Режим синхронизации:', 
                [
            'incremental' => 'Инкрементальный (только изменения)',
            'full' => 'Полный (все записи)',
            'date_range' => 'По дате'
                ], 
                ['selectbox']
            ],
            ['sync_date_from', 'Начальная дата:', '', ['text', 10]],
            ['sync_date_to', 'Конечная дата:', '', ['text', 10]],
    
            ['reset_full_import', 'Сбросить флаг первичного импорта:', 'N', ['checkbox']],
        ]
    ]
];

// Добавляем вкладку с правами
if ($showRightsTab) {
    $aTabs[] = [
        'DIV' => 'edit3',
        'TAB' => 'Права доступа',
        'TITLE' => 'Права доступа к модулю',
    ];
}

if ($request->isPost() && $request['save'] && check_bitrix_sessid()) {
    foreach ($aTabs as $aTab) {
        foreach ($aTab['OPTIONS'] as $option) {
            $name = $option[0];
            
            if ($name == 'reset_full_import') continue;
            
            $value = $request->getPost($name);
            
            if ($option[3][0] == 'checkbox') {
                $value = $value == 'Y' ? 'Y' : 'N';
            }
            
            Option::set($moduleId, $name, $value);
        }
    }
    
    // Сохраняем права доступа
    if ($showRightsTab && $request['update']) {
        $UPDATE = $request['update'];
    }
    
    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . $moduleId . '&lang=' . LANG);
}

// Создаем вкладки с учетом прав
$tabControl = new CAdminTabControl('tabControl', $aTabs, true, true);
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= $moduleId ?>&lang=<?= LANG ?>">
    <?= bitrix_sessid_post() ?>
    <? $tabControl->Begin(); ?>
    
    <? foreach ($aTabs as $aTab): ?>
        <? $tabControl->BeginNextTab(); ?>
        <? if ($aTab['DIV'] == 'edit3' && $showRightsTab): ?>
            <? require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights.php'; ?>
        <? else: ?>
            <? foreach ($aTab['OPTIONS'] as $option): ?>
                <tr>
                    <td width="40%"><?= $option[1] ?></td>
                    <td width="60%">
                        <? 
                        $type = $option[3][0];
                        $name = $option[0];
                        $value = Option::get($moduleId, $name, $option[2]);
                        
                        if ($type == 'checkbox'): ?>
                            <input type="checkbox" 
                                   name="<?= $name ?>" 
                                   value="Y" 
                                   <?= ($value == 'Y') ? 'checked' : '' ?>>
                        
                        <? elseif ($type == 'password'): ?>
                            <input type="password" 
                                   name="<?= $name ?>" 
                                   value="<?= htmlspecialcharsbx($value) ?>"
                                   size="<?= $option[3][1] ?>">
                        
                        <? elseif ($type == 'selectbox'): ?>
                            <select name="<?= $name ?>">
                                <? foreach ($option[2] as $optValue => $optLabel): ?>
                                    <option value="<?= $optValue ?>" <?= ($value == $optValue) ? 'selected' : '' ?>>
                                        <?= $optLabel ?>
                                    </option>
                                <? endforeach ?>
                            </select>
                        
                        <? else: ?>
                            <input type="text" 
                                   name="<?= $name ?>" 
                                   value="<?= htmlspecialcharsbx($value) ?>"
                                   size="<?= $option[3][1] ?>">
                        <? endif ?>
                    </td>
                </tr>
            <? endforeach ?>
        <? endif ?>
    <? endforeach ?>
    
    <? $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить настройки" class="adm-btn-save">
    <? $tabControl->End(); ?>
</form>

<?php
// Добавляем автосохранение
if (CAutoSave::Allowed()) {
    $AUTOSAVE = new CAutoSave();
    $AUTOSAVE->Init();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
?>