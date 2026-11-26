<?php
/**
 * Manajemen Akun Guru / Admin Sekolah (khusus role admin)
 */

$pageTitle = "Akun Guru & Admin";
require_once __DIR__ . '/_bootstrap.php';

if ($user['role'] !== 'admin') {
    Helper::setFlash('warning', 'Hanya admin sekolah yang dapat mengelola akun guru/admin.');
    Helper::redirect('admin/index.php');
}

$currentUserId = (int)$user['id'];

// 1. Download Template Excel / CSV
if (isset($_GET['template'])) {
    $tplHeader = ['username', 'nama', 'role', 'password', 'email'];
    $tplRows = [
        ['guru.ahmad',   'Ahmad Dahlan, S.Pd.',     'guru',    'Guru12345!',   'ahmad@sekolah.sch.id'],
        ['guru.siti',    'Siti Rahmawati, M.Pd.',   'guru',    'Siti6789!',    'siti@sekolah.sch.id'],
        ['guru.budi',    'Budi Pratama, S.Si.',     'guru',    'Budi0123!',    'budi@sekolah.sch.id'],
        ['proktor.dani', 'Dani Ramadhan, S.Kom.',   'proktor', 'Dani@4567',    'dani@sekolah.sch.id'],
        ['admin.staff1', 'Staf Administrasi TU',    'admin',   'Admin!2345',   'tu@sekolah.sch.id']
    ];
    if ($_GET['template'] === 'csv') {
        Helper::downloadCsv('template-akun-guru-admin.csv', $tplHeader, $tplRows);
    } else {
        Helper::downloadXlsx('template-akun-guru-admin.xlsx', $tplHeader, $tplRows);
    }
}

