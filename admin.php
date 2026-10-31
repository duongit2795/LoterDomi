<?php
require __DIR__ . '/lib/bootstrap.php';
if (!is_installed()) {
    header('Location: install.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (Auth::login((string) $_POST['password'])) {
        header('Location: admin.php');
        exit;
    }
    $error = 'Sai mật khẩu';
}
$logged = Auth::check();
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LoterDomi Admin</title>
<link rel="stylesheet" href="assets/style.css?v=<?= Util::h(APP_VERSION) ?>">
</head>
<body>
<?php if (!$logged): ?>
<main class="narrow" style="max-width:360px;margin-top:15vh">
  <form method="post" class="card">
    <h2>🎲 LoterDomi – Đăng nhập</h2>
    <?php if ($error): ?><div class="alert err"><?= Util::h($error) ?></div><?php endif; ?>
    <label>Mật khẩu</label>
    <input type="password" name="password" autofocus required>
    <div class="row"><button class="btn" type="submit">Đăng nhập</button></div>
  </form>
</main>
<?php else: ?>
<header>
  <h1>🎲 LoterDomi</h1>
  <nav id="tabs">
    <button data-tab="dash" class="active">Tổng quan</button>
    <button data-tab="games">Nguồn API</button>
    <button data-tab="tele">Telegram</button>
    <button data-tab="settings">Cài đặt</button>
    <button data-tab="update">Cập nhật</button>
    <button data-tab="logs">Nhật ký</button>
  </nav>
  <span style="flex:1"></span>
  <button class="btn sec" onclick="logout()">Đăng xuất</button>
</header>

