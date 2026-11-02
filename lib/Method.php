<?php
// Phương pháp tự dự đoán (không dùng dàn kế hoạch của API).
// Mỗi kỳ: chấm W/L dự đoán của kỳ vừa mở (số về = chữ số cuối kết quả), rồi tạo + lưu dự đoán cho kỳ tiếp.
// Dữ liệu:
//   data/predictions.php = { methodId: { gameId: [[kỳ, "dàn", số về, 'W'|'L'|'', giờ tạo ms], ...] } }
//   data/digits.php      = { gameId: [[kỳ, số về], ...] }  – lịch sử số về để tính số nóng/lạnh...
class Method
{
    const KEEP = 1000;
    const DIGITS_KEEP = 500;
    const STORE = 'predictions';
    const TYPES = [
        'random' => ['Ngẫu nhiên', 'Chọn ngẫu nhiên N số khác nhau'],
        'hot' => ['Số nóng', 'N số về nhiều nhất trong K kỳ gần nhất'],
        'cold' => ['Số lạnh (gan)', 'N số lâu chưa về nhất'],
        'hotcold' => ['Nóng + lạnh', 'Nửa số nóng (K kỳ), nửa số lạnh'],
        'recent' => ['Số về gần đây', 'N số khác nhau về gần nhất'],
        'follow' => ['Theo số vừa về', 'Số vừa về và các số kề ±1, ±2…'],
        'shadow' => ['Bóng âm dương', 'Số vừa về + bóng dương (0↔5, 1↔6…) + bóng âm (0↔7, 1↔4, 2↔9, 3↔6, 5↔8), thiếu thì thêm số nóng'],
    ];
    // Bóng âm
    const BONG_AM = [0 => 7, 7 => 0, 1 => 4, 4 => 1, 2 => 9, 9 => 2, 3 => 6, 6 => 3, 5 => 8, 8 => 5];

    public static function defaults()
    {
        $out = [];
        foreach (self::TYPES as $type => $info) {
            $out[] = ['id' => $type, 'name' => $info[0], 'type' => $type, 'size' => 5, 'window' => 30,
                'enabled' => true, 'notify' => $type === 'random'];
        }
        return $out;
    }

    // Cấu hình cũ chưa có các phương pháp mới thì thêm vào (chỉ thêm 1 lần; admin xoá rồi thì không thêm lại)
    public static function withDefaults(array $methods, array $added)
    {
        $ids = array_column($methods, 'id');
        foreach (self::defaults() as $d) {
            if (!in_array($d['id'], $ids, true) && !in_array($d['id'], $added, true) && $d['id'] !== 'random') {
                $methods[] = $d;
            }
        }
        return $methods;
    }

