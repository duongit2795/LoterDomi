<?php
// Kết nối MySQL/MariaDB (tuỳ chọn): thành viên + lịch sử kỳ
class Db
{
    private static $pdo = null;

    public static function configured()
    {
        $d = Config::get()['db'];
        return $d['name'] !== '' && $d['user'] !== '';
    }

    public static function connect(array $d)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'] ?: '127.0.0.1', (int) $d['port'] ?: 3306, $d['name']);
        return new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public static function pdo()
    {
        if (self::$pdo === null) {
            if (!self::configured()) {
                throw new RuntimeException('Chưa cấu hình database (admin → Cài đặt → Database)');
            }
            if (!extension_loaded('pdo_mysql')) {
                throw new RuntimeException('PHP thiếu extension pdo_mysql');
            }
            self::$pdo = self::connect(Config::get()['db']);
        }
        return self::$pdo;
    }

    public static function query($sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all($sql, array $params = [])
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one($sql, array $params = [])
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    // Tạo bảng nếu chưa có (chạy lại nhiều lần không sao)
    public static function migrate(?PDO $pdo = null)
    {
        $pdo = $pdo ?: self::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            last_login_ip VARCHAR(45) NOT NULL DEFAULT '',
            UNIQUE KEY uq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS draws (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(16) NOT NULL,
            game_name VARCHAR(64) NOT NULL DEFAULT '',
            issue VARCHAR(32) NOT NULL,
            result VARCHAR(64) NOT NULL DEFAULT '',
            play VARCHAR(32) NOT NULL DEFAULT '',
            plan_check VARCHAR(64) NOT NULL DEFAULT '',
            play_result VARCHAR(16) NOT NULL DEFAULT '',
            plan_status VARCHAR(64) NOT NULL DEFAULT '',
            win_status TINYINT NULL,
            hit_turn TINYINT NULL,
            next_issue VARCHAR(32) NOT NULL DEFAULT '',
            next_plan VARCHAR(64) NOT NULL DEFAULT '',
            next_range VARCHAR(32) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_game_issue (game_id, issue),
            KEY idx_game_time (game_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function reset()
    {
        self::$pdo = null;
    }
}