// 2. Ekspor Data Akun Guru & Admin
if (isset($_GET['export'])) {
    $allUsers = Database::fetchAll(
        "SELECT username, name, role, email, is_active, created_at FROM users WHERE tenant_id = ? ORDER BY role ASC, name ASC",
        [$tenantId]
    );
    $expHeader = ['username', 'nama', 'role', 'email', 'status', 'tanggal_buat'];
    $roleMap = ['admin' => 'Admin', 'teacher' => 'Guru', 'proctor' => 'Proktor'];
    $expRows = array_map(fn($u) => [
        $u['username'],
        $u['name'],
        $roleMap[$u['role']] ?? $u['role'],
        $u['email'] ?: '-',
        $u['is_active'] ? 'Aktif' : 'Nonaktif',
        $u['created_at']
    ], $allUsers);

    if ($_GET['export'] === 'csv') {
        Helper::downloadCsv('data-guru-admin-' . $school['code'] . '.csv', $expHeader, $expRows);
    } else {
        Helper::downloadXlsx('data-guru-admin-' . $school['code'] . '.xlsx', $expHeader, $expRows);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'teacher', 'proctor']) ? $_POST['role'] : 'teacher';
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $name === '' || $password === '') {
            Helper::setFlash('danger', 'Username, nama, dan kata sandi wajib diisi.');
        } else {
            $exists = Database::fetch(
                "SELECT id FROM users WHERE tenant_id = ? AND username = ?",
                [$tenantId, $username]
            );
            if ($exists) {
                Helper::setFlash('danger', "Username '{$username}' sudah dipakai di sekolah ini.");
            } else {
                Database::insert('users', [
                    'tenant_id' => $tenantId,
                    'username'  => $username,
                    'password'  => password_hash($password, PASSWORD_BCRYPT),
                    'name'      => $name,
                    'role'      => $role,
                    'email'     => $email !== '' ? $email : null,
                    'is_active' => 1
                ]);
                Helper::setFlash('success', "Akun '{$name}' ({$username}) berhasil dibuat dengan role {$role}.");
            }
        }
        Helper::redirect('admin/users.php');

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'teacher', 'proctor']) ? $_POST['role'] : 'teacher';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = trim($_POST['new_password'] ?? '');

        $target = Database::fetch("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        if (!$target) {
            Helper::setFlash('danger', 'Akun tidak ditemukan.');
        } elseif ($id === $currentUserId && ($role !== 'admin' || !$isActive)) {
            Helper::setFlash('danger', 'Anda tidak dapat menonaktifkan atau menurunkan role akun Anda sendiri.');
        } else {
            $data = [
                'name'      => $name,
                'role'      => $role,
                'email'     => $email !== '' ? $email : null,
                'is_active' => $isActive
            ];
            if ($newPassword !== '') {
                $data['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
            }
            Database::update('users', $data, 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', "Akun '{$name}' berhasil diperbarui.");
        }
        Helper::redirect('admin/users.php');

    } elseif ($action === 'import_excel') {
        try {
            if (!isset($_FILES['file_users']['name']) || $_FILES['file_users']['error'] !== UPLOAD_ERR_OK || $_FILES['file_users']['size'] === 0) {
                throw new RuntimeException('Silakan pilih file Excel (.xlsx) atau CSV terlebih dahulu.');
            }

            [$rawHeader, $rawRows] = Helper::parseUpload($_FILES['file_users']);

            $resolveColumn = function(string $raw): ?string {
                $clean = strtolower(trim($raw));
                $clean = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/', '', $clean);
                $clean = preg_replace('/[\s\-\.\/\(\)]+/', '_', $clean);
                $clean = trim($clean, '_');

                // 1. Username
                if (in_array($clean, ['username', 'user', 'nip', 'nuptk', 'nik', 'login', 'id_user', 'user_id', 'id_pengguna', 'nama_pengguna', 'nip_username', 'user_login'], true)) {
                    return 'username';
                }
                if (strpos($clean, 'username') !== false || strpos($clean, 'nip') !== false || strpos($clean, 'nuptk') !== false) {
                    return 'username';
                }

                // 2. Nama (pastikan bukan nama_pengguna / username)
                if (in_array($clean, ['nama', 'name', 'nama_lengkap', 'nama_guru', 'nama_admin', 'fullname', 'full_name', 'nama_user', 'nama_lengkap_guru'], true)) {
                    return 'nama';
                }
                if (strpos($clean, 'nama') !== false || strpos($clean, 'name') !== false) {
                    return 'nama';
                }

                // 3. Role
                if (in_array($clean, ['role', 'peran', 'jabatan', 'posisi', 'level', 'tipe_akun', 'role_peran', 'peran_jabatan'], true)) {
                    return 'role';
                }
                if (strpos($clean, 'role') !== false || strpos($clean, 'peran') !== false || strpos($clean, 'jabatan') !== false) {
                    return 'role';
                }

                // 4. Password
                if (in_array($clean, ['password', 'pass', 'kata_sandi', 'sandi', 'passwd', 'password_akun'], true)) {
                    return 'password';
                }
                if (strpos($clean, 'pass') !== false || strpos($clean, 'sandi') !== false) {
                    return 'password';
                }

                // 5. Email
                if (in_array($clean, ['email', 'surel', 'mail', 'e_mail', 'alamat_email'], true)) {
                    return 'email';
                }
                if (strpos($clean, 'email') !== false || strpos($clean, 'surel') !== false || strpos($clean, 'mail') !== false) {
                    return 'email';
                }

                return null;
            };

            $colMap = []; // column index => canon
            foreach ($rawHeader as $idx => $hName) {
                $canon = $resolveColumn((string)$hName);
                if ($canon && !in_array($canon, $colMap, true)) {
                    $colMap[$idx] = $canon;
                }
            }

            if (!in_array('username', $colMap, true) || !in_array('nama', $colMap, true)) {
                throw new RuntimeException("Kolom 'username' dan 'nama' wajib ada di file. Header terdeteksi: " . implode(', ', $rawHeader));
            }

            $dupMode = $_POST['dup_mode'] ?? 'skip';
            $inserted = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rawRows as $r) {
                $rowVals = array_values($r);
                $userData = [];
                foreach ($colMap as $cIdx => $cKey) {
                    $userData[$cKey] = trim((string)($rowVals[$cIdx] ?? ''));
                }
                // Fallback jika assoc key sudah sesuai
                foreach (['username', 'nama', 'role', 'password', 'email'] as $k) {
                    if (empty($userData[$k]) && isset($r[$k])) {
                        $userData[$k] = trim((string)$r[$k]);
                    }
                }

                $username = $userData['username'] ?? '';
                $name = $userData['nama'] ?? '';
                if (empty($username) || empty($name)) {
                    $skipped++;
                    continue;
                }

                $rawRole = strtolower(trim($userData['role'] ?? 'teacher'));
                $role = 'teacher';
                if (strpos($rawRole, 'admin') !== false || strpos($rawRole, 'adm') !== false || strpos($rawRole, 'tu') !== false) {
                    $role = 'admin';
                } elseif (strpos($rawRole, 'prokt') !== false || strpos($rawRole, 'proct') !== false || strpos($rawRole, 'awas') !== false) {
                    $role = 'proctor';
                } else {
                    $role = 'teacher';
                }

                $email = trim($userData['email'] ?? '');
                $emailVal = $email !== '' ? $email : null;
                $rawPass = trim((string)($userData['password'] ?? ''));

                $existing = Database::fetch(
                    "SELECT id, role FROM users WHERE tenant_id = ? AND username = ?",
                    [$tenantId, $username]
                );

                if ($existing) {
                    if ($dupMode === 'update') {
                        $updData = [
                            'name'  => $name,
                            'email' => $emailVal
                        ];
                        // Jangan turunkan role atau ubah akun yang sedang login jika bukan admin
                        if ((int)$existing['id'] !== $currentUserId) {
                            $updData['role'] = $role;
                        }
                        if ($rawPass !== '') {
                            $updData['password'] = password_hash($rawPass, PASSWORD_BCRYPT);
                        }
                        Database::update('users', $updData, 'id = :id AND tenant_id = :tenant_id', [
                            'id' => $existing['id'],
                            'tenant_id' => $tenantId
                        ]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $finalPass = $rawPass !== '' ? $rawPass : 'password';
                    Database::insert('users', [
                        'tenant_id' => $tenantId,
                        'username'  => $username,
                        'password'  => password_hash($finalPass, PASSWORD_BCRYPT),
                        'name'      => $name,
                        'role'      => $role,
                        'email'     => $emailVal,
                        'is_active' => 1
                    ]);
                    $inserted++;
                }
            }

            $msg = "Impor selesai: <b>{$inserted} akun</b> berhasil ditambahkan";
            if ($updated > 0) $msg .= ", <b>{$updated} akun</b> diperbarui";
            if ($skipped > 0) $msg .= ", <b>{$skipped} akun</b> dilewati";
            Helper::setFlash('success', $msg . '.');
        } catch (Throwable $e) {
            Helper::setFlash('danger', 'Impor gagal: ' . $e->getMessage());
        }
        Helper::redirect('admin/users.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $currentUserId) {
            Helper::setFlash('danger', 'Anda tidak dapat menghapus akun Anda sendiri.');
        } else {
            Database::delete('users', 'id = :id AND tenant_id = :tenant_id', ['id' => $id, 'tenant_id' => $tenantId]);
            Helper::setFlash('success', 'Akun berhasil dihapus.');
        }
        Helper::redirect('admin/users.php');

    } elseif ($action === 'clear_all') {
        Helper::verifyCsrf();
        // Hitung non-admin untuk pesan konfirmasi yang akurat
        $nonAdminCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role != 'admin'",
            [$tenantId]
        );
        try {
            // Nonaktifkan semua user non-admin (keamanan: jangan hapus akun fisik)
            Database::update('users', ['is_active' => 0], 'tenant_id = ? AND role != :role', [
                'tenant_id' => $tenantId,
                'role'      => 'admin',
            ]);
            Helper::setFlash('success', "Semua akun guru/proktor ({$nonAdminCount} akun) telah dinonaktifkan. Akun admin tetap tersisa.");
        } catch (Throwable $e) {
            error_log('[users clear_all] Error: ' . $e->getMessage());
            Helper::setFlash('danger', 'Gagal menonaktifkan semua akun: ' . $e->getMessage());
        }
        Helper::redirect('admin/users.php');
    }
}

