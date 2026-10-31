<?php
// Theo dõi các game: phát hiện kỳ mới có kết quả, nhắc kỳ sắp mở
class Monitor
{
    const PLAN_WAIT_MS = 20000;

    private $state;

    public function __construct()
    {
        $this->state = Store::read('state', []);
        if (!isset($this->state['games']) || !is_array($this->state['games'])) {
            $this->state['games'] = [];
        }
    }

    public static function vars(array $game, array $s, $nowMs = null)
    {
        $nowMs = $nowMs ?: Util::nowMs();
        $tz = Config::get()['timezone'];
        $next = isset($s['nextTime']) ? $s['nextTime'] : null;
        return array_merge($s, [
            'game' => $game['name'] ?: $game['id'],
            'nextTime' => Util::formatTime($next, $tz),
            'countdown' => $next ? Util::formatCountdown($next - $nowMs) : '',
            'now' => Util::formatTime($nowMs, $tz),
            'streakText' => isset($s['streakType']) ? ($s['streakType'] === 'win' ? 'THẮNG' : ($s['streakType'] === 'lose' ? 'THUA' : '')) : '',
            'streakIcon' => isset($s['streakType']) ? ($s['streakType'] === 'win' ? '🔥' : ($s['streakType'] === 'lose' ? '🧊' : '')) : '',
        ]);
    }

    public static function message($kind, array $game, array $s)
    {
        $cfg = Config::get();
        return Util::render($cfg['templates'][$kind], self::vars($game, $s), $cfg['telegram']['parseMode'] === 'HTML');
    }

    // Nơi nhận của game: danh sách riêng nếu có, không thì các chat chung
    public static function targets(array $game)
    {
        return $game['targets'] ? $game['targets'] : Config::get()['telegram']['chatIds'];
    }

    public static function activeGames()
    {
        return array_values(array_filter(Config::get()['games'], function ($g) {
            return $g['enabled'] && ($g['type'] === 'vnlp' ? $g['vnlp']['gameId'] !== '' : $g['url'] !== '');
        }));
    }

    public function poll()
    {
        $cfg = Config::get();
        $games = self::activeGames();
        if (!$games) {
            return;
        }
        $results = Source::fetchMany($games);
        foreach ($games as $game) {
            $id = $game['id'];
            $s = isset($this->state['games'][$id]) ? $this->state['games'][$id] : [];
            $r = $results[$id];
            $s['lastFetch'] = Util::nowMs();
            if (isset($r['error'])) {
                if (empty($s['lastError']) || $s['lastError'] !== $r['error']) {
                    Logger::warn("[{$game['name']}] lấy dữ liệu lỗi: {$r['error']}");
                }
                $s['lastError'] = $r['error'];
                $this->state['games'][$id] = $s;
                continue;
            }
            $s = array_merge($s, $r['data'], ['lastError' => null]);

            // Kỳ mới có kết quả
            if ($s['issue'] !== '' && $s['result'] !== '' && (!isset($s['notifiedIssue']) || $s['notifiedIssue'] !== $s['issue'])) {
                $first = !array_key_exists('notifiedIssue', $s);
                if ($first) {
                    // Lần đầu chỉ ghi nhận, không gửi để tránh spam khi mới chạy
                    $s['notifiedIssue'] = $s['issue'];
                } else {
                    if (!isset($s['pendingIssue']) || $s['pendingIssue'] !== $s['issue']) {
                        $s['pendingIssue'] = $s['issue'];
                        $s['pendingSince'] = Util::nowMs();
                    }
                    // Chờ API cập nhật xong dàn kế hoạch (tối đa PLAN_WAIT_MS) rồi mới gửi
                    if (!empty($s['planReady']) || Util::nowMs() - $s['pendingSince'] >= self::PLAN_WAIT_MS) {
                        $s['notifiedIssue'] = $s['issue'];
                        unset($s['pendingIssue'], $s['pendingSince']);
                        if ($cfg['monitor']['notifyResult']) {
                            $text = self::message('result', $game, $s);
                            if ($cfg['monitor']['combineNext']) {
                                $next = self::message('next', $game, $s);
                                $text .= $next !== '' ? "\n\n" . $next : '';
                                // Đã báo kỳ tiếp trong tin này, không nhắc riêng nữa
                                $s['remindedIssue'] = $s['nextIssue'];
                            }
                            $n = Telegram::broadcast($text, self::targets($game));
                            Logger::info("[{$game['name']}] báo kết quả kỳ {$s['issue']} → $n chat");
                        }
                        $this->streakAlert($game, $s);
                    }
                }
            }
            $this->state['games'][$id] = $s;
        }
        $this->save();
    }

    // Báo khi chuỗi thắng/thua liên tiếp đạt ngưỡng (từ ván thứ N trở đi, mỗi ván báo 1 lần)
    private function streakAlert(array $game, array $s)
    {
        $cfg = Config::get()['monitor']['streak'];
        if (!$cfg['enabled'] || !$cfg['targets'] || empty($s['streakNew']) || empty($s['streakType'])) {
            return;
        }
        $min = (int) ($s['streakType'] === 'win' ? $cfg['winMin'] : $cfg['loseMin']);
        if ($min <= 0 || $s['streakCount'] < $min) {
            return;
        }
        $n = Telegram::broadcast(self::message('streak', $game, $s), $cfg['targets']);
        Logger::info("[{$game['name']}] chuỗi {$s['streakType']} {$s['streakCount']} ván → $n chat");
    }

    public function remind()
    {
        $cfg = Config::get();
        if (!$cfg['monitor']['notifyUpcoming'] || $cfg['monitor']['combineNext']) {
            return;
        }
        $now = Util::nowMs();
        foreach (self::activeGames() as $game) {
            $s = isset($this->state['games'][$game['id']]) ? $this->state['games'][$game['id']] : null;
            if (!$s || empty($s['nextIssue']) || empty($s['nextTime'])
                || (isset($s['remindedIssue']) && $s['remindedIssue'] === $s['nextIssue'])) {
                continue;
            }
            $left = $s['nextTime'] - $now;
            if ($left <= $cfg['monitor']['remindBeforeSeconds'] * 1000 && $left > -5000) {
                $this->state['games'][$game['id']]['remindedIssue'] = $s['nextIssue'];
                $this->save();
                $n = Telegram::broadcast(self::message('upcoming', $game, $s), self::targets($game));
                Logger::info("[{$game['name']}] nhắc kỳ {$s['nextIssue']} → $n chat");
            }
        }
    }

    public function heartbeat($mode)
    {
        $this->state['worker'] = ['heartbeat' => Util::nowMs(), 'mode' => $mode, 'pid' => getmypid()];
    }

    public function get($key, $default = null)
    {
        return isset($this->state[$key]) ? $this->state[$key] : $default;
    }

    public function set($key, $value)
    {
        $this->state[$key] = $value;
    }

    public function save()
    {
        Store::write('state', $this->state);
    }

    public static function snapshot()
    {
        return Store::read('state', ['games' => []]);
    }
}
