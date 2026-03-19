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
    private $iblockId = 24;
    private $targetSectionId = 17390;
    private $sectionCache = [];
    private $isFirstImport = false;

    public function __construct()
    {
        $this->db = DbHelper::getInstance();
        $this->detectImportMode();
    }

    // Определяем режим импорта
    private function detectImportMode()
    {
        $firstImportDone = Option::get($this->moduleId, 'first_import_done', 'N');
        $this->isFirstImport = ($firstImportDone != 'Y');
        
        if ($this->isFirstImport) {
            Logger::write('Режим: ПЕРВИЧНЫЙ ИМПОРТ (будут загружены все товары)');
        } else {
            Logger::write('Режим: РЕГУЛЯРНОЕ ОБНОВЛЕНИЕ (только изменённые товары)');
        }
    }

    public function run()
    {
        if ($this->isFirstImport) {
            $this->runFirstImport();
        } else {
            $this->runRegularUpdate();
        }
    }

    // ПЕРВИЧНЫЙ ИМПОРТ - все товары пачками
    private function runFirstImport()
    {
        Logger::write('НАЧАЛО ПЕРВИЧНОГО ИМПОРТА товаров');

        $batchSize = 3000;
        $offset = 0;
        $totalProcessed = 0;
        $batchCount = 0;
        $pauseSeconds = 2;

        do {
            $batchCount++;
            Logger::write("--- Пачка #{$batchCount}, offset: {$offset} ---");

            $sql = "
                SELECT 
                    p.b2b_id,
                    p.id,
                    p.manufacturer_code,
                    p.article,
                    p.name,
                    p.manufacturer,
                    p.name_of_manufacturer,
                    p.unit_name,
                    p.multiplicity,
                    p.country_code_a3,
                    p.image_url,
                    cs.id as catalog_section_id,
                    cs.name as catalog_section_name,
                    cs.parent_id as catalog_section_parent_id
                FROM product p
                LEFT JOIN catalog_section cs ON p.catalog_section_id = cs.id
                WHERE p.is_model = 0
                ORDER BY p.id ASC
                LIMIT {$batchSize} OFFSET {$offset}";

            $result = $this->db->query($sql);
            if (!$result) {
                Logger::error('Ошибка запроса к БД B2B. Прерывание.');
                break;
            }

            $rows = $this->db->fetchAll($result);

            if (empty($rows)) {
                Logger::write("Пачка пуста. Достигнут конец выборки.");
                break;
            }

            $categoryIds = [];
            foreach ($rows as $row) {
                if (!empty($row['catalog_section_id'])) {
                    $categoryIds[] = $row['catalog_section_id'];
                }
            }

            $allNeededCategories = $this->loadCategoryHierarchy($categoryIds);
            $this->createCategoryHierarchy($allNeededCategories);

            $processed = 0;
            foreach ($rows as $row) {
                if ($this->processProduct($row)) {
                    $processed++;
                }
            }

            $totalProcessed += $processed;
            Logger::write("Пачка #{$batchCount} обработана: {$processed} товаров. Всего: {$totalProcessed}");

            $offset += $batchSize;

            Logger::write("Пауза {$pauseSeconds} секунд...");
            sleep($pauseSeconds);
            gc_collect_cycles();

        } while (true);

        Option::set($this->moduleId, 'first_import_done', 'Y');
        SyncStateTable::updateLastSync('product', $this->getMaxProductId());
        Logger::success("ПЕРВИЧНЫЙ ИМПОРТ ЗАВЕРШЕН. Всего обработано: {$totalProcessed} товаров за {$batchCount} пачек");
    }

    // РЕГУЛЯРНОЕ ОБНОВЛЕНИЕ - только изменённые товары
    private function runRegularUpdate()
    {
        Logger::write('НАЧАЛО РЕГУЛЯРНОГО ОБНОВЛЕНИЯ товаров');

        $lastSync = SyncStateTable::getLastSync('product');
        $sqlDate = $lastSync->format('Y-m-d H:i:s');

        $sql = "
            SELECT 
                p.b2b_id,
                p.id,
                p.manufacturer_code,
                p.article,
                p.name,
                p.manufacturer,
                p.name_of_manufacturer,
                p.unit_name,
                p.multiplicity,
                p.country_code_a3,
                p.image_url,
                cs.id as catalog_section_id,
                cs.name as catalog_section_name,
                cs.parent_id as catalog_section_parent_id
            FROM product p
            LEFT JOIN catalog_section cs ON p.catalog_section_id = cs.id
            WHERE p.is_model = 0
              AND p.updated_at > '$sqlDate'
            ORDER BY p.updated_at ASC
            LIMIT 100";

        $result = $this->db->query($sql);
        if (!$result) {
            Logger::error('Ошибка запроса к БД B2B');
            return;
        }

        $rows = $this->db->fetchAll($result);
        
        if (empty($rows)) {
            Logger::write('Нет товаров для обновления');
            return;
        }

        Logger::write("Найдено товаров для обновления: " . count($rows));

        $categoryIds = [];
        foreach ($rows as $row) {
            if (!empty($row['catalog_section_id'])) {
                $categoryIds[] = $row['catalog_section_id'];
            }
        }

        $allNeededCategories = $this->loadCategoryHierarchy($categoryIds);
        $this->createCategoryHierarchy($allNeededCategories);

        $processed = 0;
        $maxUpdatedAt = $lastSync;

        foreach ($rows as $row) {
            if ($this->processProduct($row)) {
                $processed++;
            }
            $rowDate = new DateTime($row['updated_at']);
            if ($rowDate->getTimestamp() > $maxUpdatedAt->getTimestamp()) {
                $maxUpdatedAt = $rowDate;
            }
        }

        SyncStateTable::updateLastSync('product', 0, $maxUpdatedAt);
        Logger::success("Регулярное обновление завершено. Обработано: {$processed} товаров");
    }

    // Получение максимального ID товара в B2B
    private function getMaxProductId()
    {
        $sql = "SELECT MAX(id) as max_id FROM product WHERE is_model = 0";
        $result = $this->db->query($sql);
        if ($row = $result->fetch_assoc()) {
            return (int)$row['max_id'];
        }
        return 0;
    }

    // Загрузка иерархии категорий
    private function loadCategoryHierarchy($categoryIds)
    {
        if (empty($categoryIds)) {
            return [];
        }

        $ids = implode(',', array_unique($categoryIds));

        $sql = "
            WITH RECURSIVE cat_tree AS (
                SELECT id, name, parent_id, sort
                FROM catalog_section
                WHERE id IN ($ids)

                UNION ALL

                SELECT cs.id, cs.name, cs.parent_id, cs.sort
                FROM catalog_section cs
                INNER JOIN cat_tree ct ON cs.id = ct.parent_id
            )
            SELECT DISTINCT id, name, parent_id, sort FROM cat_tree";

        $result = $this->db->query($sql);
        if (!$result) {
            return [];
        }

        $categories = $this->db->fetchAll($result);

        $indexed = [];
        foreach ($categories as $cat) {
            $indexed[$cat['id']] = $cat;
        }

        return $indexed;
    }

    // Создание иерархии категорий в Битрикс
    private function createCategoryHierarchy($categories)
    {
        if (empty($categories)) {
            return;
        }

        uasort($categories, function($a, $b) {
            return (int)($a['parent_id'] ?? 0) - (int)($b['parent_id'] ?? 0);
        });

        foreach ($categories as $b2bId => $cat) {
            if (isset($this->sectionCache[$b2bId])) {
                continue;
            }

            $parentBitrixId = $this->targetSectionId;
            if (!empty($cat['parent_id']) && isset($this->sectionCache[$cat['parent_id']])) {
                $parentBitrixId = $this->sectionCache[$cat['parent_id']];
            }

            $existingId = $this->findExistingSection($cat['name'], $parentBitrixId);
            if ($existingId) {
                $this->sectionCache[$b2bId] = $existingId;
                Logger::write("Найден существующий раздел: {$cat['name']} (ID: $existingId)");
                continue;
            }

            $sectionFields = [
                'IBLOCK_ID' => $this->iblockId,
                'NAME' => $cat['name'],
                'IBLOCK_SECTION_ID' => $parentBitrixId,
                'ACTIVE' => 'Y',
                'SORT' => $cat['sort'] ?? 500
            ];

            $section = new \CIBlockSection();
            $bitrixId = $section->Add($sectionFields);

            if ($bitrixId) {
                $this->sectionCache[$b2bId] = $bitrixId;
                Logger::write("Создан раздел: {$cat['name']} (ID: $bitrixId, родитель: $parentBitrixId)");
            } else {
                Logger::error("Ошибка создания раздела '{$cat['name']}': " . $section->LAST_ERROR);
            }
        }
    }

    // Поиск существующего раздела
    private function findExistingSection($name, $parentId)
    {
        $res = \CIBlockSection::GetList(
            [],
            [
                'IBLOCK_ID' => $this->iblockId,
                'NAME' => $name,
                'SECTION_ID' => $parentId
            ],
            false,
            ['ID']
        );

        if ($section = $res->Fetch()) {
            return $section['ID'];
        }

        return null;
    }

    // ================== ИСПРАВЛЕННЫЙ МЕТОД ПОИСКА ==================
    // Поиск товара по артикулу в пределах папки "Товары B2B"
    private function findProductByManufacturerCode($code, $sectionId = null)
    {
        if (empty($code)) return null;
        
        $filter = [
            'IBLOCK_ID' => $this->iblockId,
            '=PROPERTY_ARTNUMBER' => $code
        ];
        
        // Если указан раздел, ищем только в нём и подразделах
        if ($sectionId) {
            $filter['SECTION_ID'] = $sectionId;
            $filter['INCLUDE_SUBSECTIONS'] = 'Y'; // искать во всех подпапках
        }
        
        $res = \CIBlockElement::GetList(
            [],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        
        if ($item = $res->Fetch()) {
            return $item['ID'];
        }
        
        return null;
    }
    // ==============================================================

    // Обработка одного товара
    private function processProduct($row)
    {
        Logger::write("Обработка товара: [{$row['b2b_id']}] {$row['manufacturer_code']} - {$row['name']}");

        // Поиск существующего товара по артикулу в целевой папке
        $existingProductId = $this->findProductByManufacturerCode(
            $row['manufacturer_code'],
            $this->targetSectionId
        );

        $code = \CUtil::translit($row['name'], 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'max_len' => 100
        ]);

        $sectionId = $this->targetSectionId;
        if (!empty($row['catalog_section_id']) && isset($this->sectionCache[$row['catalog_section_id']])) {
            $sectionId = $this->sectionCache[$row['catalog_section_id']];
        }

        $productFields = [
            'NAME' => $row['name'],
            'CODE' => $code,
            'IBLOCK_ID' => $this->iblockId,
            'IBLOCK_SECTION_ID' => $sectionId,
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                3603 => $row['b2b_id'],
                'ARTNUMBER' => $row['manufacturer_code'],
                3602 => $row['article'],
                3576 => $row['manufacturer'] ?? $row['name_of_manufacturer'] ?? '',
                3573 => $row['unit_name'],
                3574 => $row['multiplicity'],
                3583 => $row['country_code_a3'] ?? '',
            ]
        ];

        if (!empty($row['image_url'])) {
            $image = \CFile::MakeFileArray($row['image_url']);
            if ($image) {
                $productFields['PREVIEW_PICTURE'] = $image;
                $productFields['DETAIL_PICTURE'] = $image;
            }
        }

        $productId = null;

        if ($existingProductId) {
            $result = $this->updateProduct($existingProductId, $productFields);
            if ($result) {
                Logger::write("Обновлен товар ID $existingProductId");
                $productId = $existingProductId;
            } else {
                return false;
            }
        } else {
            $newProductId = $this->createProduct($productFields);
            if ($newProductId) {
                $productId = $newProductId;
            } else {
                return false;
            }
        }

        if ($productId) {
            \Bitrix\Main\Loader::includeModule('catalog');

            $existingProduct = \Bitrix\Catalog\Model\Product::getCacheItem($productId, true);

            if (empty($existingProduct)) {
                $catalogProductFields = [
                    'ID' => $productId,
                    'QUANTITY' => 0,
                    'WEIGHT' => 0,
                    'VAT_ID' => 1,
                    'VAT_INCLUDED' => 'Y',
                    'MEASURE' => 5,
                ];

                $result = \Bitrix\Catalog\Model\Product::add($catalogProductFields);

                if ($result->isSuccess()) {
                    Logger::write("Товар ID $productId зарегистрирован в торговом каталоге");
                } else {
                    Logger::error("Ошибка регистрации в каталоге: " . implode(', ', $result->getErrorMessages()));
                }
            } else {
                Logger::write("Товар ID $productId уже есть в торговом каталоге");
            }
        }

        return true;
    }

    // Создание товара
    private function createProduct($fields)
    {
        $el = new \CIBlockElement();
        $id = $el->Add($fields);

        if (!$id) {
            Logger::error('Ошибка создания: ' . $el->LAST_ERROR);
        }

        return $id;
    }

    // Обновление товара
    private function updateProduct($id, $fields)
    {
        $el = new \CIBlockElement();
        return $el->Update($id, $fields);
    }
}