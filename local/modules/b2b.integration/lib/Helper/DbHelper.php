<?php
namespace B2b\Integration\Helper;

use Bitrix\Main\Config\Option;

class DbHelper
{
    private $connection;
    private static $instance = null;

    // Устанавливаем соединение с MySQL
    private function __construct()
    {
        // Получение параметров подключения из настроек модуля
        $host = Option::get('b2b.integration', 'mysql_host', '');
        $port = Option::get('b2b.integration', 'mysql_port', '');
        $database = Option::get('b2b.integration', 'mysql_database', '');
        $user = Option::get('b2b.integration', 'mysql_user', '');
        $password = Option::get('b2b.integration', 'mysql_password', '');

        // Создание подключения к MySQL
        $this->connection = new \mysqli($host, $user, $password, $database, $port);
        
        // Проверка ошибок подключения
        if ($this->connection->connect_error) {
            Logger::error('Ошибка подключения: ' . $this->connection->connect_error);
            throw new \Exception('Ошибка подключения к B2B');
        }
        
        // Кодировка соединения
        $this->connection->set_charset('utf8');
    }

    // Получение экземпляра класса
    public static function getInstance()
    {
        // Создание нового экземпляра при первом вызове
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Выполнение SQL запроса
    public function query($sql)
    {
        // Выполнение запроса к БД
        $result = $this->connection->query($sql);
        // Проверка ошибок выполнения
        if ($this->connection->error) {
            Logger::error('Ошибка запроса: ' . $this->connection->error);
            return false;
        }
        return $result;
    }

    // Преобразование результата в массив
    public function fetchAll($result)
    {
        $data = [];
        // Извлечение строк из результата запроса
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }

    // Экранирование спецсимволов SQL
    public function escape($value)
    {
        return $this->connection->real_escape_string($value);
    }
}