<?php
// API JSON cho trang thành viên
require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!is_installed() || !Db::configured()) {
        out(['error' => 'Chức năng thành viên chưa được bật'], 400);
    }
    $me = Member::current();
    if (!$me) {
        out(['error' => 'Chưa đăng nhập'], 401);
    }
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if ($method === 'POST' && !hash_equals(Auth::csrf(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        out(['error' => 'Phiên làm việc hết hạn, tải lại trang'], 403);
    }

    switch ("$method $action") {
        case 'GET board':
            $states = Monitor::snapshot()['games'] ?? [];
            $keys = ['issue', 'result', 'play', 'planCheck', 'playResult', 'planStatus', 'nextIssue', 'nextTime', 'plan', 'planRange', 'planTurn', 'streakType', 'streakLabel', 'lastError'];
            $games = [];
            foreach (Monitor::activeGames() as $g) {
                $s = $states[$g['id']] ?? [];
                $games[] = ['id' => $g['id'], 'name' => $g['name']] + array_intersect_key($s, array_flip($keys));
            }
            out(['games' => $games, 'serverTime' => Util::nowMs()]);

        case 'GET history':
            $gameId = (string) ($_GET['game'] ?? '');
            $valid = array_column(Monitor::activeGames(), 'id');
            if (!in_array($gameId, $valid, true)) {
                throw new RuntimeException('Game không hợp lệ');
            }
            out(History::list($gameId, (int) ($_GET['page'] ?? 1)) + ['stats' => History::stats($gameId)]);

        case 'POST change-password':
            Member::changePassword($me['id'], (string) ($body['current'] ?? ''), (string) ($body['new'] ?? ''));
            out(['ok' => true]);
    }
    out(['error' => 'Không có action này'], 404);
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 400);
}
