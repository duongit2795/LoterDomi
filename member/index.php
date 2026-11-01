<?php
require dirname(__DIR__) . '/lib/bootstrap.php';

$h = 'Util::h';
$error = '';
$notice = '';
$enabled = is_installed() && Db::configured();
$me = null;
$mode = ($_GET['p'] ?? '') === 'register' ? 'register' : 'login';

if ($enabled) {
    try {
        if (($_GET['p'] ?? '') === 'logout') {
            Member::logout();
            header('Location: ./');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals(Auth::csrf(), (string) ($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('Phiên làm việc hết hạn, thử lại');
            }
            if ($mode === 'register') {
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    throw new RuntimeException('Hai mật khẩu không khớp');
                }
                $status = Member::register((string) $_POST['username'], (string) $_POST['password'], (string) ($_POST['display_name'] ?? ''));
                if ($status === 'pending') {
                    $notice = 'Đăng ký thành công. Tài khoản đang chờ admin duyệt.';
                    $mode = 'login';
                } else {
                    Member::login((string) $_POST['username'], (string) $_POST['password']);
                    header('Location: ./');
                    exit;
                }
            } else {
                Member::login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
                header('Location: ./');
                exit;
            }
        }
        $me = Member::current();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
$allowRegister = $enabled && Config::get()['members']['allowRegister'];
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Thành viên – LoterDomi</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= $h(APP_VERSION) ?>">
</head>
<body>
<?php if (!$enabled): ?>
<main class="narrow" style="max-width:420px;margin-top:15vh">
  <div class="card"><h2>🎲 Thành viên</h2><p>Chức năng thành viên chưa được bật.</p></div>
</main>

<?php elseif (!$me): ?>
<main class="narrow" style="max-width:380px;margin-top:10vh">
  <form method="post" class="card" action="?p=<?= $mode ?>">
    <h2>🎲 <?= $mode === 'register' ? 'Đăng ký thành viên' : 'Đăng nhập thành viên' ?></h2>
    <?php if ($error): ?><div class="alert err"><?= $h($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h(Auth::csrf()) ?>">
    <label>Tên đăng nhập</label>
    <input name="username" required autofocus value="<?= $h($_POST['username'] ?? '') ?>" autocomplete="username">
    <?php if ($mode === 'register'): ?>
      <label>Tên hiển thị</label>
      <input name="display_name" value="<?= $h($_POST['display_name'] ?? '') ?>">
    <?php endif; ?>
    <label>Mật khẩu</label>
    <input type="password" name="password" required autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>">
    <?php if ($mode === 'register'): ?>
      <label>Nhập lại mật khẩu</label>
      <input type="password" name="password2" required autocomplete="new-password">
    <?php endif; ?>
    <div class="row">
      <button class="btn" type="submit"><?= $mode === 'register' ? 'Đăng ký' : 'Đăng nhập' ?></button>
      <?php if ($mode === 'register'): ?>
        <a href="./">Đã có tài khoản? Đăng nhập</a>
      <?php elseif ($allowRegister): ?>
        <a href="?p=register">Chưa có tài khoản? Đăng ký</a>
      <?php endif; ?>
    </div>
  </form>
</main>

<?php else: ?>
<header>
  <h1>🎲 LoterDomi</h1>
  <nav id="tabs">
    <button data-tab="board" class="active">Bảng kỳ</button>
    <button data-tab="road">Cầu</button>
    <button data-tab="history">Lịch sử</button>
    <button data-tab="account">Tài khoản</button>
  </nav>
  <span style="flex:1"></span>
  <span class="muted">👤 <?= $h($me['display_name'] ?: $me['username']) ?></span>
  <a class="btn sec" href="?p=logout">Đăng xuất</a>
</header>
<main>
  <section data-page="board">
    <div class="card">
      <h2>Các kỳ đang chạy</h2>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Game</th><th>Kỳ vừa mở</th><th>Dàn vừa đối chiếu</th><th>Kỳ tiếp</th><th>Kế hoạch kỳ tiếp</th><th>Còn lại</th><th>Chuỗi</th></tr></thead>
          <tbody id="board"></tbody>
        </table>
      </div>
    </div>
  </section>

  <section data-page="road" class="hidden">
    <div class="card">
      <div class="row" style="margin-top:0;justify-content:space-between">
        <div class="legend" style="margin:0">
          <span><span class="ball w">W</span> Thắng (trúng kỳ 1)</span>
          <span><span class="ball l">L</span> Thua (trượt)</span>
          <span id="road-late-legend"><span class="ball l late">L</span> Trúng muộn – tính là thua</span>
        </div>
        <select id="road-limit" style="width:auto" onchange="loadRoad()">
          <option value="50">50 dàn gần nhất</option><option value="100" selected>100 dàn gần nhất</option><option value="200">200 dàn gần nhất</option>
        </select>
      </div>
      <p class="muted" style="margin:8px 0 0">Mỗi ô là một dàn kế hoạch đã kết thúc. Cùng kết quả xếp xuống cùng cột, đổi kết quả thì sang cột mới. Rê chuột vào ô để xem chi tiết.</p>
    </div>
    <div id="roads"></div>
  </section>

  <section data-page="history" class="hidden">
    <div class="card">
      <div class="row" style="margin-top:0">
        <select id="h-game" style="max-width:260px" onchange="loadHistory(1); loadDaily()"></select>
        <span id="h-stats" class="muted"></span>
      </div>
    </div>
    <div class="card">
      <div class="row" style="margin-top:0;justify-content:space-between">
        <h2 style="margin:0">Tỉ lệ trúng theo ngày</h2>
        <div class="row" style="margin:0">
          <select id="d-days" style="width:auto" onchange="loadDaily()">
            <option value="7">7 ngày</option><option value="14" selected>14 ngày</option><option value="30">30 ngày</option>
          </select>
          <button class="btn sec" onclick="toggleDailyTable()">Bảng số liệu</button>
        </div>
      </div>
      <div class="chart-title">Số dàn kết thúc mỗi ngày</div>
      <div class="legend">
        <span><i style="background:var(--c-good)"></i>✅ Trúng kỳ 1</span>
        <span><i style="background:var(--c-warn)"></i>⏳ Trúng muộn</span>
        <span><i style="background:var(--c-bad)"></i>❌ Trượt</span>
      </div>
      <div class="chart" id="d-bars"></div>
      <div class="chart-title">% dàn trúng ngay kỳ 1</div>
      <div class="chart" id="d-rate"></div>
      <div id="d-table" class="hidden" style="overflow-x:auto;margin-top:12px"></div>
    </div>
    <div class="card">
      <h2>Lịch sử kỳ</h2>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Kỳ</th><th>Kết quả</th><th>Dàn</th><th>Về</th><th>Trạng thái</th><th>Kế hoạch kỳ tiếp</th><th>Lúc</th></tr></thead>
          <tbody id="h-rows"></tbody>
        </table>
      </div>
      <div class="row" id="h-pager"></div>
    </div>
  </section>

  <section data-page="account" class="hidden">
    <div class="card" style="max-width:520px">
      <h2>Tài khoản</h2>
      <table>
        <tr><td>Tên đăng nhập</td><td><b><?= $h($me['username']) ?></b></td></tr>
        <tr><td>Tên hiển thị</td><td><?= $h($me['display_name']) ?></td></tr>
        <tr><td>Ngày tham gia</td><td><?= $h($me['created_at']) ?></td></tr>
      </table>
      <h2 style="margin-top:20px">Đổi mật khẩu</h2>
      <label>Mật khẩu hiện tại</label><input id="pw-cur" type="password" autocomplete="current-password">
      <label>Mật khẩu mới</label><input id="pw-new" type="password" autocomplete="new-password">
      <div class="row"><button class="btn" onclick="changePassword(this)">Đổi mật khẩu</button></div>
    </div>
  </section>
</main>
<div id="toast"></div>
<script>window.CSRF = <?= json_encode(Auth::csrf()) ?>;</script>
<script src="../assets/member.js?v=<?= $h(APP_VERSION) ?>"></script>
<?php endif; ?>
</body>
</html>
