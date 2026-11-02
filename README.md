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
- Lệnh: `/next` (kỳ sắp mở + kế hoạch), `/last` (kết quả gần nhất), `/cau` (ảnh cầu), `/bind`, `/unbind`, `/topics`, `/subscribe`, `/unsubscribe`, `/id`, `/help`.
- Nhóm/kênh: thêm bot vào nhóm (kênh: cấp quyền admin), gõ `/id` để lấy chat ID.
- **Gửi theo chủ đề (topic)**: thêm bot vào nhóm và cấp quyền admin, rồi vào từng chủ đề gõ `/bind 1 phút`
  (hoặc `/bind <số>` theo danh sách mà `/bind` liệt kê). Game đó chỉ gửi vào chủ đề này. `/unbind` để bỏ, `/topics` để xem
  game nào đang gửi đi đâu. Có thể nhập tay ô **Gửi tới** của từng game dạng `chat_id:chủ_đề_id` (lấy bằng `/id` trong chủ đề).
  Game chưa gán sẽ gửi tới các chat chung ở tab Telegram.
- Bot nhận lệnh bằng `getUpdates` nên không cần webhook hay SSL.

## Báo chuỗi W / L liên tiếp

Đếm theo **từng kỳ**, cùng dữ liệu với tab Cầu: W = dàn đang nuôi trúng ở kỳ đó, L = không trúng
(trúng kỳ 3 → L L W). Khi một game có chuỗi W hoặc L liên tiếp đạt ngưỡng (mặc định **từ kỳ 4**), bot báo vào nơi
nhận riêng, mỗi kỳ tiếp theo của chuỗi đều báo:

```
🧊 Siêu Tốc 45 Giây đang THUA 5 kỳ liên tiếp
Kỳ 01/11/2026-1299: … → dàn 0 2 3 6 8 (1297-1299) về 7 ❌ Trượt
📋 Kế hoạch tiếp Đuôi 1300-1302: 1 4 5 7 9
```

Tin báo chuỗi gửi vào nơi nhận báo chuỗi **và** cả chủ đề/chat của chính game đó (tắt được trong admin).
Gán nơi nhận: vào chủ đề (vd chủ đề 461) gõ `/bind chuỗi`, hoặc nhập `chat_id:461` trong admin → Cài đặt → Báo chuỗi.
Ngưỡng W / L chỉnh riêng (0 = tắt).

## Thành viên (/member)

Trang `https://<domain>/member/` cho thành viên: đăng ký / đăng nhập, **Bảng kỳ** (kỳ vừa mở, dàn vừa đối chiếu,
kế hoạch kỳ tiếp, đếm ngược, chuỗi thắng/thua – tự cập nhật), **Lịch sử** từng game kèm thống kê trúng kỳ 1 / trúng muộn / trượt, biểu đồ theo ngày (7/14/30 ngày: số dàn mỗi ngày
và % trúng ngay kỳ 1, có bảng số liệu),
tab **Cầu** (bảng WIN/LOST kiểu big road theo **từng kỳ**: W = dàn đang nuôi trúng ở kỳ đó, L = không trúng;
cùng kết quả xếp xuống cột, đổi kết quả sang cột mới, kèm tổng WIN/LOST và chuỗi hiện tại – tự làm mới), và đổi mật khẩu.
Dữ liệu cầu không cần database: worker lưu từng kỳ vào `data/road_ky.php` (giữ 1000 kỳ gần nhất mỗi game). API chỉ trả
mỗi dàn 1 dòng nên bot tách ngược ra từng kỳ (trúng kỳ 3 → L L W, trượt cả dàn → L L L).
Tab Lịch sử (cần database) cũng thống kê và vẽ biểu đồ theo W/L từng kỳ.

## Trang xem cầu công khai (/xemcau.php)

`https://<domain>/xemcau.php` – ai có link là xem được bảng cầu W/L của tất cả game, không cần đăng nhập, cập nhật
**cùng lúc với tin Telegram**: worker ghi `cache/ver.json` (file tĩnh rất nhỏ) ngay trước khi gửi tin, trang kiểm tra file này
mỗi giây và chỉ tải lại dữ liệu khi có thay đổi. Lọc 1 game: thêm `?game=<id>` (chọn trong ô trên trang). Admin → Cài đặt → **Trang xem cầu công khai**:
bật/tắt và đặt **mã truy cập** (tuỳ chọn) – khi có mã, link phải dạng `xemcau.php?k=<mã>`; đổi mã là link cũ hết hiệu lực.

