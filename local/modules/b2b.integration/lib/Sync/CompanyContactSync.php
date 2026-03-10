<?php
namespace B2b\Integration\Sync;

use B2b\Integration\Helper\DbHelper;
use B2b\Integration\Helper\Logger;
use B2b\Integration\Helper\BitrixHelper;
use B2b\Integration\Table\SyncStateTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class CompanyContactSync
{
    private $db;
    private $moduleId = 'b2b.integration';

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
    }

    public function run($fromDate = null)
    {
        $fullImportDone = Option::get($this->moduleId, 'full_import_done', 'N');
        
        if ($fullImportDone != 'Y') {
            Logger::write('Первичный импорт компаний+контактов не выполнен. Запускаем полный импорт...');
            $this->fullImport();
            Option::set($this->moduleId, 'full_import_done', 'Y');
        } else {
            Logger::write('Запуск инкрементальной синхронизации компаний+контактов');
            $this->incrementalImport($fromDate);
        }
    }

    public function fullImport()
    {
        Logger::write('Начало ПОЛНОГО импорта компаний+контактов');
        
        // Импортируем все компании
        $this->fullImportCompanies();
        
        // Импортируем контакты
        $this->fullImportContacts();
        
        SyncStateTable::updateLastSync('company_contact');
        Logger::success("Полный импорт компаний+контактов завершен");
    }

    private function fullImportCompanies()
    {
        Logger::write('Импорт компаний');
        
        $page = 0;
        $limit = 500;
        $totalProcessed = 0;
        
        do {
            $offset = $page * $limit;
            
            $sql = "
                SELECT 
                    c.id,
                    c.name,
                    c.updated_at,
                    le.inn,
                    le.kpp,
                    le.name as legal_name,
                    le.short_name
                FROM company c
                LEFT JOIN legal_entity le ON c.id = le.company_id
                WHERE c.is_test = 0
                ORDER BY c.id ASC
                LIMIT $limit OFFSET $offset";
            
            $result = $this->db->query($sql);
            if (!$result) break;
            
            $rows = $this->db->fetchAll($result);
            $processed = 0;
            
            foreach ($rows as $row) {
                if ($this->processCompany($row)) {
                    $processed++;
                }
            }
            
            $totalProcessed += $processed;
            Logger::write("Страница $page: обработано $processed компаний");
            
            $page++;
            
        } while (count($rows) == $limit);
        
        Logger::success("Импорт компаний завершен. Всего: $totalProcessed");
    }

    private function fullImportContacts()
    {
        Logger::write('Импорт контактов для компаний');
        
        // Получаем список всех компаний с ИНН
        $sql = "
            SELECT 
                c.id as company_id,
                le.inn
            FROM company c
            LEFT JOIN legal_entity le ON c.id = le.company_id
            WHERE le.inn IS NOT NULL";
        
        $result = $this->db->query($sql);
        if (!$result) return;
        
        $companies = $this->db->fetchAll($result);
        $totalProcessed = 0;
        
        foreach ($companies as $company) {
            $processed = $this->importContactsForCompany($company['company_id'], $company['inn']);
            $totalProcessed += $processed;
        }
        
        Logger::success("Импорт контактов завершен. Всего: $totalProcessed");
    }

    private function importContactsForCompany($companyId, $inn)
    {
        // Ищем компанию в Битрикс по ИНН
        $bitrixCompanyId = BitrixHelper::findCompanyByInn($inn);
        if (!$bitrixCompanyId) {
            Logger::write("Компания с ИНН $inn не найдена в Битрикс, контакты не импортируются");
            return 0;
        }
        
        // Получаем контакты компании из order_meta
        $sql = "
            SELECT 
                om.user_firstname as firstname,
                om.user_lastname as lastname,
                om.user_phone as phone,
                om.user_email as email
            FROM order_meta om
            JOIN `order` o ON om.order_id = o.id
            WHERE o.company_id = " . (int)$companyId . "
            AND om.user_role = 'Client'
            AND om.user_firstname IS NOT NULL
            AND om.user_lastname IS NOT NULL
            AND (om.user_email IS NOT NULL OR om.user_phone IS NOT NULL)
            GROUP BY om.user_email";
        
        $result = $this->db->query($sql);
        if (!$result) return 0;
        
        $contacts = $this->db->fetchAll($result);
        $processed = 0;
        
        foreach ($contacts as $contact) {
            if ($this->processContact($contact, $bitrixCompanyId)) {
                $processed++;
            }
        }
        
        return $processed;
    }

    public function incrementalImport($fromDate = null)
    {
        if ($fromDate) {
            $sqlDate = $fromDate . ' 00:00:00';
            Logger::write("Используем дату из формы: $sqlDate");
        } else {
            $lastSync = SyncStateTable::getLastSync('company_contact');
            $sqlDate = $lastSync->format('Y-m-d H:i:s');
        }
        
        // Импортируем измененные компании
        $this->incrementalImportCompanies($sqlDate);
        
        // Импортируем контакты из измененных заказов
        $this->incrementalImportContacts($sqlDate);
        
        SyncStateTable::updateLastSync('company_contact');
    }

    private function incrementalImportCompanies($sqlDate)
    {
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.updated_at,
                le.inn,
                le.kpp,
                le.name as legal_name,
                le.short_name
            FROM company c
            LEFT JOIN legal_entity le ON c.id = le.company_id
            WHERE c.updated_at > '$sqlDate'
            AND c.is_test = 0
            ORDER BY c.updated_at ASC";
        
        $result = $this->db->query($sql);
        if (!$result) return;
        
        $rows = $this->db->fetchAll($result);
        $processed = 0;
        
        foreach ($rows as $row) {
            if ($this->processCompany($row)) {
                $processed++;
            }
        }
        
        Logger::write("Инкрементальный импорт компаний: $processed");
    }

    private function incrementalImportContacts($sqlDate)
    {
        // Получаем заказы измененные после даты
        $sql = "
            SELECT 
                o.id as order_id,
                o.company_id,
                le.inn
            FROM `order` o
            LEFT JOIN company c ON o.company_id = c.id
            LEFT JOIN legal_entity le ON c.id = le.company_id
            WHERE o.updated_at > '$sqlDate'
            AND le.inn IS NOT NULL
            GROUP BY o.company_id";
        
        $result = $this->db->query($sql);
        if (!$result) return;
        
        $orders = $this->db->fetchAll($result);
        $totalProcessed = 0;
        
        foreach ($orders as $order) {
            $processed = $this->importContactsForCompany($order['company_id'], $order['inn']);
            $totalProcessed += $processed;
        }
        
        Logger::write("Инкрементальный импорт контактов: $totalProcessed");
    }

    private function processCompany($row)
    {
        if (empty($row['inn'])) {
            Logger::write("Компания ID {$row['id']} без ИНН, пропускаем");
            return false;
        }
        
        // Ищем компанию по ИНН
        $companyId = BitrixHelper::findCompanyByInn($row['inn']);
        
        $companyData = [
            'TITLE' => $row['name'] ?: $row['legal_name'] ?: $row['short_name'] ?: 'Компания из B2B',
            'COMPANY_TYPE' => 'OTHER',
            'SOURCE_ID' => 'B2B_IMPORT',
            'OPENED' => 'Y',
            'ASSIGNED_BY_ID' => 1,
            'UF_CRM_1728386018808' => $row['inn'],
        ];
        
        // Добавляем КПП
        if (!empty($row['kpp'])) {
            $companyData['RQ_KPP'] = $row['kpp'];
        }
        
        if ($companyId) {
            // Обновление компании
            BitrixHelper::updateCompany($companyId, $companyData);
            Logger::write("Обновлена компания ID $companyId (ИНН {$row['inn']})");
        } else {
            // Создаем новую компанию
            $companyId = BitrixHelper::createCompany($companyData);
            if ($companyId) {
                Logger::success("Создана компания ID $companyId (ИНН {$row['inn']})");
            }
        }
        
        return $companyId ? true : false;
    }

    private function processContact($row, $companyId)
    {
        if (empty($row['email']) && empty($row['phone'])) {
            return false;
        }
        
        // Ищем существующий контакт по email или телефону
        $contactId = null;
        if (!empty($row['email'])) {
            $contactId = BitrixHelper::findUserByEmail($row['email']);
        }
        if (!$contactId && !empty($row['phone'])) {
            $contactId = BitrixHelper::findUserByPhone($row['phone']);
        }
        
        $contactFields = [
            'NAME' => $row['firstname'],
            'LAST_NAME' => $row['lastname'],
            'SOURCE_ID' => 'B2B_IMPORT',
            'OPENED' => 'Y',
            'ASSIGNED_BY_ID' => 1,
            'COMPANY_ID' => $companyId,
        ];
        
        if (!empty($row['phone'])) {
            $contactFields['FM']['PHONE'] = [
                ['VALUE' => $row['phone'], 'VALUE_TYPE' => 'WORK']
            ];
        }
        
        if (!empty($row['email'])) {
            $contactFields['FM']['EMAIL'] = [
                ['VALUE' => $row['email'], 'VALUE_TYPE' => 'WORK']
            ];
        }
        
        if ($contactId) {
            // Обновляем контакт
            BitrixHelper::updateContact($contactId, $contactFields);
            Logger::write("Обновлен контакт ID $contactId");
            return true;
        } else {
            // Создаем новый
            $contactId = BitrixHelper::createContact($contactFields);
            if ($contactId) {
                Logger::success("Создан контакт ID $contactId: {$row['firstname']} {$row['lastname']}");
                return true;
            }
        }
        
        return false;
    }
}