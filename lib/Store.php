<?php
// Lưu dữ liệu JSON trong file .php có dòng chặn truy cập đầu tiên,
// nên dù web server không chặn thư mục data/ thì cũng không lộ nội dung.
class Store
{
    const GUARD = "<?php exit; ?>\n";

    public static function path($name)
    {
        return DATA_DIR . '/' . $name . '.php';
    }

    public static function read($name, $default = null)
    {
        $file = self::path($name);
        if (!is_file($file)) {
            return $default;
        }
        $raw = (string) file_get_contents($file);
        if (strpos($raw, self::GUARD) === 0) {
            $raw = substr($raw, strlen(self::GUARD));
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    public static function write($name, $data)
    {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0775, true);
        }
        $file = self::path($name);
        $tmp = $file . '.' . getmypid() . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, self::GUARD . $json) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Không ghi được $file – kiểm tra quyền ghi thư mục data/");
        }
        self::fixOwner($file);
    }

    // Cron trên aaPanel chạy bằng root: trả quyền file về chủ thư mục data/ (thường là www)
    public static function fixOwner($file)
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($file, fileowner(DATA_DIR));
            @chgrp($file, filegroup(DATA_DIR));
        }
    }
}
