<?php
// Trang xem cầu W/L công khai: không cần đăng nhập, có link là xem được.
//   /xemcau.php                 tất cả game
//   /xemcau.php?game=<id>       một game
//   ?k=<mã>                     bắt buộc nếu admin đặt mã truy cập
require __DIR__ . '/lib/bootstrap.php';

$h = 'Util::h';
$pub = is_installed() ? Config::get()['publicRoad'] : ['enabled' => false, 'key' => ''];
$key = (string) ($_GET['k'] ?? '');
$allowed = $pub['enabled'] && ($pub['key'] === '' || hash_equals($pub['key'], $key));
$gameId = (string) ($_GET['game'] ?? '');

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$allowed) {
        http_response_code(403);
        exit(json_encode(['error' => 'Không có quyền xem'], JSON_UNESCAPED_UNICODE));
    }
    echo json_encode(Road::payload((int) ($_GET['limit'] ?? 200), $gameId) + ['serverTime' => Util::nowMs()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$allowed) {
    http_response_code(403);
}
$games = $allowed ? Monitor::activeGames() : [];
$qs = function (array $extra) use ($key, $gameId) {
    return '?' . http_build_query(array_filter(['k' => $key, 'game' => $gameId] + $extra, 'strlen'));
};
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Xem cầu W/L</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/style.css?v=<?= $h(APP_VERSION) ?>">
</head>
<body>
<?php if (!$allowed): ?>
<main class="narrow" style="max-width:420px;margin-top:15vh">
  <div class="card"><h2>🎲 Xem cầu</h2><p><?= $pub['enabled'] ? 'Link không đúng hoặc đã hết hiệu lực.' : 'Trang xem cầu đang tắt.' ?></p></div>
</main>
<?php else: ?>
<header>
  <h1>🎲 Cầu W / L</h1>
  <span style="flex:1"></span>
  <span class="muted" id="updated"></span>
</header>
<main>
  <div class="card">
    <div class="row" style="margin-top:0;justify-content:space-between">
      <div class="legend" style="margin:0">
        <span><span class="ball w">W</span> Kỳ dàn trúng</span>
        <span><span class="ball l">L</span> Kỳ dàn không trúng</span>
      </div>
      <div class="row" style="margin:0">
        <select id="game" style="width:auto" onchange="location.href=this.value">
          <option value="<?= $h($qs(['game' => ''])) ?>">Tất cả game</option>
          <?php foreach ($games as $g): ?>
            <option value="<?= $h($qs(['game' => $g['id']])) ?>" <?= $g['id'] === $gameId ? 'selected' : '' ?>><?= $h($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="limit" style="width:auto" onchange="load()">
          <option value="100">100 kỳ</option><option value="200" selected>200 kỳ</option><option value="500">500 kỳ</option><option value="1000">1000 kỳ</option>
        </select>
      </div>
    </div>
    <p class="muted" style="margin:8px 0 0">Mỗi ô là một kỳ: W nếu dàn kế hoạch đang nuôi trúng ở kỳ đó, L nếu không trúng. Cùng kết quả xếp xuống cùng cột, đổi kết quả thì sang cột mới. Tự cập nhật mỗi 15 giây.</p>
  </div>
  <div id="roads"></div>
</main>
<script src="assets/road.js?v=<?= $h(APP_VERSION) ?>"></script>
<script>
const params = new URLSearchParams(location.search);
let timer = null;
async function load() {
  clearTimeout(timer);
  params.set('json', '1');
  params.set('limit', document.getElementById('limit').value);
  try {
    const r = await fetch('xemcau.php?' + params).then((x) => x.json());
    if (r.error) throw new Error(r.error);
    RoadView.render(document.getElementById('roads'), r.games);
    document.getElementById('updated').textContent = 'Cập nhật ' + new Date().toLocaleTimeString('vi-VN');
  } catch (e) {
    document.getElementById('updated').textContent = 'Lỗi tải dữ liệu: ' + e.message;
  }
  timer = setTimeout(load, 15000);
}
load();
</script>
<?php endif; ?>
</body>
</html>
