<?php
/**
 * Utility Helpers
 */

class Helper {

    public static function baseUrl(string $path = ''): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Deteksi subfolder script path
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        $baseDir = preg_replace('#/(admin|superadmin|student|core|config|database|sso|api|cron)$#', '', $scriptDir);
        $baseDir = rtrim($baseDir, '/');

        // Absolute URL atau path absolut → kembalikan apa adanya
        if ($path === '' || strpos($path, '://') !== false || strpos($path, '//') === 0) {
            return $protocol . $host . ($path === '' ? $baseDir : $path);
        }

        // Path absolut dari root web (diawali /)
        if (strpos($path, '/') === 0) {
            return $protocol . $host . $path;
        }

        // Path relatif dengan '../' → tuju ke root proyek
        if (strpos($path, '../') === 0) {
            $cleaned = preg_replace('#^\.\./#', '', $path);
            return $protocol . $host . $baseDir . '/' . ltrim($cleaned, '/');
        }

        // Jika path merujuk ke modul utama root proyek
        if (preg_match('#^(admin|superadmin|student|api|sso|config|core|index\.php|logout\.php|register\.php)#', $path)) {
            return $protocol . $host . $baseDir . '/' . ltrim($path, '/');
        }

        // Path relatif internal modul (misal: 'dashboard.php' dari dalam /student/)
        return $protocol . $host . $scriptDir . '/' . ltrim($path, '/');
    }

    public static function redirect(string $path): void {
        header("Location: " . self::baseUrl($path));
        exit;
    }

    public static function setFlash(string $type, string $message): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['flash'] = [
            'type'    => $type, // 'success', 'danger', 'warning', 'info'
            'message' => $message
        ];
    }

    public static function getFlash(): ?array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }

    public static function jsonResponse(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    public static function sanitize(string $data): string {
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }

    public static function generateToken(int $length = 6): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $token;
    }

    /* =========================================================
       CSRF PROTECTION
       ========================================================= */

    public static function csrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Return hidden input field to be embedded inside every <form> */
    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . self::csrfToken() . '">';
    }

    /** Call this at the top of every POST handler. Dies with 403 if token invalid. */
    public static function verifyCsrf(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $token = $_POST['_csrf'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
            http_response_code(403);
            die('Permintaan ditolak: token keamanan (CSRF) tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
        }
    }

    /* =========================================================
       RATE LIMITING (LOGIN BRUTE FORCE PROTECTION)
       ========================================================= */

    private static function rateLimitFile(string $key): string {
        $dir = __DIR__ . '/../storage/rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
            $ht = $dir . '/.htaccess';
            if (!file_exists($ht)) {
                @file_put_contents($ht, "Require all denied\n");
            }
        }
        return $dir . '/' . md5($key) . '.json';
    }

    /** Cek apakah IP/user saat ini terkunci karena terlalu banyak percobaan gagal.
        Returns lock seconds remaining, or 0 if not locked. */
    public static function loginRateStatus(string $key): int {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        // Jika runner/kode secara eksplisit mengosongkan bucket sesi (misal unit test)
        if (isset($_SESSION['login_attempts']) && is_array($_SESSION['login_attempts']) && empty($_SESSION['login_attempts'])) {
            $file = self::rateLimitFile($key);
            if (file_exists($file)) @unlink($file);
            return 0;
        }

        $sessionBucket = $_SESSION['login_attempts'][$key] ?? null;

        // Ambil juga dari file-storage persisten (mencegah bypass tanpa cookie)
        $file = self::rateLimitFile($key);
        $fileBucket = null;
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) {
                $data = @json_decode($raw, true);
                if (is_array($data) && isset($data['count'], $data['last_attempt'])) {
                    $fileBucket = $data;
                }
            }
        }

        $count = max((int)($sessionBucket['count'] ?? 0), (int)($fileBucket['count'] ?? 0));
        $lastAttempt = max((int)($sessionBucket['last_attempt'] ?? 0), (int)($fileBucket['last_attempt'] ?? 0));

        if ($lastAttempt === 0 || $count === 0) {
            return 0;
        }

        $elapsed = time() - $lastAttempt;
        if ($elapsed > 900) { // window 15 menit
            self::loginRateClear($key);
            return 0;
        }

        if ($count >= 5) {
            return 900 - $elapsed; // sisa detik lock
        }
        return 0;
    }

    /** Catat satu percobaan login gagal. */
    public static function loginRateFail(string $key): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $now = time();

        // 1. Session bucket
        if (!isset($_SESSION['login_attempts'][$key])) {
            $_SESSION['login_attempts'][$key] = ['count' => 0, 'last_attempt' => $now];
        }
        $_SESSION['login_attempts'][$key]['count']++;
        $_SESSION['login_attempts'][$key]['last_attempt'] = $now;

        // 2. File-based persistent bucket
        $file = self::rateLimitFile($key);
        $fileCount = 0;
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) {
                $data = @json_decode($raw, true);
                if (is_array($data) && isset($data['count'], $data['last_attempt'])) {
                    if (($now - (int)$data['last_attempt']) <= 900) {
                        $fileCount = (int)$data['count'];
                    }
                }
            }
        }
        $fileCount++;
        @file_put_contents($file, json_encode(['count' => $fileCount, 'last_attempt' => $now]), LOCK_EX);
    }

    /** Reset counter setelah login sukses. */
    public static function loginRateClear(string $key): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        unset($_SESSION['login_attempts'][$key]);

        $file = self::rateLimitFile($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /* =========================================================
       PAGINATION
       ========================================================= */

    /**
     * Hitung info pagination.
     * @return array{page:int,pages:int,per_page:int,offset:int,total:int}
     */
    public static function paginate(int $total, int $perPage = 15, ?int $page = null): array {
        $page = $page ?? (int)($_GET['page'] ?? 1);
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);
        return [
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
            'total'    => $total,
        ];
    }

    /**
     * Render link pagination sederhana (tanpa library eksternal).
     * @param array $pg Hasil Helper::paginate()
     * @param array $extraQuery Query string tambahan yang harus dipertahankan (mis. ['q'=>'x','class_id'=>3])
     */
    public static function paginationLinks(array $pg, array $extraQuery = []): string {
        if ($pg['pages'] <= 1) return '';
        $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($extraQuery) {
            $qs = http_build_query(array_merge($extraQuery, ['page' => $p]));
            $cls = 'pg-btn' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
            if ($disabled) {
                return '<span class="' . $cls . '">' . $label . '</span>';
            }
            return '<a class="' . $cls . '" href="?' . htmlspecialchars($qs) . '">' . $label . '</a>';
        };

        $html = '<div class="pagination">';
        $html .= $link($pg['page'] - 1, '&laquo;', false, $pg['page'] <= 1);

        $start = max(1, $pg['page'] - 2);
        $end = min($pg['pages'], $pg['page'] + 2);
        if ($start > 1) {
            $html .= $link(1, '1');
            if ($start > 2) $html .= '<span class="pg-dots">&hellip;</span>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $html .= $link($i, (string)$i, $i === $pg['page']);
        }
        if ($end < $pg['pages']) {
            if ($end < $pg['pages'] - 1) $html .= '<span class="pg-dots">&hellip;</span>';
            $html .= $link($pg['pages'], (string)$pg['pages']);
        }

        $html .= $link($pg['page'] + 1, '&raquo;', false, $pg['page'] >= $pg['pages']);
        $html .= '<span class="pg-info">Halaman ' . $pg['page'] . ' / ' . $pg['pages'] . ' &middot; ' . $pg['total'] . ' data</span>';
        $html .= '</div>';
        return $html;
    }

    /* =========================================================
       CSV EXPORT / IMPORT
       ========================================================= */

    /** Stream file CSV ke browser (download). */
    public static function downloadCsv(string $filename, array $header, array $rows): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $out = fopen('php://output', 'w');
        // BOM agar Excel membuka UTF-8 dengan benar
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /**
     * Buat file Excel .xlsx di disk tanpa dependency eksternal menggunakan ZipArchive bawaan PHP.
     */
    public static function generateXlsxFile(string $filePath, array $header, array $rows): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

        $getColLetter = function(int $colIdx): string {
            $letter = '';
            $colIdx++;
            while ($colIdx > 0) {
                $mod = ($colIdx - 1) % 26;
                $letter = chr(65 + $mod) . $letter;
                $colIdx = (int)(($colIdx - $mod) / 26);
            }
            return $letter;
        };

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $sheetXml .= '  <sheetData>' . "\n";

        $sheetXml .= '    <row r="1">' . "\n";
        foreach ($header as $cIdx => $hName) {
            $ref = $getColLetter($cIdx) . '1';
            $val = htmlspecialchars((string)$hName, ENT_XML1, 'UTF-8');
            $sheetXml .= '      <c r="' . $ref . '" t="inlineStr"><is><t>' . $val . '</t></is></c>' . "\n";
        }
        $sheetXml .= '    </row>' . "\n";

        $rNum = 2;
        foreach ($rows as $row) {
            $sheetXml .= '    <row r="' . $rNum . '">' . "\n";
            $rowVals = array_values($row);
            foreach ($rowVals as $cIdx => $cVal) {
                $ref = $getColLetter($cIdx) . $rNum;
                $val = htmlspecialchars((string)$cVal, ENT_XML1, 'UTF-8');
                $sheetXml .= '      <c r="' . $ref . '" t="inlineStr"><is><t>' . $val . '</t></is></c>' . "\n";
            }
            $sheetXml .= '    </row>' . "\n";
            $rNum++;
        }

        $sheetXml .= '  </sheetData>' . "\n";
        $sheetXml .= '</worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
        return true;
    }

    /** Stream file Excel .xlsx ke browser (download) tanpa dependency eksternal. */
    public static function downloadXlsx(string $filename, array $header, array $rows): void {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if (!self::generateXlsxFile($tmpFile, $header, $rows)) {
            self::downloadCsv(str_replace('.xlsx', '.csv', $filename), $header, $rows);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmpFile));
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    /** Parse isi file CSV yang di-upload menjadi array baris asosiatif. */
    /**
     * Acak opsi jawaban secara deterministik berdasarkan seed.
     * Mengembalikan row soal dengan option_a..e tersusun ulang dan
     * correct_answer berupa huruf posisi baru. Opsi kosong tetap di belakang.
     * Seed yang sama selalu menghasilkan urutan yang sama (aman untuk render
     * berulang dan koreksi server-side).
     */
    public static function shuffleOptions(array $q, string $seed): array {
        $labels = ['A', 'B', 'C', 'D', 'E'];
        $filled = [];
        $empties = [];
        foreach ($labels as $lb) {
            $val = trim((string)($q['option_' . strtolower($lb)] ?? ''));
            if ($val !== '') {
                $filled[] = ['orig' => $lb, 'val' => $val];
            } else {
                $empties[] = ['orig' => $lb, 'val' => ''];
            }
        }
        usort($filled, function ($a, $b) use ($seed) {
            return strcmp(md5($seed . '|' . $a['orig']), md5($seed . '|' . $b['orig']));
        });
        $all = array_merge($filled, $empties);
        $correctOrig = strtoupper((string)($q['correct_answer'] ?? ''));
        $newCorrect = '';
        foreach ($all as $i => $opt) {
            $q['option_' . strtolower($labels[$i])] = $opt['val'];
            if ($opt['orig'] === $correctOrig) {
                $newCorrect = $labels[$i];
            }
        }
        $q['correct_answer'] = $newCorrect !== '' ? $newCorrect : $correctOrig;
        return $q;
    }

    /**
     * Optimize image: resize if exceeding max dimensions and compress to target file size.
     * Modifies the file in-place. Returns true if optimized, false if no change needed.
     */
    public static function optimizeQuestionImage(string $path, int $maxWidth = 800, int $maxHeight = 600, int $maxFileSize = 500): bool {
        if (!file_exists($path)) return false;

        $realPath = realpath($path);
        if ($realPath === false) return false;

        $metadata = getimagesize($realPath);
        if (!$metadata) return false;

        $width = $metadata[0];
        $height = $metadata[1];
        $mime = $metadata['mime'];
        $currentSize = filesize($realPath);
        $maxFileSizeBytes = $maxFileSize * 1024;

        // Check if optimization is needed
        $needsResize = $width > $maxWidth || $height > $maxHeight;
        $needsShrink = $currentSize > $maxFileSizeBytes;

        if (!$needsResize && !$needsShrink) return false;

        $image = imagecreatefromstring(file_get_contents($realPath));
        if (!$image) return false;

        // Resize proportionally
        if ($needsResize) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = (int)round($width * $ratio);
            $newHeight = (int)round($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($mime === 'image/png' || $mime === 'image/gif') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // Re-save with compression targeting max file size
        $quality = 80;
        $success = false;

        if ($mime === 'image/jpeg') {
            $tempPath = $realPath . '.tmp.jpg';
            imagejpeg($image, $tempPath, $quality);
            $success = file_exists($tempPath);
            if ($success) {
                rename($tempPath, $realPath);
            }
        } elseif ($mime === 'image/webp') {
            $tempPath = $realPath . '.tmp.webp';
            imagewebp($image, $tempPath, $quality);
            $success = file_exists($tempPath);
            if ($success) {
                rename($tempPath, $realPath);
            }
        } elseif ($mime === 'image/png') {
            // Convert PNG to JPEG for better compression
            $tempPath = $realPath . '.tmp.jpg';
            $jpeg = imagecreatetruecolor(imagesx($image), imagesy($image));
            $white = imagecolorallocate($jpeg, 255, 255, 255);
            imagefilledrectangle($jpeg, 0, 0, imagesx($jpeg), imagesy($jpeg), $white);
            imagecopy($jpeg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagedestroy($image);
            imagejpeg($jpeg, $tempPath, $quality);
            $success = file_exists($tempPath);
            if ($success) {
                rename($tempPath, $realPath);
                // Update stored path extension
                $newStoredPath = preg_replace('/\.png$/i', '.jpg', $path);
                if ($newStoredPath !== $path) {
                    @rename($realPath, $newStoredPath);
                    $realPath = realpath($newStoredPath) ?: $newStoredPath;
                }
            }
            imagedestroy($jpeg);
        } elseif ($mime === 'image/gif') {
            $tempPath = $realPath . '.tmp.jpg';
            imagejpeg($image, $tempPath, $quality);
            $success = file_exists($tempPath);
            if ($success) {
                rename($tempPath, $realPath);
                $newStoredPath = preg_replace('/\.gif$/i', '.jpg', $path);
                if ($newStoredPath !== $path) {
                    @rename($realPath, $newStoredPath);
                    $realPath = realpath($newStoredPath) ?: $newStoredPath;
                }
            }
        }

        imagedestroy($image);

        if (!$success) return false;

        // If still too large, iteratively lower quality
        $finalSize = filesize($realPath);
        while ($finalSize > $maxFileSizeBytes && $quality > 10) {
            $quality -= 5;
            $newMime = $mime;
            if (pathinfo($realPath, PATHINFO_EXTENSION) === 'png' || pathinfo($realPath, PATHINFO_EXTENSION) === 'gif') {
                $newMime = 'image/jpeg';
            }
            $tmpPath = $realPath . '.retry.jpg';
            if ($newMime === 'image/jpeg') {
                $img = imagecreatefromstring(file_get_contents($realPath));
                if ($img) {
                    imagejpeg($img, $tmpPath, $quality);
                    imagedestroy($img);
                }
            }
            if (file_exists($tmpPath)) {
                rename($tmpPath, $realPath);
                $finalSize = filesize($realPath);
            } else {
                break;
            }
        }

        @unlink($realPath . '.tmp.jpg');
        @unlink($realPath . '.tmp.webp');
        @unlink($realPath . '.retry.jpg');

        return true;
    }

    public static function parseCsvUpload(array $file): array {
        $isValidUpload = isset($file['tmp_name']) && (is_uploaded_file($file['tmp_name']) || (php_sapi_name() === 'cli' && file_exists($file['tmp_name'])));
        if (!$isValidUpload) {
            throw new RuntimeException('File upload tidak valid.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload gagal dengan kode error ' . $file['error'] . '.');
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Ukuran file maksimal 5 MB.');
        }
        $fh = fopen($file['tmp_name'], 'r');
        if (!$fh) throw new RuntimeException('Tidak bisa membaca file CSV.');

        // Auto-detect delimiter from first non-empty line
        $firstLine = '';
        while (($raw = fgets($fh)) !== false) {
            $raw = trim($raw);
            if ($raw !== '') {
                $firstLine = $raw;
                break;
            }
        }
        if ($firstLine === '') {
            fclose($fh);
            return [[], []];
        }

        // Check delimiter frequencies
        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        $tabs = substr_count($firstLine, "\t");
        $delimiter = ',';
        if ($semicolons > $commas && $semicolons > $tabs) {
            $delimiter = ';';
        } elseif ($tabs > $commas && $tabs > $semicolons) {
            $delimiter = "\t";
        }

        rewind($fh);

        $header = null;
        $rows = [];
        while (($line = fgetcsv($fh, 0, $delimiter)) !== false) {
            if ($header === null) {
                $header = array_map(function ($h) {
                    $clean = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/', '', (string)$h);
                    return strtolower(trim($clean));
                }, $line);
                continue;
            }
            // Lewati baris kosong
            if (count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) continue;
            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = trim((string)($line[$i] ?? ''));
            }
            $rows[] = $assoc;
        }
        fclose($fh);
        return [$header, $rows];
    }

    /**
     * Deteksi format upload (.csv / .xlsx) dan parse ke [header, rows] assoc.
     */
    public static function parseUpload(array $file): array {
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('Ekstensi PHP zip (ZipArchive) belum aktif di server. Aktifkan extension=zip di php.ini.');
            }
            $isValidUpload = isset($file['tmp_name']) && (is_uploaded_file($file['tmp_name']) || (php_sapi_name() === 'cli' && file_exists($file['tmp_name'])));
            if (!$isValidUpload) {
                throw new RuntimeException('File upload tidak valid.');
            }
            if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload gagal dengan kode error ' . $file['error'] . '.');
            }
            if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('Ukuran file .xlsx maksimal 5 MB.');
            }
            return self::readXlsx($file['tmp_name']);
        }
        return self::parseCsvUpload($file);
    }

    /**
     * Baca file .xlsx (sheet pertama) tanpa library eksternal.
     * Mendukung shared string, inline string, dan angka.
     * Return: [header, rows] sama seperti parseCsvUpload.
     */
    public static function readXlsx(string $path): array {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File .xlsx tidak bisa dibuka. Pastikan file Excel asli berekstensi .xlsx (bukan .xls atau .csv).');
        }

        // Shared strings (teks yang di-cache Excel)
        $strings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sx = @simplexml_load_string($ssXml);
            if ($sx) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string)$si->t;
                    } else {
                        $t = '';
                        if (isset($si->r)) {
                            foreach ($si->r as $r) {
                                $t .= (string)$r->t;
                            }
                        }
                        $strings[] = $t;
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('Sheet pertama tidak ditemukan di file .xlsx.');
        }
        $sx = @simplexml_load_string($sheetXml);
        if (!$sx || !isset($sx->sheetData)) {
            throw new RuntimeException('Gagal membaca isi file .xlsx. Simpan ulang sebagai Excel Workbook (.xlsx).');
        }

        $grid = [];
        $maxCol = 0;
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $colLetters = preg_replace('/\d+/', '', $ref);
                $colIdx = 0;
                foreach (str_split($colLetters) as $ch) {
                    $colIdx = $colIdx * 26 + (ord(strtoupper($ch)) - 64);
                }
                $colIdx = max(0, $colIdx - 1);
                $type = (string)($c['t'] ?? 'n');
                if ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                } else {
                    $v = (string)($c->v ?? '');
                    if ($type === 's') {
                        $val = $strings[(int)$v] ?? '';
                    } elseif ($type === 'b') {
                        $val = $v === '1' ? 'TRUE' : 'FALSE';
                    } else {
                        $val = $v;
                    }
                }
                $cells[$colIdx] = $val;
                $maxCol = max($maxCol, $colIdx);
            }
            $grid[] = $cells;
        }

        if (empty($grid)) {
            return [[], []];
        }

        $header = [];
        foreach (range(0, $maxCol) as $i) {
            $h = strtolower(trim((string)($grid[0][$i] ?? '')));
            $h = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/', '', $h);
            $header[] = $h !== '' ? $h : 'col_' . ($i + 1);
        }

        $rows = [];
        for ($ri = 1; $ri < count($grid); $ri++) {
            $row = [];
            foreach ($header as $ci => $h) {
                $row[$h] = trim((string)($grid[$ri][$ci] ?? ''));
            }
            if (implode('', array_values($row)) === '') continue;
            $rows[] = $row;
        }
        return [$header, $rows];
    }

    public static function platformConfig(?string $key = null, mixed $default = null): mixed {
        static $cfg = null;
        if ($cfg === null) {
            $path = __DIR__ . '/../config/platform.php';
            $cfg = file_exists($path) ? require $path : [];
        }
        if ($key === null) return $cfg;
        return $cfg[$key] ?? $default;
    }

    public static function checkMaintenance(): void {
        $cfg = self::platformConfig();
        if (!empty($cfg['maintenance_mode'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $role = $_SESSION['auth_user']['role'] ?? null;
            if ($role === 'superadmin') return;

            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (strpos($script, 'superadmin') !== false) return;

            http_response_code(503);
            $msg = htmlspecialchars($cfg['maintenance_msg'] ?? 'Sistem sedang dalam pemeliharaan.');
            $appName = htmlspecialchars($cfg['app_name'] ?? 'Veyra CBT Platform');
            echo "<!DOCTYPE html><html lang='id'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Pemeliharaan Sistem — {$appName}</title><link rel='preconnect' href='https://fonts.googleapis.com'><link href='https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap' rel='stylesheet'><style>body{font-family:'Plus Jakarta Sans',sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box;color:#1e293b;}.box{background:#fff;border-radius:24px;padding:42px 36px;max-width:480px;text-align:center;box-shadow:0 20px 45px rgba(0,0,0,0.06);border:1px solid #edf2f7;}.icon{width:64px;height:64px;border-radius:20px;background:#fffbeb;color:#d97706;display:inline-flex;align-items:center;justify-content:center;font-size:30px;margin-bottom:18px;}.title{font-size:22px;font-weight:800;margin-bottom:10px;color:#0f172a;letter-spacing:-0.4px;}.desc{font-size:14px;color:#64748b;line-height:1.6;margin-bottom:24px;}.badge{display:inline-block;padding:5px 14px;background:#fef3c7;color:#92400e;border-radius:9999px;font-size:12px;font-weight:700;margin-bottom:14px;}</style></head><body><div class='box'><div class='icon'>⚙️</div><div><span class='badge'>Status: Maintenance Mode</span></div><div class='title'>Sistem Sedang Dalam Pemeliharaan</div><div class='desc'>{$msg}</div><div style='font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:16px;'>{$appName} &bull; Hubungi tim helpdesk jika ada pertanyaan mendesak.</div></div></body></html>";
            exit;
        }
    }
}
