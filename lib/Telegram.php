<?php
class Telegram
{
    // Lỗi tạm thời (mạng, 429, 5xx) thì nên gửi lại; lỗi 400/403 (sai chat, bot bị kick) thì không
    public static function isTransient(Exception $e)
    {
        return !($e instanceof TelegramException) || $e->retryable;
    }

    public static function call($method, array $params = [], $token = null)
    {
        $token = $token ?: Config::get()['telegram']['token'];
        if (!$token) {
            throw new TelegramException('Chưa cấu hình Telegram bot token', 0, false);
        }
        for ($attempt = 1; ; $attempt++) {
            $base = rtrim(Config::get()['telegram']['apiBase'] ?: 'https://api.telegram.org', '/');
            // Có file đính kèm (ảnh) thì gửi multipart, không thì gửi JSON
            $multipart = false;
            foreach ($params as $v) {
                $multipart = $multipart || $v instanceof CURLFile;
            }
            list($status, $body) = Util::http("$base/bot$token/$method", [
                'method' => 'POST',
                'headers' => $multipart ? [] : ['Content-Type' => 'application/json'],
                'body' => $multipart ? $params : json_encode($params, JSON_UNESCAPED_UNICODE),
                'timeout' => $multipart ? 40 : 20,
            ]);
            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['ok'])) {
                return $data['result'];
            }
            $desc = is_array($data) && isset($data['description']) ? $data['description'] : "HTTP $status";
            $retryAfter = (int) ($data['parameters']['retry_after'] ?? 0);
            // Bị giới hạn tốc độ: chờ đúng thời gian Telegram yêu cầu rồi gửi lại (tối đa 3 lần, mỗi lần ≤ 15 giây)
            if ($status === 429 && $attempt < 3 && $retryAfter > 0 && $retryAfter <= 15) {
                Logger::warn("Telegram giới hạn tốc độ, chờ {$retryAfter}s rồi gửi lại");
                sleep($retryAfter);
                continue;
            }
            throw new TelegramException("Telegram $method: $desc", $status, $status === 429 || $status >= 500 || $status === 0, $retryAfter);
        }
    }

    // Nơi nhận dạng "chat_id" hoặc "chat_id:topic_id" (chủ đề trong nhóm diễn đàn)
    public static function parseTarget($target)
    {
        $target = trim((string) $target);
        if (preg_match('/^(-?\d+|@\w+):(\d+)$/', $target, $m)) {
            return ['chat_id' => $m[1], 'message_thread_id' => (int) $m[2]];
        }
        return ['chat_id' => $target];
    }

    public static function sendMessage($target, $text, $parseMode = null)
    {
        $params = self::parseTarget($target) + ['text' => $text, 'disable_web_page_preview' => true];
        $mode = $parseMode === null ? Config::get()['telegram']['parseMode'] : $parseMode;
        if ($mode) {
            $params['parse_mode'] = $mode;
        }
        return self::call('sendMessage', $params);
    }

    // Gửi ảnh (file PNG trên đĩa) kèm chú thích
    public static function sendPhoto($target, $file, $caption = '')
    {
        $params = self::parseTarget($target);
        foreach ($params as $k => $v) {
            $params[$k] = (string) $v;
        }
        $params['photo'] = new CURLFile($file, 'image/png', 'cau.png');
        if ($caption !== '') {
            // Chú thích ảnh tối đa 1024 ký tự
            $params['caption'] = mb_substr($caption, 0, 1024);
            $mode = Config::get()['telegram']['parseMode'];
            if ($mode) {
                $params['parse_mode'] = $mode;
            }
        }
        return self::call('sendPhoto', $params);
    }

    // Gửi ảnh + chú thích tới nhiều nơi. Lỗi tạm thời: đưa chú thích (dạng chữ) vào hàng chờ để không mất tin.
    public static function broadcastPhoto($png, $caption, array $targets)
    {
        $file = tempnam(sys_get_temp_dir(), 'cau') . '.png';
        file_put_contents($file, $png);
        $sent = 0;
        try {
            foreach ($targets as $id) {
                if (Outbox::has($id)) {
                    Outbox::add($id, $caption);
                    continue;
                }
                try {
                    self::sendPhoto($id, $file, $caption);
                    $sent++;
                } catch (Exception $e) {
                    if (self::isTransient($e)) {
                        Outbox::add($id, $caption);
                        Logger::warn("Gửi ảnh tới $id lỗi tạm thời, đã đưa tin chữ vào hàng chờ: " . $e->getMessage());
                    } else {
                        // Ảnh lỗi (vd quá lớn / không hỗ trợ) thì vẫn gửi bản chữ
                        Logger::error("Gửi ảnh tới $id lỗi: " . $e->getMessage() . ' – gửi bản chữ thay thế');
                        $sent += self::broadcast($caption, [$id]);
                    }
                }
            }
        } finally {
            @unlink($file);
        }
        return $sent;
    }

    // Gửi tới danh sách nơi nhận (mặc định: các chat chung), trả về số nơi gửi thành công.
    // Lỗi tạm thời: tin được đưa vào hàng chờ (Outbox) để gửi lại, không bị mất.
    public static function broadcast($text, ?array $targets = null)
    {
        $sent = 0;
        foreach ($targets === null ? Config::get()['telegram']['chatIds'] : $targets as $id) {
            // Nơi này còn tin chờ gửi lại thì xếp sau để giữ đúng thứ tự
            if (Outbox::has($id)) {
                Outbox::add($id, $text);
                continue;
            }
            try {
                self::sendMessage($id, $text);
                $sent++;
            } catch (Exception $e) {
                if (self::isTransient($e)) {
                    Outbox::add($id, $text);
                    Logger::warn("Gửi tới $id lỗi tạm thời, đã đưa vào hàng chờ: " . $e->getMessage());
                } else {
                    Logger::error("Gửi tới $id lỗi: " . $e->getMessage());
                }
            }
        }
        return $sent;
    }
}

class TelegramException extends RuntimeException
{
    public $retryable;
    public $retryAfter;

    public function __construct($message, $status, $retryable, $retryAfter = 0)
    {
        parent::__construct($message, (int) $status);
        $this->retryable = $retryable;
        $this->retryAfter = $retryAfter;
    }
}