// Search + Pagination
$q = trim($_GET['q'] ?? '');
$countParams = ['tenant_id' => $tenantId];
$countSql = "SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id";
if ($q !== '') {
    $countSql .= " AND (name LIKE :qn OR username LIKE :qu)";
    $countParams['qn'] = '%' . $q . '%';
    $countParams['qu'] = '%' . $q . '%';
}
$totalUsers = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalUsers, 15);

$listParams = ['tenant_id' => $tenantId];
$listSql = "SELECT * FROM users WHERE tenant_id = :tenant_id";
if ($q !== '') {
    $listSql .= " AND (name LIKE :qn OR username LIKE :qu)";
    $listParams['qn'] = '%' . $q . '%';
    $listParams['qu'] = '%' . $q . '%';
}
$listSql .= " ORDER BY role ASC, name ASC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$users = Database::fetchAll($listSql, $listParams);

$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = Database::fetch(
        "SELECT * FROM users WHERE id = ? AND tenant_id = ?",
        [(int)$_GET['edit'], $tenantId]
    );
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 class="card-title" style="margin: 0;">Akun Guru & Admin (<?= $totalUsers ?> Akun)</h3>
            <p style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0 0 0;">Kelola hak akses pengajar, proktor, dan admin sekolah menggunakan Excel / CSV</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <!-- Unduh Template Excel -->
            <a href="users.php?template=xlsx" class="btn btn-outline btn-sm" title="Unduh format file Excel kosong untuk akun guru/admin">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Template Excel (.xlsx)
            </a>

            <!-- Impor Excel -->
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportUsers').classList.add('show')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Impor Excel / CSV
            </button>

            <!-- Ekspor Data -->
            <a href="users.php?export=xlsx" class="btn btn-outline btn-sm" title="Ekspor daftar akun ke file Excel">
                Ekspor Data
            </a>

            <!-- Tambah Manual -->
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddUser').classList.add('show')">
                + Buat Akun Baru
            </button>

            <?php if ($totalUsers > 0): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Nonaktifkan semua akun guru & proktor di sekolah ini? Akun admin tetap aman.\n\nTindakan ini tidak dapat dibatalkan.')">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Helper::csrfToken()) ?>">
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="btn btn-danger btn-sm" title="Nonaktifkan semua akun guru & proktor" style="white-space:nowrap;">
                    🗑 Nonaktifkan Semua
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 16px 20px 0 20px;">
            <form method="GET" class="toolbar-row" style="margin-bottom: 0;">
                <div class="search-inline">
                    <input type="text" name="q" class="form-control" placeholder="Cari nama / username..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Cari</button>
                <?php if ($q !== ''): ?>
                    <a href="users.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($users)): ?>
            <div style="padding: 48px 20px; text-align: center; color: var(--text-muted);">
                <div style="margin-bottom: 12px;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </div>
                <div style="font-weight: 700; color: #1e293b; font-size: 15px; margin-bottom: 4px;">Belum Ada Akun Terdaftar</div>
                <div style="font-size: 12.5px; margin-bottom: 18px;">Gunakan template Excel untuk menambahkan banyak guru dan admin sekaligus, atau buat manual satu per satu.</div>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a href="users.php?template=xlsx" class="btn btn-outline btn-sm">Unduh Template Excel</a>
                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportUsers').classList.add('show')">Impor File Excel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddUser').classList.add('show')">+ Buat Akun Baru</button>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Username</th>
                            <th>Nama Lengkap</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $idx => $u): ?>
                            <tr>
                                <td><?= $pg['offset'] + $idx + 1 ?></td>
                                <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                                <td>
                                    <b><?= htmlspecialchars($u['name']) ?></b>
                                    <?php if ((int)$u['id'] === $currentUserId): ?>
                                        <span class="badge badge-primary">Anda</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12.5px; color: var(--text-muted);"><?= htmlspecialchars($u['email'] ?: '-') ?></td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge badge-primary">Admin</span>
                                    <?php elseif ($u['role'] === 'teacher'): ?>
                                        <span class="badge badge-neutral">Guru</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Proktor</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="users.php?edit=<?= $u['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-outline btn-sm">Edit</a>

                                    <?php if ((int)$u['id'] !== $currentUserId): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus akun <?= htmlspecialchars(addslashes($u['username'])) ?>?')">
                                            <?= Helper::csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= Helper::paginationLinks($pg, $q !== '' ? ['q' => $q] : []) ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL TAMBAH AKUN -->
