<?php
class Util
{
    // Gộp cấu hình: mảng kết hợp gộp đệ quy, danh sách (list) thì thay thế
    public static function merge($base, $over)
    {
        if (!is_array($base) || !is_array($over) || self::isList($base) || self::isList($over)) {
            return $over === null ? $base : $over;
        }
        foreach ($over as $k => $v) {
            $base[$k] = array_key_exists($k, $base) ? self::merge($base[$k], $v) : $v;
        }
        return $base;
    }

    public static function isList($a)
    {
        return is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1));
    }

    // Lấy giá trị theo đường dẫn "a.b[0].c" hoặc "a.b.0.c"
    public static function getPath($data, $path)
    {
        if ($path === '' || $path === null) {
            return null;
        }
        $keys = array_filter(explode('.', preg_replace('/\[(\w+)\]/', '.$1', $path)), 'strlen');
        foreach ($keys as $k) {
            if (!is_array($data) || !array_key_exists($k, $data)) {
                return null;
            }
            $data = $data[$k];
        }
        return $data;
    }

    public static function str($v)
    {
        if ($v === null) {
            return '';
        }
        if (is_array($v)) {
            return self::isList($v) ? implode(',', array_map([self::class, 'str'], $v)) : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }

    // Chuyển giá trị thời gian về mili-giây epoch
    public static function toTimestamp($v, $mode, $nowMs)
    {
        if ($v === null || $v === '') {
            return null;
        }
        $isNum = is_numeric($v);
        switch ($mode) {
            case 'remaining':
                return $isNum ? $nowMs + (int) round($v * 1000) : null;
            case 'seconds':
                return $isNum ? (int) round($v * 1000) : null;
            case 'milliseconds':
                return $isNum ? (int) $v : null;
            default:
                if ($isNum) {
                    return $v < 1e11 ? (int) round($v * 1000) : (int) $v;
                }
                $t = strtotime((string) $v);
                return $t === false ? null : $t * 1000;
        }
    }

    public static function nowMs()
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function formatTime($ms, $tz)
    {
        if (!$ms) {
            return '';
        }
        try {
            $d = new DateTime('@' . intdiv((int) $ms, 1000));
            $d->setTimezone(new DateTimeZone($tz ?: 'Asia/Ho_Chi_Minh'));
            return $d->format('H:i:s d/m/Y');
        } catch (Exception $e) {
            return date('H:i:s d/m/Y', intdiv((int) $ms, 1000));
        }
    }

    public static function formatCountdown($ms)
    {
        if ($ms === null) {
            return '';
        }
        $s = max(0, (int) round($ms / 1000));
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return ($h ? "{$h}h " : '') . ($h || $m ? "{$m}p " : '') . ($s % 60) . 's';
    }

    public static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    // Thay {biến}; dòng nào có biến mà tất cả đều rỗng thì bỏ dòng đó
    public static function render($tpl, array $vars, $html)
    {
        $val = function ($k) use ($vars) {
            return isset($vars[$k]) ? (string) $vars[$k] : '';
        };
        $lines = [];
        foreach (explode("\n", (string) $tpl) as $line) {
            preg_match_all('/\{(\w+)\}/', $line, $m);
            if ($m[1]) {
                $any = false;
                foreach ($m[1] as $k) {
                    if ($val($k) !== '') {
                        $any = true;
                        break;
                    }
                }
                if (!$any) {
                    continue;
                }
            }
            $lines[] = $line;
        }
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($val, $html) {
            return $html ? htmlspecialchars($val($m[1]), ENT_NOQUOTES, 'UTF-8') : $val($m[1]);
        }, implode("\n", $lines));
    }

    public static function randomId($len = 8)
    {
        return substr(bin2hex(random_bytes($len)), 0, $len);
    }

    // Gọi HTTP bằng cURL, trả về [status, body]
    public static function http($url, array $opts = [])
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, self::curlOptions($url, $opts));
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("Lỗi kết nối: $err");
        }
        return [$status, $body];
    }

    public static function curlOptions($url, array $opts)
    {
        $method = isset($opts['method']) ? strtoupper($opts['method']) : 'GET';
        $headers = [];
        foreach (isset($opts['headers']) ? $opts['headers'] : [] as $k => $v) {
            $headers[] = "$k: $v";
        }
        $o = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => isset($opts['timeout']) ? (int) $opts['timeout'] : 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_ENCODING => '',
        ];
        if (isset($opts['body']) && $method !== 'GET') {
            $o[CURLOPT_POSTFIELDS] = $opts['body'];
        }
        if (!empty($opts['file'])) {
            $o[CURLOPT_FILE] = $opts['file'];
            unset($o[CURLOPT_RETURNTRANSFER]);
        }
        return $o;
    }
}
