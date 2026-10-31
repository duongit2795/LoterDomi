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

## Cấu hình nguồn API

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
(biến `{game} {issue} {result} {nextIssue} {nextTime} {countdown} {now}`), header chung cho mọi request.

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
