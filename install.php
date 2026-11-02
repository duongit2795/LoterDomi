<?php
require __DIR__ . '/lib/bootstrap.php';

$h = 'Util::h';
$errors = [];
$warnings = [];
$done = false;

// ---- Kiểm tra yêu cầu hệ thống ----
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}
$checks = [
    ['PHP >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION, true],
    ['Extension curl', extension_loaded('curl'), '', true],
    ['Extension json', extension_loaded('json'), '', true],
    ['Extension mbstring', extension_loaded('mbstring'), '', true],
    ['Extension zip (cho Cập nhật)', class_exists('ZipArchive'), '', false],
    ['Extension pdo_mysql (cho thành viên)', extension_loaded('pdo_mysql'), '', false],
    ['Extension gd + FreeType (cho ảnh cầu)', function_exists('imagettftext'), '', false],
    ['Thư mục data/ ghi được', is_writable(DATA_DIR), DATA_DIR, true],
    ['Thư mục code ghi được (cho Cập nhật)', is_writable(APP_DIR), APP_DIR, false],
];
$canInstall = true;
foreach ($checks as $c) {
    if ($c[3] && !$c[1]) {
        $canInstall = false;
    }
}

function site_user()
{
    if (function_exists('posix_getpwuid')) {
        $u = posix_getpwuid(fileowner(APP_DIR));
        if ($u && $u['name'] !== 'root') {
            return $u['name'];
        }
    }
    return 'www';
}

function base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return ($https ? 'https' : 'http') . "://$host$dir";
}

$installed = is_installed();

