<?php
// Lưu kết quả TỪNG KỲ của dàn kế hoạch: W = dàn đang nuôi trúng ở kỳ đó, L = không trúng.
// API chỉ trả mỗi dàn 1 dòng (trúng ở kỳ thứ mấy), nên tách ngược ra từng kỳ:
// trúng kỳ 3 → L L W; trượt cả dàn → L L L; đang nuôi (đã qua n kỳ) → n lần L.
// Worker gộp dần vào data/road_ky.php, chỉ ghi khi có kỳ mới.
class Road
{
    const KEEP = 1000;
    const STORE = 'road_ky';

    // Tách 1 dòng kế hoạch thành các kỳ đã mở: [[mã kỳ, 'W'|'L', dàn, số về, kỳ thứ trong dàn], ...]
    public static function expand(array $r)
    {
        if (!preg_match('/^(.*)-(\d+)$/', (string) ($r['turnNum'] ?? ''), $tm)
            || !preg_match('/^(\d+)\s*-\s*(\d+)$/', (string) ($r['turnNumRange'] ?? ''), $rm)) {
            return [];
        }
        $start = (int) $rm[1];
        $end = (int) $rm[2];
        if ($end < $start) {
            return []; // dàn vắt qua nửa đêm (đánh số lại từ đầu) – bỏ qua
        }
        $ws = (int) ($r['winStatus'] ?? 2);
        $sort = (int) ($r['currentTurnSort'] ?? 0);
        // Số kỳ của dàn đã mở thưởng
        $n = $ws === 2 ? $sort - 1 : $sort;
        $n = min($n, $end - $start + 1);
        $plan = str_replace(',', ' ', (string) ($r['planNumber'] ?? ''));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $issue = $tm[1] . '-' . str_pad((string) ($start + $i), strlen($rm[1]), '0', STR_PAD_LEFT);
            $win = $ws === 1 && $i === $n - 1;
            // Chỉ biết số về của kỳ mới nhất trong dàn (kỳ API vừa đối chiếu)
            $open = $issue === $r['turnNum'] ? (string) ($r['openNum'] ?? '') : '';
            $out[] = [$issue, $win ? 'W' : 'L', $plan, $open, $i + 1];
        }
        return $out;
    }

    // Khoá sắp xếp theo thời gian: "01/11/2026-0939" → "20261101-0939"
    private static function sortKey($issue)
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})-(\d+)$#', $issue, $m) ? "$m[3]$m[2]$m[1]-" . str_pad($m[4], 6, '0', STR_PAD_LEFT) : $issue;
    }

    // $rows: dòng kế hoạch từ API (mới nhất trước)
    public static function merge($gameId, array $rows)
    {
        $all = Store::read(self::STORE, []);
        $list = $all[$gameId] ?? [];
        $seen = [];
        foreach ($list as $it) {
            $seen[$it[0]] = true;
        }
        $fresh = [];
        foreach ($rows as $r) {
            foreach (self::expand($r) as $k) {
                if (!isset($seen[$k[0]])) {
                    $seen[$k[0]] = true;
                    $fresh[] = $k;
                }
            }
        }
        if (!$fresh) {
            return $list;
        }
        $list = array_merge($list, $fresh);
        usort($list, function ($a, $b) {
            return strcmp(self::sortKey($a[0]), self::sortKey($b[0]));
        });
        $all[$gameId] = array_slice($list, -self::KEEP);
        Store::write(self::STORE, $all);
        return $all[$gameId];
    }

    // Chuỗi W/L liên tiếp tính từ kỳ mới nhất
    public static function streak(array $list)
    {
        $n = count($list);
        if (!$n) {
            return ['type' => '', 'count' => 0, 'label' => '0', 'lastIssue' => ''];
        }
        $type = $list[$n - 1][1];
        $count = 0;
        for ($i = $n - 1; $i >= 0 && $list[$i][1] === $type; $i--) {
            $count++;
        }
        return [
            'type' => $type === 'W' ? 'win' : 'lose',
            'count' => $count,
            // Cả lịch sử đã lưu đều cùng một chuỗi: thực tế có thể dài hơn
            'label' => $count === $n ? "$count+" : (string) $count,
            'lastIssue' => $list[$n - 1][0],
        ];
    }

    // Dữ liệu cho bảng cầu của các game đang bật
    public static function payload($limit, $onlyGameId = '')
    {
        $limit = min(self::KEEP, max(20, (int) $limit));
        $states = Monitor::snapshot()['games'] ?? [];
        $games = [];
        foreach (Monitor::activeGames() as $g) {
            if ($onlyGameId !== '' && $g['id'] !== $onlyGameId) {
                continue;
            }
            $games[] = ['id' => $g['id'], 'name' => $g['name'], 'road' => self::get($g['id'], $limit),
                'nextIssue' => $states[$g['id']]['nextIssue'] ?? '', 'nextTime' => $states[$g['id']]['nextTime'] ?? null,
                'plan' => $states[$g['id']]['plan'] ?? ''];
        }
        return ['games' => $games, 'serverTime' => Util::nowMs()];
    }

    public static function get($gameId, $limit)
    {
        $list = Store::read(self::STORE, [])[$gameId] ?? [];
        return array_slice($list, -max(1, (int) $limit));
    }
}
