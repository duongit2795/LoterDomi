<?php
// Cập nhật code từ GitHub: tải zip của nhánh, sao lưu code hiện tại rồi chép đè (giữ nguyên data/)
class Updater
{
    const KEEP_BACKUPS = 5;

    private static function settings()
    {
        $u = Config::get()['update'];
        if (!preg_match('#^[\w.-]+/[\w.-]+$#', $u['repo'])) {
            throw new RuntimeException('Repo GitHub không hợp lệ (dạng owner/repo)');
        }
        return $u;
    }

    private static function headers()
    {
        $u = Config::get()['update'];
        $h = ['User-Agent' => 'LoterDomi-Updater', 'Accept' => 'application/vnd.github+json'];
        if ($u['token']) {
            $h['Authorization'] = 'Bearer ' . $u['token'];
        }
        return $h;
    }

    public static function current()
    {
        return Store::read('version', ['sha' => '', 'message' => '', 'date' => '', 'updatedAt' => '']);
    }

    public static function check()
    {
        $u = self::settings();
        list($status, $body) = Util::http(
            "https://api.github.com/repos/{$u['repo']}/commits/" . rawurlencode($u['branch']),
            ['headers' => self::headers(), 'timeout' => 20]
        );
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || empty($data['sha'])) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP $status";
            if ($status === 404) {
                $msg .= ' – repo private thì cần nhập GitHub token';
            }
            throw new RuntimeException("GitHub: $msg");
        }
        $cur = self::current();
        return [
            'current' => $cur,
            'latest' => [
                'sha' => $data['sha'],
                'message' => $data['commit']['message'],
                'date' => $data['commit']['committer']['date'],
                'url' => $data['html_url'],
            ],
            'hasUpdate' => $cur['sha'] !== $data['sha'],
        ];
    }

    public static function apply()
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP thiếu extension zip – bật trong aaPanel: App Store → PHP → Install extensions → zip');
        }
        @set_time_limit(300);
        $info = self::check();
        $u = self::settings();
        $work = DATA_DIR . '/update_' . Util::randomId();
        @mkdir($work, 0775, true);
        try {
            $zipFile = "$work/src.zip";
            $fp = fopen($zipFile, 'wb');
            $ch = curl_init();
            curl_setopt_array($ch, Util::curlOptions(
                "https://api.github.com/repos/{$u['repo']}/zipball/" . rawurlencode($u['branch']),
                ['headers' => self::headers(), 'timeout' => 120, 'file' => $fp]
            ));
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $status !== 200) {
                throw new RuntimeException("Tải bản cập nhật lỗi: HTTP $status $err");
            }

            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('File zip tải về bị lỗi');
            }
            $zip->extractTo("$work/src");
            $zip->close();
            $dirs = glob("$work/src/*", GLOB_ONLYDIR);
            if (count($dirs) !== 1) {
                throw new RuntimeException('Cấu trúc zip không như mong đợi');
            }
            $root = $dirs[0];
            if (!is_file("$root/lib/bootstrap.php")) {
                throw new RuntimeException('Bản tải về không phải mã nguồn LoterDomi');
            }

            $files = self::listFiles($root);
            foreach ($files as $rel) {
                $target = APP_DIR . '/' . $rel;
                $dir = is_file($target) ? $target : dirname($target);
                while (!is_dir($dir)) {
                    $dir = dirname($dir);
                }
                if (!is_writable(is_file($target) ? $target : $dir)) {
                    throw new RuntimeException("Không có quyền ghi: $rel – chown thư mục site cho user www");
                }
            }

            $backup = self::backup();
            foreach ($files as $rel) {
                $target = APP_DIR . '/' . $rel;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                if (!copy("$root/$rel", $target)) {
                    throw new RuntimeException("Chép file lỗi: $rel (đã sao lưu ở $backup)");
                }
                Store::fixOwner($target);
            }
            // Xoá file đã bị gỡ khỏi repo so với lần cập nhật trước
            $removed = 0;
            $cur = self::current();
            foreach (isset($cur['files']) ? $cur['files'] : [] as $rel) {
                if (!in_array($rel, $files, true) && strpos($rel, 'data/') !== 0 && is_file(APP_DIR . '/' . $rel)) {
                    @unlink(APP_DIR . '/' . $rel) && $removed++;
                }
            }

            $latest = $info['latest'];
            Store::write('version', [
                'sha' => $latest['sha'],
                'message' => $latest['message'],
                'date' => $latest['date'],
                'updatedAt' => date('c'),
                'files' => $files,
            ]);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            Logger::info('Đã cập nhật lên ' . substr($latest['sha'], 0, 7) . ' – ' . strtok($latest['message'], "\n"));
            return ['updated' => count($files), 'removed' => $removed, 'backup' => basename($backup), 'version' => $latest];
        } finally {
            self::rmrf($work);
        }
    }

    // Liệt kê file (đường dẫn tương đối), bỏ qua data/ và file ẩn của git
    private static function listFiles($root)
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if (!$f->isFile() || strpos($rel, 'data/') === 0 || strpos($rel, 'cache/') === 0 || strpos($rel, '.git/') === 0 || $rel === '.user.ini') {
                continue;
            }
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    public static function backupDir()
    {
        $dir = DATA_DIR . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            file_put_contents("$dir/index.html", '');
        }
        return $dir;
    }

    // Nén code hiện tại (trừ data/) để có thể khôi phục
    public static function backup()
    {
        $file = self::backupDir() . '/code-' . date('Ymd-His') . '-' . Util::randomId(6) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Không tạo được file sao lưu');
        }
        foreach (self::listFiles(APP_DIR) as $rel) {
            $zip->addFile(APP_DIR . '/' . $rel, $rel);
        }
        $zip->close();
        Store::fixOwner($file);
        $all = glob(self::backupDir() . '/code-*.zip');
        rsort($all);
        foreach (array_slice($all, self::KEEP_BACKUPS) as $old) {
            @unlink($old);
        }
        return $file;
    }

    public static function backups()
    {
        $all = glob(self::backupDir() . '/code-*.zip');
        rsort($all);
        return array_map(function ($f) {
            return ['name' => basename($f), 'size' => filesize($f), 'time' => date('c', filemtime($f))];
        }, $all);
    }

    public static function restore($name)
    {
        if (!preg_match('/^code-[\w-]+\.zip$/', $name) || !is_file(self::backupDir() . "/$name")) {
            throw new RuntimeException('Không tìm thấy bản sao lưu');
        }
        $zip = new ZipArchive();
        if ($zip->open(self::backupDir() . "/$name") !== true) {
            throw new RuntimeException('File sao lưu bị lỗi');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rel = $zip->getNameIndex($i);
            if (strpos($rel, '..') !== false || strpos($rel, 'data/') === 0) {
                throw new RuntimeException('File sao lưu không hợp lệ');
            }
        }
        $zip->extractTo(APP_DIR);
        $zip->close();
        $v = self::current();
        $v['sha'] = '';
        $v['message'] = "Khôi phục từ $name";
        Store::write('version', $v);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        Logger::info("Đã khôi phục code từ $name");
    }

    private static function rmrf($path)
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $f) {
                if ($f !== '.' && $f !== '..') {
                    self::rmrf("$path/$f");
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
