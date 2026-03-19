<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

class CBPCompanySearchActivity extends CBPActivity

{
    public function __construct($name)
    {
        // Свойства ДО вызова parent
        $this->arProperties = [
            'Title' => '',
            'SearchQuery' => '',
            'ApiKey' => '',
            'CountLimit' => 1,
            'Found' => null,
            'ErrorMessage' => null,
            'INN' => null,
            'KPP' => null,
            'OGRN' => null,
            'CompanyNameFull' => null,
            'AddressFull' => null,
            'Phone' => null,
            'Email' => null,
            'DirectorName' => null,
            'STATUS' => null,
            'SITE' => null,
            'ADDRESS_CITY' => null,
        ];
        
        parent::__construct($name);
    }

    public function Execute()
    {
        file_put_contents(__DIR__.'/execute_check.txt', 
            "Execute вызван\n" .
            "query: " . ($this->SearchQuery ?? 'пусто') . "\n" .
            "apiKey из свойства: " . ($this->ApiKey ?? 'пусто') . "\n",
        FILE_APPEND);

        $this->Found = false;
        $this->ErrorMessage = '';

        $query = trim($this->SearchQuery ?? '');
        $apiKey = trim($this->ApiKey ?? '');

        if (empty($apiKey)) {
            $configFile = __DIR__ . '/config.php';
            if (file_exists($configFile)) {
                $config = include $configFile;
                $apiKey = $config['api_key'] ?? '';
            }
        }

        if (empty($query)) {
            $this->ErrorMessage = Loc::getMessage('DADATA_ERROR_EMPTY_QUERY');
            return CBPActivityExecutionStatus::Closed;
        }

        if (empty($apiKey)) {
            $this->ErrorMessage = Loc::getMessage('DADATA_ERROR_EMPTY_KEY');
            return CBPActivityExecutionStatus::Closed;
        }

        $result = $this->fetchFromDaData($query, $apiKey);

        file_put_contents(__DIR__.'/dadata_raw.txt', print_r($result, true));

        if ($result === false) {
            $this->ErrorMessage = Loc::getMessage('DADATA_ERROR_REQUEST_FAILED');
            return CBPActivityExecutionStatus::Closed;
        }

        if (!empty($result) && isset($result[0])) {
            $company = $result[0];
            $data = $company['data'] ?? [];
            
            $this->Found = true;
            $this->INN = $data['inn'] ?? '';
            $this->KPP = $data['kpp'] ?? '';
            $this->OGRN = $data['ogrn'] ?? '';
            $this->COMPANY_NAME_FULL = $company['value'] ?? '';
            $this->ADDRESS_FULL = $data['address']['value'] ?? $company['value'] ?? '';
            $this->Phone = !empty($data['phones']) ? $data['phones'][0] : '';
            $this->Email = !empty($data['emails']) ? $data['emails'][0] : '';
            $this->DIRECTOR_NAME = ($data['management'] ?? [])['name'] ?? '';
            $this->STATUS = $data['state']['status'] ?? '';
            $this->SITE = $data['website'] ?? '';
            $this->ADDRESS_CITY = $data['address']['data']['city'] ?? '';
                
            } else {
            $this->ErrorMessage = Loc::getMessage('DADATA_ERROR_NOT_FOUND');
        }

        return CBPActivityExecutionStatus::Closed;
    }

    private function fetchFromDaData(string $inn, string $token)
    {
        $logFile = __DIR__.'/dadata_debug.log';
        
        file_put_contents($logFile, date('Y-m-d H:i:s')." Начало запроса для ИНН: $inn\n", FILE_APPEND);
        
        $url = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/party';
        
        try {
            $httpClient = new HttpClient();
            $httpClient->setHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Token ' . $token,
            ]);
            
            $postData = Json::encode([
                'query' => $inn,
                'count' => 5
            ]);
            
            file_put_contents($logFile, date('Y-m-d H:i:s')." Отправляем: $postData\n", FILE_APPEND);
            
            $response = $httpClient->post($url, $postData);
            $status = $httpClient->getStatus();
            
            file_put_contents($logFile, date('Y-m-d H:i:s')." Статус: $status\n", FILE_APPEND);
            file_put_contents($logFile, date('Y-m-d H:i:s')." Ответ: $response\n", FILE_APPEND);
            
            if ($status !== 200) {
                file_put_contents($logFile, date('Y-m-d H:i:s')." Ошибка HTTP: $status\n", FILE_APPEND);
                return false;
            }
            
            $data = Json::decode($response);
            file_put_contents($logFile, date('Y-m-d H:i:s')." Успешно декодировано\n", FILE_APPEND);
            
            return $data['suggestions'] ?? [];
            
        } catch (\Exception $e) {
            file_put_contents($logFile, date('Y-m-d H:i:s')." Исключение: ".$e->getMessage()."\n", FILE_APPEND);
            return false;
        }
    }
}