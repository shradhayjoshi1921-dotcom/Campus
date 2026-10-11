<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
use PDOException;
final class Database {
    private static ?PDO $connection = null;
    public static function connection(): PDO {
        if (self::$connection) return self::$connection;
        $host = Env::get('DB_HOST', '127.0.0.1'); $port = Env::get('DB_PORT', '3306'); $name = Env::get('DB_NAME', 'ai_campus_portal');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$connection = new PDO($dsn, Env::get('DB_USER', 'root') ?? 'root', Env::get('DB_PASS', '') ?? '', [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false, PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        return self::$connection;
    }
}
