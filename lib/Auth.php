<?php
class Auth
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('loterdomi');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Strict']);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public static function check()
    {
        self::start();
        return !empty($_SESSION['admin']);
    }

    public static function login($password)
    {
        self::start();
        $hash = Config::get(true)['admin']['passwordHash'];
        if ($hash && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            return true;
        }
        Logger::warn('Đăng nhập admin thất bại từ ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        sleep(1);
        return false;
    }

    public static function logout()
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrf()
    {
        self::start();
        return $_SESSION['csrf'];
    }
}