    public static function normalize(array $m)
    {
        $m = Util::merge(['id' => '', 'name' => '', 'type' => 'random', 'size' => 5, 'window' => 30, 'enabled' => true, 'notify' => false, 'targets' => []], $m);
        // Topic/chat riêng của phương pháp: có thì tin cầu của phương pháp chỉ gửi vào đây
        $m['targets'] = Config::cleanTargets($m['targets']);
        if (!preg_match('/^[a-z0-9_]{2,24}$/', (string) $m['id'])) {
            $m['id'] = 'm' . Util::randomId(6);
        }
        if (!isset(self::TYPES[$m['type']])) {
            $m['type'] = 'random';
        }
        $m['size'] = min(9, max(1, (int) $m['size']));
        $m['window'] = min(Method::DIGITS_KEEP, max(5, (int) $m['window']));
        $m['name'] = mb_substr(trim((string) $m['name']), 0, 40) ?: self::TYPES[$m['type']][0];
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

    // Tìm phương pháp theo id / một phần tên (không phân biệt hoa thường)
    public static function search($q)
    {
        $q = mb_strtolower(trim($q));
        $all = Config::get()['methods'];
        foreach ($all as $m) {
            if ($m['id'] === $q || mb_strtolower($m['name']) === $q) {
                return [$m];
            }
        }
        $found = array_values(array_filter($all, function ($m) use ($q) {
            return $q !== '' && (mb_strpos(mb_strtolower($m['name']), $q) !== false || mb_strpos($m['id'], $q) !== false);
        }));
        // Nhiều kết quả: chọn tên khớp sát nhất (ngắn nhất), vd "nóng" → "Số nóng" chứ không phải "Nóng + lạnh"
        if (count($found) > 1) {
            // Độ dài tên không tính phần trong ngoặc: "Số lạnh (gan)" → "Số lạnh"
            $len = function ($m) {
                return mb_strlen(trim(preg_replace('/\(.*?\)/u', '', $m['name'])));
            };
            usort($found, function ($a, $b) use ($len) {
                return $len($a) <=> $len($b);
            });
            if ($len($found[0]) < $len($found[1])) {
                return [$found[0]];
            }
        }
        return $found;
    }

    // Phương pháp đang dùng cho game (đã bật), null = dàn API
    public static function activeFor(array $game)
    {
        $id = $game['predictMethod'] ?? '';
        if ($id === '') {
            $id = Config::get()['activeMethod'];
        }
        if ($id === '' || $id === 'api' || ($game['type'] ?? '') !== 'vnlp') {
            return null;
        }
        $m = self::find($id);
        return $m && $m['enabled'] ? $m : null;
    }

    // Số về của kiểu chơi Đuôi = chữ số cuối của kết quả ("5,7,7,9,8,2" → "2")
    public static function resultDigit($result)
    {
        return preg_match('/(\d)\D*$/', (string) $result, $m) ? $m[1] : '';
    }

    // Lịch sử số về (cũ → mới). Lúc đầu bổ sung từ dữ liệu cầu (các kỳ có số về) cho có dữ liệu sớm.
    public static function digits($gameId)
    {
        $own = Store::read('digits', [])[$gameId] ?? [];
        $map = [];
        foreach (Road::get($gameId, Road::KEEP) as $r) {
            if (($r[3] ?? '') !== '') {
                $map[$r[0]] = (int) $r[3];
            }
        }
        foreach ($own as $d) {
            $map[$d[0]] = (int) $d[1];
        }
        uksort($map, function ($a, $b) {
            return strcmp(Road::sortKeyOf($a), Road::sortKeyOf($b));
        });
        return array_values($map);
    }

    private static function recordDigit($gameId, $issue, $digit)
    {
        $all = Store::read('digits', []);
        $list = $all[$gameId] ?? [];
        if ($list && end($list)[0] === $issue) {
            return;
        }
        foreach ($list as $d) {
            if ($d[0] === $issue) {
                return;
            }
        }
        $list[] = [$issue, (int) $digit];
        $all[$gameId] = array_slice($list, -self::DIGITS_KEEP);
        Store::write('digits', $all);
    }

    private static function shuffled()
    {
        $d = range(0, 9);
        // Fisher–Yates với random_int (ngẫu nhiên an toàn)
        for ($i = 9; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$d[$i], $d[$j]] = [$d[$j], $d[$i]];
        }
        return $d;
    }

    // Xếp hạng số nóng: về nhiều nhất trong $window kỳ gần nhất; hoà thì số về gần hơn đứng trước
    private static function hotRank(array $hist, $window)
    {
        $recent = array_slice($hist, -$window);
        $count = array_fill(0, 10, 0);
        $last = array_fill(0, 10, -1);
        foreach ($recent as $i => $d) {
            $count[$d]++;
            $last[$d] = $i;
        }
        $ds = range(0, 9);
        usort($ds, function ($a, $b) use ($count, $last) {
            return [$count[$b], $last[$b]] <=> [$count[$a], $last[$a]];
        });
        return $ds;
    }

    // Xếp hạng số lạnh: lâu chưa về nhất (chưa từng về đứng đầu)
    private static function coldRank(array $hist)
    {
        $gap = [];
        $n = count($hist);
        foreach (range(0, 9) as $d) {
            $pos = array_search($d, array_reverse($hist), true);
            $gap[$d] = $pos === false ? $n + 1 : $pos;
        }
        $ds = range(0, 9);
        usort($ds, function ($a, $b) use ($gap) {
            return $gap[$b] <=> $gap[$a];
        });
        return $ds;
    }

    // Lấy N số không trùng theo thứ tự ưu tiên; thiếu thì thêm số ngẫu nhiên
    private static function take(array $order, $n)
    {
        $out = [];
        foreach (array_merge($order, self::shuffled()) as $d) {
            if (count($out) >= $n) {
                break;
            }
            if (!in_array((int) $d, $out, true)) {
                $out[] = (int) $d;
            }
        }
        sort($out);
        return implode(' ', $out);
    }

