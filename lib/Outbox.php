<?php
// Hàng chờ tin nhắn gửi lỗi tạm thời: gửi lại theo đúng thứ tự từng nơi nhận, bỏ sau MAX_AGE
class Outbox
{
    const MAX_AGE_MS = 900000; // 15 phút
    const MAX_PER_FLUSH = 15;

    private static function withLock(callable $fn)
    {
        $lock = fopen(DATA_DIR . '/outbox.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function items()
    {
        return Store::read('outbox', []);
    }

    public static function has($target)
    {
        foreach (self::items() as $it) {
            if ($it['target'] === (string) $target) {
                return true;
            }
        }
        return false;
    }

    public static function add($target, $text)
    {
        self::withLock(function () use ($target, $text) {
            $items = self::items();
            $now = Util::nowMs();
            $items[] = ['target' => (string) $target, 'text' => $text, 'tries' => 0, 'firstAt' => $now, 'nextAt' => $now + 3000];
            Store::write('outbox', $items);
        });
    }

    // Gửi lại các tin đến hạn; nơi nào còn lỗi thì dừng nơi đó để giữ thứ tự
    public static function flush()
    {
        if (!is_file(Store::path('outbox'))) {
            return;
        }
        self::withLock(function () {
            $items = self::items();
            if (!$items) {
                return;
            }
            $now = Util::nowMs();
            $blocked = [];
            $sent = 0;
            $keep = [];
            foreach ($items as $it) {
                $t = $it['target'];
                if ($now - $it['firstAt'] > self::MAX_AGE_MS) {
                    Logger::error("Bỏ tin gửi tới $t sau 15 phút gửi lại không được");
                    continue;
                }
                if (isset($blocked[$t]) || $it['nextAt'] > $now || $sent >= self::MAX_PER_FLUSH) {
                    $blocked[$t] = true;
                    $keep[] = $it;
                    continue;
                }
                try {
                    Telegram::sendMessage($t, $it['text']);
                    $sent++;
                    Logger::info("Đã gửi lại tin tới $t (lần thử " . ($it['tries'] + 1) . ')');
                } catch (Exception $e) {
                    if (!Telegram::isTransient($e)) {
                        Logger::error("Bỏ tin gửi tới $t: " . $e->getMessage());
                        continue;
                    }
                    $it['tries']++;
                    $wait = $e instanceof TelegramException && $e->retryAfter ? $e->retryAfter * 1000 : min(60000, 5000 * (2 ** ($it['tries'] - 1)));
                    $it['nextAt'] = $now + $wait;
                    $blocked[$t] = true;
                    $keep[] = $it;
                }
            }
            Store::write('outbox', $keep);
        });
    }
}
