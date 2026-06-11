<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

class CBPCompanySearchSberActivity extends CBPActivity
{
    public function __construct($name)
    {
        // Свойства ДО вызова parent
        $this->arProperties = [
            'Title' => '',
            'Inn' => '',
            'CompanyInfo' => null,
            'LegalRisks' => null,
            'CompanyData' => null,
        ];
        
        parent::__construct($name);
    }

    public function Execute()
    {
        $this->CompanyInfo = null;
        $this->LegalRisks = null;
        $this->CompanyData = null;

        $inn = trim($this->Inn ?? '');
        
        if (empty($inn)) {
            return CBPActivityExecutionStatus::Closed;
        }

        // Получаем конфигурацию
        $config = $this->getConfig();
        
        if (empty($config['api_key'])) {
            return CBPActivityExecutionStatus::Closed;
        }

        // Выполняем запрос к API Сбера
        $apiResult = $this->fetchCompanyData($inn, $config);
        
        if (!$apiResult['success']) {
            return CBPActivityExecutionStatus::Closed;
        }

        // Сохраняем данные компании
        $companyData = $apiResult['data'];
        $this->CompanyInfo = json_encode($companyData);
        
        // Обрабатываем юридические риски
        $legalRisks = $this->extractLegalRisks($companyData);
        $this->LegalRisks = json_encode($legalRisks);
        
        // Сохраняем полные данные компании
        $this->CompanyData = json_encode($companyData);
        
        return CBPActivityExecutionStatus::Closed;
    }
    
    /**
     * Получение данных компании через API Сбера
     *
     * @param string $inn ИНН компании
     * @param array $config Конфигурация
     * @return array Результат запроса
     */
    private function fetchCompanyData($inn, $config)
    {
        try {
            // Формируем URL для запроса
            $url = $config['api_url'] . '/traffic-lights';
            
            // Подготавливаем заголовки
            $headers = [
                'Authorization: Bearer ' . $config['api_key'],
                'Content-Type: application/json',
                'Accept: application/json'
            ];
            
            // Подготавливаем данные для запроса
            $data = [
                'inn' => $inn
            ];
            
            // Выполняем HTTP-запрос
            $response = $this->makeHttpRequest($url, $data, $headers);
            
            if ($response['status'] !== 200) {
                return [
                    'success' => false,
                    'error' => Loc::getMessage('COMPANY_SEARCH_SBER_ERROR_API_CALL') . ': ' . $response['status']
                ];
            }
            
            $responseData = json_decode($response['body'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => Loc::getMessage('COMPANY_SEARCH_SBER_ERROR_JSON_PARSE')
                ];
            }
            
            return [
                'success' => true,
                'data' => $responseData
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => Loc::getMessage('COMPANY_SEARCH_SBER_ERROR_API_CALL') . ': ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Извлечение информации о юридических рисках
     *
     * @param array $companyData Данные компании
     * @return array Массив юридических рисков
     */
    private function extractLegalRisks($companyData)
    {
        $risks = [];
        
        // Проверяем наличие данных о рисках
        if (isset($companyData['risks']) && is_array($companyData['risks'])) {
            foreach ($companyData['risks'] as $risk) {
                $risks[] = [
                    'type' => $risk['type'] ?? '',
                    'description' => $risk['description'] ?? '',
                    'severity' => $risk['severity'] ?? '',
                    'date' => $risk['date'] ?? ''
                ];
            }
        }
        
        return $risks;
    }
    
    /**
     * Выполнение HTTP-запроса
     *
     * @param string $url URL для запроса
     * @param array $data Данные для отправки
     * @param array $headers Заголовки
     * @return array Результат запроса
     */
    private function makeHttpRequest($url, $data, $headers)
    {
        $httpClient = new HttpClient();
        $httpClient->setHeaders($headers);
        
        $postData = Json::encode($data);
        $response = $httpClient->post($url, $postData);
        $status = $httpClient->getStatus();
        
        if ($status !== 200) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $status
            ];
        }
        
        return [
            'status' => $status,
            'body' => $response
        ];
    }
    
    /**
     * Получение конфигурации активити
     *
     * @return array Конфигурация
     */
    private function getConfig()
    {
        $configPath = dirname(__FILE__) . '/config.php';
        
        if (file_exists($configPath)) {
            $config = include $configPath;
            return is_array($config) ? $config : [];
        }
        
        return [];
    }
}