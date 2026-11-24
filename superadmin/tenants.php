<?php
/**
 * CRUD Tenant / Sekolah Platform
 */

$pageTitle = "Data Tenant (Sekolah / Lembaga)";
require_once __DIR__ . '/_bootstrap.php';

// Package features map (Tersinkronisasi dengan Paket Veyra Educate)
$packageFeatures = [
    'semester' => [
        'name'      => 'Paket Semesteran',
        'students'  => '250 Siswa',
        'teachers'  => '10 Guru',
        'questions' => 'Unlimited Soal',
        'cards'     => '🔒 Cetak Kartu Terkunci',
        'proctoring'=> 'Proctoring Standar'
    ],
    'tahunan' => [
        'name'      => 'Paket 1 Tahun Penuh',
        'students'  => '750 Siswa',
        'teachers'  => 'Unlimited Guru',
        'questions' => 'Unlimited Soal',
        'cards'     => '🪪 Cetak Kartu Aktif',
        'proctoring'=> 'Proctoring Lengkap'
    ],
    'super' => [
        'name'      => 'Paket Super',
        'students'  => 'Unlimited Siswa',
        'teachers'  => 'Unlimited Guru',
        'questions' => 'Unlimited Soal',
        'cards'     => '🪪 Cetak Kartu Aktif',
        'proctoring'=> 'Full Proctoring & Priority'
    ],
    'basic' => [
        'name'      => 'Paket Semesteran (Legacy)',
        'students'  => '100 Siswa',
        'teachers'  => '1 Guru',
        'questions' => '10 Soal/Bank',
        'cards'     => '🔒 Cetak Kartu Terkunci',
        'proctoring'=> 'Tanpa Proctoring'
    ],
    'pro' => [
        'name'      => 'Paket 1 Tahun (Legacy)',
        'students'  => '500 Siswa',
        'teachers'  => '5 Guru',
        'questions' => '100 Soal/Bank',
        'cards'     => '🪪 Cetak Kartu Aktif',
        'proctoring'=> 'Proctoring Dasar'
    ],
    'enterprise' => [
        'name'      => 'Paket Super (Legacy)',
        'students'  => 'Unlimited Siswa',
        'teachers'  => 'Unlimited Guru',
        'questions' => 'Unlimited Soal',
        'cards'     => '🪪 Cetak Kartu Aktif',
        'proctoring'=> 'Full Proctoring'
    ]
];

// Handle Exit Impersonate (Kembali ke Superadmin)
if (isset($_GET['exit_impersonate']) && !empty($_SESSION['impersonator_superadmin'])) {
    $_SESSION['auth_user'] = $_SESSION['impersonator_superadmin'];
    unset($_SESSION['impersonator_superadmin']);
    Helper::setFlash('success', 'Berhasil kembali ke Superadmin Control Center.');
    Helper::redirect('superadmin/index.php');
}

