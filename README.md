# LoterDomi – Telegram bot báo kỳ xổ số + Web admin (PHP)

Bot Telegram theo dõi các game trên **vnlotteryplan.com** (Siêu Tốc 45s/1p/1.5p/2p/5p, M.Bắc 75s, M.Nam 45s, M.Trung 75s) và gửi:

- 🎯 **Kết quả** kỳ vừa mở + dàn kế hoạch vừa đối chiếu (✅ trúng kỳ N / ❌ trượt / ⏳ đang nuôi)
- ⏭ **Kế hoạch kỳ tiếp** gửi kèm ngay trong tin kết quả (1 tin/kỳ). Có thể tắt gộp để gửi tin ⏰ nhắc kỳ sắp mở riêng trước N giây.

Toàn bộ cấu hình nằm trong web admin. Không cần MySQL: dữ liệu lưu ở `data/` dạng file có chặn truy cập.

## Cài đặt trên aaPanel

1. **Website → Add site**, chọn PHP ≥ 7.4 (khuyên dùng 8.x).
2. Đưa code vào thư mục site, ví dụ `/www/wwwroot/bot.example.com`:
   ```bash
   cd /www/wwwroot/bot.example.com
   git clone https://github.com/duongit2795/loterdomi.git .
   chown -R www:www .
   ```
   Hoặc tải zip từ GitHub rồi upload và giải nén bằng File Manager của aaPanel.
3. Bật extension **zip** cho PHP (App Store → PHP → Settings → Install extensions), dùng cho tính năng Cập nhật.
4. Mở `https://bot.example.com/install.php`, điền mật khẩu admin, token bot, chat ID rồi bấm **Cài đặt**.
5. Tạo **Cron**: aaPanel → Cron → Add Cron
   - Type: **Shell Script**, Period: **N Minutes = 1**
   - Script: `sudo -u www php /www/wwwroot/bot.example.com/worker.php`

   Worker chạy khoảng 55 giây mỗi lần, nên các game 45 giây vẫn được báo kịp.
   Nếu có nhiều bản PHP, dùng đường dẫn đầy đủ, ví dụ `/www/server/php/82/bin/php`.
6. Nginx (mặc định của aaPanel): Website → Conf, thêm:
   ```nginx
   location ^~ /data/ { deny all; }
   location ^~ /lib/ { deny all; }
   ```
   Kể cả khi thiếu dòng này, các file dữ liệu vẫn không bị lộ nội dung.

**Cron 5 giây qua link** (dịch vụ cron hỗ trợ chu kỳ giây): gọi
`https://bot.example.com/worker.php?key=<cronKey>&mode=tick` mỗi 5 giây. Link đầy đủ có trong admin → Cập nhật → Hệ thống.
Mỗi lần gọi chỉ chạy ~4 giây rồi thoát, trả lời ngay (PHP-FPM), nếu lần trước chưa xong thì bỏ qua – không chồng request.
Dòng lệnh tương đương: `php worker.php --tick`.

Chạy liên tục (không cần cron): dùng **Supervisor** của aaPanel với lệnh
`php /www/wwwroot/bot.example.com/worker.php --daemon`, user `www`.

## Cập nhật từ GitHub

Admin → tab **Cập nhật** → **Kiểm tra cập nhật** → **Cập nhật ngay**.

- Tải code của nhánh đã cấu hình (mặc định `duongit2795/loterdomi`, nhánh `master`) qua GitHub API. Không cần `git` hay `exec` trên server.
- Trước khi chép đè, code hiện tại được tự sao lưu vào `data/backups/` (giữ 5 bản). Có thể **Khôi phục** từ tab này.
- Thư mục `data/` (cấu hình, trạng thái, log) luôn được giữ nguyên.
- Repo private: tạo fine-grained token với quyền **Contents: Read-only** và nhập vào ô GitHub token.
- Thư mục site phải ghi được bởi user chạy PHP: `chown -R www:www /www/wwwroot/bot.example.com`.

## Bot Telegram

