<?php
// Vẽ ảnh PNG bảng cầu W/L của 1 game (PHP GD + FreeType) để gửi lên Telegram.
// Vẽ ở kích thước gấp đôi rồi thu nhỏ để bóng tròn và chữ mịn.
class RoadImage
{
    const ROWS = 6;
    const COLS = 28;      // số cột cầu hiển thị (gần nhất)
    const WINDOW = 200;   // số kỳ dùng để đếm WIN/LOST
    const S = 2;          // hệ số vẽ phóng to

    private $im;
    private $font;
    private $fontBold;

    public static function available()
    {
        return function_exists('imagettftext') && function_exists('imagepng');
    }

    private function c($hex, $alpha = 0)
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocatealpha($this->im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    private function text($x, $y, $size, $color, $str, $bold = false, $align = 'left')
    {
        $f = $bold ? $this->fontBold : $this->font;
        $size *= self::S;
        $box = imagettfbbox($size, 0, $f, $str);
        $w = $box[2] - $box[0];
        if ($align === 'right') {
            $x -= $w / self::S;
        } elseif ($align === 'center') {
            $x -= $w / 2 / self::S;
        }
        imagettftext($this->im, $size, 0, (int) ($x * self::S), (int) ($y * self::S), $color, $f, $str);
        return $w / self::S;
    }

    private function roundRect($x1, $y1, $x2, $y2, $r, $color)
    {
        $s = self::S;
        list($x1, $y1, $x2, $y2, $r) = [$x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, $r * $s];
        imagefilledrectangle($this->im, (int) ($x1 + $r), (int) $y1, (int) ($x2 - $r), (int) $y2, $color);
        imagefilledrectangle($this->im, (int) $x1, (int) ($y1 + $r), (int) $x2, (int) ($y2 - $r), $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as $p) {
            imagefilledellipse($this->im, (int) $p[0], (int) $p[1], (int) (2 * $r), (int) (2 * $r), $color);
        }
    }

    // Bóng tròn có khối: tô các vòng tròn từ ngoài (tối) vào trong (sáng), tâm lệch lên trên-trái
    private function ball($cx, $cy, $r, $type, $last)
    {
        $s = self::S;
        list($cx, $cy, $r) = [$cx * $s, $cy * $s, $r * $s];
        $stops = $type === 'W' ? [[8, 122, 8], [12, 163, 12], [110, 226, 110]] : [[150, 39, 39], [208, 59, 59], [240, 133, 133]];
        if ($last) {
            imagefilledellipse($this->im, (int) $cx, (int) $cy, (int) (2 * $r + 9 * $s), (int) (2 * $r + 9 * $s), $this->c('#f0efec'));
            imagefilledellipse($this->im, (int) $cx, (int) $cy, (int) (2 * $r + 4 * $s), (int) (2 * $r + 4 * $s), $this->c('#0f141b'));
        }
        imagefilledellipse($this->im, (int) $cx, (int) ($cy + 1.5 * $s), (int) (2 * $r), (int) (2 * $r), $this->c('#000000', 90));
        for ($k = $r; $k > 0; $k -= 1) {
            $t = 1 - $k / $r; // 0 ở viền → 1 ở tâm sáng
            $a = $t < 0.5 ? $stops[0] : $stops[1];
            $b = $t < 0.5 ? $stops[1] : $stops[2];
            $u = $t < 0.5 ? $t * 2 : ($t - 0.5) * 2;
            $col = imagecolorallocate($this->im, (int) ($a[0] + ($b[0] - $a[0]) * $u), (int) ($a[1] + ($b[1] - $a[1]) * $u), (int) ($a[2] + ($b[2] - $a[2]) * $u));
            $off = $t * $r * 0.32;
            imagefilledellipse($this->im, (int) ($cx - $off), (int) ($cy - $off), (int) (2 * $k), (int) (2 * $k), $col);
        }
    }

    // Xếp kiểu big road (giống assets/road.js)
    public static function layout(array $types)
    {
        $used = [];
        $out = [];
        $c = -1;
        $r = 0;
        $startCol = -1;
        $prev = null;
        foreach ($types as $i => $t) {
            if ($t !== $prev) {
                $c = $startCol + 1;
                while (isset($used["$c,0"])) {
                    $c++;
                }
                $startCol = $c;
                $r = 0;
            } elseif ($r + 1 < self::ROWS && !isset($used[$c . ',' . ($r + 1)])) {
                $r++;
            } else {
                $c++;
                while (isset($used["$c,$r"])) {
                    $c++;
                }
            }
            $used["$c,$r"] = true;
            $out[] = [$c, $r, $t, $i];
            $prev = $t;
        }
        return $out;
    }

    // $road: các kỳ [mã kỳ, 'W'|'L', ...] cũ → mới; $s: state của game (nextIssue, plan...)
    public function render($gameName, array $road, array $s)
    {
        $this->font = APP_DIR . '/assets/fonts/DejaVuSans.ttf';
        $this->fontBold = APP_DIR . '/assets/fonts/DejaVuSans-Bold.ttf';
        $road = array_slice($road, -self::WINDOW);
        $types = array_column($road, 1);
        $n = count($types);
        $wins = count(array_filter($types, function ($t) {
            return $t === 'W';
        }));
        $losses = $n - $wins;
        $pct = $n ? (int) round($wins * 100 / $n) : 0;
        $lastType = $n ? $types[$n - 1] : null;
        $streak = 0;
        for ($i = $n - 1; $i >= 0 && $types[$i] === $lastType; $i--) {
            $streak++;
        }
        $placed = self::layout($types);
        $maxCol = $placed ? max(array_column($placed, 0)) : 0;
        $startCol = max(0, $maxCol - self::COLS + 1);

        $cell = 44;
        $pad = 24;
        $W = $pad * 2 + self::COLS * $cell;
        $gridTop = 112;
        $gridH = self::ROWS * $cell;
        $H = $gridTop + $gridH + 70;
        $s2 = self::S;
        $big = imagecreatetruecolor($W * $s2, $H * $s2);
        $this->im = $big;
        imagealphablending($big, true);
        imagefilledrectangle($big, 0, 0, $W * $s2, $H * $s2, $this->c('#18202b'));

        // Đầu ảnh: tên game + chuỗi hiện tại
        $this->text($pad, 40, 22, $this->c('#f0efec'), $gameName, true);
        if ($lastType) {
            $label = ($lastType === 'W' ? 'WIN ' : 'LOST ') . $streak . ' kỳ';
            $pillColor = $lastType === 'W' ? '#0ca30c' : '#d03b3b';
            $tw = imagettfbbox(15 * $s2, 0, $this->fontBold, $label);
            $pw = ($tw[2] - $tw[0]) / $s2 + 28;
            // Màu nền đặc (đã pha sẵn với nền thẻ) để các mảnh hình chồng nhau không bị đậm màu
            $this->roundRect($W - $pad - $pw, 16, $W - $pad, 48, 16, $this->c($lastType === 'W' ? '#163a20' : '#3e2228'));
            $this->text($W - $pad - $pw / 2, 38, 15, $this->c($pillColor === '#0ca30c' ? '#4fd34f' : '#f07a7a'), $label, true, 'center');
        }
        // Thanh tỉ lệ WIN/LOST + số
        $barY = 62;
        $barW = $W - 2 * $pad;
        $this->roundRect($pad, $barY, $pad + $barW, $barY + 8, 4, $this->c('#d03b3b'));
        if ($pct > 0) {
            $this->roundRect($pad, $barY, $pad + max(8, $barW * $pct / 100), $barY + 8, 4, $this->c('#0ca30c'));
        }
        $x = $pad + $this->text($pad, 94, 14, $this->c('#94a3b8'), 'WIN ');
        $x += $this->text($x, 94, 16, $this->c('#3fcf3f'), (string) $wins, true);
        $this->text($x, 94, 14, $this->c('#94a3b8'), "  ·  $pct%");
        $rx = $W - $pad - $this->text($W - $pad, 94, 14, $this->c('#94a3b8'), ' LOST', false, 'right');
        $rx -= $this->text($rx, 94, 16, $this->c('#ef6b6b'), (string) $losses, true, 'right');
        $this->text($rx, 94, 14, $this->c('#94a3b8'), (100 - $pct) . '%  ·  ', false, 'right');

        // Lưới cầu
        $this->roundRect($pad - 2, $gridTop - 2, $W - $pad + 2, $gridTop + $gridH + 2, 10, $this->c('#0f141b'));
        $line = $this->c('#2a3442');
        for ($i = 1; $i < self::ROWS; $i++) {
            imagefilledrectangle($big, $pad * $s2, ($gridTop + $i * $cell) * $s2, ($W - $pad) * $s2, ($gridTop + $i * $cell) * $s2 + 1, $line);
        }
        for ($j = 1; $j < self::COLS; $j++) {
            imagefilledrectangle($big, ($pad + $j * $cell) * $s2, $gridTop * $s2, ($pad + $j * $cell) * $s2 + 1, ($gridTop + $gridH) * $s2, $line);
        }
        foreach ($placed as $p) {
            list($c, $r, $t, $idx) = $p;
            if ($c < $startCol) {
                continue;
            }
            $cx = $pad + ($c - $startCol) * $cell + $cell / 2;
            $cy = $gridTop + $r * $cell + $cell / 2;
            $this->ball($cx, $cy, $cell / 2 - 5, $t, $idx === $n - 1);
            $this->text($cx, $cy + 6, 15, $this->c('#ffffff'), $t, true, 'center');
        }
        if (!$n) {
            $this->text($W / 2, $gridTop + $gridH / 2, 16, $this->c('#94a3b8'), 'Chưa có dữ liệu', false, 'center');
        }

        // Chân ảnh: kỳ tiếp + dàn số + giờ
        $fy = $gridTop + $gridH + 44;
        $x = $pad;
        if (!empty($s['nextIssue'])) {
            $x += $this->text($x, $fy, 15, $this->c('#94a3b8'), 'Kỳ tiếp ');
            $x += $this->text($x, $fy, 16, $this->c('#f0efec'), preg_replace('/^.*-/', '', $s['nextIssue']), true);
        }
        $plan = array_filter(preg_split('/\s+/', (string) ($s['plan'] ?? '')), 'strlen');
        if ($plan) {
            $x += $this->text($x, $fy, 15, $this->c('#94a3b8'), '   Dàn ');
            foreach ($plan as $num) {
                $this->roundRect($x + 2, $fy - 22, $x + 32, $fy + 8, 7, $this->c('#22385a'));
                $this->text($x + 17, $fy, 16, $this->c('#f0efec'), $num, true, 'center');
                $x += 36;
            }
        }
        $this->text($W - $pad, $fy, 13, $this->c('#94a3b8'), date('H:i:s d/m/Y'), false, 'right');

        // Thu nhỏ về kích thước thật
        $out = imagecreatetruecolor($W, $H);
        imagecopyresampled($out, $big, 0, 0, 0, 0, $W, $H, $W * $s2, $H * $s2);
        imagedestroy($big);
        ob_start();
        imagepng($out, null, 6);
        imagedestroy($out);
        return ob_get_clean();
    }
}
