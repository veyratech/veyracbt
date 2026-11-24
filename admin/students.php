<?php
/**
 * Master Data Siswa per Tenant
 */

$pageTitle = "Data Siswa";
require_once __DIR__ . '/_bootstrap.php';

$package = strtolower((string)($school['package'] ?? 'semester'));

$packageLabels = [
    'semester'   => 'Paket Semesteran',
    'tahunan'    => 'Paket 1 Tahun Penuh',
    '1tahun'     => 'Paket 1 Tahun Penuh',
    'super'      => 'Paket Super',
    'basic'      => 'Paket Semesteran',
    'pro'        => 'Paket 1 Tahun Penuh',
    'enterprise' => 'Paket Super',
];
$packageLabel = $packageLabels[$package] ?? ('Paket ' . ucfirst($package));

// Aturan Veyra Educate: Hanya Paket 1 Tahun dan Paket Super yang bisa mencetak kartu
$isPremium = in_array($package, ['tahunan', 'super', '1tahun', '1_tahun', 'pro', 'enterprise'], true);

// URL Portal Veyra dinamis untuk upgrade paket
$ssoConfigFile = __DIR__ . '/../config/sso.php';
$ssoConfig = file_exists($ssoConfigFile) ? (require $ssoConfigFile) : [];
$portalServerUrl = rtrim((string)($ssoConfig['sso_server_url'] ?? 'http://localhost/veyraeducate/public'), '/');
$portalUpgradeUrl = $portalServerUrl . '/harga';

// Auto-migration kolom photo pada tabel students jika belum ada
try {
    $hasPhotoCol = Database::fetch("SHOW COLUMNS FROM students LIKE 'photo'");
    if (!$hasPhotoCol) {
        Database::query("ALTER TABLE students ADD COLUMN photo VARCHAR(255) NULL AFTER gender");
    }
} catch (Throwable $e) {}


