<?php
// Phương pháp tự dự đoán (không dùng dàn kế hoạch của API).
// Mỗi kỳ: chấm W/L dự đoán của kỳ vừa mở (số về = chữ số cuối kết quả), rồi tạo + lưu dự đoán cho kỳ tiếp.
// Dữ liệu: data/predictions.php = { methodId: { gameId: [[kỳ, "dàn", số về, 'W'|'L'|'', giờ tạo ms], ...] } }
class Method
{
    const KEEP = 1000;
    const STORE = 'predictions';
    const TYPES = ['random' => 'Ngẫu nhiên'];

    public static function normalize(array $m)
    {
        $m = Util::merge(['id' => '', 'name' => 'Ngẫu nhiên', 'type' => 'random', 'size' => 5, 'enabled' => true, 'notify' => true], $m);
        if (!preg_match('/^[a-z0-9_]{2,24}$/', (string) $m['id'])) {
            $m['id'] = 'm' . Util::randomId(6);
        }
        if (!isset(self::TYPES[$m['type']])) {
            $m['type'] = 'random';
        }
        $m['size'] = min(9, max(1, (int) $m['size']));
        $m['name'] = mb_substr(trim((string) $m['name']), 0, 40) ?: self::TYPES[$m['type']];
        $m['enabled'] = (bool) $m['enabled'];
        $m['notify'] = (bool) $m['notify'];
        return $m;
    }

    public static function enabled()
    {
        return array_values(array_filter(Config::get()['methods'], function ($m) {
            return $m['enabled'];
        }));
    }

    public static function find($id)
    {
        foreach (Config::get()['methods'] as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }
        return null;
    }

    // Số về của kiểu chơi Đuôi = chữ số cuối của kết quả ("5,7,7,9,8,2" → "2")
    public static function resultDigit($result)
    {
        return preg_match('/(\d)\D*$/', (string) $result, $m) ? $m[1] : '';
    }

    // Sinh dàn số theo phương pháp
    public static function generate(array $m, array $game, array $s)
    {
        switch ($m['type']) {
            case 'random':
            default:
                $digits = range(0, 9);
                // Xáo trộn Fisher–Yates bằng random_int (ngẫu nhiên an toàn)
                for ($i = 9; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$digits[$i], $digits[$j]] = [$digits[$j], $digits[$i]];
                }
                $pick = array_slice($digits, 0, $m['size']);
                sort($pick);
                return implode(' ', $pick);
        }
    }

    // Gọi mỗi lần có dữ liệu mới của 1 game; trả về true nếu có thay đổi
    public static function process(array $game, array $s)
    {
        $methods = self::enabled();
        if (!$methods || $game['type'] !== 'vnlp' || empty($s['issue'])) {
            return false;
        }
        $all = Store::read(self::STORE, []);
        $changed = false;
        $digit = self::resultDigit($s['result'] ?? '');
        foreach ($methods as $m) {
            $list = $all[$m['id']][$game['id']] ?? [];
            $keep = [];
            foreach ($list as $it) {
                if ($it[3] === '') {
                    if ($it[0] === $s['issue'] && $digit !== '') {
                        // Chấm kỳ vừa mở
                        $it[2] = $digit;
                        $it[3] = in_array($digit, explode(' ', $it[1]), true) ? 'W' : 'L';
                        $changed = true;
                    } elseif ($it[0] !== ($s['nextIssue'] ?? '') && $it[0] !== $s['issue']) {
                        // Kỳ đã qua mà không bắt được kết quả (bot dừng...) – bỏ dự đoán đó
                        $changed = true;
                        continue;
                    }
                }
                $keep[] = $it;
            }
            // Tạo dự đoán cho kỳ tiếp (mỗi kỳ chỉ 1 lần)
            $next = $s['nextIssue'] ?? '';
            if ($next !== '' && !in_array($next, array_column($keep, 0), true)) {
                $keep[] = [$next, self::generate($m, $game, $s), '', '', Util::nowMs()];
                $changed = true;
            }
            $all[$m['id']][$game['id']] = array_slice($keep, -self::KEEP);
        }
        if ($changed) {
            Store::write(self::STORE, $all);
        }
        return $changed;
    }

    // Dự đoán đã chấm, dạng giống dữ liệu cầu: [kỳ, W|L, dàn, số về, null]
    public static function road($methodId, $gameId, $limit)
    {
        $list = Store::read(self::STORE, [])[$methodId][$gameId] ?? [];
        $out = [];
        foreach ($list as $it) {
            if ($it[3] !== '') {
                $out[] = [$it[0], $it[3], $it[1], $it[2], null];
            }
        }
        return array_slice($out, -max(1, (int) $limit));
    }

    // Dự đoán đang chờ kết quả của kỳ $issue
    public static function pending($methodId, $gameId, $issue)
    {
        foreach (array_reverse(Store::read(self::STORE, [])[$methodId][$gameId] ?? []) as $it) {
            if ($it[0] === $issue) {
                return $it;
            }
        }
        return null;
    }

    // Kết quả đã chấm của kỳ $issue
    public static function graded($methodId, $gameId, $issue)
    {
        $it = self::pending($methodId, $gameId, $issue);
        return $it && $it[3] !== '' ? $it : null;
    }

    // Các dòng gắn vào cuối tin kết quả Telegram
    public static function messageLines(array $game, array $s, $html)
    {
        $h = function ($x) use ($html) {
            return $html ? Util::h($x) : $x;
        };
        $b = function ($x) use ($html, $h) {
            return $html ? '<b>' . $h($x) . '</b>' : $x;
        };
        $lines = [];
        foreach (self::enabled() as $m) {
            if (!$m['notify']) {
                continue;
            }
            $done = self::graded($m['id'], $game['id'], $s['issue'] ?? '');
            $next = self::pending($m['id'], $game['id'], $s['nextIssue'] ?? '');
            if (!$done && !$next) {
                continue;
            }
            $line = '🎲 ' . $b('PP ' . $m['name']);
            if ($done) {
                $line .= ': dàn ' . $h($done[1]) . ' → về ' . $b($done[2]) . ' ' . ($done[3] === 'W' ? '✅ W' : '❌ L');
            }
            if ($next) {
                $line .= "\n   Kỳ tiếp " . $h(preg_replace('/^.*-/', '', $next[0])) . ': ' . $b($next[1]);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    // Thống kê W/L N kỳ gần nhất của từng phương pháp × game
    public static function stats($limit = 200)
    {
        $all = Store::read(self::STORE, []);
        $out = [];
        foreach (Config::get()['methods'] as $m) {
            $row = ['id' => $m['id'], 'name' => $m['name'], 'games' => []];
            foreach (Config::get()['games'] as $g) {
                $done = array_values(array_filter($all[$m['id']][$g['id']] ?? [], function ($it) {
                    return $it[3] !== '';
                }));
                $done = array_slice($done, -$limit);
                $w = count(array_filter($done, function ($it) {
                    return $it[3] === 'W';
                }));
                $row['games'][] = ['id' => $g['id'], 'name' => $g['name'], 'n' => count($done), 'w' => $w];
            }
            $out[] = $row;
        }
        return $out;
    }
}
