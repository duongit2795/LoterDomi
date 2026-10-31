<?php
// Xử lý lệnh Telegram (getUpdates, không cần webhook/SSL)
class Bot
{
    const HELP = "<b>LoterDomi bot</b>\n/next – các kỳ sắp mở + kế hoạch\n/last – kết quả kỳ gần nhất\n/bind tên game – gửi game đó vào chủ đề/chat này (admin nhóm)\n/bind chuỗi – nhận báo chuỗi thắng/thua liên tiếp tại chủ đề này\n/unbind – bỏ gán các game khỏi chủ đề này\n/topics – xem game nào gửi đi đâu\n/subscribe – nhận thông báo tại chat này\n/unsubscribe – huỷ nhận thông báo\n/id – xem chat id, chủ đề id";

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

    // Người gửi có phải admin nhóm không (chat riêng thì luôn được)
    private static function isGroupAdmin(array $msg)
    {
        if ($msg['chat']['type'] === 'private') {
            return true;
        }
        if (empty($msg['from']['id'])) {
            return false;
        }
        try {
            $m = Telegram::call('getChatMember', ['chat_id' => $msg['chat']['id'], 'user_id' => $msg['from']['id']]);
            return in_array($m['status'], ['creator', 'administrator'], true);
        } catch (Exception $e) {
            return false;
        }
    }

    // Tìm game theo số thứ tự (1, 2...) hoặc một phần tên ("1 phút", "45")
    private static function findGame(array $games, $query)
    {
        if (ctype_digit($query) && isset($games[(int) $query - 1])) {
            return [$games[(int) $query - 1]];
        }
        $q = mb_strtolower($query);
        $exact = array_values(array_filter($games, function ($g) use ($q) {
            return mb_strtolower($g['name']) === $q;
        }));
        if ($exact) {
            return $exact;
        }
        $found = array_values(array_filter($games, function ($g) use ($q) {
            return mb_strpos(mb_strtolower($g['name']), $q) !== false;
        }));
        if (count($found) > 1) {
            // "5 phút" khớp cả "1.5 Phút": ưu tiên game có cụm từ đứng đầu một từ
            $word = array_values(array_filter($found, function ($g) use ($q) {
                return preg_match('/(^|\s)' . preg_quote($q, '/') . '/u', mb_strtolower($g['name']));
            }));
            if (count($word) === 1) {
                return $word;
            }
        }
        return $found;
    }

    private static function gameList(array $games)
    {
        $lines = [];
        foreach ($games as $i => $g) {
            $where = $g['targets'] ? implode(', ', $g['targets']) : 'chat chung';
            $lines[] = ($i + 1) . '. <b>' . Util::h($g['name']) . '</b> → <code>' . Util::h($where) . '</code>';
        }
        return implode("\n", $lines);
    }

