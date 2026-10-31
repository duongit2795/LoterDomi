<?php
// Tiến trình nền: gọi API, gửi thông báo, trả lời lệnh bot.
//   Cron mỗi phút:  php /www/wwwroot/<site>/worker.php          (chạy ~55 giây rồi thoát)
//   Chạy liên tục:  php /www/wwwroot/<site>/worker.php --daemon (dùng Supervisor của aaPanel)
//   Qua URL:        https://<site>/worker.php?key=<cronKey>
require __DIR__ . '/lib/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    $cronKey = is_installed() ? Config::get()['cronKey'] : '';
    if ($cronKey === '' || !hash_equals($cronKey, $key)) {
        http_response_code(403);
        exit("Sai key\n");
    }
    ignore_user_abort(true);
}
if (!is_installed()) {
    exit("Chưa cài đặt – mở install.php\n");
}

$args = $cli ? array_slice($argv, 1) : [];
$daemon = in_array('--daemon', $args, true);
$once = in_array('--once', $args, true);
$runFor = $daemon ? PHP_INT_MAX : ($once ? 0 : 55);
@set_time_limit($daemon ? 0 : $runFor + 60);

// Không cho 2 worker chạy cùng lúc; lần cron mới chờ lần cũ kết thúc tối đa 15 giây
$lock = fopen(DATA_DIR . '/worker.lock', 'c');
$waitUntil = microtime(true) + 15;
while (!flock($lock, LOCK_EX | LOCK_NB)) {
    if (microtime(true) > $waitUntil) {
        exit("Worker khác đang chạy\n");
    }
    usleep(500000);
}

$start = microtime(true);
$monitor = new Monitor();
$mode = $daemon ? 'daemon' : ($cli ? 'cron' : 'url');
$nextPoll = 0;
$nextBot = 0;

do {
    $loopStart = microtime(true);
    $cfg = Config::get(true);
    $monitor->heartbeat($mode);
    try {
        if ($cfg['monitor']['enabled']) {
            if ($loopStart >= $nextPoll) {
                $nextPoll = $loopStart + max(2, (int) $cfg['monitor']['intervalSeconds']);
                $monitor->poll();
            }
            $monitor->remind();
        }
        if ($loopStart >= $nextBot) {
            $nextBot = $loopStart + 2;
            $monitor->set('tgOffset', Bot::poll((int) $monitor->get('tgOffset', 0), $monitor));
        }
    } catch (Throwable $e) {
        Logger::error('Worker: ' . $e->getMessage());
    }
    $monitor->save();
    $sleep = 1 - (microtime(true) - $loopStart);
    if ($sleep > 0 && microtime(true) - $start < $runFor) {
        usleep((int) ($sleep * 1000000));
    }
} while (microtime(true) - $start < $runFor);

flock($lock, LOCK_UN);
echo "OK\n";
