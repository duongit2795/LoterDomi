<?php
// Xử lý lệnh Telegram (getUpdates, không cần webhook/SSL)
class Bot
{
    const HELP = "<b>LoterDomi bot</b>\n/next – các kỳ sắp mở + kế hoạch\n/last – kết quả kỳ gần nhất\n/subscribe – nhận thông báo tại chat này\n/unsubscribe – huỷ nhận thông báo\n/id – xem chat id";

    public static function nextText(array $states)
    {
        $tz = Config::get()['timezone'];
        $lines = [];
        foreach (Monitor::activeGames() as $g) {
            $s = isset($states[$g['id']]) ? $states[$g['id']] : [];
            $name = Util::h($g['name']);
            if (empty($s['nextIssue'])) {
                $lines[] = "• <b>$name</b>: chưa có dữ liệu";
                continue;
            }
            $line = "• <b>$name</b>: kỳ <code>" . Util::h($s['nextIssue']) . '</code>';
            if (!empty($s['nextTime'])) {
                $line .= ' – ' . Util::formatTime($s['nextTime'], $tz) . ' (còn ' . Util::formatCountdown($s['nextTime'] - Util::nowMs()) . ')';
            }
            if (!empty($s['plan'])) {
                $line .= "\n   📋 " . Util::h(isset($s['play']) ? $s['play'] : '') . ' ' . Util::h(isset($s['planRange']) ? $s['planRange'] : '') . ': <b>' . Util::h($s['plan']) . '</b>';
            }
            $lines[] = $line;
        }
        return $lines ? "⏰ <b>Kỳ sắp mở</b>\n" . implode("\n", $lines) : 'Chưa cấu hình game nào.';
    }

    public static function lastText(array $states)
    {
        $lines = [];
        foreach (Monitor::activeGames() as $g) {
            $s = isset($states[$g['id']]) ? $states[$g['id']] : [];
            $name = Util::h($g['name']);
            if (empty($s['issue'])) {
                $lines[] = "• <b>$name</b>: chưa có dữ liệu";
                continue;
            }
            $line = "• <b>$name</b>: kỳ <code>" . Util::h($s['issue']) . '</code> → <b>' . Util::h($s['result']) . '</b>';
            if (!empty($s['planStatus'])) {
                $line .= "\n   📋 " . Util::h($s['planCheck']) . ' → ' . Util::h($s['planStatus']);
            }
            $lines[] = $line;
        }
        return $lines ? "🎯 <b>Kết quả gần nhất</b>\n" . implode("\n", $lines) : 'Chưa cấu hình game nào.';
    }

    // Lấy và xử lý các tin nhắn mới; trả về offset mới
    public static function poll($offset, Monitor $monitor)
    {
        $cfg = Config::get();
        if (!$cfg['telegram']['token']) {
            return $offset;
        }
        try {
            $updates = Telegram::call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message']]);
        } catch (Exception $e) {
            Logger::warn('getUpdates: ' . $e->getMessage());
            return $offset;
        }
        foreach ($updates as $u) {
            $offset = $u['update_id'] + 1;
            if (empty($u['message']['text'])) {
                continue;
            }
            try {
                self::handle($u['message'], $monitor);
            } catch (Exception $e) {
                Logger::error('Xử lý lệnh lỗi: ' . $e->getMessage());
            }
        }
        return $offset;
    }

    private static function handle(array $msg, Monitor $monitor)
    {
        $parts = preg_split('/\s+/', trim($msg['text']));
        $cmd = strtolower(explode('@', $parts[0])[0]);
        $id = (string) $msg['chat']['id'];
        $cfg = Config::get(true);
        $states = $monitor->get('games', []);
        $reply = function ($text) use ($id) {
            Telegram::sendMessage($id, $text, 'HTML');
        };
        switch ($cmd) {
            case '/start':
            case '/help':
                return $reply(self::HELP);
            case '/next':
                return $reply(self::nextText($states));
            case '/last':
                return $reply(self::lastText($states));
            case '/id':
                return $reply("Chat id: <code>$id</code>");
            case '/subscribe':
                if (!$cfg['telegram']['allowSubscribe']) {
                    return $reply('Đăng ký đang bị tắt, liên hệ admin.');
                }
                if (!in_array($id, $cfg['telegram']['chatIds'], true)) {
                    Config::update(['telegram' => ['chatIds' => array_merge($cfg['telegram']['chatIds'], [$id])]]);
                    Logger::info("Chat $id đã đăng ký");
                }
                return $reply('✅ Đã đăng ký nhận thông báo.');
            case '/unsubscribe':
                Config::update(['telegram' => ['chatIds' => array_values(array_diff($cfg['telegram']['chatIds'], [$id]))]]);
                Logger::info("Chat $id huỷ đăng ký");
                return $reply('Đã huỷ nhận thông báo.');
        }
    }
}
