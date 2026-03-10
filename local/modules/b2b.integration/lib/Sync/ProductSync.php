<?php
namespace B2b\Integration\Sync;

use B2b\Integration\Helper\DbHelper;
use B2b\Integration\Helper\Logger;
use B2b\Integration\Helper\BitrixHelper;
use B2b\Integration\Table\SyncStateTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class ProductSync
{
    private $db;
    private $moduleId = 'b2b.integration';
    private $iblockId = 24; // ID инфоблока "Товарный каталог CRM"
    private $targetSectionId = 17390; // ID папки "Товары B2B"

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
    }

    // Основной метод запуска синхронизации
    public function run()
    {
        $fullImportDone = Option::get($this->moduleId, 'full_import_products', 'N');
        
        if ($fullImportDone != 'Y') {
            Logger::write('Первичный импорт товаров не выполнен. Запускаем полный импорт...');
            $this->fullImport();
            Option::set($this->moduleId, 'full_import_products', 'Y');
        } else {
            Logger::write('Запуск инкрементальной синхронизации товаров');
            $this->incrementalImport();
        }
    }

    // Полный импорт всех товаров
    public function fullImport()
    {
        Logger::write('Начало полного импорта товаров');
        
        $page = 0;
        $limit = 500;
        $totalProcessed = 0;
        
        do {
            $offset = $page * $limit;
            
            $sql = "
                SELECT 
                    p.b2b_id,
                    p.manufacturer_code,
                    p.article,
                    p.name,
                    p.unit_name,
                    p.multiplicity,
                    p.country_code_a3,
                    p.image_url,
                    p.raec_product_id,
                    p.raec_brand_id,
                    p.vendor_name,
                    p.raec_vendor_id,
                    p.weight_netto,
                    p.weight_brutto,
                    p.series,
                    p.guarantee,
                    p.production_time,
                    p.delivery_time,
                    p.okei_unit,
                    p.code_etm,
                    p.achp_stop,
                    p.honest_sign,
                    b.name as brand_name
                FROM product p
                LEFT JOIN brand b ON p.brand_id = b.id
                WHERE p.is_model = 0
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

    // Инкрементальный импорт измененных товаров
    public function incrementalImport()
    {
        $lastSync = SyncStateTable::getLastSync('product');
        $sqlDate = $lastSync->format('Y-m-d H:i:s');
        
        $sql = "
            SELECT 
                p.b2b_id,
                p.manufacturer_code,
                p.article,
                p.name,
                p.unit_name,
                p.multiplicity,
                p.country_code_a3,
                p.image_url,
                p.raec_product_id,
                p.raec_brand_id,
                p.vendor_name,
                p.raec_vendor_id,
                p.weight_netto,
                p.weight_brutto,
                p.series,
                p.guarantee,
                p.production_time,
                p.delivery_time,
                p.okei_unit,
                p.code_etm,
                p.achp_stop,
                p.honest_sign,
                b.name as brand_name
            FROM product p
            LEFT JOIN brand b ON p.brand_id = b.id
            WHERE p.updated_at > '$sqlDate'
            AND p.is_model = 0
            ORDER BY p.updated_at ASC
            LIMIT 100";
        
        $result = $this->db->query($sql);
        if (!$result) return false;
        
        $processed = 0;
        foreach ($this->db->fetchAll($result) as $row) {
            if ($this->processProduct($row)) {
                $processed++;
            }
        }
        
        if ($processed) {
            SyncStateTable::updateLastSync('product');
            Logger::success("Инкрементальная синхронизация товаров: $processed");
        }
        
        return true;
    }

    // Обработка одного товара
    private function processProduct($row)
    {
        Logger::write("Обработка товара: [{$row['b2b_id']}] {$row['manufacturer_code']} - {$row['name']}");
        
        // Поиск существующего товара по коду производителя (manufacturer_code)
        $existingProductId = $this->findProductByManufacturerCode($row['manufacturer_code']);
        
        // Генерация символьного кода
        $code = \CUtil::translit($row['name'], 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'max_len' => 100
        ]);
        
        // Формирование полей товара
        $productFields = [
            'NAME' => $row['name'],
            'CODE' => $code,
            'IBLOCK_ID' => $this->iblockId,
            'IBLOCK_SECTION_ID' => $this->targetSectionId,
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                // Основные поля
                603 => $row['b2b_id'],                          // ID B2B
                'ARTNUMBER' => $row['manufacturer_code'],       // Артикул (код производителя)
                3602 => $row['article'],                         // Код 1С
                3576 => $row['brand_name'] ?? '',                // Бренд
                3573 => $row['unit_name'],                       // Единица измерения
                3574 => $row['multiplicity'],                    // Кратность
                3583 => $row['country_code_a3'] ?? '',           // Страна
                
                // Поля РАЭК
                3569 => $row['raec_product_id'] ?? '',           // ID РАЭК
                3570 => $row['raec_brand_id'] ?? '',             // ID бренда в РАЭК
                3571 => $row['vendor_name'] ?? '',                // Наименование вендора
                3572 => $row['raec_vendor_id'] ?? '',            // ID вендора в РАЭК
                
                // Вес и размеры
                3580 => $row['weight_netto'] ?? '',              // Вес нетто, кг
                3582 => $row['weight_brutto'] ?? '',             // Вес брутто, кг
                3585 => $row['series'] ?? '',                     // Серия товара
                3586 => $row['guarantee'] ?? '',                  // Гарантия производителя, мес.
                
                // Сроки
                3578 => $row['production_time'] ?? '',           // Срок производства (дней)
                3579 => $row['delivery_time'] ?? '',             // Срок поставки (дней)
                
                // Дополнительные коды
                3575 => $row['okei_unit'] ?? '',                  // Единица измерения ОКЕИ
                3593 => $row['code_etm'] ?? '',                   // Код ЭТМ
                3595 => $row['achp_stop'] ?? '',                  // Есть в стоп-листе АЧП
                3594 => $row['honest_sign'] ?? '',                // Подлежит маркировке Честный Знак
            ]
        ];

        // Добавление картинки
        if (!empty($row['image_url'])) {
            $image = \CFile::MakeFileArray($row['image_url']);
            if ($image) {
                $productFields['PREVIEW_PICTURE'] = $image;
                $productFields['DETAIL_PICTURE'] = $image;
            }
        }

        // Создание или обновление товара
        if ($existingProductId) {
            $result = $this->updateProduct($existingProductId, $productFields);
            if ($result) {
                Logger::write("Обновлен товар ID $existingProductId: {$row['manufacturer_code']}");
            } else {
                Logger::error("Ошибка обновления товара {$row['manufacturer_code']}");
                return false;
            }
        } else {
            $newProductId = $this->createProduct($productFields);
            if ($newProductId) {
                Logger::success("Создан товар ID $newProductId: {$row['manufacturer_code']} - {$row['name']}");
            } else {
                Logger::error("Ошибка создания товара {$row['manufacturer_code']}");
                return false;
            }
        }

        return true;
    }

    // Поиск товара по коду производителя (manufacturer_code)
    private function findProductByManufacturerCode($code)
    {
        if (empty($code)) return null;
        
        $code = \Bitrix\Main\Application::getConnection()->getSqlHelper()->forSql($code);
        
        $res = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $this->iblockId,
                '=PROPERTY_ARTNUMBER' => $code
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        
        if ($item = $res->Fetch()) {
            return $item['ID'];
        }
        
        return null;
    }

    // Создание нового товара
    private function createProduct($fields)
    {
        $el = new \CIBlockElement();
        $id = $el->Add($fields);
        
        if (!$id) {
            Logger::error('Ошибка создания: ' . $el->LAST_ERROR);
        }
        
        return $id;
    }

    // Обновление существующего товара
    private function updateProduct($id, $fields)
    {
        $el = new \CIBlockElement();
        return $el->Update($id, $fields);
    }
}