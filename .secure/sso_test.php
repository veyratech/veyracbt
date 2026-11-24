<?php
/**
 * Veyra SSO Simulation & Testing Console
 * Alat uji coba interaktif untuk menguji auto-login SSO ke CBT Online
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Tenant.php';
require_once __DIR__ . '/core/Auth.php';
use Firebase\JWT\JWT;

// Keamanan: Hanya izinkan akses simulator dari localhost atau sesi Superadmin aktif
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
if (!$isLocalRequest && (!Auth::check() || Auth::role() !== 'superadmin')) {
    http_response_code(403);
    die('Akses ditolak: Konsol Simulator SSO hanya dapat diakses di lingkungan lokal (localhost) atau oleh Superadmin.');
}

$ssoConfig = require __DIR__ . '/config/sso.php';
$secret = $ssoConfig['sso_secret'];

// Jika ada request simulasi, buatkan token dan langsung redirect ke callback CBT
if (isset($_GET['action']) && $_GET['action'] === 'launch') {
    $role = $_GET['role'] ?? 'admin';
    $now = time();
    $exp = $now + 300; // 5 menit

    switch ($role) {
        case 'admin':
            $payload = [
                'sub'         => 101,
                'name'        => 'Doni Pratama (Admin Sekolah)',
                'email'       => 'doni@smknusantara.sch.id',
                'username'    => 'admin_smknus',
                'role'        => 'admin',
                'tenant_id'   => 2,
                'tenant_name' => 'SMK Nusantara Teknologi',
                'iat'         => $now,
                'exp'         => $exp,
            ];
            break;

        case 'teacher':
            $payload = [
                'sub'         => 102,
                'name'        => 'Halimi Zuhdi, S.Pd (Guru)',
                'email'       => 'siti@sekolah.sch.id',
                'username'    => 'halimi',
                'role'        => 'teacher',
                'tenant_id'   => 1,
                'tenant_name' => 'SMKS AL-FATA',
                'iat'         => $now,
                'exp'         => $exp,
            ];
            break;

        case 'student':
            $payload = [
                'sub'         => 103,
                'name'        => 'Rizky Pratama (Siswa)',
                'nis'         => '2024101',
                'gender'      => 'L',
                'role'        => 'student',
                'tenant_id'   => 2,
                'tenant_name' => 'SMK Nusantara Teknologi',
                'iat'         => $now,
                'exp'         => $exp,
            ];
            break;

        case 'superadmin':
            $payload = [
                'sub'         => 1,
                'name'        => 'Master Superadmin Veyra',
                'username'    => 'admin',
                'email'       => 'admin@cbtplatform.test',
                'role'        => 'superadmin',
                'iat'         => $now,
                'exp'         => $exp,
            ];
            break;

        case 'new_tenant':
            $randId = rand(100, 999);
            $payload = [
                'sub'         => 5000 + $randId,
                'name'        => 'Kepala Sekolah Mitra Baru ' . $randId,
                'email'       => 'mitra' . $randId . '@sekolahbaru.sch.id',
                'username'    => 'admin_mitra' . $randId,
                'role'        => 'admin',
                'tenant_name' => 'SMA Bintang Mandiri ' . $randId,
                'iat'         => $now,
                'exp'         => $exp,
            ];
            break;

        default:
            die('Role tidak valid');
    }

    $jwt = JWT::encode($payload, $secret, 'HS256');
    $callbackUrl = 'sso/callback.php?token=' . urlencode($jwt);
    header('Location: ' . $callbackUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veyra Portal — Simulator Auto-Login SSO</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --card-bg: rgba(255, 255, 255, 0.03);
            --border: rgba(255, 255, 255, 0.08);
            --accent: #f59e0b;
            --cyan: #06b6d4;
            --green: #10b981;
            --purple: #8b5cf6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }
        .container {
            width: 100%;
            max-width: 900px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .title {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        .subtitle {
            color: #64748b;
            font-size: 14px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
        }
        .card-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
        }
        .card-sub {
            font-size: 12px;
            color: #64748b;
        }
        .card-desc {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
            margin-bottom: 18px;
        }
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .btn-amber  { background: linear-gradient(135deg, #f59e0b, #d97706); color: #000; }
        .btn-cyan   { background: linear-gradient(135deg, #06b6d4, #0891b2); color: #000; }
        .btn-green  { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
        .btn-purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #fff; }
        .btn-rose   { background: linear-gradient(135deg, #f43f5e, #e11d48); color: #fff; }

        .btn:hover {
            filter: brightness(1.15);
            transform: scale(1.02);
        }
        .info-box {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 20px;
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .code-pill {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(0, 0, 0, 0.4);
            padding: 2px 8px;
            border-radius: 6px;
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.2);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="badge">🌐 Simulator Ekosistem Veyra</div>
        <h1 class="title">Konsol Uji Coba Auto-Login SSO</h1>
        <p class="subtitle">Klik salah satu tombol di bawah untuk menguji auto-login instan dari Portal Veyra ke CBT Online.</p>
    </div>

    <div class="grid">
        <!-- 1. Admin Sekolah -->
        <div class="card">
            <div>
                <div class="card-top">
                    <div class="icon" style="background: rgba(6, 182, 212, 0.15); color: #22d3ee;">👨‍💼</div>
                    <div>
                        <div class="card-title">Admin Sekolah</div>
                        <div class="card-sub">SMK Nusantara</div>
                    </div>
                </div>
                <div class="card-desc">Simulasikan admin sekolah yang mengklik launcher CBT dari portal pusat. Langsung mendarat di panel kelola sekolah.</div>
            </div>
            <a href="sso_test.php?action=launch&role=admin" class="btn btn-cyan">Uji Auto-Login Admin &rarr;</a>
        </div>

        <!-- 2. Guru / Pengajar -->
        <div class="card">
            <div>
                <div class="card-top">
                    <div class="icon" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">👩‍🏫</div>
                    <div>
                        <div class="card-title">Guru / Pembuat Soal</div>
                        <div class="card-sub">SMKS AL-FATA</div>
                    </div>
                </div>
                <div class="card-desc">Simulasikan guru pengampu mapel. Otomatis terautentikasi dan langsung masuk ke dashboard manajemen ujian.</div>
            </div>
            <a href="sso_test.php?action=launch&role=teacher" class="btn btn-green">Uji Auto-Login Guru &rarr;</a>
        </div>

        <!-- 3. Siswa Peserta Ujian -->
        <div class="card">
            <div>
                <div class="card-top">
                    <div class="icon" style="background: rgba(139, 92, 246, 0.15); color: #a78bfa;">🎓</div>
                    <div>
                        <div class="card-title">Siswa (Peserta Ujian)</div>
                        <div class="card-sub">Rizky Pratama (NIS: 2024101)</div>
                    </div>
                </div>
                <div class="card-desc">Simulasikan siswa mengklik tombol ujian dari portal. Langsung masuk ke Student Dashboard tanpa login NIS lagi.</div>
            </div>
            <a href="sso_test.php?action=launch&role=student" class="btn btn-purple">Uji Auto-Login Siswa &rarr;</a>
        </div>

        <!-- 4. Superadmin Platform -->
        <div class="card">
            <div>
                <div class="card-top">
                    <div class="icon" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">⚡</div>
                    <div>
                        <div class="card-title">Superadmin Veyra</div>
                        <div class="card-sub">Master Admin Platform</div>
                    </div>
                </div>
                <div class="card-desc">Simulasikan Superadmin Veyra masuk ke ruang kendali multi-sekolah, live monitoring, dan audit log CBT.</div>
            </div>
            <a href="sso_test.php?action=launch&role=superadmin" class="btn btn-amber">Uji Auto-Login Superadmin &rarr;</a>
        </div>

        <!-- 5. Sekolah Baru (Auto-Provisioning) -->
        <div class="card">
            <div>
                <div class="card-top">
                    <div class="icon" style="background: rgba(244, 63, 94, 0.15); color: #fb7185;">✨</div>
                    <div>
                        <div class="card-title">Sekolah Baru (On-The-Fly)</div>
                        <div class="card-sub">Tes Auto-Provisioning</div>
                    </div>
                </div>
                <div class="card-desc">Menguji pembuatan otomatis profil sekolah dan akun baru di basis data CBT saat pertama kali mengklik CBT dari portal.</div>
            </div>
            <a href="sso_test.php?action=launch&role=new_tenant" class="btn btn-rose">Uji Auto-Provisioning &rarr;</a>
        </div>
    </div>

    <div class="info-box">
        <div>
            Status SSO CBT: <strong style="color: #10b981;">Aktif & Siap Menerima Token</strong> &bull; Algoritma: <span class="code-pill">HS256 (JWT)</span>
        </div>
        <div>
            <a href="index.php" style="color: #94a3b8; text-decoration: none; font-weight: 600;">&larr; Kembali ke Login CBT</a>
        </div>
    </div>
</div>

</body>
</html>