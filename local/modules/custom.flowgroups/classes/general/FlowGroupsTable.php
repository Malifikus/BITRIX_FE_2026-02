<?php
namespace Custom\FlowGroups;

use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;

class FlowGroupsTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_custom_flow_groups';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new Entity\StringField('NAME', [
                'required' => true
            ]),
            new Entity\TextField('DESCRIPTION'),
            new Entity\IntegerField('OWNER_ID'),
            new Entity\IntegerField('SORT', [
                'default_value' => 500
            ]),
            new Entity\TextField('FLOW_IDS', [
                'serialized' => true
            ]),
            new Entity\BooleanField('EXPANDED', [
                'values' => ['N', 'Y'],
                'default_value' => 'N'
            ]),
            new Entity\BooleanField('ACTIVE', [
                'values' => ['N', 'Y'],
                'default_value' => 'Y'
            ]),
        ];
    }
}