- Tạo bot với @BotFather, dán token vào tab **Telegram**, bấm **Kiểm tra token**.
- Thêm chat ID thủ công, hoặc người dùng tự gõ `/subscribe` (nếu bật cho phép).
- Lệnh: `/next` (kỳ sắp mở + kế hoạch), `/last` (kết quả gần nhất), `/bind`, `/unbind`, `/topics`, `/subscribe`, `/unsubscribe`, `/id`, `/help`.
- Nhóm/kênh: thêm bot vào nhóm (kênh: cấp quyền admin), gõ `/id` để lấy chat ID.
- **Gửi theo chủ đề (topic)**: thêm bot vào nhóm và cấp quyền admin, rồi vào từng chủ đề gõ `/bind 1 phút`
  (hoặc `/bind <số>` theo danh sách mà `/bind` liệt kê). Game đó chỉ gửi vào chủ đề này. `/unbind` để bỏ, `/topics` để xem
  game nào đang gửi đi đâu. Có thể nhập tay ô **Gửi tới** của từng game dạng `chat_id:chủ_đề_id` (lấy bằng `/id` trong chủ đề).
  Game chưa gán sẽ gửi tới các chat chung ở tab Telegram.
- Bot nhận lệnh bằng `getUpdates` nên không cần webhook hay SSL.

## Báo chuỗi thắng/thua liên tiếp

Mỗi "ván" là một dàn kế hoạch đã kết thúc (✅ trúng / ❌ trượt). Khi một game thắng hoặc thua liên tiếp đạt ngưỡng
(mặc định **từ ván 4**), bot báo vào nơi nhận riêng, mỗi ván tiếp theo của chuỗi đều báo:

```
🔥 M.Nam Siêu Tốc 45s đang THẮNG 9 ván liên tiếp
Kỳ 31/10/2026-0479: 0,5,8,0,1,1 → dàn 1 3 7 8 9 (0478-0480) về 1 ✅ Trúng kỳ 2
📋 Kế hoạch tiếp Đuôi 0480-0482: 3 4 5 6 9
```

Gán nơi nhận: vào chủ đề (vd chủ đề 461) gõ `/bind chuỗi`, hoặc nhập `chat_id:461` trong admin → Cài đặt → Báo chuỗi.
Ngưỡng thắng / thua chỉnh riêng (0 = tắt). Bot đếm trên 30 dàn gần nhất; nếu cả 30 cùng một chuỗi sẽ hiện dạng `29+`.

## Mẫu tin nhắn

Biến: `{game} {issue} {result} {nextIssue} {nextTime} {countdown} {now}`.
Riêng vnlotteryplan có thêm: `{play} {plan} {planRange} {planTurn} {planCheck} {playResult} {planStatus} {streakIcon} {streakText} {streakLabel}`.
Dòng nào có biến mà tất cả đều rỗng sẽ tự bị bỏ.

## API vnlotteryplan.com

Công khai, không cần đăng nhập:

| Endpoint | Nội dung |
|---|---|
| `GET /api/front/wl/plan/lotteryProject/planGameList` | danh sách game (`rows`) và kiểu chơi (`other`, vd `DI` = Đuôi) |
| `GET /api/front/wl/plan/lotteryProject/queryLotteryPlanProject?gameId=110&play=DI&turnNumType=3&planNumberType=1&page=1&pageSize=5` | `t.lottery` (kỳ vừa mở: `turnNum`, `openNum`), `t.nextTurnNum`, `t.nextOpenTime`, `t.serverTime`, `t.planNumber` (dàn hiện tại), `t.resultDetail.data` (lịch sử kế hoạch, `winStatus` 1=trúng 0=trượt 2=đang nuôi) |
| `GET /api/front/wl/time` | giờ máy chủ |

`turnNumType` là số kỳ nuôi (3/4/5), chỉnh được cho từng game. Ngoài ra có thể thêm **API tuỳ chỉnh** bất kỳ (endpoint JSON + đường dẫn trường).

## Cấu trúc

```
install.php      trình cài đặt (tự khoá sau khi cài)
admin.php        trang quản trị (đăng nhập)
api.php          API JSON cho trang quản trị
worker.php       tiến trình nền: gọi API, gửi thông báo, nhận lệnh bot
lib/             mã nguồn (Config, Source, Monitor, Bot, Telegram, Updater...)
assets/          CSS/JS giao diện
data/            cấu hình, trạng thái, log, bản sao lưu (không đưa lên git)
```