Cần MySQL/MariaDB:
1. aaPanel → **Databases** → Add database (tên + user + mật khẩu).
2. Admin → tab **Thành viên** → mục Database: nhập host `127.0.0.1`, tên DB, user, mật khẩu → **Kết nối & lưu**
   (bảng `members`, `draws` tự tạo). Hoặc điền luôn ở bước 5 của `install.php`.
3. Bật extension **pdo_mysql** cho PHP nếu thiếu.

Mật khẩu database chỉ lưu trong `data/config.php` trên server (không đưa lên GitHub, không gửi ra trình duyệt).

Tab **Thành viên** trong admin: bật/tắt tự đăng ký, bắt buộc duyệt tài khoản mới, tìm kiếm, duyệt / khoá / mở khoá,
đặt lại mật khẩu, xoá, thêm thành viên thủ công. Lịch sử kỳ được lưu vào bảng `draws` từ lúc bật database.

## Phương pháp tự dự đoán

Không dùng dàn kế hoạch của API. Mỗi kỳ bot tự tạo dàn cho **kỳ tiếp theo** và lưu lại (`data/predictions.php`,
1000 kỳ/game); khi API có kết quả kỳ đó thì chấm **W** nếu số về (chữ số cuối của kết quả – Đuôi) nằm trong dàn, ngược lại **L**.

- Phương pháp đầu tiên: **Ngẫu nhiên** – chọn ngẫu nhiên N số khác nhau từ 0–9 (mặc định 5, dùng `random_int`).
- Admin → Cài đặt → **Phương pháp tự dự đoán**: thêm/xoá, đặt tên, số lượng số, bật/tắt, gửi kèm tin Telegram;
  bảng W/L 200 kỳ gần nhất theo từng game.
- Tin kết quả Telegram có thêm dòng: `🎲 PP Ngẫu nhiên: dàn 0 2 4 8 9 → về 8 ✅ W` và dàn kỳ tiếp.
- Trang xem cầu / tab Cầu: chọn nguồn **Dàn API** hoặc **PP …** để xem cầu W/L riêng của phương pháp
  (link: `xemcau.php?src=random`).

## Ảnh bảng cầu trên Telegram

Ngay khi có kết quả kỳ mới, bot **vẽ ảnh bảng cầu W/L** của game đó (đã gồm kỳ vừa mở) và gửi vào topic/chat của game,
**ảnh + chú thích là nội dung tin kết quả** (vẫn 1 tin/kỳ). Admin → Cài đặt: bật/tắt, hoặc chỉ gửi ảnh mỗi N kỳ
(các kỳ khác gửi tin chữ). Lệnh `/cau` (trong topic đã `/bind`) hoặc `/cau tên game` để xem ảnh bất kỳ lúc nào.

Cần PHP **GD có FreeType** (aaPanel bật sẵn trong đa số bản PHP). Font DejaVu được kèm trong `assets/fonts/`
(giấy phép tự do, xem `LICENSE-DejaVu.txt`). Gửi ảnh lỗi tạm thời thì tin chữ được đưa vào hàng chờ, không mất kết quả.

## Độ trễ

Quanh giờ mở thưởng của từng game, worker gọi API **mỗi giây** cho riêng game đó (các game khác vẫn theo chu kỳ thường),
nên tin Telegram đi ra chưa tới 1 giây sau khi trang nguồn công bố kết quả. Bản thân trang nguồn công bố chậm
khoảng 5 giây (M.Nam, M.Trung) đến 10 giây (Siêu Tốc, M.Bắc) sau giờ mở thưởng – phần này không rút ngắn được.

## Chống gửi thiếu tin

- Telegram giới hạn ~20 tin/phút mỗi nhóm. Khi bị giới hạn (lỗi 429), bot chờ đúng số giây Telegram yêu cầu rồi gửi lại.
- Lỗi tạm thời (mạng, Telegram lỗi 5xx): tin vào **hàng chờ** và được gửi lại theo đúng thứ tự từng nơi nhận
  (tối đa 15 phút). Số tin đang chờ hiện ở thẻ Worker trên trang Tổng quan.
- Lỗi cố định (sai chat id, bot bị kick – lỗi 400/403) thì bỏ tin và ghi Nhật ký.
- Máy chủ không truy cập được Telegram trực tiếp: đổi **Địa chỉ Bot API** ở tab Telegram sang Bot API server riêng / máy chủ trung gian.

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
member/          trang thành viên (đăng ký, đăng nhập, bảng kỳ, lịch sử)
assets/          CSS/JS giao diện
data/            cấu hình, trạng thái, log, bản sao lưu (không đưa lên git)
```
