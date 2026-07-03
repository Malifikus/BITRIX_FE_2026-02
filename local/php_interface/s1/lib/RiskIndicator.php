<?php

use Bitrix\Main\Loader;

class RiskIndicator
{
    public static function GetUserTypeDescription()
    {
        return [
            'USER_TYPE_ID' => 'risk_indicator',
            'CLASS_NAME' => __CLASS__,
            'DESCRIPTION' => 'Индикатор риска (Сбер)',
            'BASE_TYPE' => 'enum',
            'EDIT_CALLBACK' => [__CLASS__, 'GetEditFormHTML'],
            'VIEW_CALLBACK' => [__CLASS__, 'GetAdminListViewHTML'],
        ];
    }

    
    // Редактирование
    public static function GetEditFormHTML($userField, $htmlControl)
    {
        $value = $htmlControl['VALUE'] ?? '';
        $fieldName = htmlspecialcharsbx($htmlControl['NAME'] ?? '');
        
        Loader::includeModule('iblock');
        $enum = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => (int)$userField['ID']]);
        
        $html = '<select name="' . $fieldName . '">';
        $html .= '<option value="">-- Выберите --</option>';
        while ($ar = $enum->Fetch()) {
            $selected = ($ar['ID'] == $value) ? ' selected' : '';
            $html .= '<option value="' . (int)$ar['ID'] . '"' . $selected . '>' . htmlspecialcharsbx($ar['VALUE']) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // Просмотр
    public static function GetAdminListViewHTML($userField, $htmlControl)
    {
        $value = (int)($htmlControl['VALUE'] ?? 0);
        if (!$value) return '&nbsp;';
        
        $colors = [
            1 => '#22A65B',
            2 => '#F7A700', 
            3 => '#E97A00',
            4 => '#D12121',
        ];
        $color = $colors[$value] ?? '#CCCCCC';
        
        Loader::includeModule('iblock');
        $enum = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => (int)$userField['ID']]);
        $label = (string)$value;
        while ($ar = $enum->Fetch()) {
            if ((int)$ar['ID'] === $value) {
                $label = $ar['VALUE'];
                break;
            }
        }
        
        return '<span style="display:inline-block;background:' . $color . ';color:#fff;padding:4px 16px;border-radius:20px;font-size:13px;font-weight:600;">' . htmlspecialcharsbx($label) . '</span>';
    }
}

// Регистрация для CRM
AddEventHandler('main', 'OnUserTypeBuildList', ['RiskIndicator', 'GetUserTypeDescription']);