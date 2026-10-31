<?php
// Theo dõi các game: phát hiện kỳ mới có kết quả, nhắc kỳ sắp mở
class Monitor
{
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
        ]);
    }

    public static function message($kind, array $game, array $s)
    {
        $cfg = Config::get();
        return Util::render($cfg['templates'][$kind], self::vars($game, $s), $cfg['telegram']['parseMode'] === 'HTML');
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
                $s['notifiedIssue'] = $s['issue'];
                // Lần đầu chỉ ghi nhận, không gửi để tránh spam khi mới chạy
                if (!$first && $cfg['monitor']['notifyResult']) {
                    $n = Telegram::broadcast(self::message('result', $game, $s));
                    Logger::info("[{$game['name']}] báo kết quả kỳ {$s['issue']} → $n chat");
                }
            }
            $this->state['games'][$id] = $s;
        }
        $this->save();
    }

    public function remind()
    {
        $cfg = Config::get();
        if (!$cfg['monitor']['notifyUpcoming']) {
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
                $n = Telegram::broadcast(self::message('upcoming', $game, $s));
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
