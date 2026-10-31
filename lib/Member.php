<?php
// Thành viên: đăng ký, đăng nhập, quản lý (dùng chung session với admin)
class Member
{
    const STATUSES = ['active' => 'Hoạt động', 'pending' => 'Chờ duyệt', 'locked' => 'Bị khoá'];

    public static function validUsername($u)
    {
        return (bool) preg_match('/^[a-zA-Z0-9_.]{3,32}$/', $u);
    }

    public static function register($username, $password, $displayName, $byAdmin = false)
    {
        $cfg = Config::get()['members'];
        if (!$byAdmin && !$cfg['allowRegister']) {
            throw new RuntimeException('Đăng ký đang tạm đóng');
        }
        $username = strtolower(trim($username));
        if (!self::validUsername($username)) {
            throw new RuntimeException('Tên đăng nhập 3–32 ký tự: chữ, số, dấu _ hoặc .');
        }
        if (strlen($password) < 6) {
            throw new RuntimeException('Mật khẩu tối thiểu 6 ký tự');
        }
        if (Db::one('SELECT id FROM members WHERE username = ?', [$username])) {
            throw new RuntimeException('Tên đăng nhập đã có người dùng');
        }
        $status = !$byAdmin && $cfg['requireApproval'] ? 'pending' : 'active';
        Db::query('INSERT INTO members (username, password_hash, display_name, status, created_at) VALUES (?, ?, ?, ?, NOW())', [
            $username, password_hash($password, PASSWORD_DEFAULT), mb_substr(trim($displayName), 0, 64), $status,
        ]);
        Logger::info("Thành viên mới: $username" . ($status === 'pending' ? ' (chờ duyệt)' : ''));
        return $status;
    }

    public static function login($username, $password)
    {
        Auth::start();
        $m = Db::one('SELECT * FROM members WHERE username = ?', [strtolower(trim($username))]);
        if (!$m || !password_verify($password, $m['password_hash'])) {
            sleep(1);
            throw new RuntimeException('Sai tên đăng nhập hoặc mật khẩu');
        }
        if ($m['status'] === 'pending') {
            throw new RuntimeException('Tài khoản đang chờ admin duyệt');
        }
        if ($m['status'] !== 'active') {
            throw new RuntimeException('Tài khoản đã bị khoá');
        }
        session_regenerate_id(true);
        $_SESSION['member_id'] = (int) $m['id'];
        Db::query('UPDATE members SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$_SERVER['REMOTE_ADDR'] ?? '', $m['id']]);
        return $m;
    }

    // Thành viên đang đăng nhập (null nếu chưa / bị khoá)
    public static function current()
    {
        Auth::start();
        if (empty($_SESSION['member_id'])) {
            return null;
        }
        $m = Db::one('SELECT id, username, display_name, status, created_at, last_login_at FROM members WHERE id = ?', [$_SESSION['member_id']]);
        if (!$m || $m['status'] !== 'active') {
            unset($_SESSION['member_id']);
            return null;
        }
        return $m;
    }

    public static function logout()
    {
        Auth::start();
        unset($_SESSION['member_id']);
    }

    public static function changePassword($id, $current, $new)
    {
        $m = Db::one('SELECT password_hash FROM members WHERE id = ?', [$id]);
        if (!$m || !password_verify($current, $m['password_hash'])) {
            throw new RuntimeException('Mật khẩu hiện tại không đúng');
        }
        self::setPassword($id, $new);
    }

    public static function setPassword($id, $new)
    {
        if (strlen($new) < 6) {
            throw new RuntimeException('Mật khẩu mới tối thiểu 6 ký tự');
        }
        Db::query('UPDATE members SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $id]);
    }

    // ---- Dành cho admin ----
    public static function search($q, $status, $page = 1, $perPage = 50)
    {
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(username LIKE ? OR display_name LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if (isset(self::STATUSES[$status])) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int) $page);
        $total = (int) Db::one("SELECT COUNT(*) c FROM members $sqlWhere", $params)['c'];
        $rows = Db::all("SELECT id, username, display_name, status, note, created_at, last_login_at, last_login_ip
            FROM members $sqlWhere ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
        $counts = [];
        foreach (Db::all('SELECT status, COUNT(*) c FROM members GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'counts' => $counts];
    }

    public static function update($id, array $data)
    {
        if (isset($data['status'])) {
            if (!isset(self::STATUSES[$data['status']])) {
                throw new RuntimeException('Trạng thái không hợp lệ');
            }
            Db::query('UPDATE members SET status = ? WHERE id = ?', [$data['status'], $id]);
        }
        if (isset($data['note'])) {
            Db::query('UPDATE members SET note = ? WHERE id = ?', [mb_substr($data['note'], 0, 255), $id]);
        }
        if (isset($data['display_name'])) {
            Db::query('UPDATE members SET display_name = ? WHERE id = ?', [mb_substr($data['display_name'], 0, 64), $id]);
        }
    }

    public static function delete($id)
    {
        Db::query('DELETE FROM members WHERE id = ?', [$id]);
    }
}
