<?php
// Tiến trình nền: gọi API, gửi thông báo, trả lời lệnh bot.
//   Cron mỗi phút:    php /www/wwwroot/<site>/worker.php           (chạy ~55 giây rồi thoát)
//   Chạy liên tục:    php /www/wwwroot/<site>/worker.php --daemon  (dùng Supervisor của aaPanel)
//   Cron 5 giây:      php /www/wwwroot/<site>/worker.php --tick
//   Qua URL 1 phút:   https://<site>/worker.php?key=<cronKey>
//   Qua URL 5 giây:   https://<site>/worker.php?key=<cronKey>&mode=tick
require __DIR__ . '/lib/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
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
$tick = in_array('--tick', $args, true) || in_array('--once', $args, true) || (!$cli && ($_GET['mode'] ?? '') === 'tick');
// tick: chạy ~4 giây rồi thoát, hợp với cron gọi mỗi 5 giây
$runFor = $daemon ? PHP_INT_MAX : ($tick ? 4 : 55);
@set_time_limit($daemon ? 0 : $runFor + 60);

$lock = fopen(DATA_DIR . '/worker.lock', 'c');
if ($tick) {
    // Đang có worker khác chạy thì bỏ qua ngay, không để request chồng nhau
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        exit("SKIP – worker khác đang chạy\n");
    }
} else {
    // Lần cron mới chờ lần cũ kết thúc tối đa 15 giây
    $waitUntil = microtime(true) + 15;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $waitUntil) {
            exit("Worker khác đang chạy\n");
        }
        usleep(500000);
    }
}

// Gọi qua URL: trả lời ngay cho dịch vụ cron, phần việc chạy tiếp ở nền
if (!$cli) {
    echo "OK\n";
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

$start = microtime(true);
$monitor = new Monitor();
$mode = $daemon ? 'daemon' : ($tick ? 'tick' : ($cli ? 'cron' : 'url'));

do {
    $loopStart = microtime(true);
    $now = Util::nowMs();
    $cfg = Config::get(true);
    $monitor->heartbeat($mode);
    try {
        // Gửi lại tin bị lỗi tạm thời trước, để giữ đúng thứ tự
        Outbox::flush();
        if ($cfg['monitor']['enabled']) {
            // Mốc thời gian lưu trong state để các lần tick nối tiếp nhau đều đặn
            $interval = max(2, (int) $cfg['monitor']['intervalSeconds']) * 1000;
            if ($now - (int) $monitor->get('lastPoll', 0) >= $interval - 1000) {
                $monitor->set('lastPoll', $now);
                $monitor->poll();
            } elseif ($due = $monitor->dueIds()) {
                // Quanh giờ mở thưởng: gọi API mỗi giây cho riêng game đó để báo kết quả sớm nhất
                $monitor->poll($due);
            }
            $monitor->remind();
        }
        if ($now - (int) $monitor->get('lastBot', 0) >= 2000) {
            $monitor->set('lastBot', $now);
            $monitor->set('tgOffset', Bot::poll((int) $monitor->get('tgOffset', 0), $monitor));
        }
    } catch (Throwable $e) {
        Logger::error('Worker: ' . $e->getMessage());
    }
    $monitor->save();
    $sleep = 1 - (microtime(true) - $loopStart);
    if ($sleep > 0 && microtime(true) - $start + $sleep < $runFor) {
        usleep((int) ($sleep * 1000000));
    }
} while (microtime(true) - $start < $runFor);

flock($lock, LOCK_UN);
if ($cli) {
    echo "OK\n";
}
