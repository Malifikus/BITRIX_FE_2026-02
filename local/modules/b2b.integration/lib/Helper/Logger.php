<?php
namespace B2b\Integration\Helper;

class Logger
{
    private static $logDir;
    private static $logFile;

    // Создание папки для логов если её нет
    private static function init()
    {
        self::$logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/b2b.integration/logs';
        self::$logFile = self::$logDir . '/sync.log';
        
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }

    // Запись сообщения в лог-файл
    public static function write($message, $type = 'INFO')
    {
        self::init();
        
        $data = '[' . date('Y-m-d H:i:s') . '] [' . $type . '] ' . $message . PHP_EOL;
        file_put_contents(self::$logFile, $data, FILE_APPEND);
        
        // Вывод в консоль при ручном запуске
        if (php_sapi_name() == 'cli') {
            echo $data;
        }
    }

    // Запись ошибки
    public static function error($message) { self::write($message, 'ERROR'); }
    
    // Запись успешного действия
    public static function success($message) { self::write($message, 'SUCCESS'); }
}