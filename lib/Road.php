<?php
// Lịch sử dàn đã kết thúc của từng game để vẽ bảng cầu WIN/LOST.
// API chỉ trả ~30 dàn gần nhất nên worker gộp dần vào file riêng (chỉ ghi khi có dàn mới).
class Road
{
    const KEEP = 300;

    // $rows: dòng kế hoạch từ API (mới nhất trước)
    public static function merge($gameId, array $rows)
    {
        $all = Store::read('road', []);
        $list = $all[$gameId] ?? [];
        $seen = [];
        foreach ($list as $it) {
            $seen[$it[0]] = true;
        }
        $fresh = [];
        foreach ($rows as $r) {
            $ws = (int) ($r['winStatus'] ?? 2);
            if ($ws === 2 || empty($r['turnNum']) || isset($seen[$r['turnNum']])) {
                continue;
            }
            // [kỳ kết thúc, winStatus, trúng ở kỳ thứ, khoảng kỳ, dàn, số về]
            $fresh[] = [$r['turnNum'], $ws, (int) ($r['currentTurnSort'] ?? 0), (string) ($r['turnNumRange'] ?? ''),
                str_replace(',', ' ', (string) ($r['planNumber'] ?? '')), (string) ($r['openNum'] ?? '')];
        }
        if (!$fresh) {
            return;
        }
        $list = array_merge($list, array_reverse($fresh));
        $all[$gameId] = array_slice($list, -self::KEEP);
        Store::write('road', $all);
    }

    public static function get($gameId, $limit)
    {
        $list = Store::read('road', [])[$gameId] ?? [];
        return array_slice($list, -max(1, (int) $limit));
    }
}