<div class="modal-backdrop" id="modalAddUser">
    <div class="modal-dialog">
        <form method="POST" action="./users.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Buat Akun Guru / Admin</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Contoh: guru_matematika" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="teacher">Guru (Teacher)</option>
                            <option value="proctor">Proktor</option>
                            <option value="admin">Admin Sekolah</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Lengkap</label>
                    <input type="text" name="name" class="form-control" placeholder="Nama lengkap..." required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email (Opsional)</label>
                    <input type="email" name="email" class="form-control" placeholder="guru@sekolah.sch.id">
                </div>
                <div class="form-group">
                    <label class="form-label">Kata Sandi</label>
                    <input type="text" name="password" class="form-control" placeholder="Minimal 6 karakter" required minlength="6">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Akun</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT AKUN -->
<div class="modal-backdrop <?= $editUser ? 'show' : '' ?>" id="modalEditUser">
    <div class="modal-dialog">
        <form method="POST" action="./users.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="update">
            <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Edit Akun (<?= htmlspecialchars($editUser['username'] ?? '') ?>)</h3>
                <?php if ($editUser): ?>
                    <a href="users.php" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editUser['name'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <?php foreach (['teacher' => 'Guru (Teacher)', 'proctor' => 'Proktor', 'admin' => 'Admin Sekolah'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= ($editUser['role'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email (Opsional)</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Kata Sandi Baru (kosongkan jika tidak diganti)</label>
                    <input type="text" name="new_password" class="form-control" placeholder="Biarkan kosong jika tetap" minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= !isset($editUser['is_active']) || $editUser['is_active'] ? 'checked' : '' ?>>
                        Akun aktif (boleh login)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editUser): ?>
                    <a href="users.php" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL IMPOR EXCEL / CSV AKUN GURU & ADMIN -->
<div class="modal-backdrop" id="modalImportUsers">
    <div class="modal-dialog">
        <form method="POST" action="./users.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="import_excel">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Impor Akun Guru & Admin dari Excel / CSV</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">File Excel (.xlsx) atau CSV</label>
                    <input type="file" name="file_users" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Jika Username Sudah Terdaftar</label>
                    <select name="dup_mode" class="form-control">
                        <option value="skip">Lewati (pertahankan data lama)</option>
                        <option value="update">Perbarui (update nama, role, email &amp; sandi jika diisi)</option>
                    </select>
                </div>

                <div style="background: #f8fafc; border: 1px solid #eef2f7; border-radius: 14px; padding: 16px; font-size: 12.5px; color: #475569; line-height: 1.6;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;">Format Header Kolom File:</div>
                    <code>username, nama, role, password, email</code><br><br>
                    • <b>username</b>: Username login unik, misal <code>guru.budi</code> (<b>wajib diisi</b>).<br>
                    • <b>nama</b>: Nama lengkap &amp; gelar pengajar/admin (<b>wajib diisi</b>).<br>
                    • <b>role</b>: Peran akun: <code>guru</code>, <code>proktor</code>, atau <code>admin</code> (default: <code>guru</code>).<br>
                    • <b>password</b>: Kata sandi akun (opsional, jika kosong default: <code>password</code>).<br>
                    • <b>email</b>: Alamat email pengajar/admin (opsional).<br>
                    • Format file mendukung <b>.xlsx</b> (Microsoft Excel) dan <b>.csv</b>.
                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="users.php?template=xlsx" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template Excel (.xlsx)
                        </a>
                        <a href="users.php?template=csv" class="btn btn-outline btn-sm" style="font-size: 11px;">
                            &darr; Unduh Template CSV
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="button" class="btn btn-primary" id="btnMulaiImpor">Mulai Impor File</button>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    var modal = document.getElementById('modalImportUsers');
    if (!modal) return;
    var form = modal.querySelector('form');
    var btn  = modal.querySelector('#btnMulaiImpor');
    if (!form || !btn) return;
    btn.addEventListener('click', function () {
        if (!form.checkValidity()) { form.reportValidity(); return; }
        btn.disabled = true;
        btn.textContent = 'Memproses...';
        new Image().src = '../api/ping.php?_=' + Date.now();
        var formData = new FormData(form);
        console.log('[CBT] Import user → csrf=' + (!!formData.get('_csrf')), 'file=' + (formData.get('file_users')?.name || 'none'));
        fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) {
                console.log('[CBT] Response status=' + r.status);
                var loc = r.headers.get('Location');
                if (r.ok && loc) { window.location.replace(loc); }
                else if (r.ok) { window.location.reload(); }
                else { return r.text().then(function(t){ throw new Error('HTTP '+r.status+': '+t.substring(0,300)); }); }
            })
            .catch(function(err) {
                console.error('[CBT] Error:', err);
                alert('Impor gagal — ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Mulai Impor File';
            });
    }, false);
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
