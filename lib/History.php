<?php
// Lưu lịch sử kỳ vào database để thành viên xem lại
class History
{
    private static $lastError = '';

    public static function record(array $game, array $s)
    {
        if (!Db::configured()) {
            return;
        }
        try {
            Db::query('INSERT IGNORE INTO draws (game_id, game_name, issue, result, play, plan_check, play_result, plan_status,
                    win_status, hit_turn, next_issue, next_plan, next_range, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $game['id'], $game['name'], $s['issue'], $s['result'],
                $s['play'] ?? '', $s['planCheck'] ?? '', $s['playResult'] ?? '', $s['planStatus'] ?? '',
                isset($s['hitStatus']) && $s['hitStatus'] !== '' ? (int) $s['hitStatus'] : null,
                isset($s['hitTurn']) && $s['hitTurn'] !== '' ? (int) $s['hitTurn'] : null,
                $s['nextIssue'] ?? '', $s['plan'] ?? '', $s['planRange'] ?? '',
                // Giờ theo múi giờ của app (VN), không phụ thuộc múi giờ MySQL
                date('Y-m-d H:i:s'),
            ]);
            self::$lastError = '';
        } catch (Exception $e) {
            // Chỉ ghi log khi lỗi thay đổi, tránh lặp mỗi kỳ
            if ($e->getMessage() !== self::$lastError) {
                self::$lastError = $e->getMessage();
                Logger::warn('Lưu lịch sử vào DB lỗi: ' . $e->getMessage());
            }
            Db::reset();
        }
    }

    public static function list($gameId, $page = 1, $perPage = 30)
    {
        $page = max(1, (int) $page);
        $perPage = min(100, max(10, (int) $perPage));
        $total = (int) Db::one('SELECT COUNT(*) c FROM draws WHERE game_id = ?', [$gameId])['c'];
        $rows = Db::all('SELECT issue, result, play, plan_check, play_result, plan_status, win_status, hit_turn,
                next_issue, next_plan, next_range, created_at
            FROM draws WHERE game_id = ? ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), [$gameId]);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    // Số dàn kết thúc theo từng ngày (N ngày gần nhất, kể cả ngày không có dữ liệu)
    public static function daily($gameId, $days = 14)
    {
        $days = min(90, max(1, (int) $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = Db::all("SELECT DATE(created_at) d,
                SUM(win_status = 1 AND COALESCE(hit_turn, 1) <= 1) win1,
                SUM(win_status = 1 AND hit_turn > 1) late,
                SUM(win_status = 0) lose
            FROM draws WHERE game_id = ? AND win_status IN (0, 1) AND created_at >= ?
            GROUP BY DATE(created_at)", [$gameId, $from . ' 00:00:00']);
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['d']] = ['win1' => (int) $r['win1'], 'late' => (int) $r['late'], 'lose' => (int) $r['lose']];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[] = ['day' => $d] + ($byDay[$d] ?? ['win1' => 0, 'late' => 0, 'lose' => 0]);
        }
        return $out;
    }

    // Thống kê các dàn đã kết thúc trong N kỳ gần nhất
    public static function stats($gameId, $limit = 200)
    {
        $rows = Db::all('SELECT win_status, hit_turn FROM draws WHERE game_id = ? AND win_status IN (0, 1)
            ORDER BY id DESC LIMIT ' . (int) $limit, [$gameId]);
        $out = ['plans' => count($rows), 'win1' => 0, 'winLate' => 0, 'lose' => 0];
        foreach ($rows as $r) {
            if ((int) $r['win_status'] === 0) {
                $out['lose']++;
            } elseif ((int) $r['hit_turn'] <= 1) {
                $out['win1']++;
            } else {
                $out['winLate']++;
            }
        }
        return $out;
    }
}