if (!$installed && $canInstall && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    if (strlen($pw) < 6) {
        $errors[] = 'Mật khẩu admin tối thiểu 6 ký tự';
    } elseif ($pw !== $pw2) {
        $errors[] = 'Hai mật khẩu không khớp';
    }
    $token = trim((string) ($_POST['token'] ?? ''));
    if ($token !== '') {
        try {
            $me = Telegram::call('getMe', [], $token);
        } catch (Exception $e) {
            $errors[] = 'Token Telegram không hợp lệ: ' . $e->getMessage();
        }
    }
    $db = [
        'host' => trim((string) ($_POST['db_host'] ?? '')) ?: '127.0.0.1',
        'port' => (int) ($_POST['db_port'] ?? 3306) ?: 3306,
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ];
    if (!$errors && $db['name'] !== '') {
        try {
            Db::migrate(Db::connect($db));
        } catch (Exception $e) {
            $errors[] = 'Không kết nối được database: ' . $e->getMessage();
        }
    }
    if (!$errors) {
        $games = [];
        if (!empty($_POST['import'])) {
            try {
                $list = Source::vnlpGameList();
                $play = $list['plays'] ? $list['plays'][0] : ['play' => 'DI', 'playName' => 'Đuôi'];
                foreach ($list['games'] as $g) {
                    $games[] = ['name' => $g['name'], 'type' => 'vnlp', 'enabled' => true,
                        'vnlp' => ['gameId' => $g['gameId'], 'play' => $play['play'], 'playName' => $play['playName']]];
                }
            } catch (Exception $e) {
                $warnings[] = 'Không lấy được danh sách game từ vnlotteryplan (' . $e->getMessage() . '). Có thể nhập sau trong admin.';
            }
        }
        $chatIds = preg_split('/[\s,]+/', (string) ($_POST['chatIds'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        Config::update([
            'installed' => true,
            'admin' => ['passwordHash' => password_hash($pw, PASSWORD_DEFAULT)],
            'cronKey' => bin2hex(random_bytes(16)),
            'telegram' => ['token' => $token, 'chatIds' => $chatIds],
            'monitor' => ['enabled' => !empty($_POST['enable'])],
            'update' => [
                'repo' => trim((string) ($_POST['repo'] ?? '')) ?: 'duongit2795/loterdomi',
                'branch' => trim((string) ($_POST['branch'] ?? '')) ?: 'master',
                'token' => trim((string) ($_POST['ghtoken'] ?? '')),
            ],
            'games' => $games,
            'db' => $db['name'] !== '' ? $db : ['name' => '', 'user' => '', 'pass' => ''],
        ]);
        // Ghi nhận phiên bản hiện tại (nếu kết nối được GitHub)
        try {
            $info = Updater::check();
            Store::write('version', $info['latest'] + ['updatedAt' => date('c')]);
        } catch (Exception $e) {
            $warnings[] = 'Chưa kiểm tra được phiên bản trên GitHub: ' . $e->getMessage();
        }
        Logger::info('Cài đặt hoàn tất' . ($games ? ', đã nhập ' . count($games) . ' game' : ''));
        $done = true;
    }
}

$cfg = is_installed() ? Config::get(true) : null;
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cài đặt LoterDomi</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="narrow">
  <h1>🎲 Cài đặt LoterDomi</h1>

<?php if ($done): ?>
  <div class="alert ok">✅ Cài đặt thành công.</div>
  <?php foreach ($warnings as $w): ?><div class="alert warn"><?= $h($w) ?></div><?php endforeach; ?>
  <div class="card">
    <h2>Bước cuối: tạo Cron trên aaPanel</h2>
    <p>aaPanel → <b>Cron</b> → <b>Add Cron</b>: Type = <b>Shell Script</b>, Period = <b>N Minutes, 1</b>, Script content:</p>
    <code class="block">sudo -u <?= $h(site_user()) ?> php <?= $h(APP_DIR) ?>/worker.php</code>
    <p class="muted">Mỗi lần cron chạy, worker hoạt động ~55 giây nên các game 45 giây vẫn được báo kịp.
      Nếu trên aaPanel có nhiều bản PHP, thay <code>php</code> bằng đường dẫn đầy đủ, ví dụ <code>/www/server/php/82/bin/php</code>.</p>
    <p class="muted">Không dùng được Shell Script? Chọn Type = <b>Access URL</b> (mỗi 1 phút) với URL:</p>
    <code class="block"><?= $h(base_url()) ?>/worker.php?key=<?= $h($cfg['cronKey']) ?></code>
    <p class="muted">Muốn gọi link <b>mỗi 5 giây</b> (dịch vụ cron hỗ trợ giây), dùng chế độ tick:</p>
    <code class="block"><?= $h(base_url()) ?>/worker.php?key=<?= $h($cfg['cronKey']) ?>&amp;mode=tick</code>
  </div>
  <div class="card">
    <h2>Bảo mật</h2>
    <p>Trình cài đặt đã tự khoá. Nên xoá file <code>install.php</code> sau khi cài xong (bản cập nhật từ GitHub sẽ chép lại nhưng vẫn bị khoá).</p>
    <p>Nếu dùng Nginx, thêm vào cấu hình site (aaPanel → Website → Conf):</p>
    <code class="block">location ^~ /data/ { deny all; }</code>
  </div>
  <a class="btn" href="admin.php">Vào trang quản trị →</a>

<?php elseif ($installed): ?>
  <div class="alert warn">LoterDomi đã được cài đặt. Muốn cài lại, xoá file <code>data/config.php</code> rồi mở lại trang này.</div>
  <a class="btn" href="admin.php">Vào trang quản trị →</a>

<?php else: ?>
  <div class="card">
    <h2>1. Kiểm tra hệ thống</h2>
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= $h($c[0]) ?> <span class="muted"><?= $h($c[2]) ?></span></td>
          <td><?= $c[1] ? '<span class="badge b-ok">OK</span>' : ($c[3] ? '<span class="badge b-err">Thiếu</span>' : '<span class="badge b-warn">Nên có</span>') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$canInstall): ?>
      <div class="alert err" style="margin-top:12px">Cần khắc phục các mục "Thiếu" trước khi cài. Quyền ghi: <code>chown -R www:www <?= $h(APP_DIR) ?></code></div>
    <?php endif; ?>
  </div>

  <?php foreach ($errors as $e): ?><div class="alert err"><?= $h($e) ?></div><?php endforeach; ?>

  <?php if ($canInstall): ?>
  <form method="post" class="card">
    <h2>2. Tài khoản quản trị</h2>
    <div class="grid">
      <div><label>Mật khẩu admin</label><input type="password" name="password" required minlength="6"></div>
      <div><label>Nhập lại mật khẩu</label><input type="password" name="password2" required minlength="6"></div>
    </div>

    <h2 style="margin-top:20px">3. Bot Telegram <span class="muted">(có thể điền sau)</span></h2>
    <label>Bot token (lấy từ @BotFather)</label>
    <input name="token" value="<?= $h($_POST['token'] ?? '') ?>" placeholder="123456:ABC...">
    <label>Chat ID nhận thông báo (cách nhau bởi dấu phẩy hoặc xuống dòng)</label>
    <input name="chatIds" value="<?= $h($_POST['chatIds'] ?? '') ?>" placeholder="-1001234567890">

    <h2 style="margin-top:20px">4. Nguồn dữ liệu</h2>
    <label class="check"><input type="checkbox" name="import" value="1" checked> Nhập toàn bộ game từ vnlotteryplan.com</label>
    <label class="check"><input type="checkbox" name="enable" value="1" checked> Bật theo dõi & gửi thông báo ngay</label>

    <h2 style="margin-top:20px">5. Database cho thành viên <span class="muted">(tuỳ chọn – có thể điền sau)</span></h2>
    <p class="muted">MySQL tạo trong aaPanel → Databases. Dùng cho trang /member và lưu lịch sử kỳ.</p>
    <div class="grid">
      <div><label>Host</label><input name="db_host" value="<?= $h($_POST['db_host'] ?? '127.0.0.1') ?>"></div>
      <div><label>Port</label><input name="db_port" value="<?= $h($_POST['db_port'] ?? '3306') ?>"></div>
      <div><label>Tên database</label><input name="db_name" value="<?= $h($_POST['db_name'] ?? '') ?>"></div>
      <div><label>User</label><input name="db_user" value="<?= $h($_POST['db_user'] ?? '') ?>"></div>
      <div><label>Mật khẩu</label><input name="db_pass" type="password" autocomplete="new-password"></div>
    </div>

    <h2 style="margin-top:20px">6. Cập nhật từ GitHub</h2>
    <div class="grid">
      <div><label>Repo (owner/repo)</label><input name="repo" value="<?= $h($_POST['repo'] ?? 'duongit2795/loterdomi') ?>"></div>
      <div><label>Nhánh</label><input name="branch" value="<?= $h($_POST['branch'] ?? 'master') ?>"></div>
    </div>
    <label>GitHub token <span class="muted">(chỉ cần nếu repo private – quyền Contents: Read-only)</span></label>
    <input name="ghtoken" type="password" value="">

    <div class="row"><button class="btn" type="submit">Cài đặt</button></div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