// Handle Impersonate (Superadmin login as Tenant Admin)
if (isset($_GET['impersonate'])) {
    $token = (string)($_GET['_csrf'] ?? '');
    if (!hash_equals(Helper::csrfToken(), $token)) {
        Helper::setFlash('danger', 'Token keamanan CSRF tidak valid untuk impersonasi.');
        Helper::redirect('superadmin/tenants.php');
    }

    $tenantId = (int)$_GET['impersonate'];
    $tenant = Database::fetch("SELECT * FROM tenants WHERE id = ?", [$tenantId]);

    if ($tenant) {
        $firstAdmin = Database::fetch("SELECT * FROM users WHERE tenant_id = ? AND role = 'admin' LIMIT 1", [$tenantId]);
        if ($firstAdmin) {
            // Simpan session superadmin asli agar bisa kembali dengan 1 klik
            $_SESSION['impersonator_superadmin'] = $_SESSION['auth_user'];
            $_SESSION['auth_user'] = [
                'id'        => $firstAdmin['id'],
                'tenant_id' => $tenant['id'],
                'username'  => $firstAdmin['username'],
                'name'      => $firstAdmin['name'],
                'role'      => 'admin',
                'email'     => $firstAdmin['email']
            ];
            Tenant::setTenant($tenant);
            Helper::setFlash('info', "Anda sedang login sebagai Admin Sekolah '{$tenant['name']}'. Klik tombol banner di atas untuk kembali ke Superadmin kapan saja.");
            Helper::redirect('../admin/index.php');
        } else {
            Helper::setFlash('danger', 'Tenant ini belum memiliki akun user admin.');
            Helper::redirect('superadmin/tenants.php');
        }
    }
}

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $slug = strtolower(trim($_POST['slug'] ?? ''));
        $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $package = in_array($_POST['package'] ?? '', ['basic', 'pro', 'enterprise']) ? $_POST['package'] : 'pro';
        $quota = max(10, (int)($_POST['student_quota'] ?? 500));
        $adminUser = trim($_POST['admin_username'] ?? '');
        $adminPass = trim($_POST['admin_password'] ?? '');
        $adminName = trim($_POST['admin_name'] ?? "");

        if (empty($adminPass)) {
            Helper::setFlash('danger', 'Kata sandi admin tenant wajib diisi.');
            $error = true;
        }

        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        }
        if (empty($subdomain)) {
            $subdomain = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        }

        // Cek duplikasi code, slug, atau subdomain
        $chk = Database::fetch("SELECT id FROM tenants WHERE code = ? OR slug = ? OR subdomain = ?", [$code, $slug, $subdomain]);
        if ($chk) {
            Helper::setFlash('danger', "Kode, slug, atau subdomain sudah terdaftar pada tenant lain!");
        } else {
            $newTenantId = Database::insert('tenants', [
                'name'          => $name,
                'code'          => $code,
                'slug'          => $slug,
                'subdomain'     => $subdomain,
                'address'       => $address,
                'phone'         => $phone,
                'package'       => $package,
                'student_quota' => $quota,
                'is_active'     => 1
            ]);

            // Buat admin awal untuk tenant ini jika diisi
            if (!empty($adminUser)) {
                Database::insert('users', [
                    'tenant_id' => $newTenantId,
                    'username'  => $adminUser,
                    'password'  => password_hash($adminPass, PASSWORD_BCRYPT),
                    'name'      => $adminName ?: "Admin {$name}",
                    'role'      => 'admin',
                    'is_active' => 1
                ]);
            }

            Helper::setFlash('success', "Tenant sekolah '{$name}' (Kode: {$code}, Subdomain: {$subdomain}.localhost) berhasil ditambahkan.");
        }
        Helper::redirect('superadmin/tenants.php');

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $package = in_array($_POST['package'] ?? '', ['basic', 'pro', 'enterprise']) ? $_POST['package'] : 'pro';
        $quota = max(10, (int)($_POST['student_quota'] ?? 500));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && !empty($name)) {
            Database::update('tenants', [
                'name'          => $name,
                'package'       => $package,
                'student_quota' => $quota,
                'is_active'     => $isActive
            ], 'id = :id', ['id' => $id]);
            Helper::setFlash('success', "Tenant '{$name}' berhasil diperbarui.");
        }
        Helper::redirect('superadmin/tenants.php');

    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $current = (int)Database::fetchColumn("SELECT is_active FROM tenants WHERE id = ?", [$id]);
        $newStatus = $current ? 0 : 1;
        Database::update('tenants', ['is_active' => $newStatus], 'id = :id', ['id' => $id]);
        Helper::setFlash('success', "Status aktif tenant berhasil diperbarui.");
        Helper::redirect('superadmin/tenants.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        Database::delete('tenants', 'id = :id', ['id' => $id]);
        Helper::setFlash('success', "Tenant berhasil dihapus beserta seluruh datanya.");
        Helper::redirect('superadmin/tenants.php');
    }
}

