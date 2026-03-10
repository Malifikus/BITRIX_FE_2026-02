<?php
namespace B2b\Integration\Table;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

class SyncStateTable extends Entity\DataManager
{
    // Название таблицы в базе данных
    public static function getTableName()
    {
        return 'b2b_sync_state';
    }

    // Структура полей таблицы
    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new Entity\StringField('ENTITY_TYPE', [
                'required' => true,
                'validation' => fn() => [new Entity\Validator\Length(null, 50)]
            ]),
            new Entity\DatetimeField('LAST_SYNC', [
                'required' => true
            ])
        ];
    }

    // Получение даты последней синхронизации для типа сущности
    public static function getLastSync($entityType)
    {
        $result = self::getList([
            'filter' => ['=ENTITY_TYPE' => $entityType],
            'select' => ['LAST_SYNC']
        ])->fetch();
        
        // Возврат даты или 1 января 1970 года если записи нет
        return $result ? $result['LAST_SYNC'] : DateTime::createFromTimestamp(0);
    }

    // Обновление даты последней синхронизации
    public static function updateLastSync($entityType)
    {
        $now = new DateTime();
        
        $exists = self::getList([
            'filter' => ['=ENTITY_TYPE' => $entityType],
            'select' => ['ID']
        ])->fetch();
        
        if ($exists) {
            self::update($exists['ID'], ['LAST_SYNC' => $now]);
        } else {
            self::add(['ENTITY_TYPE' => $entityType, 'LAST_SYNC' => $now]);
        }
    }
}