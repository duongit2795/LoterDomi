<?php
class Logger
{
    const MAX = 300;

    public static function log($level, $msg)
    {
        $entries = Store::read('logs', []);
        $entries[] = ['time' => date('c'), 'level' => $level, 'msg' => (string) $msg];
        if (count($entries) > self::MAX) {
            $entries = array_slice($entries, -self::MAX);
        }
        try {
            Store::write('logs', $entries);
        } catch (Exception $e) {
            // không ghi được log thì thôi, vẫn in ra stdout
        }
        if (PHP_SAPI === 'cli') {
            echo '[' . date('H:i:s') . '] ' . strtoupper($level) . ' ' . $msg . PHP_EOL;
        }
    }

    public static function info($m)
    {
        self::log('info', $m);
    }

    public static function warn($m)
    {
        self::log('warn', $m);
    }

    public static function error($m)
    {
        self::log('error', $m);
    }

    public static function latest($n = 100)
    {
        return array_reverse(array_slice(Store::read('logs', []), -$n));
    }
}
