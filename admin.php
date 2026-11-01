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
    <button data-tab="members">Thành viên</button>
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
      <label>Địa chỉ Bot API (nâng cao – để mặc định nếu không dùng máy chủ trung gian)</label>
      <input id="tg-api" placeholder="https://api.telegram.org">
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
      <p class="muted">Đếm theo <b>từng kỳ</b>, cùng dữ liệu với tab Cầu của trang thành viên: W = dàn đang nuôi trúng ở kỳ đó, L = không trúng. Khi chuỗi đạt ngưỡng, mỗi kỳ tiếp theo của chuỗi đều báo (vd ngưỡng 4: báo ở kỳ 4, 5, 6…). Gán nhanh: vào chủ đề muốn nhận, gõ <code>/bind chuỗi</code>.</p>
      <label class="check"><input type="checkbox" id="st-enabled"> Bật báo chuỗi</label>
      <div class="grid">
        <div><label>W liên tiếp từ (kỳ) – 0 = tắt</label><input id="st-win" type="number" min="0"></div>
        <div><label>L liên tiếp từ (kỳ) – 0 = tắt</label><input id="st-lose" type="number" min="0"></div>
      </div>
      <label>Gửi tới (chat_id:chủ_đề_id, cách nhau bởi dấu phẩy)</label>
      <input id="st-targets" placeholder="vd: -1001234567890:461">
      <label class="check"><input type="checkbox" id="st-game"> Gửi thêm vào chủ đề/chat của từng game</label>
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

  <!-- Thành viên -->
  <section data-page="members" class="hidden">
    <div class="card">
      <h2>Database (MySQL/MariaDB)</h2>
      <p class="muted">Dùng cho thành viên và lịch sử kỳ. Tạo database trong aaPanel → Databases. Bảng sẽ được tạo tự động.
        <span id="db-status"></span></p>
      <div class="grid">
        <div><label>Host</label><input id="db-host" placeholder="127.0.0.1"></div>
        <div><label>Port</label><input id="db-port" type="number" placeholder="3306"></div>
        <div><label>Tên database</label><input id="db-name"></div>
        <div><label>User</label><input id="db-user"></div>
        <div><label>Mật khẩu</label><input id="db-pass" type="password" autocomplete="new-password"></div>
      </div>
      <div class="row"><button class="btn" onclick="saveDb(this)">Kết nối & lưu</button></div>
    </div>
    <div class="card">
      <h2>Đăng ký</h2>
      <p class="muted">Trang thành viên: <a id="member-link" href="member/" target="_blank">member/</a></p>
      <label class="check"><input type="checkbox" id="mb-register"> Cho phép tự đăng ký</label>
      <label class="check"><input type="checkbox" id="mb-approval"> Tài khoản mới cần admin duyệt</label>
      <div class="row"><button class="btn" onclick="saveMemberCfg()">Lưu</button></div>
    </div>
    <div class="card">
      <h2>Danh sách thành viên <span class="muted" id="mb-counts"></span></h2>
      <div class="row" style="margin-top:0">
        <input id="mb-q" placeholder="Tìm tên đăng nhập / tên hiển thị" style="max-width:280px" onkeydown="event.key==='Enter'&&loadMembers(1)">
        <select id="mb-status" style="max-width:160px" onchange="loadMembers(1)">
          <option value="">Tất cả</option><option value="active">Hoạt động</option><option value="pending">Chờ duyệt</option><option value="locked">Bị khoá</option>
        </select>
        <button class="btn sec" onclick="loadMembers(1)">Tìm</button>
      </div>
      <div style="overflow-x:auto;margin-top:12px">
        <table>
          <thead><tr><th>#</th><th>Tên đăng nhập</th><th>Tên hiển thị</th><th>Trạng thái</th><th>Ngày tạo</th><th>Đăng nhập gần nhất</th><th></th></tr></thead>
          <tbody id="mb-rows"></tbody>
        </table>
      </div>
      <div class="row" id="mb-pager"></div>
    </div>
    <div class="card">
      <h2>Thêm thành viên</h2>
      <div class="grid">
        <div><label>Tên đăng nhập</label><input id="nm-user"></div>
        <div><label>Mật khẩu</label><input id="nm-pass" type="password" autocomplete="new-password"></div>
        <div><label>Tên hiển thị</label><input id="nm-name"></div>
      </div>
      <div class="row"><button class="btn" onclick="createMember(this)">Thêm</button></div>
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
