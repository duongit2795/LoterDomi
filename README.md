# LoterDomi – Telegram bot báo phiên + Web admin

Bot Telegram theo dõi các phiên xổ số (ví dụ trên vnlotteryplan.com/mobile.html), gửi:
- 🎯 **Kết quả** khi có phiên mới mở thưởng
- ⏰ **Nhắc phiên sắp tới** trước N giây

Mọi cấu hình (token bot, chat nhận tin, API nguồn, mẫu tin nhắn, chu kỳ...) chỉnh trong **web admin**, lưu ở `data/config.json`.

## Chạy

```bash
cp .env.example .env      # đặt ADMIN_PASSWORD
npm install
npm start                 # http://localhost:3000
```

## Nguồn vnlotteryplan.com (có sẵn)

Tab **Nguồn API** → **⬇ Nhập game từ vnlotteryplan** → **Lưu**. Bot tự thêm toàn bộ game
(Siêu Tốc 45s/1p/1.5p/2p/5p, M.Bắc 75s, M.Nam 45s, M.Trung 75s) và báo:
- kết quả kỳ vừa mở + dàn kế hoạch vừa đối chiếu (✅ trúng kỳ N / ❌ trượt / ⏳ đang nuôi)
- kỳ sắp mở + dàn kế hoạch mới

API dùng (công khai, không cần đăng nhập):
| Endpoint | Nội dung |
|---|---|
| `GET /api/front/wl/plan/lotteryProject/planGameList` | danh sách game (`rows`) và kiểu chơi (`other`, vd `DI` = Đuôi) |
| `GET /api/front/wl/plan/lotteryProject/queryLotteryPlanProject?gameId=110&play=DI&turnNumType=3&planNumberType=1&page=1&pageSize=5` | `t.lottery` (kỳ vừa mở: `turnNum`, `openNum`), `t.nextTurnNum`, `t.nextOpenTime`, `t.serverTime`, `t.planNumber` (dàn hiện tại), `t.resultDetail.data` (lịch sử kế hoạch, `winStatus` 1=trúng 0=trượt 2=đang nuôi) |
| `GET /api/front/wl/time` | giờ máy chủ |

`turnNumType` = số kỳ nuôi (3/4/5), chỉnh được cho từng game.

> Nếu máy chủ chạy bot phải đi qua proxy HTTP, chạy với `NODE_USE_ENV_PROXY=1` (Node ≥ 22.21) để `fetch` dùng `HTTPS_PROXY`.

## Cấu hình API tuỳ chỉnh

1. Mở `https://www.vnlotteryplan.com/mobile.html` trên Chrome → F12 → tab **Network** → lọc **Fetch/XHR**.
2. Tìm request trả về JSON có mã phiên / kết quả / thời gian phiên kế tiếp. Copy URL (và headers cần thiết nếu có, ví dụ token).
3. Web admin → **Nguồn API** → *Thêm game* → dán URL, điền đường dẫn trường, ví dụ:
   | Trường | Ví dụ |
   |---|---|
   | Mã phiên vừa mở | `data.list[0].issue` |
   | Kết quả | `data.list[0].openCode` |
   | Mã phiên sắp tới | `data.next.issue` |
   | Thời gian mở phiên sắp tới | `data.next.openTime` |
4. Bấm **Thử gọi API** để xem JSON thô và kiểm tra giá trị trích xuất, rồi **Lưu**.
5. **Kiểu thời gian**: *Tự nhận* (unix giây/ms hoặc chuỗi ngày), hoặc *Số giây còn lại* nếu API trả countdown.

URL có thể chứa `{ts}` để chèn timestamp chống cache. Hỗ trợ GET/POST, JSON và JSONP.

## Telegram

- Tạo bot qua @BotFather, dán token ở tab **Telegram** → *Kiểm tra token*.
- Thêm chat id thủ công, hoặc người dùng gõ `/subscribe` (nếu bật cho phép).
- Lệnh bot: `/next`, `/last`, `/subscribe`, `/unsubscribe`, `/id`, `/help`.
- Muốn gửi vào nhóm/kênh: thêm bot vào nhóm (kênh: cấp quyền admin), gõ `/id` để lấy chat id.

## Cài đặt

Tab **Cài đặt**: bật/tắt theo dõi, chu kỳ gọi API, số giây nhắc trước, bật/tắt từng loại thông báo, mẫu tin nhắn
(biến `{game} {issue} {result} {nextIssue} {nextTime} {countdown} {now}`; riêng vnlotteryplan thêm
`{play} {plan} {planRange} {planCheck} {playResult} {planStatus}`), header chung cho mọi request.
Dòng nào có biến mà tất cả đều rỗng sẽ tự bị bỏ.

> Lần chạy đầu tiên bot chỉ ghi nhận phiên hiện tại, không gửi kết quả cũ để tránh spam.

## Cấu trúc

```
src/index.js     khởi động web admin + monitor + bot
src/admin.js     API web admin (đăng nhập bằng ADMIN_PASSWORD)
src/monitor.js   vòng lặp gọi API, phát hiện phiên mới, nhắc phiên sắp tới
src/source.js    gọi API nguồn & trích trường theo đường dẫn JSON
src/telegram.js  gửi tin / nhận lệnh (long polling, không cần webhook)
src/bot.js       xử lý lệnh bot
public/index.html  giao diện admin
```
