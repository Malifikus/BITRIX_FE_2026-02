<?php
namespace B2b\Integration\Helper;

use CCrmCompany;
use CCrmContact;
use CCrmDeal;
use CCrmOwnerType;

class BitrixHelper
{
    // Поиск компании по ИНН в базе Битрикс
    public static function findCompanyByInn($inn)
    {
        global $DB;
        
        if (empty($inn)) return null;
        
        $inn = $DB->ForSQL($inn);
        
        // Поиск в пользовательском поле ИНН
        $res = $DB->Query("SELECT VALUE_ID FROM b_uts_crm_company WHERE UF_CRM_1728386018808 = '$inn' LIMIT 1");
        if ($company = $res->Fetch()) {
            return $company['VALUE_ID'];
        }
        
        // Поиск в реквизитах компании
        $res = $DB->Query("SELECT ENTITY_ID FROM b_crm_requisite WHERE ENTITY_TYPE_ID = 4 AND RQ_INN = '$inn' LIMIT 1");
        if ($company = $res->Fetch()) {
            $companyId = $company['ENTITY_ID'];
            // Обновляем пользовательское поле ИНН для быстрого поиска
            $DB->Query("UPDATE b_uts_crm_company SET UF_CRM_1728386018808 = '$inn' WHERE VALUE_ID = $companyId");
            return $companyId;
        }
        
        return null;
    }

    // Поиск пользователя Битрикс по email
    public static function findUserByEmail($email)
    {
        global $DB;
        
        if (empty($email)) return null;
        
        $email = $DB->ForSQL($email);
        $res = $DB->Query("SELECT ID FROM b_user WHERE EMAIL = '$email' LIMIT 1");
        
        return ($user = $res->Fetch()) ? $user['ID'] : null;
    }

    // Поиск пользователя Битрикс по телефону
    public static function findUserByPhone($phone)
    {
        global $DB;
        
        if (empty($phone)) return null;
        
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $phone = $DB->ForSQL($phone);
        
        $res = $DB->Query("SELECT ID FROM b_user WHERE PERSONAL_PHONE = '$phone' OR WORK_PHONE = '$phone' OR PERSONAL_MOBILE = '$phone' LIMIT 1");
        
        return ($user = $res->Fetch()) ? $user['ID'] : null;
    }

    // Создание новой компании в Битрикс
    public static function createCompany($data)
    {
        $company = new CCrmCompany();
        $id = $company->Add($data);
        
        // Создаем реквизиты если компания создана с ИНН
        if ($id && !empty($data['UF_CRM_1728386018808'])) {
            $requisiteFields = [
                'ENTITY_TYPE_ID' => CCrmOwnerType::Company,
                'ENTITY_ID' => $id,
                'PRESET_ID' => 1,
                'NAME' => $data['TITLE'] ?? 'Компания из B2B',
                'ACTIVE' => 'Y',
                'RQ_INN' => $data['UF_CRM_1728386018808'],
            ];
            
            if (!empty($data['RQ_KPP'])) {
                $requisiteFields['RQ_KPP'] = $data['RQ_KPP'];
            }
            
            $requisite = new \Bitrix\Crm\Requisite();
            $requisite->add($requisiteFields);
        }
        
        return $id;
    }

    // Создание нового контакта в Битрикс
    public static function createContact($data)
    {
        $contact = new CCrmContact();
        return $contact->Add($data);
    }

    // Создание новой сделки в Битрикс
    public static function createDeal($data)
    {
        $deal = new CCrmDeal();
        return $deal->Add($data);
    }

    // Обновление существующей компании
    public static function updateCompany($id, $data)
    {
        $company = new CCrmCompany();
        return $company->Update($id, $data);
    }

    // Обновление существующего контакта
    public static function updateContact($id, $data)
    {
        $contact = new CCrmContact();
        return $contact->Update($id, $data);
    }

    // Поиск контакта по email
    public static function findContactByEmail($email)
    {
        global $DB;
        
        if (empty($email)) return null;
        
        $email = $DB->ForSQL($email);
        $res = $DB->Query("SELECT ID FROM b_crm_contact WHERE EMAIL = '$email' LIMIT 1");
        
        return ($contact = $res->Fetch()) ? $contact['ID'] : null;
    }

    // Поиск контакта по телефону
    public static function findContactByPhone($phone)
    {
        global $DB;
        
        if (empty($phone)) return null;
        
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $phone = $DB->ForSQL($phone);
        
        $res = $DB->Query("SELECT ID FROM b_crm_contact WHERE PHONE = '$phone' LIMIT 1");
        
        return ($contact = $res->Fetch()) ? $contact['ID'] : null;
    }
}