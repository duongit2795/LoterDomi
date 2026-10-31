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

    public static function sendMessage($chatId, $text, $parseMode = null)
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true];
        $mode = $parseMode === null ? Config::get()['telegram']['parseMode'] : $parseMode;
        if ($mode) {
            $params['parse_mode'] = $mode;
        }
        return self::call('sendMessage', $params);
    }

    // Gửi tới mọi chat đã cấu hình, trả về số chat gửi thành công
    public static function broadcast($text)
    {
        $sent = 0;
        foreach (Config::get()['telegram']['chatIds'] as $id) {
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