    // Sinh dàn số theo phương pháp
    public static function generate(array $m, array $hist)
    {
        $n = $m['size'];
        $last = $hist ? end($hist) : null;
        switch ($m['type']) {
            case 'hot':
                return self::take($hist ? self::hotRank($hist, $m['window']) : [], $n);
            case 'cold':
                return self::take($hist ? self::coldRank($hist) : [], $n);
            case 'hotcold':
                if (!$hist) {
                    return self::take([], $n);
                }
                $hot = self::hotRank($hist, $m['window']);
                $cold = self::coldRank($hist);
                $order = [];
                for ($i = 0; $i < 10; $i++) {
                    $order[] = $hot[$i];
                    $order[] = $cold[$i];
                }
                return self::take($order, $n);
            case 'recent':
                return self::take(array_reverse($hist), $n);
            case 'follow':
                if ($last === null) {
                    return self::take([], $n);
                }
                $order = [$last];
                for ($k = 1; $k <= 5; $k++) {
                    $order[] = ($last + $k) % 10;
                    $order[] = ($last - $k + 10) % 10;
                }
                return self::take($order, $n);
            case 'shadow':
                if ($last === null) {
                    return self::take([], $n);
                }
                $duong = ($last + 5) % 10;
                $am = self::BONG_AM[$last];
                $order = [$last, $duong, $am, ($am + 5) % 10, self::BONG_AM[$duong]];
                return self::take(array_merge($order, self::hotRank($hist, $m['window'])), $n);
            case 'random':
            default:
                return self::take([], $n);
        }
    }

    // Gọi mỗi lần có dữ liệu mới của 1 game; trả về true nếu có thay đổi
    public static function process(array $game, array $s)
    {
        if ($game['type'] !== 'vnlp' || empty($s['issue'])) {
            return false;
        }
        $digit = self::resultDigit($s['result'] ?? '');
        if ($digit !== '') {
            self::recordDigit($game['id'], $s['issue'], $digit);
        }
        $methods = self::enabled();
        if (!$methods) {
            return false;
        }
        $hist = self::digits($game['id']);
        $all = Store::read(self::STORE, []);
        $changed = false;
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
            // Tạo dự đoán cho kỳ tiếp (mỗi kỳ chỉ 1 lần), dựa trên lịch sử đã gồm kỳ vừa mở
            $next = $s['nextIssue'] ?? '';
            if ($next !== '' && !in_array($next, array_column($keep, 0), true)) {
                $keep[] = [$next, self::generate($m, $hist), '', '', Util::nowMs()];
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

    // Dự đoán của kỳ $issue (đã chấm hoặc chưa)
    public static function pending($methodId, $gameId, $issue)
    {
        foreach (array_reverse(Store::read(self::STORE, [])[$methodId][$gameId] ?? []) as $it) {
            if ($it[0] === $issue) {
                return $it;
            }
        }
        return null;
    }

    public static function graded($methodId, $gameId, $issue)
    {
        $it = self::pending($methodId, $gameId, $issue);
        return $it && $it[3] !== '' ? $it : null;
    }

    // State của game nhìn theo phương pháp đang dùng: thay dàn/W-L/chuỗi của API bằng của phương pháp
    public static function view(array $game, array $s, ?array $m = null)
    {
        $m = $m ?: self::activeFor($game);
        if (!$m) {
            return $s;
        }
        $done = self::graded($m['id'], $game['id'], $s['issue'] ?? '');
        $next = self::pending($m['id'], $game['id'], $s['nextIssue'] ?? '');
        $road = self::road($m['id'], $game['id'], self::KEEP);
        $st = Road::streak($road);
        return array_merge($s, [
            'method' => $m['id'],
            'play' => 'PP ' . $m['name'],
            'planCheck' => $done ? $done[1] : '',
            'playResult' => $done ? $done[2] : '',
            'planStatus' => $done ? ($done[3] === 'W' ? '✅ W' : '❌ L') : '',
            'plan' => $next ? $next[1] : '',
            'planRange' => '',
            'planTurn' => '',
            'streakType' => $road ? $st['type'] : '',
            'streakCount' => $st['count'],
            'streakLabel' => $st['label'],
            'streakNew' => $st['lastIssue'] !== '' && $st['lastIssue'] === ($s['issue'] ?? ''),
        ]);
    }

    // Các dòng gắn vào cuối tin kết quả Telegram (các phương pháp bật "gửi kèm", trừ phương pháp đang dùng)
    public static function messageLines(array $game, array $s, $html)
    {
        $h = function ($x) use ($html) {
            return $html ? Util::h($x) : $x;
        };
        $b = function ($x) use ($html, $h) {
            return $html ? '<b>' . $h($x) . '</b>' : $x;
        };
        $active = self::activeFor($game);
        $lines = [];
        foreach (self::enabled() as $m) {
            if (!$m['notify'] || $m['targets'] || ($active && $active['id'] === $m['id'])) {
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