<main>
  <!-- Tổng quan -->
  <section data-page="dash">
    <div class="grid">
      <div class="card"><div class="muted">Worker (cron)</div><div class="stat" id="s-worker">–</div></div>
      <div class="card"><div class="muted">Theo dõi</div><div class="stat" id="s-monitor">–</div></div>
      <div class="card"><div class="muted">Số chat nhận tin</div><div class="stat" id="s-subs">–</div></div>
      <div class="card"><div class="muted">Game đang bật</div><div class="stat" id="s-games">–</div></div>
    </div>
    <div class="card hidden" id="cron-help">
      <h2>⚠ Worker chưa chạy</h2>
      <p>Tạo Cron trên aaPanel → <b>Cron</b> → <b>Add Cron</b>: Type = <b>Shell Script</b>, Period = <b>N Minutes, 1</b>, nội dung:</p>
      <code class="block" id="cron-cmd"></code>
      <p class="muted">Hoặc gọi link mỗi 1 phút (Type = <b>Access URL</b>):</p>
      <code class="block" id="cron-url"></code>
      <p class="muted">Hoặc gọi link <b>mỗi 5 giây</b> (chế độ tick – mỗi lần chạy ~4 giây, trả lời ngay, không bị chồng request):</p>
      <code class="block" id="tick-url"></code>
    </div>
    <div class="card">
      <h2>Các kỳ</h2>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Game</th><th>Kỳ gần nhất</th><th>Kết quả</th><th>Kỳ sắp mở</th><th>Kế hoạch</th><th>Còn lại</th><th>Trạng thái</th></tr></thead>
        <tbody id="t-games"></tbody>
      </table>
      </div>
    </div>
  </section>

  <!-- Nguồn API -->
  <section data-page="games" class="hidden">
    <div class="card">
      <h2>Nguồn dữ liệu theo game</h2>
      <p class="muted"><b>vnlotteryplan.com</b>: bấm <i>Nhập game từ vnlotteryplan</i> để thêm toàn bộ game, bot tự lấy kết quả, kỳ sắp mở và dàn kế hoạch.<br>
        <b>API tuỳ chỉnh</b>: endpoint JSON bất kỳ, đường dẫn trường dạng <code>data.list[0].issue</code>, URL có thể chứa <code>{ts}</code>.
        Bấm <b>Thử gọi API</b> để xem JSON thô và giá trị trích xuất.</p>
      <div id="games"></div>
      <div class="row">
        <button class="btn" onclick="importVnlp()">⬇ Nhập game từ vnlotteryplan</button>
        <button class="btn sec" onclick="addGame()">+ Thêm API tuỳ chỉnh</button>
        <button class="btn" onclick="saveGames()">Lưu</button>
      </div>
    </div>
    <div class="card hidden" id="test-box">
      <h2>Kết quả thử</h2>
      <label>Giá trị trích xuất</label><pre id="test-ext"></pre>
      <label>JSON thô</label><pre id="test-raw"></pre>
    </div>
  </section>

  <!-- Telegram -->
  <section data-page="tele" class="hidden">
    <div class="card">
      <h2>Bot Telegram</h2>
      <label>Bot token (lấy từ @BotFather)</label>
      <input id="tg-token" type="password" placeholder="123456:ABC...">
      <label>Chat ID nhận thông báo (mỗi dòng 1 id; nhóm/kênh thường bắt đầu bằng -100)</label>
      <textarea id="tg-chats"></textarea>
      <label class="check"><input type="checkbox" id="tg-sub"> Cho phép người dùng tự đăng ký bằng /subscribe</label>
      <label>Parse mode</label>
      <select id="tg-parse"><option value="HTML">HTML</option><option value="">Văn bản thường</option></select>
      <div class="row">
        <button class="btn" onclick="saveTele()">Lưu</button>
        <button class="btn sec" onclick="botInfo()">Kiểm tra token</button>
        <button class="btn sec" onclick="testSend()">Gửi tin thử</button>
        <span id="bot-info" class="muted"></span>
      </div>
      <p class="muted">Lệnh bot: /next, /last, /bind, /unbind, /topics, /subscribe, /unsubscribe, /id.</p>
      <p class="muted"><b>Gửi theo chủ đề (topic) của nhóm:</b> thêm bot vào nhóm, cấp quyền admin, rồi vào từng chủ đề gõ
        <code>/bind 1 phút</code> (hoặc <code>/bind 2</code> theo số thứ tự trong <code>/bind</code>). Game đó sẽ chỉ gửi vào chủ đề này.
        Cũng có thể gõ <code>/id</code> trong chủ đề để lấy mã <code>chat_id:chủ_đề_id</code> rồi dán vào ô "Gửi tới" của game ở tab Nguồn API.
        Game không gán chủ đề riêng sẽ gửi tới danh sách chat ở trên.</p>
    </div>
  </section>

  <!-- Cài đặt -->
  <section data-page="settings" class="hidden">
    <div class="card">
      <h2>Theo dõi</h2>
      <label class="check"><input type="checkbox" id="m-enabled"> <b>Bật theo dõi & gửi thông báo</b></label>
      <div class="grid">
        <div><label>Chu kỳ gọi API (giây)</label><input id="m-interval" type="number" min="2"></div>
        <div><label>Nhắc trước khi mở thưởng (giây)</label><input id="m-remind" type="number" min="0"></div>
        <div><label>Timeout request (giây)</label><input id="m-timeout" type="number" min="1"></div>
        <div><label>Múi giờ</label><input id="m-tz"></div>
      </div>
      <label class="check"><input type="checkbox" id="m-result"> Báo kết quả khi có kỳ mới</label>
      <label class="check"><input type="checkbox" id="m-combine"> Gửi kèm kế hoạch kỳ tiếp trong tin kết quả (gộp 1 tin, không gửi tin nhắc riêng)</label>
      <label class="check"><input type="checkbox" id="m-upcoming"> Nhắc kỳ sắp mở bằng tin riêng (chỉ dùng khi không gộp)</label>
    </div>
    <div class="card">
      <h2>Báo chuỗi thắng/thua liên tiếp</h2>
      <p class="muted">Mỗi "ván" là một dàn kế hoạch đã kết thúc (✅ trúng / ❌ trượt). Khi chuỗi đạt ngưỡng, mỗi ván tiếp theo đều báo (vd ngưỡng 4: báo ở ván 4, 5, 6…).
        Gán nhanh: vào chủ đề muốn nhận, gõ <code>/bind chuỗi</code>.</p>
      <label class="check"><input type="checkbox" id="st-enabled"> Bật báo chuỗi</label>
      <div class="grid">
        <div><label>Thắng liên tiếp từ (ván) – 0 = tắt</label><input id="st-win" type="number" min="0"></div>
        <div><label>Thua liên tiếp từ (ván) – 0 = tắt</label><input id="st-lose" type="number" min="0"></div>
      </div>
      <label>Gửi tới (chat_id:chủ_đề_id, cách nhau bởi dấu phẩy)</label>
      <input id="st-targets" placeholder="vd: -1001234567890:461">
    </div>
    <div class="card">
      <h2>Mẫu tin nhắn</h2>
      <p class="muted">Biến: {game} {issue} {result} {nextIssue} {nextTime} {countdown} {now}<br>
        Riêng vnlotteryplan: {play} kiểu chơi, {plan} dàn kế hoạch kỳ tiếp, {planRange} khoảng kỳ, {planTurn} đang nuôi kỳ thứ mấy, {planCheck} dàn vừa đối chiếu, {playResult} số về, {planStatus} trúng/trượt/đang nuôi, {streakIcon} {streakText} {streakLabel} chuỗi thắng/thua.<br>
        Dòng nào có biến mà tất cả đều rỗng sẽ tự bị bỏ.</p>
      <label>Báo kết quả</label><textarea id="tpl-result"></textarea>
      <button class="btn sec" onclick="preview('result')" style="margin-top:6px">Xem trước</button>
      <label>Phần kỳ tiếp (nối vào cuối tin kết quả khi bật gộp)</label><textarea id="tpl-next"></textarea>
      <label>Báo chuỗi thắng/thua</label><textarea id="tpl-streak"></textarea>
      <button class="btn sec" onclick="preview('streak')" style="margin-top:6px">Xem trước</button>
      <label>Nhắc kỳ sắp mở (tin riêng)</label><textarea id="tpl-upcoming"></textarea>
      <button class="btn sec" onclick="preview('upcoming')" style="margin-top:6px">Xem trước</button>
      <pre id="tpl-preview" class="hidden"></pre>
    </div>
    <div class="card">
      <h2>Header chung cho mọi request (JSON)</h2>
      <textarea id="g-headers"></textarea>
    </div>
    <div class="row" style="margin-bottom:16px">
      <button class="btn" onclick="saveSettings()">Lưu cài đặt</button>
      <button class="btn sec" onclick="resetState()">Xoá trạng thái theo dõi</button>
    </div>
    <div class="card">
      <h2>Đổi mật khẩu admin</h2>
      <div class="grid">
        <div><label>Mật khẩu hiện tại</label><input id="pw-cur" type="password"></div>
        <div><label>Mật khẩu mới</label><input id="pw-new" type="password"></div>
      </div>
      <div class="row"><button class="btn" onclick="changePassword()">Đổi mật khẩu</button></div>
    </div>
  </section>

  <!-- Cập nhật -->
  <section data-page="update" class="hidden">
    <div class="card">
      <h2>Phiên bản</h2>
      <table>
        <tr><td>Đang chạy</td><td id="v-cur">–</td></tr>
        <tr><td>Mới nhất trên GitHub</td><td id="v-latest"><span class="muted">bấm Kiểm tra</span></td></tr>
      </table>
      <div class="row">
        <button class="btn sec" onclick="checkUpdate()">Kiểm tra cập nhật</button>
        <button class="btn" id="btn-update" onclick="applyUpdate()" disabled>Cập nhật ngay</button>
      </div>
      <p class="muted">Cập nhật sẽ tải code của nhánh đã chọn từ GitHub, tự sao lưu code hiện tại rồi chép đè. Dữ liệu trong <code>data/</code> (cấu hình, trạng thái) được giữ nguyên.</p>
    </div>
    <div class="card">
      <h2>Nguồn GitHub</h2>
      <div class="grid">
        <div><label>Repo (owner/repo)</label><input id="u-repo"></div>
        <div><label>Nhánh</label><input id="u-branch"></div>
      </div>
      <label>GitHub token (chỉ cần nếu repo private – quyền Contents: Read-only)</label>
      <input id="u-token" type="password">
      <div class="row"><button class="btn" onclick="saveUpdateCfg()">Lưu</button></div>
    </div>
    <div class="card">
      <h2>Bản sao lưu</h2>
      <table><tbody id="backups"></tbody></table>
    </div>
    <div class="card">
      <h2>Hệ thống</h2>
      <table id="sys"></table>
    </div>
  </section>

  <!-- Nhật ký -->
  <section data-page="logs" class="hidden">
    <div class="card"><h2>Nhật ký</h2><pre id="logs" style="max-height:70vh"></pre></div>
  </section>
</main>
<div id="toast"></div>
<script>window.CSRF = <?= json_encode(Auth::csrf()) ?>;</script>
<script src="assets/admin.js?v=<?= Util::h(APP_VERSION) ?>"></script>
<?php endif; ?>
</body>
</html>
