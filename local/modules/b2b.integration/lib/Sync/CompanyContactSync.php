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
    
    // Кэш компаний и менеджеров
    private $companyCache = [];
    private $managerCache = [];
    
    // ID администратора B2B (исключаем из менеджеров)
    private $b2bAdminId = 9947;
    private $techUserId = 1668;
    
    // Режим работы
    private $isFirstImport = false;

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
        $this->detectImportMode();
    }

    private function detectImportMode()
    {
        $firstImportDone = Option::get($this->moduleId, 'first_import_companies_done', 'N');
        $this->isFirstImport = ($firstImportDone != 'Y');
        
        if ($this->isFirstImport) {
            Logger::write('Режим первичный импорт компании и контакты');
        } else {
            Logger::write('Режим регулярное обновление компании и контакты');
        }
    }

    public function run()
    {
        Logger::write('Начало синхронизации компаний и контактов');
        
        if ($this->isFirstImport) {
            $this->runFirstImport();
        } else {
            $this->runRegularUpdate();
        }
        
        Logger::write('Синхронизация компаний и контактов завершена');
    }

    private function runFirstImport()
    {
        Logger::write('Первичный импорт компаний');
        $this->syncCompanies(true);
        
        Logger::write('Первичный импорт контактов');
        $this->syncContacts(true);
        
        Option::set($this->moduleId, 'first_import_companies_done', 'Y');
        
        $now = new DateTime();
        SyncStateTable::updateLastSync('company', 0, $now);
        SyncStateTable::updateLastSync('contact', 0, $now);
    }

    private function runRegularUpdate()
    {
        Logger::write('Регулярное обновление компаний');
        $this->syncCompanies(false);
        
        Logger::write('Регулярное обновление контактов');
        $this->syncContacts(false);
        
        $now = new DateTime();
        SyncStateTable::updateLastSync('company', 0, $now);
        SyncStateTable::updateLastSync('contact', 0, $now);
    }

    private function syncCompanies($isFirstImport)
    {
        $lastSync = $isFirstImport 
            ? DateTime::createFromTimestamp(0) 
            : SyncStateTable::getLastSync('company');
        
        $sqlDate = $lastSync->format('Y-m-d H:i:s');
        
        $sql = "
            SELECT 
                c.id as company_id,
                c.name as company_name,
                c.is_individual,          -- нужно для определения типа (юрлицо/ИП)
                c.updated_at,
                le.inn,
                le.kpp,
                le.name as legal_name,
                le.short_name as legal_short_name
            FROM company c
            LEFT JOIN legal_entity le ON c.id = le.company_id
            WHERE le.inn IS NOT NULL";
        
        if (!$isFirstImport) {
            $sql .= " AND c.updated_at > '$sqlDate'";
        }
        
        $sql .= " ORDER BY c.id ASC";
        
        $result = $this->db->query($sql);
        if (!$result) {
            Logger::error('Ошибка запроса компаний');
            return;
        }
        
        $rows = $this->db->fetchAll($result);
        Logger::write("Найдено компаний для обработки: " . count($rows));
        
        foreach ($rows as $row) {
            $this->processCompany($row);
        }
    }

    private function syncContacts($isFirstImport)
    {
        $lastSync = $isFirstImport 
            ? DateTime::createFromTimestamp(0) 
            : SyncStateTable::getLastSync('contact');
        
        $sqlDate = $lastSync->format('Y-m-d H:i:s');
        
        $sql = "
            SELECT 
                u.id as user_id,
                u.company_id,
                u.firstname,
                u.lastname,
                u.middlename,
                u.phone,
                u.email,
                u.group,
                u.updated_at,
                le.inn as company_inn
            FROM user u
            LEFT JOIN company c ON u.company_id = c.id
            LEFT JOIN legal_entity le ON c.id = le.company_id
            WHERE u.firstname IS NOT NULL 
              AND u.lastname IS NOT NULL
              AND u.group = 'client'";   // если group может быть NULL, можно добавить OR u.group IS NULL
        
        if (!$isFirstImport) {
            $sql .= " AND u.updated_at > '$sqlDate'";
        }
        
        $sql .= " ORDER BY u.id ASC";
        
        $result = $this->db->query($sql);
        if (!$result) {
            Logger::error('Ошибка запроса контактов');
            return;
        }
        
        $rows = $this->db->fetchAll($result);
        Logger::write("Найдено контактов для обработки: " . count($rows));
        
        foreach ($rows as $row) {
            $this->processContact($row);
        }
    }

    private function processCompany($row)
    {
        if (empty($row['inn'])) return;
        
        $companyId = BitrixHelper::findCompanyByInn($row['inn']);
        
        $companyTitle = $row['legal_name'] ?: $row['legal_short_name'] ?: $row['company_name'] ?: 'Компания из B2B';
        
        $companyData = [
            'TITLE' => $companyTitle,
            'COMPANY_TYPE' => 'CUSTOMER',
            'SOURCE_ID' => 'B2B_IMPORT',
            'OPENED' => 'Y',
            'ASSIGNED_BY_ID' => $this->techUserId,
            'UF_CRM_1728386018808' => $row['inn'],
        ];
        
        if ($companyId) {
            BitrixHelper::updateCompany($companyId, $companyData);
            Logger::write("Обновлена компания ID $companyId инн {$row['inn']}");
        } else {
            $companyId = BitrixHelper::createCompany($companyData);
            if ($companyId) {
                Logger::write("Создана компания ID $companyId инн {$row['inn']}");
            } else {
                return;
            }
        }
        
        // Загружаем менеджеров
        $managerIds = $this->loadCompanyManagers($row['company_id']);
        $this->assignCompanyManagers($companyId, $managerIds);
        
        // Сохраняем в кэш
        $this->companyCache[$row['company_id']] = $companyId;
        $this->managerCache[$row['company_id']] = $managerIds;
        
        // ===== НОВОЕ: создаём реквизиты для компании =====
        // Определяем пресет по is_individual
        // 0 = юрлицо (пресет 2), 1 = ИП (пресет 4)
        $presetId = ($row['is_individual'] == 1) ? 4 : 2;
        
        // Проверяем, есть ли уже реквизиты у компании (через BitrixHelper)
        // Если нет, создаём
        //BitrixHelper::createCompanyRequisites($companyId, $row['inn'], $presetId, $row['kpp'] ?? '');
        // ================================================
    }

    private function loadCompanyManagers($b2bCompanyId)
    {
        $sql = "
            SELECT 
                m.id,
                m.email,
                m.phone,
                m.identity
            FROM company_manager_relation cmr
            LEFT JOIN manager m ON cmr.manager_id = m.id
            WHERE cmr.company_id = $b2bCompanyId
              AND m.id != $this->b2bAdminId";
        
        $result = $this->db->query($sql);
        if (!$result) return [];
        
        $managers = $this->db->fetchAll($result);
        if (empty($managers)) return [];
        
        $bitrixManagerIds = [];
        foreach ($managers as $manager) {
            $userId = $this->findBitrixUser($manager);
            if ($userId) {
                $bitrixManagerIds[] = $userId;
            }
        }
        
        return $bitrixManagerIds;
    }

    private function findBitrixUser($manager)
    {
        if (!empty($manager['email'])) {
            $userId = BitrixHelper::findUserByEmail($manager['email']);
            if ($userId) return $userId;
        }
        
        if (!empty($manager['phone'])) {
            $userId = BitrixHelper::findUserByPhone($manager['phone']);
            if ($userId) return $userId;
        }
        
        return null;
    }

    private function assignCompanyManagers($companyId, $managerIds)
    {
        if (empty($managerIds)) return;
        
        $currentAssigned = $this->getCurrentAssigned($companyId, 'company');
        
        $updateData = [
            'UF_COMPANY_B2B_MANAGER' => $managerIds,
            'OBSERVER' => $managerIds
        ];
        
        if (!$currentAssigned || $currentAssigned == $this->techUserId) {
            $updateData['ASSIGNED_BY_ID'] = $managerIds[0];
            Logger::write("Назначен ответственный ID {$managerIds[0]} для компании ID $companyId");
        }
        
        BitrixHelper::updateCompany($companyId, $updateData);
    }

    private function processContact($row)
    {
        if (empty($row['company_inn'])) {
            Logger::write("Пропуск контакта ID {$row['user_id']} нет инн компании");
            return;
        }
        
        $companyId = BitrixHelper::findCompanyByInn($row['company_inn']);
        if (!$companyId) {
            Logger::write("Пропуск контакта ID {$row['user_id']} компания не найдена");
            return;
        }
        
        $contactId = $this->findContact($row);
        
        $contactFields = [
            'NAME' => $row['firstname'] ?? '',
            'LAST_NAME' => $row['lastname'] ?? '',
            'SECOND_NAME' => $row['middlename'] ?? '',
            'COMPANY_ID' => $companyId,
            'SOURCE_ID' => 'B2B_IMPORT',
            'OPENED' => 'Y',
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
        
        $managerIds = $this->managerCache[$row['company_id']] ?? [];
        if (!empty($managerIds)) {
            $contactFields['UF_CONTACT_B2B_MANAGER'] = $managerIds;
            $contactFields['OBSERVER'] = $managerIds;
            
            $currentAssigned = $contactId ? $this->getCurrentAssigned($contactId, 'contact') : null;
            if (!$currentAssigned || $currentAssigned == $this->techUserId) {
                $contactFields['ASSIGNED_BY_ID'] = $managerIds[0];
            }
        }
        
        if ($contactId) {
            $this->updateContact($contactId, $contactFields);
            Logger::write("Обновлён контакт ID $contactId {$row['firstname']} {$row['lastname']}");
        } else {
            $contactId = $this->createContact($contactFields);
            if ($contactId) {
                Logger::write("Создан контакт ID $contactId {$row['firstname']} {$row['lastname']}");
                
                // ===== ДЛЯ КОНТАКТОВ тоже можно создать реквизиты (физлицо, пресет 6), если есть ИНН, но в user нет ИНН. Пока пропускаем.
            }
        }
    }

    private function findContact($row)
    {
        if (!empty($row['email'])) {
            $res = \CCrmContact::GetListEx(
                [],
                ['=FM.EMAIL' => $row['email']],
                false,
                ['nTopCount' => 1],
                ['ID']
            );
            if ($contact = $res->Fetch()) return $contact['ID'];
        }
        
        if (!empty($row['phone'])) {
            $phone = preg_replace('/[^0-9+]/', '', $row['phone']);
            $res = \CCrmContact::GetListEx(
                [],
                ['=FM.PHONE' => $phone],
                false,
                ['nTopCount' => 1],
                ['ID']
            );
            if ($contact = $res->Fetch()) return $contact['ID'];
        }
        
        return null;
    }

    private function createContact($fields)
    {
        $contact = new \CCrmContact();
        $id = $contact->Add($fields);
        if (!$id) Logger::error('Ошибка создания контакта ' . $contact->LAST_ERROR);
        return $id;
    }

    private function updateContact($id, $fields)
    {
        $contact = new \CCrmContact();
        $result = $contact->Update($id, $fields);
        if (!$result) Logger::error('Ошибка обновления контакта ' . $contact->LAST_ERROR);
        return $result;
    }

    private function getCurrentAssigned($entityId, $entityType)
    {
        if ($entityType == 'company') {
            $res = \CCrmCompany::GetListEx([], ['ID' => $entityId], false, ['nTopCount' => 1], ['ASSIGNED_BY_ID']);
        } else {
            $res = \CCrmContact::GetListEx([], ['ID' => $entityId], false, ['nTopCount' => 1], ['ASSIGNED_BY_ID']);
        }
        return ($item = $res->Fetch()) ? $item['ASSIGNED_BY_ID'] : null;
    }
}