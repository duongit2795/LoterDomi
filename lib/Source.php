<?php
class Source
{
    const VNLP_BASE = 'https://www.vnlotteryplan.com/api/front/wl';

    private static function vnlpStatus($winStatus, $sort)
    {
        switch ((int) $winStatus) {
            case 0:
                return '❌ Trượt';
            case 1:
                return "✅ Trúng kỳ $sort";
            case 2:
                return "⏳ Chưa trúng, nuôi tiếp kỳ $sort";
        }
        return '';
    }

    public static function vnlpUrl(array $game)
    {
        $v = $game['vnlp'];
        return self::VNLP_BASE . '/plan/lotteryProject/queryLotteryPlanProject?' . http_build_query([
            'turnNumType' => $v['turnNumType'],
            'planNumberType' => $v['planNumberType'],
            'page' => 1,
            'pageSize' => 30, // đủ lịch sử để đếm chuỗi thắng/thua
            'gameId' => $v['gameId'],
            'play' => $v['play'],
            '_t' => Util::nowMs(),
        ]);
    }

    // Thông số request cho một game: [url, options]
    private static function request(array $game)
    {
        $cfg = Config::get();
        $headers = array_merge(
            ['Accept' => 'application/json, text/plain, */*'],
            (array) $cfg['globalHeaders'],
            (array) $game['headers']
        );
        $method = strtoupper($game['method'] ?: 'GET');
        $opts = ['method' => $method, 'headers' => $headers, 'timeout' => $cfg['monitor']['requestTimeoutSeconds']];
        if ($method !== 'GET' && $game['body'] !== '') {
            $opts['body'] = $game['body'];
            $hasType = false;
            foreach ($headers as $k => $_) {
                $hasType = $hasType || strtolower($k) === 'content-type';
            }
            if (!$hasType) {
                $opts['headers']['Content-Type'] = substr(ltrim($game['body']), 0, 1) === '{' ? 'application/json' : 'application/x-www-form-urlencoded';
            }
        }
        $url = $game['type'] === 'vnlp' ? self::vnlpUrl($game) : str_replace('{ts}', Util::nowMs(), $game['url']);
        return [$url, $opts];
    }