    private static function handle(array $msg, Monitor $monitor)
    {
        $parts = preg_split('/\s+/', trim($msg['text']), 2);
        $cmd = strtolower(explode('@', $parts[0])[0]);
        $arg = isset($parts[1]) ? trim($parts[1]) : '';
        $chatId = (string) $msg['chat']['id'];
        // Tin nhắn trong một chủ đề (topic) của nhóm diễn đàn
        $thread = !empty($msg['is_topic_message']) && !empty($msg['message_thread_id']) ? (int) $msg['message_thread_id'] : 0;
        $here = $thread ? "$chatId:$thread" : $chatId;
        $cfg = Config::get(true);
        $states = $monitor->get('games', []);
        $reply = function ($text) use ($here) {
            Telegram::sendMessage($here, $text, 'HTML');
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
                return $reply("Chat id: <code>$chatId</code>" . ($thread ? "\nChủ đề id: <code>$thread</code>\nNơi nhận (dán vào admin): <code>$here</code>" : ''));
            case '/topics':
                $st = $cfg['monitor']['streak'];
                return $reply("📌 <b>Nơi gửi của từng game</b>\n" . (self::gameList($cfg['games']) ?: 'Chưa có game.')
                    . "\n\n🔥 <b>Báo chuỗi</b> (thắng ≥ {$st['winMin']}, thua ≥ {$st['loseMin']}) → <code>" . Util::h($st['targets'] ? implode(', ', $st['targets']) : 'chưa gán') . '</code>');
            case '/bind':
            case '/unbind':
                if (!self::isGroupAdmin($msg)) {
                    return $reply('Chỉ admin nhóm mới dùng được lệnh này.');
                }
                $games = $cfg['games'];
                if ($cmd === '/unbind') {
                    $names = [];
                    foreach ($games as &$g) {
                        if (in_array($here, $g['targets'], true)) {
                            $g['targets'] = array_values(array_diff($g['targets'], [$here]));
                            $names[] = $g['name'];
                        }
                    }
                    unset($g);
                    $streakTargets = $cfg['monitor']['streak']['targets'];
                    if (in_array($here, $streakTargets, true)) {
                        $names[] = 'báo chuỗi';
                    }
                    Config::update(['games' => $games, 'monitor' => ['streak' => ['targets' => array_values(array_diff($streakTargets, [$here]))]]]);
                    Logger::info("Bỏ gán $here khỏi: " . implode(', ', $names));
                    return $reply($names ? 'Đã bỏ gán: ' . Util::h(implode(', ', $names)) : 'Chủ đề này chưa gán game nào.');
                }
                if (in_array(mb_strtolower($arg), ['chuỗi', 'chuoi', 'streak'], true)) {
                    $targets = $cfg['monitor']['streak']['targets'];
                    if (!in_array($here, $targets, true)) {
                        $targets[] = $here;
                    }
                    Config::update(['monitor' => ['streak' => ['targets' => $targets, 'enabled' => true]]]);
                    Logger::info("Gán báo chuỗi thắng/thua → $here");
                    $st = $cfg['monitor']['streak'];
                    return $reply('✅ Báo chuỗi thắng/thua sẽ gửi vào ' . ($thread ? 'chủ đề này' : 'chat này') . " (<code>$here</code>).\nNgưỡng: thắng từ {$st['winMin']} ván, thua từ {$st['loseMin']} ván (0 = tắt) – chỉnh trong admin → Cài đặt.");
                }
                if ($arg === '') {
                    return $reply("Gõ <code>/bind tên game</code> hoặc <code>/bind số</code> ngay trong chủ đề muốn nhận tin (<code>/bind chuỗi</code> để nhận báo chuỗi thắng/thua):\n" . self::gameList($games));
                }
                $found = self::findGame($games, $arg);
                if (count($found) !== 1) {
                    return $reply(($found ? 'Có nhiều game khớp, ghi rõ hơn hoặc dùng số:' : 'Không tìm thấy game.') . "\n" . self::gameList($games));
                }
                foreach ($games as &$g) {
                    if ($g['id'] === $found[0]['id'] && !in_array($here, $g['targets'], true)) {
                        $g['targets'][] = $here;
                    }
                }
                unset($g);
                Config::update(['games' => $games]);
                Logger::info("Gán {$found[0]['name']} → $here");
                return $reply('✅ <b>' . Util::h($found[0]['name']) . '</b> sẽ gửi vào ' . ($thread ? 'chủ đề này' : 'chat này') . " (<code>$here</code>).");
            case '/subscribe':
                if (!$cfg['telegram']['allowSubscribe']) {
                    return $reply('Đăng ký đang bị tắt, liên hệ admin.');
                }
                if (!in_array($here, $cfg['telegram']['chatIds'], true)) {
                    Config::update(['telegram' => ['chatIds' => array_merge($cfg['telegram']['chatIds'], [$here])]]);
                    Logger::info("Chat $here đã đăng ký");
                }
                return $reply('✅ Đã đăng ký nhận thông báo (các game chưa gán chủ đề riêng).');
            case '/unsubscribe':
                Config::update(['telegram' => ['chatIds' => array_values(array_diff($cfg['telegram']['chatIds'], [$here]))]]);
                Logger::info("Chat $here huỷ đăng ký");
                return $reply('Đã huỷ nhận thông báo.');
        }
    }
}
