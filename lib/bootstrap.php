<?php
// Nạp chung cho mọi entrypoint
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    http_response_code(500);
    exit('LoterDomi cần PHP >= 7.4');
}

define('APP_DIR', dirname(__DIR__));
define('DATA_DIR', APP_DIR . '/data');
define('APP_VERSION', trim((string) @file_get_contents(APP_DIR . '/VERSION')) ?: 'dev');

date_default_timezone_set('Asia/Ho_Chi_Minh');

require __DIR__ . '/Util.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Telegram.php';
require __DIR__ . '/Outbox.php';
require __DIR__ . '/Source.php';
require __DIR__ . '/Monitor.php';
require __DIR__ . '/Road.php';
require __DIR__ . '/RoadImage.php';
require __DIR__ . '/Method.php';
require __DIR__ . '/Bot.php';
require __DIR__ . '/Updater.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Db.php';
require __DIR__ . '/History.php';
require __DIR__ . '/Member.php';

function is_installed()
{
    $cfg = Store::read('config');
    return is_array($cfg) && !empty($cfg['installed']);
}