// Ambil seluruh tenant
$tenants = Database::fetchAll(
    "SELECT t.*,
            (SELECT COUNT(*) FROM students WHERE tenant_id = t.id AND is_active = 1) as current_students,
            (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as current_users
     FROM tenants t
     ORDER BY t.id DESC"
);

// Data untuk modal edit
$editTenant = null;
if (isset($_GET['edit'])) {
    $editTenant = Database::fetch("SELECT * FROM tenants WHERE id = ?", [(int)$_GET['edit']]);
}

require_once __DIR__ . '/header.php';
?>
<script>
function updatePackageInfo(pkg) {
    ['semester','tahunan','super','basic','pro','enterprise'].forEach(function(p) {
        var el = document.getElementById('packageInfo' + p.charAt(0).toUpperCase() + p.slice(1));
        if (el) el.style.display = p === pkg ? 'block' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('addPackageSelect');
    if (sel) {
        sel.addEventListener('change', function() {
            ['semester','tahunan','super','basic','pro','enterprise'].forEach(function(p) {
                var el = document.getElementById('addPackageInfo' + p.charAt(0).toUpperCase() + p.slice(1));
                if (el) el.style.display = p === this.value ? 'block' : 'none';
            }.bind(this));
        });
    }

    // Auto open modal jika ada parameter action=new atau hash #add
    var params = new URLSearchParams(window.location.search);
    if (params.get('action') === 'new' || window.location.hash === '#add') {
        var addModal = document.getElementById('modalAddTenant');
        if (addModal) addModal.classList.add('show');
    }
});
</script>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">Manajemen Tenant Sekolah & Lembaga (<?= count($tenants) ?> Mitra)</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">Daftar institusi pendidikan mitra platform, alokasi kuota, dan kontrol hak akses</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm" style="border-radius: 9999px; padding: 8px 18px; font-weight: 700;" onclick="document.getElementById('modalAddTenant').classList.add('show')">
            + Tambah Sekolah Baru
        </button>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>Kode & Nama Tenant</th>
                        <th>Subdomain</th>
                        <th>Paket & Fitur</th>
                        <th>Kuota Siswa</th>
                        <th>Akun Guru/Admin</th>
                        <th>Status</th>
                        <th style="text-align: right;">Aksi Superadmin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tenants as $idx => $t):
                        $quotaPercent = $t['student_quota'] > 0 ? round(($t['current_students'] / $t['student_quota']) * 100) : 0;
                        $quotaColor = $quotaPercent >= 100 ? '#ef4444' : ($quotaPercent >= 80 ? '#f59e0b' : '#10b981');
                        $features = $packageFeatures[$t['package']] ?? $packageFeatures['pro'];
                    ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                 <b><?= htmlspecialchars($t['name'] ?? '-') ?></b>
                                 <div style="font-size: 11px; color: var(--text-muted);">
                                     Kode: <code><?= htmlspecialchars($t['code']) ?></code> | Slug: <code><?= htmlspecialchars($t['slug']) ?></code>
                                 </div>
                             </td>
                             <td>
                                 <?php if (!empty($t['subdomain'])): ?>
                                     <code style="font-size: 11px;"><?= htmlspecialchars($t['subdomain']) ?></code>
                                     <div style="font-size: 10px; color: var(--text-muted);">.localhost/cbtonline</div>
                                 <?php else: ?>
                                     <span style="color: var(--text-muted); font-size: 12px;">—</span>
                                 <?php endif; ?>
                             </td>
                            <td>
                                <span class="badge badge-pkg-<?= htmlspecialchars($t['package'] ?? 'pro') ?>" style="font-size: 11px; font-weight: 700;"><?= htmlspecialchars($features['name'] ?? ucfirst($t['package'])) ?></span>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px; line-height: 1.6;">
                                    <div>&#128101; <?= $features['students'] ?></div>
                                    <div>&#128104;&#8205;&#127979;&#65039; <?= $features['teachers'] ?></div>
                                    <div><?= $features['cards'] ?? '🪪 Cetak Kartu' ?></div>
                                    <div>&#128065; <?= $features['proctoring'] ?></div>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px;">
                                    <span style="font-size: 12px; font-weight: 600; color: <?= $quotaColor ?>;">
                                        <?= (int)$t['current_students'] ?> / <?= $t['student_quota'] ?>
                                    </span>
                                    <?php if ($quotaPercent >= 100): ?>
                                        <span class="badge badge-danger" style="font-size: 9px;">Penuh</span>
                                    <?php elseif ($quotaPercent >= 80): ?>
                                        <span class="badge badge-warning" style="font-size: 9px;">Hampir Penuh</span>
                                    <?php endif; ?>
                                </div>
                                <div style="width: 100%; height: 6px; background: #f1f5f9; border-radius: 9999px; overflow: hidden;">
                                    <div style="height: 100%; width: <?= min(100, $quotaPercent) ?>%; background: <?= $quotaColor ?>; border-radius: 9999px; transition: width 0.3s ease;"></div>
                                </div>
                            </td>
                            <td><span class="badge badge-neutral"><?= (int)$t['current_users'] ?> User</span></td>
                            <td>
                                <?php if ($t['is_active']): ?>
                                    <span class="badge badge-success">&#9679; Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">&#9679; Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="tenants.php?impersonate=<?= $t['id'] ?>&_csrf=<?= Helper::csrfToken() ?>" class="btn btn-sm" style="background: #6ee7b7; color: #064e3b; font-weight: 700; border-radius: 9999px; border: none; padding: 5px 14px;" title="Masuk ke dashboard sekolah ini (Impersonate)">
                                    &#128065; Buka Panel
                                </a>
                                <a href="tenants.php?edit=<?= $t['id'] ?>" class="btn btn-outline btn-sm" style="border-radius: 9999px; padding: 5px 12px; font-weight: 600;">Edit</a>
                                <form method="POST" style="display: inline;" id="toggleForm<?= $t['id'] ?>">
                                    <?= Helper::csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="button" class="btn btn-<?= $t['is_active'] ? 'outline' : 'success' ?> btn-sm" style="border-radius: 9999px; padding: 5px 12px; font-weight: 600;" onclick="document.getElementById('toggleForm<?= $t['id'] ?>').submit()" title="<?= $t['is_active'] ? 'Nonaktifkan tenant ini' : 'Aktifkan tenant ini' ?>">
                                        <?= $t['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('PERINGATAN: Menghapus tenant <?= htmlspecialchars(addslashes($t['name'])) ?> akan menghapus SELURUH data siswa, bank soal, dan hasil ujian sekolah ini. Lanjutkan?')">
                                    <?= Helper::csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" style="border-radius: 9999px; padding: 5px 12px; font-weight: 600;">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH TENANT -->
<div class="modal-backdrop" id="modalAddTenant">
    <div class="modal-dialog" style="max-width: 600px;">
        <form method="POST">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Daftarkan Sekolah / Tenant Baru</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Sekolah / Lembaga</label>
                    <input type="text" name="name" class="form-control" placeholder="Contoh: SMA Negeri 1 Maju Bersama" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Kode Singkat Tenant (Unik)</label>
                        <input type="text" name="code" class="form-control" placeholder="Contoh: SMAN1" required style="text-transform: uppercase;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Slug URL</label>
                        <input type="text" name="slug" class="form-control" placeholder="Contoh: sman-1">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Subdomain Lembaga</label>
                    <input type="text" name="subdomain" class="form-control" placeholder="Contoh: sman-1">
                    <div class="subdomain-hint" style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                        Akses via <code>sman-1.localhost/cbtonline</code>. Hanya huruf kecil, angka, dan strip.
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Paket Layanan</label>
                        <select name="package" class="form-control" id="addPackageSelect">
                            <option value="semester">Paket Semesteran (6 Bulan)</option>
                            <option value="tahunan" selected>Paket 1 Tahun Penuh (12 Bulan)</option>
                            <option value="super">Paket Super (1 Tahun + Lengkap)</option>
                        </select>
                        <div id="addPackageInfoSemester" class="package-info-box" style="display: none; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
                            &#128101; 250 Siswa &bull; &#128104;&#8205;&#127979;&#65039; 10 Guru &bull; 🔒 Cetak Kartu Terkunci &bull; &#128065; Proctoring Standar
                        </div>
                        <div id="addPackageInfoTahunan" class="package-info-box" style="display: block; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
                            &#128101; 750 Siswa &bull; &#128104;&#8205;&#127979;&#65039; Unlimited Guru &bull; 🪪 Cetak Kartu Aktif &bull; &#128065; Proctoring Lengkap
                        </div>
                        <div id="addPackageInfoSuper" class="package-info-box" style="display: none; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
                            &#128101; Unlimited Siswa &bull; &#128104;&#8205;&#127979;&#65039; Unlimited Guru &bull; 🪪 Cetak Kartu Aktif &bull; &#128065; Full Proctoring &amp; Priority
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Batas Kuota Siswa</label>
                        <input type="number" name="student_quota" class="form-control" value="500" min="10" required>
                    </div>
                </div>

                <hr style="border: none; border-top: 1px solid var(--border); margin: 18px 0;">

                <h4 style="font-size: 14px; font-weight: 700; color: var(--text-heading); margin-bottom: 12px;">Akun Admin Sekolah Pertama:</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username Admin</label>
                        <input type="text" name="admin_username" class="form-control" placeholder="Contoh: admin_sman1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kata Sandi</label>
                        <input type="text" name="admin_password" class="form-control" value="password" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal style="border-radius: 9999px; padding: 7px 18px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="border-radius: 9999px; padding: 7px 20px; font-weight: 700;">Daftarkan Tenant</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT TENANT -->
<div class="modal-backdrop <?= $editTenant ? 'show' : '' ?>" id="modalEditTenant">
    <div class="modal-dialog" style="max-width: 600px;">
        <form method="POST">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="update">
            <?php if ($editTenant): ?>
                <input type="hidden" name="id" value="<?= $editTenant['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Edit Tenant <?= htmlspecialchars($editTenant['name'] ?? '') ?></h3>
                <?php if ($editTenant): ?>
                    <a href="tenants.php" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Sekolah / Lembaga</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editTenant['name'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Kode (tidak bisa diubah)</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($editTenant['code'] ?? '') ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Slug (tidak bisa diubah)</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($editTenant['slug'] ?? '') ?>" disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Subdomain</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($editTenant['subdomain'] ?? '') ?>" disabled>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                        <code><?= htmlspecialchars($editTenant['subdomain'] ?? '') ?>.localhost/cbtonline</code>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Paket Layanan</label>
                        <select name="package" class="form-control" onchange="updatePackageInfo(this.value)">
                            <option value="semester" <?= in_array($editTenant['package'] ?? '', ['semester', 'basic'], true) ? 'selected' : '' ?>>Paket Semesteran (6 Bulan)</option>
                            <option value="tahunan" <?= in_array($editTenant['package'] ?? '', ['tahunan', 'pro'], true) ? 'selected' : '' ?>>Paket 1 Tahun Penuh (12 Bulan)</option>
                            <option value="super" <?= in_array($editTenant['package'] ?? '', ['super', 'enterprise'], true) ? 'selected' : '' ?>>Paket Super (1 Tahun + Lengkap)</option>
                        </select>
                        <div id="packageInfoSemester" class="package-info-box" style="display: <?= in_array($editTenant['package'] ?? '', ['semester', 'basic'], true) ? 'block' : 'none' ?>; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
                            &#128101; 250 Siswa &bull; &#128104;&#8205;&#127979;&#65039; 10 Guru &bull; 🔒 Cetak Kartu Terkunci &bull; &#128065; Proctoring Standar
                        </div>
                        <div id="packageInfoTahunan" class="package-info-box" style="display: <?= in_array($editTenant['package'] ?? '', ['tahunan', 'pro'], true) ? 'block' : 'none' ?>; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
                            &#128101; 750 Siswa &bull; &#128104;&#8205;&#127979;&#65039; Unlimited Guru &bull; 🪪 Cetak Kartu Aktif &bull; &#128065; Proctoring Lengkap
                        </div>
                        <div id="packageInfoSuper" class="package-info-box" style="display: <?= in_array($editTenant['package'] ?? '', ['super', 'enterprise'], true) ? 'block' : 'none' ?>; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.8;">
                            &#128101; Unlimited Siswa &bull; &#128104;&#8205;&#127979;&#65039; Unlimited Guru &bull; 🪪 Cetak Kartu Aktif &bull; &#128065; Full Proctoring &amp; Priority
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Batas Kuota Siswa</label>
                        <input type="number" name="student_quota" class="form-control" value="<?= (int)($editTenant['student_quota'] ?? 500) ?>" min="10" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= !isset($editTenant['is_active']) || $editTenant['is_active'] ? 'checked' : '' ?>>
                        Tenant aktif
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editTenant): ?>
                    <a href="tenants.php" class="btn btn-outline" style="border-radius: 9999px; padding: 7px 18px;">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal style="border-radius: 9999px; padding: 7px 18px;">Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="border-radius: 9999px; padding: 7px 20px; font-weight: 700;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
