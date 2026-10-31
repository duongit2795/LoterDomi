<?php
// API JSON cho trang quản trị
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_installed()) {
    out(['error' => 'Chưa cài đặt'], 400);
}
if (!Auth::check()) {
    out(['error' => 'Chưa đăng nhập'], 401);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($method === 'POST' && !hash_equals(Auth::csrf(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    out(['error' => 'CSRF token không hợp lệ, tải lại trang'], 403);
}

function worker_status()
{
    $w = Monitor::snapshot()['worker'] ?? null;
    $age = $w ? (Util::nowMs() - $w['heartbeat']) / 1000 : null;
    return ['alive' => $age !== null && $age < 90, 'lastSeen' => $w['heartbeat'] ?? null, 'mode' => $w['mode'] ?? null];
}

try {
    switch ("$method $action") {
        case 'GET config':
            out(Config::publicView());

        case 'POST save':
            unset($body['admin'], $body['installed'], $body['cronKey']);
            Config::update($body);
            Logger::info('Cấu hình đã được cập nhật');
            out(Config::publicView());

        case 'GET status':
            $cfg = Config::get();
            $states = Monitor::snapshot()['games'] ?? [];
            $games = [];
            foreach ($cfg['games'] as $g) {
                $games[] = ['id' => $g['id'], 'name' => $g['name'], 'enabled' => $g['enabled'], 'state' => $states[$g['id']] ?? null];
            }
            out([
                'monitorEnabled' => $cfg['monitor']['enabled'],
                'subscribers' => count($cfg['telegram']['chatIds']),
                'games' => $games,
                'worker' => worker_status(),
                'logs' => Logger::latest(100),
                'serverTime' => Util::nowMs(),
            ]);

        case 'GET system':
            $user = 'www';
            if (function_exists('posix_getpwuid')) {
                $u = posix_getpwuid(fileowner(APP_DIR));
                if ($u && $u['name'] !== 'root') {
                    $user = $u['name'];
                }
            }
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $base = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            out([
                'cronCommand' => "sudo -u $user php " . APP_DIR . '/worker.php',
                'cronUrl' => "$base/worker.php?key=" . Config::get()['cronKey'],
                'tickUrl' => "$base/worker.php?key=" . Config::get()['cronKey'] . '&mode=tick',
                'php' => PHP_VERSION,
                'appVersion' => APP_VERSION,
                'version' => array_diff_key(Updater::current(), ['files' => 1]),
                'worker' => worker_status(),
            ]);

        case 'POST test-fetch':
            $game = Config::normalizeGame($body['game'] ?? []);
            if ($game['type'] === 'vnlp' ? $game['vnlp']['gameId'] === '' : $game['url'] === '') {
                throw new RuntimeException($game['type'] === 'vnlp' ? 'Thiếu gameId' : 'Thiếu URL');
            }
            $raw = Source::fetchRaw($game);
            out(['raw' => $raw, 'extracted' => Source::extract($game, $raw)]);

        case 'GET vnlp-games':
            out(Source::vnlpGameList());

        case 'POST preview':
            $game = ['id' => 'demo', 'name' => 'Demo'];
            foreach (Config::get()['games'] as $g) {
                if ($g['id'] === ($body['gameId'] ?? '')) {
                    $game = $g;
                }
            }
            $s = Monitor::snapshot()['games'][$game['id']] ?? ['issue' => '31/10/2026-0001', 'result' => '1,2,3,4,5,6', 'nextIssue' => '31/10/2026-0002', 'nextTime' => Util::nowMs() + 60000];
            out(['text' => Monitor::message(($body['kind'] ?? '') === 'upcoming' ? 'upcoming' : 'result', $game, $s)]);

        case 'POST bot-info':
            out(Telegram::call('getMe', [], ($body['token'] ?? '') ?: null));

        case 'POST test-send':
            $text = ($body['text'] ?? '') ?: '✅ Tin nhắn thử từ LoterDomi admin';
            if (!Telegram::broadcast($text)) {
                throw new RuntimeException('Không gửi được tới chat nào (xem Nhật ký)');
            }
            out(['ok' => true]);

        case 'POST reset-state':
            Store::write('state', ['games' => []]);
            Logger::info('Đã xoá trạng thái theo dõi');
            out(['ok' => true]);

        case 'POST change-password':
            $hash = Config::get(true)['admin']['passwordHash'];
            if (!password_verify($body['current'] ?? '', $hash)) {
                throw new RuntimeException('Mật khẩu hiện tại không đúng');
            }
            if (strlen($body['new'] ?? '') < 6) {
                throw new RuntimeException('Mật khẩu mới tối thiểu 6 ký tự');
            }
            Config::update(['admin' => ['passwordHash' => password_hash($body['new'], PASSWORD_DEFAULT)]]);
            Logger::info('Đã đổi mật khẩu admin');
            out(['ok' => true]);

        case 'GET update-check':
            out(Updater::check());

        case 'POST update-apply':
            out(Updater::apply());

        case 'GET backups':
            out(Updater::backups());

        case 'POST restore':
            Updater::restore($body['name'] ?? '');
            out(['ok' => true]);

        case 'POST logout':
            Auth::logout();
            out(['ok' => true]);
    }
    out(['error' => 'Không có action này'], 404);
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 400);
}
