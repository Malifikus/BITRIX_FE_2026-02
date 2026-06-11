<? if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

use \Bitrix\Main\Localization\Loc;

$arActivityDescription = [
    "NAME" => Loc::getMessage("COMPANY_SEARCH_SBER_DESCR_NAME"),
    "DESCRIPTION" => Loc::getMessage("COMPANY_SEARCH_SBER_DESCR_DESCR"),
    "TYPE" => "activity",
    "CLASS" => "CBPCompanySearchSberActivity",
    "JSCLASS" => "BizProcActivity",
    "CATEGORY" => [
        "ID" => "integrations",
        "OWN_ID" => "integrations",
        "OWN_NAME" => "Интеграции",
    ],
    "ICON" => "/local/activities/custom/companysearchsberactivity/sberbank-1_32x32.svg",
    "RETURN" => [
        "COMPANY_INFO" => [
            "NAME" => Loc::getMessage("COMPANY_SEARCH_SBER_RETURN_COMPANY_INFO") ?: "Информация о компании",
            "TYPE" => "string",
        ],
        "LEGAL_RISKS" => [
            "NAME" => Loc::getMessage("COMPANY_SEARCH_SBER_RETURN_LEGAL_RISKS") ?: "Юридические риски",
            "TYPE" => "string",
        ],
        "COMPANY_DATA" => [
            "NAME" => Loc::getMessage("COMPANY_SEARCH_SBER_RETURN_COMPANY_DATA") ?: "Данные компании",
            "TYPE" => "string",
        ],
    ],
];