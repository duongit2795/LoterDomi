<?php
class Config
{
    private static $cache = null;

    public static function defaultGame()
    {
        return [
            'id' => '',
            'name' => '',
            'enabled' => true,
            // vnlp: nguồn có sẵn vnlotteryplan.com | custom: API tự cấu hình
            'type' => 'custom',
            // Nơi nhận riêng của game ("chat_id" hoặc "chat_id:topic_id"); rỗng = gửi tới các chat chung
            'targets' => [],
            'vnlp' => [
                'gameId' => '',
                'play' => 'DI',
                'playName' => 'Đuôi',
                'turnNumType' => 3,   // kế hoạch nuôi 3/4/5 kỳ
                'planNumberType' => 1,
            ],
            'url' => '',
            'method' => 'GET',
            'headers' => new stdClass(),
            'body' => '',
            // Đường dẫn JSON tới các trường, dạng "data.list[0].issue"
            'paths' => [
                'issue' => '',
                'result' => '',
                'nextIssue' => '',
                'nextTime' => '',
            ],
            // auto | seconds | milliseconds | remaining
            'timeMode' => 'auto',
        ];
    }

    public static function defaults()
    {
        return [
            'installed' => false,
            'admin' => ['passwordHash' => ''],
            'cronKey' => '',
            'telegram' => [
                'token' => '',
                'chatIds' => [],
                'allowSubscribe' => true,
                'parseMode' => 'HTML',
            ],
            'monitor' => [
                'enabled' => false,
                'intervalSeconds' => 5,
                'notifyResult' => true,
                'notifyUpcoming' => true,
                // Gửi kèm kế hoạch kỳ tiếp trong tin kết quả, không gửi tin nhắc riêng
                'combineNext' => true,
                // Báo chuỗi thắng/thua liên tiếp (vnlotteryplan) vào nơi nhận riêng
                'streak' => [
                    'enabled' => true,
                    'winMin' => 4,      // 0 = không báo chuỗi thắng
                    'loseMin' => 4,     // 0 = không báo chuỗi thua
                    'targets' => [],    // vd "-1001234567890:461"; rỗng = không gửi
                ],
                'remindBeforeSeconds' => 30,
                'requestTimeoutSeconds' => 15,
            ],
            'globalHeaders' => [
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
                'Referer' => 'https://www.vnlotteryplan.com/mobile.html',
            ],
            'templates' => [
                'result' => "🎯 <b>{game}</b> – Kỳ <code>{issue}</code>\nKết quả: <b>{result}</b>\n📋 Dàn {planCheck}: về <b>{playResult}</b> → {planStatus}",
                'streak' => "{streakIcon} <b>{game}</b> đang <b>{streakText} {streakLabel} ván</b> liên tiếp\nKỳ <code>{issue}</code>: {result} → dàn {planCheck} về <b>{playResult}</b> {planStatus}\n📋 Kế hoạch tiếp {play} {planRange}: <b>{plan}</b>",
                'next' => "⏭ Kỳ tiếp <code>{nextIssue}</code> – {nextTime}\n📋 Kế hoạch {play} {planRange} (nuôi kỳ {planTurn}): <b>{plan}</b>",
                'upcoming' => "⏰ <b>{game}</b>\nKỳ sắp mở: <code>{nextIssue}</code> – {nextTime} (còn {countdown})\n📋 Kế hoạch {play} {planRange} (nuôi kỳ {planTurn}): <b>{plan}</b>",
            ],
            'timezone' => 'Asia/Ho_Chi_Minh',
            'update' => [
                'repo' => 'duongit2795/loterdomi',
                'branch' => 'master',
                'token' => '',
            ],
            'games' => [],
        ];
    }

    public static function normalizeGame(array $g)
    {
        $game = Util::merge(self::defaultGame(), $g);
        if (empty($game['id'])) {
            $game['id'] = Util::randomId();
        }
        if (!$game['headers'] || !is_array($game['headers'])) {
            $game['headers'] = new stdClass();
        }
        $game['enabled'] = (bool) $game['enabled'];
        $game['targets'] = self::cleanTargets($game['targets']);
        $game['vnlp']['turnNumType'] = (int) $game['vnlp']['turnNumType'] ?: 3;
        $game['vnlp']['planNumberType'] = (int) $game['vnlp']['planNumberType'] ?: 1;
        return $game;
    }

    public static function cleanTargets($list)
    {
        if (!is_array($list)) {
            $list = preg_split('/[\s,]+/', (string) $list);
        }
        return array_values(array_unique(array_filter(array_map(function ($t) {
            return trim((string) $t);
        }, $list), 'strlen')));
    }

    public static function get($fresh = false)
    {
        if ($fresh || self::$cache === null) {
            $cfg = Util::merge(self::defaults(), Store::read('config', []));
            $cfg['games'] = array_map([self::class, 'normalizeGame'], $cfg['games'] ?: []);
            self::$cache = $cfg;
        }
        return self::$cache;
    }

    public static function update(array $partial)
    {
        $cfg = Util::merge(self::get(true), $partial);
        if (isset($partial['games'])) {
            $cfg['games'] = array_map([self::class, 'normalizeGame'], $partial['games']);
        }
        $cfg['telegram']['chatIds'] = self::cleanTargets($cfg['telegram']['chatIds']);
        $cfg['monitor']['streak']['targets'] = self::cleanTargets($cfg['monitor']['streak']['targets']);
        Store::write('config', $cfg);
        self::$cache = $cfg;
        return $cfg;
    }

    // Cấu hình gửi ra trình duyệt: bỏ các trường bí mật
    public static function publicView()
    {
        $cfg = self::get(true);
        unset($cfg['admin'], $cfg['installed']);
        return $cfg;
    }
}
