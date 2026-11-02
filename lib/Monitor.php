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

    // Game cần gọi API dồn dập: đang chờ dàn kế hoạch cập nhật, hoặc đã tới giờ mở thưởng mà chưa có kết quả
    public function dueIds()
    {
        $now = Util::nowMs();
        $ids = [];
        foreach (self::activeGames() as $g) {
            $s = $this->state['games'][$g['id']] ?? [];
            if (!empty($s['pendingIssue'])
                || (!empty($s['nextTime']) && $now >= $s['nextTime'] - 500 && $now <= $s['nextTime'] + 120000)) {
                $ids[] = $g['id'];
            }
        }
        return $ids;
    }

    // Báo cho trang xem cầu / trang thành viên biết dữ liệu vừa đổi: ghi cache/ver.json (file tĩnh, rất nhỏ)
    private function publishVersion()
    {
        $sig = [];
        foreach ($this->state['games'] as $id => $s) {
            $sig[$id] = [$s['issue'] ?? '', $s['nextIssue'] ?? '', $s['plan'] ?? '', $s['streakLabel'] ?? ''];
        }
        $sig = md5(json_encode($sig));
        if (($this->state['pubSig'] ?? '') === $sig) {
            return;
        }
        $this->state['pubSig'] = $sig;
        $dir = APP_DIR . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = "$dir/ver.json";
        if (@file_put_contents("$file.tmp", json_encode(['v' => Util::nowMs()])) !== false && @rename("$file.tmp", $file)) {
            Store::fixOwner($file);
        }
    }

    // $onlyIds: chỉ gọi API các game này (chế độ gọi nhanh quanh giờ mở thưởng)
    public function poll(?array $onlyIds = null)
    {
        $cfg = Config::get();
        $games = self::activeGames();
        if ($onlyIds !== null) {
            $games = array_values(array_filter($games, function ($g) use ($onlyIds) {
                return in_array($g['id'], $onlyIds, true);
            }));
        }
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
            if (isset($r['data']['roadRows'])) {
                // Chuỗi W/L theo từng kỳ (cùng dữ liệu với bảng Cầu)
                $st = Road::streak(Road::merge($id, $r['data']['roadRows']));
                unset($r['data']['roadRows']);
                $r['data'] += [
                    'streakType' => $st['type'],
                    'streakCount' => $st['count'],
                    'streakLabel' => $st['label'],
                    // Kỳ vừa mở đã có trong dữ liệu từng kỳ thì chuỗi vừa được cập nhật
                    'streakNew' => $st['lastIssue'] !== '' && $st['lastIssue'] === $r['data']['issue'],
                ];
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
                        History::record($game, $s);
                        // Cập nhật trang xem cầu ngay, cùng lúc với gửi Telegram (lưu state trước để trang đọc được)
                        $this->state['games'][$id] = $s;
                        $this->save();
                        $this->publishVersion();
                        if ($cfg['monitor']['notifyResult']) {
                            $text = self::message('result', $game, $s);
                            if ($cfg['monitor']['combineNext']) {
                                $next = self::message('next', $game, $s);
                                $text .= $next !== '' ? "\n\n" . $next : '';
                                // Đã báo kỳ tiếp trong tin này, không nhắc riêng nữa
                                $s['remindedIssue'] = $s['nextIssue'];
                            }
                            $img = $cfg['monitor']['roadImage'];
                            $s['imgCount'] = (int) ($s['imgCount'] ?? 0) + 1;
                            $png = null;
                            if ($img['enabled'] && $game['type'] === 'vnlp' && RoadImage::available()
                                && ($s['imgCount'] - 1) % max(1, (int) $img['every']) === 0) {
                                try {
                                    $png = (new RoadImage())->render($game['name'], Road::get($id, RoadImage::WINDOW), $s);
                                } catch (Throwable $e) {
                                    Logger::warn("[{$game['name']}] vẽ ảnh cầu lỗi: " . $e->getMessage());
                                }
                            }
                            $n = $png !== null
                                ? Telegram::broadcastPhoto($png, $text, self::targets($game))
                                : Telegram::broadcast($text, self::targets($game));
                            Logger::info("[{$game['name']}] báo kết quả kỳ {$s['issue']}" . ($png !== null ? ' (kèm ảnh cầu)' : '') . " → $n chat");
                        }
                        $this->streakAlert($game, $s);
                    }
                }
            }
            $this->state['games'][$id] = $s;
        }
        $this->publishVersion();
        $this->save();
    }

    // Báo khi chuỗi W/L liên tiếp (theo từng kỳ) đạt ngưỡng; từ kỳ thứ N trở đi mỗi kỳ báo 1 lần
    private function streakAlert(array $game, array $s)
    {
        $cfg = Config::get()['monitor']['streak'];
        if (!$cfg['enabled'] || (!$cfg['targets'] && !$cfg['alsoGameTargets']) || empty($s['streakNew']) || empty($s['streakType'])) {
            return;
        }
        $min = (int) ($s['streakType'] === 'win' ? $cfg['winMin'] : $cfg['loseMin']);
        if ($min <= 0 || $s['streakCount'] < $min) {
            return;
        }
        // Gửi vào nơi nhận báo chuỗi và cả nơi nhận của game đó (không trùng lặp)
        $targets = $cfg['targets'];
        if ($cfg['alsoGameTargets']) {
            $targets = array_values(array_unique(array_merge($targets, self::targets($game))));
        }
        $n = Telegram::broadcast(self::message('streak', $game, $s), $targets);
        Logger::info("[{$game['name']}] chuỗi {$s['streakType']} {$s['streakCount']} kỳ → $n chat");
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