/** Upload pasfoto siswa; return path relatif atau null. */
function handleStudentPhoto(int $tenantId, ?array $file): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto siswa gagal (kode ' . $file['error'] . ').');
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto siswa maksimal 3 MB.');
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Format foto siswa harus berupa file gambar valid (JPG, PNG, atau WEBP).');
    }
    $ext = $allowed[$mime];
    $name = 'sp_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destDir = __DIR__ . '/../assets/uploads/students';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $dest = $destDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal memindahkan file foto ke server.');
    }
    return 'assets/uploads/students/' . $name;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['_csrf'] ?? '';
    $action    = $_POST['action'] ?? '(no-action)';
    $sessHas   = isset($_SESSION['csrf_token']) ? 'YES' : 'NO';
    $tokenOk   = ($sessHas === 'YES' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken));
    error_log('[students] POST action=' . $action . ' sess_csrf=' . $sessHas . ' token_ok=' . ($tokenOk ? '1' : '0') . ' n_fields=' . count($_POST));
    try {
        Helper::verifyCsrf();

    if ($action === 'create') {
        try {
            $nis = trim($_POST['nis'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $classId = (int)($_POST['class_id'] ?? 0) ?: null;
            $gender = in_array($_POST['gender'] ?? '', ['L', 'P']) ? $_POST['gender'] : 'L';
            $password = trim($_POST['password'] ?? 'password');
            $photoPath = handleStudentPhoto($tenantId, $_FILES['student_photo'] ?? null);

            if ($classId !== null) {
                $validClass = Database::fetch("SELECT id FROM classes WHERE id = ? AND tenant_id = ?", [$classId, $tenantId]);
                if (!$validClass) {
                    $classId = null;
                }
            }

            if (empty($nis)) {
                Helper::setFlash('danger', 'NIS siswa wajib diisi.');
            } elseif (empty($name)) {
                Helper::setFlash('danger', 'Nama lengkap siswa wajib diisi.');
            } else {
                $exists = Database::fetch("SELECT id FROM students WHERE tenant_id = ? AND nis = ?", [$tenantId, $nis]);
                if ($exists) {
                    Helper::setFlash('danger', "Siswa dengan NIS '{$nis}' sudah terdaftar di sekolah ini!");
                } else {
                    Database::insert('students', [
                        'tenant_id' => $tenantId,
                        'class_id'  => $classId,
                        'nis'       => $nis,
                        'name'      => $name,
                        'password'  => password_hash($password, PASSWORD_BCRYPT),
                        'gender'    => $gender,
                        'photo'     => $photoPath,
                        'is_active' => 1
                    ]);
                    Helper::setFlash('success', "Siswa '{$name}' (NIS: {$nis}) berhasil ditambahkan.");
                }
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal menyimpan data siswa: ' . $e->getMessage());
        }
        Helper::redirect('admin/students.php?page=1');

    } elseif ($action === 'update') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $nis = trim($_POST['nis'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $classId = (int)($_POST['class_id'] ?? 0) ?: null;
            $gender = in_array($_POST['gender'] ?? '', ['L', 'P']) ? $_POST['gender'] : 'L';
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $newPass = trim($_POST['password'] ?? '');
            $removePhoto = isset($_POST['remove_photo']);

            if ($classId !== null) {
                $validClass = Database::fetch("SELECT id FROM classes WHERE id = ? AND tenant_id = ?", [$classId, $tenantId]);
                if (!$validClass) {
                    $classId = null;
                }
            }

            if ($id > 0 && empty($nis)) {
                Helper::setFlash('danger', 'NIS siswa wajib diisi.');
            } elseif ($id > 0 && empty($name)) {
                Helper::setFlash('danger', 'Nama lengkap siswa wajib diisi.');
            } elseif ($id > 0) {
                $exists = Database::fetch("SELECT id FROM students WHERE tenant_id = ? AND nis = ? AND id != ?", [$tenantId, $nis, $id]);
                if ($exists) {
                    Helper::setFlash('danger', "Siswa lain dengan NIS '{$nis}' sudah terdaftar!");
                } else {
                    $existing = Database::fetch("SELECT * FROM students WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
                    $photoPath = $existing['photo'] ?? null;
                    if ($removePhoto) {
                        $photoPath = null;
                    }
                    $newPhoto = handleStudentPhoto($tenantId, $_FILES['student_photo'] ?? null);
                    if ($newPhoto !== null) {
                        $photoPath = $newPhoto;
                    }

                    $updData = [
                        'nis'       => $nis,
                        'name'      => $name,
                        'class_id'  => $classId,
                        'gender'    => $gender,
                        'photo'     => $photoPath,
                        'is_active' => $isActive
                    ];
                    if ($newPass !== '') {
                        $updData['password'] = password_hash($newPass, PASSWORD_BCRYPT);
                    }
                    Database::update('students', $updData, 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
                    Helper::setFlash('success', "Data siswa '{$name}' berhasil diperbarui" . ($newPass !== '' ? " (kata sandi diubah menjadi: <b>" . htmlspecialchars($newPass) . "</b>)." : "."));
                }
            } else {
                Helper::setFlash('danger', 'ID siswa tidak valid.');
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal memperbarui data siswa: ' . $e->getMessage());
        }
        Helper::redirect('admin/students.php?page=1');

    } elseif ($action === 'change_password' || $action === 'reset_password') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $newPass = trim($_POST['password'] ?? '');
            if ($newPass === '') {
                $newPass = 'password';
            }
            $target = Database::fetch("SELECT id, name, nis FROM students WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
            if ($target) {
                Database::update('students', [
                    'password' => password_hash($newPass, PASSWORD_BCRYPT),
                ], 'id = :id AND tenant_id = :tenant_id', [
                    'id' => $id, 'tenant_id' => $tenantId
                ]);
                Helper::setFlash('success', "Kata sandi siswa '{$target['name']}' (NIS: {$target['nis']}) berhasil diubah menjadi: <b>" . htmlspecialchars($newPass) . "</b>");
            } else {
                Helper::setFlash('danger', "Data siswa tidak ditemukan.");
            }
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal mengubah kata sandi: ' . $e->getMessage());
        }
        Helper::redirect('admin/students.php?page=1');

    } elseif ($action === 'import_csv') {
        try {
            $upload = $_FILES['csv_file'] ?? null;
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || $upload['size'] === 0) {
                throw new RuntimeException('Pilih file CSV atau Excel terlebih dahulu.');
            }

            [$header, $rows] = Helper::parseUpload($upload);

            if (empty($header) || count($rows) === 0) {
                throw new RuntimeException('File kosong atau tidak bisa dibaca. Pastikan format sesuai template yang tersedia.');
            }

            // Rename kolom ke format standar tanpa mengubah header secara in-place
            $renameMap = [];
            foreach ([
                'nama'          => 'name',
                'nama_siswa'    => 'name',
                'nama_lengkap'  => 'name',
                'fullname'      => 'name',
                'jk'            => 'gender',
                'jenis_kelamin' => 'gender',
                'kelamin'       => 'gender',
                'sex'           => 'gender',
                'kelas'         => 'class',
                'rombel'        => 'class',
                'nama_kelas'    => 'class',
                'tingkat_kelas' => 'class',
                'password'      => 'password',
                'kata_sandi'    => 'password',
                'sandi'         => 'password',
                'pass'          => 'password',
                'passwd'        => 'password'
            ] as $alias => $canon) {
                $ai = array_search($alias, $header, true);
                if ($ai !== false && !in_array($canon, $header, true)) {
                    $renameMap[$alias] = ['idx' => $ai, 'canon' => $canon];
                }
            }

            // Terapkan rename semua sekali setelah semua indeks terdeteksi dari header asli
            foreach ($renameMap as $alias => $info) {
                $header[$info['idx']] = $info['canon'];
                foreach ($rows as &$rrow) {
                    if (!isset($rrow[$info['canon']]) || $rrow[$info['canon']] === '') {
                        $rrow[$info['canon']] = $rrow[$alias] ?? '';
                    }
                    unset($rrow[$alias]);
                }
                unset($rrow);
            }

            $required = ['nis', 'name'];
            foreach ($required as $col) {
                if (!in_array($col, $header)) {
                    throw new RuntimeException("Kolom wajib '{$col}' tidak ditemukan di file. Header yang terbaca: " . implode(', ', $header));
                }
            }

            $classMap = [];
            foreach (Database::fetchAll("SELECT id, name FROM classes WHERE tenant_id = ?", [$tenantId]) as $c) {
                $classMap[strtolower(trim($c['name']))] = (int)$c['id'];
            }

            $imported = 0; $skipped = 0; $updated = 0;
            $dupMode = ($_POST['dup_mode'] ?? 'skip') === 'update' ? 'update' : 'skip';
            foreach ($rows as $row) {
                $nis = trim((string)($row['nis'] ?? ''));
                $name = trim((string)($row['name'] ?? ''));
                if ($nis === '' || $name === '') { $skipped++; continue; }

                $gender = strtoupper($row['gender'] ?? 'L') === 'P' ? 'P' : 'L';
                $className = strtolower(trim($row['class'] ?? ''));
                $classId = $classMap[$className] ?? null;
                $rawPass = trim((string)($row['password'] ?? ''));
                // Generate random password if not provided
                $userPass = $rawPass !== '' ? $rawPass : bin2hex(random_bytes(8));

                $exists = Database::fetch("SELECT id FROM students WHERE tenant_id = ? AND nis = ?", [$tenantId, $nis]);
                if ($exists) {
                    if ($dupMode === 'update') {
                        $upd = ['name' => $name, 'gender' => $gender];
                        if ($classId !== null) {
                            $upd['class_id'] = $classId;
                        }
                        if ($rawPass !== '') {
                            $upd['password'] = password_hash($rawPass, PASSWORD_BCRYPT);
                        }
                        Database::update('students', $upd, 'id = :id AND tenant_id = :tenant_id', ['id' => $exists['id'], 'tenant_id' => $tenantId]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                Database::insert('students', [
                    'tenant_id' => $tenantId,
                    'class_id'  => $classId,
                    'nis'       => $nis,
                    'name'      => $name,
                    'password'  => password_hash($userPass, PASSWORD_BCRYPT),
                    'gender'    => $gender,
                    'is_active' => 1
                ]);
                $imported++;
            }
            $msg = "Impor selesai: {$imported} siswa ditambahkan";
            if ($updated > 0) $msg .= ", {$updated} diperbarui";
            if ($skipped > 0) $msg .= ", {$skipped} dilewati (duplikat/kosong)";
            Helper::setFlash('success', $msg . '.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Impor gagal: ' . $e->getMessage());
        }
        Helper::redirect('admin/students.php?page=1');

    } elseif ($action === 'delete') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            Database::delete('students', 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', "Data siswa berhasil dihapus.");
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Gagal menghapus data siswa: ' . $e->getMessage());
        }
        Helper::redirect('admin/students.php?page=1');
    }
    } catch (Throwable $e) {
        error_log('[students] Uncaught POST error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        Helper::setFlash('danger', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        Helper::redirect('admin/students.php?page=1');
    }
}

// Download Template Excel / CSV
if (isset($_GET['template'])) {
    $tplHeader = ['nis', 'nama', 'gender', 'class', 'password'];
    $tplRows = [
        ['1001', 'Ahmad Fadillah', 'L', 'X-MIPA-1', 'password123'],
        ['1002', 'Bella Safitri',  'P', 'X-MIPA-1', '1002'],
        ['1003', 'Citra Dewi',     'P', 'X-IPS-1', 'citra321'],
        ['1004', 'Deni Setiawan',  'L', 'X-IPS-1', 'password']
    ];
    if ($_GET['template'] === 'csv') {
        Helper::downloadCsv('template-data-siswa.csv', $tplHeader, $tplRows);
    } else {
        Helper::downloadXlsx('template-data-siswa.xlsx', $tplHeader, $tplRows);
    }
}

// Ekspor Excel / CSV
if (isset($_GET['export'])) {
    $filterClassExp = (int)($_GET['class_id'] ?? 0);
    $exportSql = "SELECT s.nis, s.name, c.name as class_name, s.gender,
                         CASE WHEN s.is_active = 1 THEN 'Aktif' ELSE 'Nonaktif' END as status
                  FROM students s
                  LEFT JOIN classes c ON s.class_id = c.id
                  WHERE s.tenant_id = :tenant_id";
    $exportParams = ['tenant_id' => $tenantId];
    if ($filterClassExp > 0) {
        $exportSql .= " AND s.class_id = :class_id";
        $exportParams['class_id'] = $filterClassExp;
    }
    $exportSql .= " ORDER BY s.nis ASC";
    $all = Database::fetchAll($exportSql, $exportParams);
    $rows = array_map(fn($s) => [$s['nis'], $s['name'], $s['class_name'] ?? '', $s['gender'], $s['status']], $all);
    $expHeader = ['nis', 'nama', 'kelas', 'jk', 'status'];
    if ($_GET['export'] === 'csv') {
        Helper::downloadCsv('data-siswa-' . $school['code'] . '.csv', $expHeader, $rows);
    } else {
        Helper::downloadXlsx('data-siswa-' . $school['code'] . '.xlsx', $expHeader, $rows);
    }
}

// Ambil daftar kelas untuk dropdown filter dan form
$classList = Database::fetchAll("SELECT * FROM classes WHERE tenant_id = ? ORDER BY name ASC", [$tenantId]);

// Filter + Search + Pagination
$filterClass = (int)($_GET['class_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$countSql = "SELECT COUNT(*) FROM students s WHERE s.tenant_id = :tenant_id";
$countParams = ['tenant_id' => $tenantId];
if ($filterClass > 0) {
    $countSql .= " AND s.class_id = :class_id";
    $countParams['class_id'] = $filterClass;
}
if ($q !== '') {
    $countSql .= " AND (s.name LIKE :q OR s.nis LIKE :qn)";
    $countParams['q'] = '%' . $q . '%';
    $countParams['qn'] = '%' . $q . '%';
}
$totalStudents = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalStudents, 15);

$listSql = "SELECT s.*, c.name as class_name 
     FROM students s 
     LEFT JOIN classes c ON s.class_id = c.id 
     WHERE s.tenant_id = :tenant_id";
$listParams = ['tenant_id' => $tenantId];
if ($filterClass > 0) {
    $listSql .= " AND s.class_id = :class_id";
    $listParams['class_id'] = $filterClass;
}
if ($q !== '') {
    $listSql .= " AND (s.name LIKE :q OR s.nis LIKE :qn)";
    $listParams['q'] = '%' . $q . '%';
    $listParams['qn'] = '%' . $q . '%';
}
$listSql .= " ORDER BY s.nis ASC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$students = Database::fetchAll($listSql, $listParams);

// Data untuk modal edit
$editStudent = null;
if (isset($_GET['edit'])) {
    $editStudent = Database::fetch(
        "SELECT * FROM students WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

$keepQuery = array_filter(['q' => $q, 'class_id' => $filterClass ?: null]);

$allStudentsCnt = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ?", [$tenantId]);
$activeStudentsCnt = (int)Database::fetchColumn("SELECT COUNT(*) FROM students WHERE tenant_id = ? AND is_active = 1", [$tenantId]);
$totalClassesCnt = count($classList);
$quotaMax = (int)($school['student_quota'] ?? 0);

require_once __DIR__ . '/header.php';
?>

<!-- RINGKASAN DATA SISWA -->
<div class="fan-kpi-grid" style="margin-bottom: 22px;">
    <div class="fan-kpi-card fan-kpi-green">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Total Peserta Didik</div>
                <div class="fan-kpi-info-val"><?= number_format($allStudentsCnt, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Terdaftar di sekolah ini</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-pink">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Akun Siswa Aktif</div>
                <div class="fan-kpi-info-val"><?= number_format($activeStudentsCnt, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Dapat mengakses ruang ujian</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-purple">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Rombongan Belajar</div>
                <div class="fan-kpi-info-val"><?= number_format($totalClassesCnt, 0, ',', '.') ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Kelas terdaftar</span></div>
    </div>

    <div class="fan-kpi-card fan-kpi-orange">
        <div class="fan-kpi-top">
            <div>
                <div class="fan-kpi-info-label">Kapasitas Kuota</div>
                <div class="fan-kpi-info-val"><?= $quotaMax > 0 ? number_format($quotaMax, 0, ',', '.') : '&infin;' ?></div>
            </div>
            <div class="fan-kpi-icon-pill">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
        </div>
        <div class="fan-kpi-sub"><span>Sisa kuota: <?= $quotaMax > 0 ? number_format(max(0, $quotaMax - $allStudentsCnt), 0, ',', '.') : 'Tak terbatas' ?></span></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">Daftar Siswa (<?= $totalStudents ?> Siswa)</h3>
            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Kelola akun peserta ujian, impor data kolektif via Excel/CSV, dan cetak kartu peserta</div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="../student/dashboard.php" target="_blank" class="btn btn-outline btn-sm" style="border-color: rgba(99,102,241,0.3); color: var(--primary);" title="Pratinjau antarmuka Dashboard Siswa">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                Dashboard Siswa ↗
            </a>
            <a href="students.php?template=xlsx" class="btn btn-outline btn-sm" title="Unduh format template Excel untuk data siswa">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Template Excel (.xlsx)
            </a>
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportCsv').classList.add('show')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Impor Excel / CSV
            </button>
            <a href="students.php?export=xlsx<?= $filterClass ? '&class_id=' . $filterClass : '' ?>" class="btn btn-outline btn-sm" title="Ekspor data siswa ke file Excel">
                Ekspor Excel
            </a>
            <a href="student-cards.php<?= $filterClass ? '?class_id=' . $filterClass : '' ?>" target="_blank" class="btn btn-outline btn-sm" style="border-color: #f59e0b; color: #f59e0b; font-weight: 700;" title="Buka jendela cetak kartu ujian siswa siap print (A4)">
                🪪 Cetak Kartu Siswa
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddStudent').classList.add('show')">
                + Tambah Siswa Baru
            </button>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 20px 0 20px;">
            <form method="GET" class="toolbar-row" style="margin-bottom: 0;">
                <div class="search-inline">
                    <input type="text" name="q" class="form-control" placeholder="Cari nama atau NIS..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <select name="class_id" class="form-control" style="width: auto; font-size: 13px; padding: 6px 10px;">
                    <option value="">-- Semua Kelas --</option>
                    <?php foreach ($classList as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filterClass === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm">Terapkan</button>
                <?php if ($q !== '' || $filterClass > 0): ?>
                    <a href="students.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($students)): ?>
            <p style="padding: 32px; text-align: center; color: var(--text-muted);">Belum ada siswa terdaftar pada kriteria ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>NIS</th>
                            <th>Nama Lengkap</th>
                            <th>Kelas</th>
                            <th>Gender</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $idx => $st): ?>
                            <tr>
                                <td><?= $pg['offset'] + $idx + 1 ?></td>
                                <td>
                                    <code><?= htmlspecialchars($st['nis'] ?? '') ?></code>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php if (!empty($st['photo'])): ?>
                                            <img src="../<?= htmlspecialchars($st['photo']) ?>" alt="Foto" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--border-color); flex-shrink: 0;">
                                        <?php else: ?>
                                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #e2e8f0; color: #475569; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0;">
                                                <?= strtoupper(substr((string)($st['name'] ?? ''), 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <b><?= htmlspecialchars($st['name'] ?? '-') ?></b>
                                            <?php if (!empty($st['photo'])): ?>
                                                <span style="font-size: 10px; color: #059669; background: #d1fae5; padding: 1px 6px; border-radius: 4px; margin-left: 4px; font-weight: 600;">Foto ✓</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-neutral"><?= htmlspecialchars($st['class_name'] ?: 'Umum') ?></span></td>
                                <td><?= $st['gender'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></td>
                                <td>
                                    <?php if ($st['is_active']): ?>
                                        <span class="badge badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="../student/dashboard.php?preview_student_id=<?= $st['id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="Simulasi tampilan dashboard sebagai <?= htmlspecialchars($st['name'] ?? '-') ?>">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1px; margin-right: 3px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        Simulasi ↗
                                    </a>

                                    <a href="students.php?edit=<?= $st['id'] ?><?= $keepQuery ? '&' . http_build_query($keepQuery) : '' ?>" class="btn btn-outline btn-sm">Edit</a>

                                    <button type="button" class="btn btn-outline btn-sm" onclick="openQuickPassModal(<?= (int)$st['id'] ?>, <?= json_encode($st['name'] ?? '') ?>, <?= json_encode($st['nis'] ?? '') ?>)" title="Ubah kata sandi siswa ini secara bebas">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1px; margin-right: 3px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Sandi
                                    </button>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus siswa <?= json_encode($st['name'] ?? '') ?>?')">
                                        <?= Helper::csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= Helper::paginationLinks($pg, $keepQuery) ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL TAMBAH SISWA -->
<div class="modal-backdrop" id="modalAddStudent">
    <div class="modal-dialog">
        <form method="POST" action="./students.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Tambah Data Siswa</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">NIS (Username Login)</label>
                        <input type="text" name="nis" class="form-control" placeholder="Contoh: 2024005" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jenis Kelamin</label>
                        <select name="gender" class="form-control">
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Nama Lengkap Siswa</label>
                    <input type="text" name="name" class="form-control" placeholder="Nama lengkap..." required>
                </div>

                <div class="form-group">
                    <label class="form-label">Rombel / Kelas</label>
                    <select name="class_id" class="form-control">
                        <option value="">-- Tanpa Kelas --</option>
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="background: var(--bg-subtle, #f8fafc); border: 1px dashed var(--border-color, #cbd5e1); border-radius: 12px; padding: 14px;">
                    <label class="form-label" style="font-weight: 700;">📸 Pasfoto Siswa (Opsional)</label>
                    <input type="file" name="student_photo" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Format JPG, PNG, atau WEBP maks 3 MB. Pasfoto ini otomatis tercetak pada Kartu Pelajar &amp; Kartu Ujian.</small>
                </div>

                <div class="form-group" style="background: var(--bg-subtle, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 14px;">
                    <label class="form-label" style="font-weight: 700;">Kata Sandi Awal Siswa</label>
                    <input type="text" name="password" id="addStudentPassInput" class="form-control" value="password" required placeholder="Tentukan kata sandi awal...">
                    <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="document.getElementById('addStudentPassInput').value = document.querySelector('#modalAddStudent input[name=nis]').value || '123456'">Gunakan NIS</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="document.getElementById('addStudentPassInput').value = 'password'">password</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="document.getElementById('addStudentPassInput').value = Math.floor(100000 + Math.random() * 900000)">Acak 6 Angka</button>
                    </div>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Admin bebas menentukan kata sandi awal apa saja.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Siswa</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT SISWA -->
<div class="modal-backdrop <?= $editStudent ? 'show' : '' ?>" id="modalEditStudent">
    <div class="modal-dialog">
        <form method="POST" action="./students.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="update">
            <?php if ($editStudent): ?>
                <input type="hidden" name="id" value="<?= $editStudent['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Edit Data Siswa</h3>
                <?php if ($editStudent): ?>
                    <a href="students.php<?= $keepQuery ? '?' . http_build_query($keepQuery) : '' ?>" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">NIS (Username Login)</label>
                        <input type="text" name="nis" class="form-control" value="<?= htmlspecialchars($editStudent['nis'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jenis Kelamin</label>
                        <select name="gender" class="form-control">
                            <option value="L" <?= ($editStudent['gender'] ?? 'L') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="P" <?= ($editStudent['gender'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Nama Lengkap Siswa</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editStudent['name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Rombel / Kelas</label>
                    <select name="class_id" class="form-control">
                        <option value="">-- Tanpa Kelas --</option>
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (int)($editStudent['class_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="background: var(--bg-subtle, #f8fafc); border: 1px dashed var(--border-color, #cbd5e1); border-radius: 12px; padding: 14px;">
                    <label class="form-label" style="font-weight: 700;">📸 Pasfoto Siswa (Opsional)</label>
                    <input type="file" name="student_photo" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($editStudent['photo'])): ?>
                        <div style="margin-top: 10px; display: flex; align-items: center; gap: 12px; background: rgba(0,0,0,0.03); padding: 8px 12px; border-radius: 8px;">
                            <img src="../<?= htmlspecialchars($editStudent['photo']) ?>" alt="Foto saat ini" style="width: 48px; height: 58px; object-fit: cover; border-radius: 6px; border: 1.5px solid var(--border-color);">
                            <div>
                                <div style="font-size: 12px; font-weight: 600; color: #059669;">Pasfoto aktif terpasang</div>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--danger); cursor: pointer; margin-top: 4px;">
                                    <input type="checkbox" name="remove_photo" value="1"> Hapus pasfoto ini
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Pasfoto ini otomatis ditampilkan pada Kartu Pelajar &amp; Kartu Ujian.</small>
                </div>

                <div class="form-group" style="background: var(--bg-subtle, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 14px;">
                    <label class="form-label" style="font-weight: 700; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
                        <span>Ganti Kata Sandi Siswa</span>
                        <span style="font-weight: normal; font-size: 11px; color: var(--text-muted);">Kosongkan jika tidak ingin mengubah</span>
                    </label>
                    <input type="text" name="password" id="editStudentPassInput" class="form-control" placeholder="Ketik kata sandi baru secara bebas...">
                    <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="document.getElementById('editStudentPassInput').value = document.querySelector('#modalEditStudent input[name=nis]').value">Gunakan NIS</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="document.getElementById('editStudentPassInput').value = 'password'">password</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="document.getElementById('editStudentPassInput').value = Math.floor(100000 + Math.random() * 900000)">Acak 6 Angka</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px; color: var(--danger, #ef4444);" onclick="document.getElementById('editStudentPassInput').value = ''">Kosongkan</button>
                    </div>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Admin bebas menentukan kata sandi baru apa saja tanpa batasan format.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= !isset($editStudent['is_active']) || $editStudent['is_active'] ? 'checked' : '' ?>>
                        Akun siswa aktif (boleh login)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editStudent): ?>
                    <a href="students.php<?= $keepQuery ? '?' . http_build_query($keepQuery) : '' ?>" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL IMPOR CSV / EXCEL -->
<div class="modal-backdrop" id="modalImportCsv">
    <div class="modal-dialog">
        <form method="POST" action="./students.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="import_csv">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Impor Siswa dari CSV / Excel</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">File CSV (maks 2 MB) atau Excel .xlsx (maks 5 MB)</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Jika NIS sudah terdaftar</label>
                    <select name="dup_mode" class="form-control">
                        <option value="skip">Lewati (pertahankan data lama)</option>
                        <option value="update">Perbarui data (nama, gender, kelas)</option>
                    </select>
                    <small style="color: var(--text-muted); font-size: 11.5px;">Mode perbarui cocok untuk sinkron kelas: kata sandi &amp; status akun tidak diubah.</small>
                </div>
                <div style="background: var(--bg-subtle); border-radius: 10px; padding: 14px; font-size: 12.5px; color: var(--text-muted); line-height: 1.6;">
                    <b style="color: var(--text-heading);">Format kolom header wajib:</b><br>
                    <code>nis,nama,gender,class</code> (atau alias <code>nama,jk,kelas</code>)<br><br>
                    • <b>nis</b> &amp; <b>nama</b> wajib diisi.<br>
                    • <b>gender</b>: L atau P (opsional, default L).<br>
                    • <b>class</b>: harus persis sama dengan nama kelas yang sudah ada (opsional).<br>
                    • Untuk file <b>.xlsx</b>: hanya sheet pertama yang dibaca; format kolom NIS sebagai <b>Teks</b> agar tidak berubah notasi ilmiah.<br>
                    • <b>password</b>: Kata sandi akun siswa (opsional, jika kosong sistem akan membuat sandi acak).<br>
                    <span style="color: #dc2626; font-weight: 600;">⚠️ Hindari menggunakan password default seperti 'password' atau '123456'.</span>
                    • Format kolom NIS sebaiknya diformat sebagai <b>Teks</b> di Excel agar angka 0 di depan tidak terpotong.
                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="students.php?template=xlsx" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template Excel (.xlsx)
                        </a>
                        <a href="students.php?template=csv" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template CSV
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="button" class="btn btn-primary" id="btnMulaiImpor">Mulai Impor</button>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    var modal = document.getElementById('modalImportCsv');
    if (!modal) return;
    var form = modal.querySelector('form');
    var btn  = modal.querySelector('#btnMulaiImpor');
    if (!form || !btn) return;

    // Validasi form sebelum submit
    btn.addEventListener('click', function () {
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        // Disable tombol & tampilkan status
        btn.disabled = true;
        btn.textContent = 'Memproses...';
        // Refresh session keep-alive
        new Image().src = '../api/ping.php?_=' + Date.now();
        var formData = new FormData(form);
        console.log('[CBT] Import submit → file=' + (formData.get('csv_file')?.name || 'none'), 'csrf=' + (!!formData.get('_csrf')));
        fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) {
                console.log('[CBT] Import response status=' + r.status);
                var loc = r.headers.get('Location');
                if (r.ok && loc) {
                    window.location.replace(loc);
                } else if (r.ok) {
                    window.location.reload();
                } else {
                    return r.text().then(function(txt) {
                        throw new Error('HTTP ' + r.status + ': ' + txt.substring(0, 300));
                    });
                }
            })
            .catch(function(err) {
                console.error('[CBT] Import error:', err);
                alert('Impor gagal — ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Mulai Impor';
            });
    }, false);
})();
</script>

<!-- MODAL KELOLA / GANTI KATA SANDI SISWA -->
<div class="modal-backdrop" id="modalQuickPassStudent">
    <div class="modal-dialog">
        <form method="POST" action="./students.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="id" id="quickPassStudentId" value="">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Ubah Kata Sandi Siswa
                </h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div style="background: var(--bg-subtle, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 12px 16px; margin-bottom: 16px;">
                    <div style="font-size: 14px; font-weight: 700; color: var(--text-heading, #1e293b);" id="quickPassStudentName">-</div>
                    <div style="font-size: 12px; color: var(--text-muted, #64748b); margin-top: 2px;">
                        NIS (Username): <code id="quickPassStudentNis" style="font-weight: 600;">-</code>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Kata Sandi Baru</label>
                    <input type="text" name="password" id="quickPassInput" class="form-control" placeholder="Ketik kata sandi baru secara bebas..." required autocomplete="off">
                    <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="applyQuickPassPreset('nis')">Gunakan NIS</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="applyQuickPassPreset('password')">Default (password)</button>
                        <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="applyQuickPassPreset('random')">Acak 6 Digit</button>
                    </div>
                    <small style="color: var(--text-muted); font-size: 11.5px; margin-top: 6px; display: block; line-height: 1.5;">
                        Admin bebas menentukan kata sandi apa saja (misal: tanggal lahir, NIS, kombinasi huruf &amp; angka, dsb).
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Kata Sandi</button>
            </div>
        </form>
    </div>
</div>

<script>
var currentQuickNis = '';
function openQuickPassModal(id, name, nis) {
    currentQuickNis = nis;
    document.getElementById('quickPassStudentId').value = id;
    document.getElementById('quickPassStudentName').textContent = name;
    document.getElementById('quickPassStudentNis').textContent = nis;
    document.getElementById('quickPassInput').value = nis;
    document.getElementById('modalQuickPassStudent').classList.add('show');
    setTimeout(function() {
        var inp = document.getElementById('quickPassInput');
        if (inp) { inp.focus(); inp.select(); }
    }, 100);
}
function applyQuickPassPreset(type) {
    var inp = document.getElementById('quickPassInput');
    if (!inp) return;
    if (type === 'nis') inp.value = currentQuickNis;
    else if (type === 'password') inp.value = 'password';
    else if (type === 'random') inp.value = Math.floor(100000 + Math.random() * 900000);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