    private static function decode($status, $body)
    {
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("HTTP $status: " . mb_substr($body, 0, 200));
        }
        $data = json_decode($body, true);
        if (is_array($data)) {
            return $data;
        }
        // JSONP: callback({...})
        if (preg_match('/^[\w$.]+\(([\s\S]*)\)\s*;?\s*$/', trim($body), $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                return $data;
            }
        }
        throw new RuntimeException('Phản hồi không phải JSON: ' . mb_substr($body, 0, 200));
    }

    public static function fetchRaw(array $game)
    {
        list($url, $opts) = self::request($game);
        list($status, $body) = Util::http($url, $opts);
        return self::decode($status, $body);
    }

    // Gọi song song nhiều game, trả về [id => ['data' => ..] | ['error' => ..]]
    public static function fetchMany(array $games)
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($games as $game) {
            list($url, $opts) = self::request($game);
            $ch = curl_init();
            curl_setopt_array($ch, Util::curlOptions($url, $opts));
            curl_multi_add_handle($mh, $ch);
            $handles[$game['id']] = [$ch, $game];
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        $now = Util::nowMs();
        foreach ($handles as $id => list($ch, $game)) {
            try {
                $body = curl_multi_getcontent($ch);
                if ($body === null || $body === '' && curl_error($ch)) {
                    throw new RuntimeException('Lỗi kết nối: ' . curl_error($ch));
                }
                $raw = self::decode(curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) $body);
                $out[$id] = ['data' => self::extract($game, $raw, $now)];
            } catch (Exception $e) {
                $out[$id] = ['error' => $e->getMessage()];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    // Danh sách game + kiểu chơi có sẵn trên vnlotteryplan
    public static function vnlpGameList()
    {
        $raw = self::fetchRaw(Config::normalizeGame([
            'id' => 'list',
            'url' => self::VNLP_BASE . '/plan/lotteryProject/planGameList?_t={ts}',
        ]));
        if (empty($raw['success'])) {
            throw new RuntimeException(isset($raw['msg']) ? $raw['msg'] : 'planGameList lỗi');
        }
        $games = [];
        foreach (isset($raw['rows']) ? $raw['rows'] : [] as $g) {
            $games[] = [
                'gameId' => isset($g['gameId']) ? $g['gameId'] : $g['id'],
                'name' => $g['name'],
                'remark' => isset($g['remark']) ? $g['remark'] : '',
                'cate' => isset($g['cateName']) ? $g['cateName'] : '',
            ];
        }
        $plays = [];
        foreach (isset($raw['other']) ? $raw['other'] : [] as $p) {
            $plays[] = ['play' => $p['playCode'], 'playName' => $p['playName'], 'cate' => $p['cateName']];
        }
        return ['games' => $games, 'plays' => $plays];
    }

    public static function extract(array $game, array $raw, $nowMs = null)
    {
        $nowMs = $nowMs ?: Util::nowMs();
        if ($game['type'] === 'vnlp') {
            return self::extractVnlp($game, $raw, $nowMs);
        }
        $p = $game['paths'];
        return [
            'issue' => Util::str(Util::getPath($raw, $p['issue'])),
            'result' => Util::str(Util::getPath($raw, $p['result'])),
            'nextIssue' => Util::str(Util::getPath($raw, $p['nextIssue'])),
            'nextTime' => Util::toTimestamp(Util::getPath($raw, $p['nextTime']), $game['timeMode'], $nowMs),
            'planReady' => true,
        ];
    }

    private static function extractVnlp(array $game, array $raw, $nowMs)
    {
        if (empty($raw['success']) || empty($raw['t'])) {
            throw new RuntimeException('vnlotteryplan: ' . (isset($raw['msg']) ? $raw['msg'] : 'không có dữ liệu'));
        }
        $t = $raw['t'];
        $lot = isset($t['lottery']) && is_array($t['lottery']) ? $t['lottery'] : [];
        $rows = isset($t['resultDetail']['data']) && is_array($t['resultDetail']['data']) ? $t['resultDetail']['data'] : [];
        $turn = isset($lot['turnNum']) ? $lot['turnNum'] : '';
        // Dòng kế hoạch bị ảnh hưởng bởi kỳ vừa mở
        $hit = null;
        foreach ($rows as $r) {
            if (isset($r['turnNum']) && $r['turnNum'] === $turn) {
                $hit = $r;
                break;
            }
        }
        $spaced = function ($s) {
            return str_replace(',', ' ', (string) $s);
        };
        $serverTime = !empty($t['serverTime']) ? $t['serverTime'] : $nowMs;
        $nextTurn = isset($t['nextTurnNum']) ? (string) $t['nextTurnNum'] : '';
        // Kế hoạch kỳ tiếp đã sẵn sàng: dòng mới nhất đang nuôi và khoảng kỳ chứa kỳ tiếp
        $nextReady = false;
        if (isset($rows[0]['winStatus'], $rows[0]['turnNumRange']) && (int) $rows[0]['winStatus'] === 2
            && preg_match('/(\d+)\s*-\s*(\d+)/', $rows[0]['turnNumRange'], $rm) && preg_match('/(\d+)$/', $nextTurn, $nm)) {
            $nextReady = (int) $nm[1] >= (int) $rm[1] && (int) $nm[1] <= (int) $rm[2];
        }
        return [
            'issue' => Util::str($turn),
            'result' => Util::str(isset($lot['openNum']) ? $lot['openNum'] : ''),
            'nextIssue' => Util::str(isset($t['nextTurnNum']) ? $t['nextTurnNum'] : ''),
            // Bù lệch đồng hồ giữa máy chủ nguồn và máy chạy bot
            'nextTime' => !empty($t['nextOpenTime']) ? (int) ($t['nextOpenTime'] - $serverTime + $nowMs) : null,
            'play' => $game['vnlp']['playName'] ?: $game['vnlp']['play'],
            // Kế hoạch cho kỳ tiếp = dòng mới nhất đang nuôi (t.planNumber là dàn vừa đối chiếu, không phải dàn kỳ tiếp)
            'plan' => $nextReady ? $spaced($rows[0]['planNumber']) : '',
            'planRange' => $nextReady ? $rows[0]['turnNumRange'] : '',
            'planTurn' => $nextReady ? (string) $rows[0]['currentTurnSort'] : '',
            'planCheck' => $hit ? $spaced($hit['planNumber']) . ' (' . $hit['turnNumRange'] . ')' : '',
            'playResult' => $hit ? Util::str($hit['openNum']) : '',
            'planStatus' => $hit ? self::vnlpStatus($hit['winStatus'], $hit['currentTurnSort']) : '',
            'hitStatus' => $hit ? (string) $hit['winStatus'] : '',
            'hitTurn' => $hit ? (string) $hit['currentTurnSort'] : '',
            // Dữ liệu kế hoạch đã khớp với kỳ vừa mở và kỳ tiếp theo
            'planReady' => $hit !== null && $nextReady,
            'roadRows' => $rows,
        ] + self::streak($rows, $hit);
    }

    // Phân loại dàn đã kết thúc: win = trúng trong winMaxTurn kỳ đầu, lose = trượt,
    // late = trúng muộn hơn (tuỳ cài đặt: tính là thua hoặc làm đứt chuỗi), null = đang nuôi
    private static function planOutcome(array $r, $winMaxTurn)
    {
        $ws = isset($r['winStatus']) ? (int) $r['winStatus'] : 2;
        if ($ws === 0) {
            return 'lose';
        }
        if ($ws === 1) {
            $turn = isset($r['currentTurnSort']) ? (int) $r['currentTurnSort'] : 1;
            return $turn <= $winMaxTurn ? 'win' : 'late';
        }
        return null;
    }

    // Chuỗi thắng/thua liên tiếp của các dàn đã kết thúc (mới nhất trước).
    // streakNew = kỳ vừa mở làm kết thúc một dàn (chuỗi vừa thay đổi).
    private static function streak(array $rows, $hit)
    {
        $streakCfg = Config::get()['monitor']['streak'];
        $winMaxTurn = max(1, (int) $streakCfg['winMaxTurn']);
        $lateAsLose = $streakCfg['lateAs'] === 'lose';
        $type = null;
        $count = 0;
        $completed = 0;
        $broken = false;
        foreach ($rows as $r) {
            $o = self::planOutcome($r, $winMaxTurn);
            if ($o === null) {
                continue;
            }
            if ($o === 'late' && $lateAsLose) {
                $o = 'lose';
            }
            $completed++;
            if ($broken) {
                continue;
            }
            if ($type === null) {
                $type = $o;
            }
            if ($o !== $type || $o === 'late') {
                $broken = true;
                continue;
            }
            $count++;
        }
        if ($type === 'late') {
            $type = null;
            $count = 0;
        }
        return [
            'streakType' => $type === null ? '' : $type,
            'streakCount' => $count,
            // Cả lịch sử tải về đều cùng một chuỗi: thực tế có thể dài hơn
            'streakLabel' => $count && $count === $completed ? "$count+" : (string) $count,
            'streakNew' => $hit !== null && in_array((int) $hit['winStatus'], [0, 1], true),
        ];
    }
}
