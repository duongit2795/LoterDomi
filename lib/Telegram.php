<?php
class Telegram
{
    public static function call($method, array $params = [], $token = null)
    {
        $token = $token ?: Config::get()['telegram']['token'];
        if (!$token) {
            throw new RuntimeException('Chưa cấu hình Telegram bot token');
        }
        list($status, $body) = Util::http("https://api.telegram.org/bot$token/$method", [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'timeout' => 20,
        ]);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) && isset($data['description']) ? $data['description'] : "HTTP $status";
            throw new RuntimeException("Telegram $method: $desc");
        }
        return $data['result'];
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

    // Gửi tới danh sách nơi nhận (mặc định: các chat chung), trả về số nơi gửi thành công
    public static function broadcast($text, ?array $targets = null)
    {
        $sent = 0;
        foreach ($targets === null ? Config::get()['telegram']['chatIds'] : $targets as $id) {
            try {
                self::sendMessage($id, $text);
                $sent++;
            } catch (Exception $e) {
                Logger::error("Gửi tới $id lỗi: " . $e->getMessage());
            }
        }
        return $sent;
    }
}
