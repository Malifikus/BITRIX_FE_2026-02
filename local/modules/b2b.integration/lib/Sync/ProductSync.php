<?php
namespace B2b\Integration\Sync;

use B2b\Integration\Helper\DbHelper;
use B2b\Integration\Helper\Logger;
use B2b\Integration\Table\SyncStateTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class ProductSync
{
    private $db;
    private $moduleId = 'b2b.integration';

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
    }

    public function run()
{
    // Временный импорт
    $this->incrementalImport();
}
    // public function run()
    // {
    //     $fullImportDone = Option::get($this->moduleId, 'full_import_products', 'N');
        
    //     if ($fullImportDone != 'Y') {
    //         Logger::write('Первичный импорт товаров не выполнен. Запускаем полный импорт...');
    //         $this->fullImport();
    //         Option::set($this->moduleId, 'full_import_products', 'Y');
    //     } else {
    //         Logger::write('Запуск инкрементальной синхронизации товаров');
    //         $this->incrementalImport();
    //     }
    // }

    public function fullImport()
    {
        Logger::write('Начало импорта товаров');
        
        $page = 0;
        $limit = 500;
        $totalProcessed = 0;
        
        do {
            $offset = $page * $limit;
            
            $sql = "
                SELECT 
                    p.id,
                    p.article,
                    p.name,
                    p.manufacturer_code,
                    p.type,
                    p.updated_at,
                    b.name as brand_name,
                    s.name as section_name
                FROM product p
                LEFT JOIN brand b ON p.brand_id = b.id
                LEFT JOIN catalog_section s ON p.section_id = s.id
                WHERE p.is_test = 0
                ORDER BY p.id ASC
                LIMIT $limit OFFSET $offset";
            
            $result = $this->db->query($sql);
            if (!$result) break;
            
            $rows = $this->db->fetchAll($result);
            $processed = 0;
            
            foreach ($rows as $row) {
                if ($this->processProduct($row)) {
                    $processed++;
                }
            }
            
            $totalProcessed += $processed;
            Logger::write("Страница $page: обработано $processed товаров");
            
            $page++;
            
        } while (count($rows) == $limit);
        
        SyncStateTable::updateLastSync('product');
        Logger::success("Полный импорт товаров завершен. Всего: $totalProcessed");
    }

    public function incrementalImport()
    {
    Logger::write('incrementalImport() вызван');
  
    // Тест
    $testLimit = 10;
    
    $sql = "
        SELECT 
            p.id,
            p.article,
            p.name,
            p.manufacturer_code,
            p.updated_at,
            br.name as brand_name,
            cs.name as section_name
        FROM product p
        LEFT JOIN brand br ON p.brand_id = br.id
        LEFT JOIN catalog_section cs ON p.catalog_section_id = cs.id
        WHERE p.is_model = 0
        ORDER BY p.id ASC
        LIMIT $testLimit";
    
    $result = $this->db->query($sql);
    if (!$result) return false;
    
    $processed = 0;
    foreach ($this->db->fetchAll($result) as $row) {
        if ($this->processProduct($row)) {
            $processed++;
        }
    }
    
    Logger::success("Тестовая выгрузка: обработано $processed товаров");
    
    return true;
    }
    // public function incrementalImport()
    // {
    //     $lastSync = SyncStateTable::getLastSync('product');
    //     $sqlDate = $lastSync->format('Y-m-d H:i:s');
        
    //     $sql = "
    //         SELECT 
    //             p.id,
    //             p.article,
    //             p.name,
    //             p.manufacturer_code,
    //             p.type,
    //             p.updated_at,
    //             b.name as brand_name,
    //             s.name as section_name
    //         FROM product p
    //         LEFT JOIN brand b ON p.brand_id = b.id
    //         LEFT JOIN catalog_section s ON p.section_id = s.id
    //         WHERE p.updated_at > '$sqlDate'
    //         AND p.is_test = 0
    //         ORDER BY p.updated_at ASC
    //         LIMIT 100";
        
    //     $result = $this->db->query($sql);
    //     if (!$result) return false;
        
    //     $processed = 0;
    //     foreach ($this->db->fetchAll($result) as $row) {
    //         if ($this->processProduct($row)) {
    //             $processed++;
    //         }
    //     }
        
    //     if ($processed) {
    //         SyncStateTable::updateLastSync('product');
    //         Logger::success("Инкрементальная синхронизация товаров: $processed");
    //     }
        
    //     return true;
    // }

    private function processProduct($row)
    {
        // Логируем
        Logger::write("Товар: [{$row['id']}] {$row['article']} - {$row['name']}");
        
        return true;
    }
}