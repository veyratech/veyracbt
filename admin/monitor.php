<?php
// Force no-cache to prevent stale JS ReferenceError from old cached versions
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
/**
 * Live Monitoring Pengawas Ujian — Enhanced Proctoring Center
 * Real-time heartbeat, anti-cheat violation ticker, countdown timers,
 * broadcast announcements, extra time compensation & Berita Acara Ujian (BAU).
 */

$pageTitle = "Live Monitor Pengawas";
require_once __DIR__ . '/_bootstrap.php';

$examId = (int)($_GET['exam_id'] ?? 0);
$examsList = Database::fetchAll("SELECT id, title, token FROM exams WHERE tenant_id = ? ORDER BY id DESC", [$tenantId]);

if ($examId === 0 && !empty($examsList)) {
    $examId = (int)$examsList[0]['id'];
}

// -----------------------------------------------------------------------------
// 1. HANDLE POST ACTIONS
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';
    $sessionId = (int)($_POST['session_id'] ?? 0);

    // Helper aman untuk insert log aktivitas
    $logInsert = function(array $data) use ($tenantId): void {
        if (Database::tableExists('activity_logs')) {
            Database::insert('activity_logs', $data);
        }
    };

    try {
    if ($action === 'unblock') {
        Database::update('exam_sessions', [
            'status'           => 'in_progress',
            'total_violations' => 0
        ], 'id = :id AND tenant_id = :tenant_id', ['id' => $sessionId, 'tenant_id' => $tenantId]);

        $logInsert([
            'tenant_id'  => $tenantId,
            'session_id' => $sessionId,
            'event_type' => 'unblock',
            'message'    => "Pengawas membuka kunci status siswa untuk sesi #{$sessionId}",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        Helper::setFlash('success', 'Status siswa berhasil diaktifkan kembali.');
        Helper::redirect("admin/monitor.php?exam_id={$examId}");

    } elseif ($action === 'force_finish') {
        $score = 0;
        if (Database::tableExists('exam_answers') && Database::tableExists('questions')) {
            $score = Database::fetchColumn(
                "SELECT COALESCE(SUM(CASE WHEN q.question_type = 'essay' THEN COALESCE(ea.essay_score, 0)
                                         WHEN ea.is_correct = 1 THEN q.points ELSE 0 END), 0)
                 FROM exam_answers ea
                 JOIN questions q ON ea.question_id = q.id
                 WHERE ea.session_id = ?",
                [$sessionId]
            );
        }

        Database::update('exam_sessions', [
            'status'      => 'completed',
            'score'       => (float)$score,
            'finish_time' => date('Y-m-d H:i:s')
        ], 'id = :id AND tenant_id = :tenant_id', ['id' => $sessionId, 'tenant_id' => $tenantId]);

        $logInsert([
            'tenant_id'  => $tenantId,
            'session_id' => $sessionId,
            'event_type' => 'force_finish',
            'message'    => "Pengawas mengumpulkan paksa sesi #{$sessionId}",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        Helper::setFlash('success', 'Ujian siswa berhasil diselesaikan secara paksa.');
        Helper::redirect("admin/monitor.php?exam_id={$examId}");

    } elseif ($action === 'reset_session') {
        $sess = Database::fetch(
            "SELECT * FROM exam_sessions WHERE id = ? AND tenant_id = ?",
            [$sessionId, $tenantId]
        );
        if ($sess) {
            if (Database::tableExists('exam_answers')) {
                Database::delete('exam_answers', 'session_id = :sid', ['sid' => $sessionId]);
            }
            Database::delete('exam_sessions', 'id = :id AND tenant_id = :tenant_id', ['id' => $sessionId, 'tenant_id' => $tenantId]);

            $logInsert([
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
                'event_type' => 'reset_session',
                'message'    => "Pengawas mereset penuh sesi #{$sessionId}; siswa dapat mengerjakan ulang dengan token",
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            Helper::setFlash('success', 'Sesi ujian siswa direset. Siswa dapat mengerjakan ulang dengan token.');
        } else {
            Helper::setFlash('danger', 'Sesi ujian tidak ditemukan.');
        }
        Helper::redirect("admin/monitor.php?exam_id={$examId}");

    } elseif ($action === 'add_extra_time') {
        $extraMinutes = (int)($_POST['extra_minutes'] ?? 0);
        if ($sessionId > 0 && $extraMinutes > 0) {
            Database::query(
                "UPDATE exam_sessions SET extra_time_minutes = extra_time_minutes + ? WHERE id = ? AND tenant_id = ?",
                [$extraMinutes, $sessionId, $tenantId]
            );

            $logInsert([
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
                'event_type' => 'extra_time',
                'message'    => "Pengawas menambahkan waktu kompensasi {$extraMinutes} menit untuk sesi #{$sessionId}",
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            Helper::setFlash('success', "Waktu ujian siswa berhasil ditambah {$extraMinutes} menit.");
        }
        Helper::redirect("admin/monitor.php?exam_id={$examId}");

    } elseif ($action === 'broadcast') {
        $message = trim($_POST['message'] ?? '');
        $targetSessionId = (int)($_POST['target_session_id'] ?? 0) ?: null;

        if ($message !== '' && $examId > 0 && Database::tableExists('exam_broadcasts')) {
            Database::insert('exam_broadcasts', [
                'tenant_id'   => $tenantId,
                'exam_id'     => $examId,
                'session_id'  => $targetSessionId,
                'message'     => $message,
                'sender_name' => $user['name'] ?? 'Pengawas Ruang'
            ]);

            $targetMsg = $targetSessionId ? "khusus sesi #{$targetSessionId}" : "ke seluruh peserta";
            $logInsert([
                'tenant_id'  => $tenantId,
                'session_id' => $targetSessionId,
                'event_type' => 'broadcast',
                'message'    => "Pengawas mengirim siaran ({$targetMsg}): \"{$message}\"",
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            Helper::setFlash('success', 'Pesan pengumuman berhasil dikirim ke layar ujian siswa.');
        } elseif ($message !== '' && $examId > 0) {
            Helper::setFlash('warning', 'Tabel siaran belum tersedia. Tabel exam_broadcasts belum dibuat di database.');
        }
        Helper::redirect("admin/monitor.php?exam_id={$examId}");

    } elseif ($action === 'bulk_force_finish') {
        $inProgressSessions = [];
        if (Database::tableExists('exam_sessions')) {
            $inProgressSessions = Database::fetchAll(
                "SELECT id FROM exam_sessions WHERE exam_id = ? AND tenant_id = ? AND status = 'in_progress'",
                [$examId, $tenantId]
            );
        }

        $count = 0;
        foreach ($inProgressSessions as $sessRow) {
            $sid = (int)$sessRow['id'];
            $score = 0;
            if (Database::tableExists('exam_answers') && Database::tableExists('questions')) {
                $score = Database::fetchColumn(
                    "SELECT COALESCE(SUM(CASE WHEN q.question_type = 'essay' THEN COALESCE(ea.essay_score, 0)
                                             WHEN ea.is_correct = 1 THEN q.points ELSE 0 END), 0)
                     FROM exam_answers ea
                     JOIN questions q ON ea.question_id = q.id
                     WHERE ea.session_id = ?",
                    [$sid]
                );
            }

            Database::update('exam_sessions', [
                'status'      => 'completed',
                'score'       => (float)$score,
                'finish_time' => date('Y-m-d H:i:s')
            ], 'id = :id AND tenant_id = :tenant_id', ['id' => $sid, 'tenant_id' => $tenantId]);
            $count++;
        }

        if ($count > 0) {
            $logInsert([
                'tenant_id'  => $tenantId,
                'session_id' => null,
                'event_type' => 'bulk_force_finish',
                'message'    => "Pengawas mengumpulkan paksa massal seluruh sesi berjalan ({$count} siswa)",
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            Helper::setFlash('success', "Berhasil mengumpulkan paksa {$count} siswa yang sedang berjalan.");
        } else {
            Helper::setFlash('info', 'Tidak ada siswa yang sedang berjalan.');
        }
        Helper::redirect("admin/monitor.php?exam_id={$examId}");

    } elseif ($action === 'bulk_unblock') {
        if (Database::tableExists('exam_sessions')) {
            Database::query(
                "UPDATE exam_sessions SET status = 'in_progress', total_violations = 0
                 WHERE exam_id = ? AND tenant_id = ? AND status = 'blocked'",
                [$examId, $tenantId]
            );
        }

        $logInsert([
            'tenant_id'  => $tenantId,
            'session_id' => null,
            'event_type' => 'bulk_unblock',
            'message'    => "Pengawas membuka kunci massal seluruh siswa terblokir",
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        Helper::setFlash('success', 'Seluruh siswa yang terblokir berhasil dibuka kuncinya.');
        Helper::redirect("admin/monitor.php?exam_id={$examId}");
    }
    } catch (\Throwable $e) {
        error_log('[monitor-post] ' . $e->getMessage());
        Helper::setFlash('danger', 'Terjadi kesalahan saat memproses aksi. Silakan coba lagi.');
        Helper::redirect("admin/monitor.php?exam_id={$examId}");
    }
}

// -----------------------------------------------------------------------------
// 2. FETCH DATA UJIAN & PESERTA
// -----------------------------------------------------------------------------
$participants = [];
$totalQuestionsInBank = 0;
$recentLogs = [];
$kpi = [
    'total'        => 0,
    'in_progress'  => 0,
    'completed'    => 0,
    'blocked'      => 0,
    'offline'      => 0,
    'online'       => 0,
    'avg_progress' => 0
];

try {
    $selectedExam = Database::fetch(
        "SELECT e.*, qb.title as bank_title, s.name as subject_name
         FROM exams e
         JOIN question_banks qb ON e.bank_id = qb.id
         JOIN subjects s ON qb.subject_id = s.id
         WHERE e.id = ? AND e.tenant_id = ?",
        [$examId, $tenantId]
    );

    if ($selectedExam) {
        $totalQuestionsInBank = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM questions WHERE bank_id = ?",
            [$selectedExam['bank_id']]
        );

        $participants = Database::fetchAll(
            "SELECT es.id, es.status, es.total_violations, es.start_time, es.end_time,
                    es.extra_time_minutes, es.last_activity, es.finish_time, es.score, es.ip_address,
                    es.last_webcam_frame, es.last_webcam_at, es.webcam_status,
                    st.name as student_name, st.nis, c.name as class_name,
                    (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id AND (student_answer IS NOT NULL OR answer_text IS NOT NULL)) as answered_count
             FROM exam_sessions es
             JOIN students st ON es.student_id = st.id
             LEFT JOIN classes c ON st.class_id = c.id
             WHERE es.exam_id = ? AND es.tenant_id = ?
             ORDER BY es.status DESC, es.total_violations DESC, es.start_time DESC",
            [$examId, $tenantId]
        );

        $recentLogs = Database::fetchAll(
            "SELECT al.id, al.session_id, al.event_type, al.message, al.created_at, al.ip_address,
                    st.name as student_name, st.nis
             FROM activity_logs al
             JOIN exam_sessions es ON al.session_id = es.id
             JOIN students st ON es.student_id = st.id
             WHERE es.exam_id = ? AND al.tenant_id = ?
             ORDER BY al.id DESC LIMIT 20",
            [$examId, $tenantId]
        );
    } else {
        // Fallback: tampilkan pesan jika ujian tidak ditemukan
        $participants = [];
    }
} catch (\Throwable $e) {
    error_log('[monitor-fetch] ' . $e->getMessage());
    $selectedExam = null;
    $participants = [];
    $recentLogs = [];
}

if (!$selectedExam) {
    $examId = 0;
}

// -----------------------------------------------------------------------------
// 3. CETAK BERITA ACARA UJIAN (BAU) JIKA DIPINTA (?print_bau=1)
// -----------------------------------------------------------------------------
if (isset($_GET['print_bau']) && $selectedExam) {
    $countTotal = count($participants);
    $countDone = 0;
    $countBlocked = 0;
    $countProgress = 0;
    $totalScore = 0;

    foreach ($participants as $p) {
        if ($p['status'] === 'completed') {
            $countDone++;
            $totalScore += (float)$p['score'];
        } elseif ($p['status'] === 'blocked') {
            $countBlocked++;
        } else {
            $countProgress++;
        }
    }
    $avgScore = $countDone > 0 ? round($totalScore / $countDone, 1) : 0;

    $violationsLogs = Database::fetchAll(
        "SELECT al.*, st.name as student_name, st.nis 
         FROM activity_logs al
         JOIN exam_sessions es ON al.session_id = es.id
         JOIN students st ON es.student_id = st.id
         WHERE es.exam_id = ? AND al.tenant_id = ? AND al.event_type = 'violation'
         ORDER BY al.id ASC",
        [$examId, $tenantId]
    );
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Berita Acara Ujian - <?= htmlspecialchars($selectedExam['title']) ?></title>
        <style>
            body { font-family: 'Times New Roman', Times, serif; font-size: 13pt; color: #000; margin: 0; padding: 25px; line-height: 1.4; }
            .header-kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 12px; margin-bottom: 20px; }
            .header-kop h2 { margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase; }
            .header-kop p { margin: 3px 0 0 0; font-size: 11pt; }
            .bau-title { text-align: center; font-size: 14pt; font-weight: bold; text-transform: uppercase; margin: 15px 0 20px 0; text-decoration: underline; }
            table.info-meta { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            table.info-meta td { padding: 4px 6px; font-size: 12pt; vertical-align: top; }
            table.data-table { width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 25px; }
            table.data-table th, table.data-table td { border: 1px solid #000; padding: 6px 8px; font-size: 11pt; }
            table.data-table th { background: #f0f0f0; text-align: center; }
            .signature-box { display: flex; justify-content: space-between; margin-top: 40px; page-break-inside: avoid; }
            .sig-col { text-align: center; width: 45%; }
            .sig-space { height: 75px; }
            .no-print { background: #f1f5f9; padding: 12px 20px; border-bottom: 1px solid #cbd5e1; margin: -25px -25px 25px -25px; display: flex; gap: 10px; align-items: center; justify-content: space-between; font-family: sans-serif; }
            @media print { .no-print { display: none !important; } body { padding: 0; } }
        </style>
    </head>
    <body>
        <div class="no-print">
            <div>
                <b>Dokumen Berita Acara Pelaksanaan Ujian CBT</b> (Siap Cetak / Simpan PDF)
            </div>
            <div style="display: flex; gap: 8px;">
                <button onclick="window.print()" style="padding: 8px 16px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: bold; cursor: pointer;">
                    🖨️ Cetak Dokumen (Print / PDF)
                </button>
                <button onclick="window.close();" style="padding: 8px 16px; background: #64748b; color: #fff; border: none; border-radius: 6px; cursor: pointer;">
                    Tutup Halaman
                </button>
            </div>
        </div>

        <div class="header-kop">
            <h2><?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></h2>
            <p><?= htmlspecialchars($school['address'] ?: 'Alamat Sekolah Terdaftar') ?> | Telp: <?= htmlspecialchars($school['phone'] ?: '-') ?></p>
            <p>Sistem Ujian Berbasis Komputer (CBT Online Multi-Tenant)</p>
        </div>

        <div class="bau-title">BERITA ACARA PELAKSANAAN UJIAN CBT</div>

        <p>Pada hari ini <b><?= date('l') ?></b>, tanggal <b><?= date('d') ?></b> bulan <b><?= date('F') ?></b> tahun <b><?= date('Y') ?></b>, telah diselenggarakan Ujian Berbasis Komputer (CBT) dengan data pelaksanaan sebagai berikut:</p>

        <table class="info-meta">
            <tr>
                <td style="width: 25%;"><b>Mata Pelajaran</b></td>
                <td style="width: 2%;">:</td>
                <td style="width: 73%;"><?= htmlspecialchars($selectedExam['subject_name']) ?></td>
            </tr>
            <tr>
                <td><b>Judul Ujian</b></td>
                <td>:</td>
                <td><?= htmlspecialchars($selectedExam['title']) ?> (Bank Soal: <?= htmlspecialchars($selectedExam['bank_title']) ?>)</td>
            </tr>
            <tr>
                <td><b>Kode Token Ujian</b></td>
                <td>:</td>
                <td><b><?= htmlspecialchars($selectedExam['token']) ?></b></td>
            </tr>
            <tr>
                <td><b>Durasi & Waktu</b></td>
                <td>:</td>
                <td><?= (int)$selectedExam['duration_minutes'] ?> Menit (Jadwal: <?= $selectedExam['start_time'] ?> s/d <?= $selectedExam['end_time'] ?>)</td>
            </tr>
            <tr>
                <td><b>Batas Pelanggaran Layar</b></td>
                <td>:</td>
                <td><?= (int)$selectedExam['max_violations'] ?>x Pindah Tab / Keluar Layar Penuh</td>
            </tr>
            <tr>
                <td><b>Rekapitulasi Peserta</b></td>
                <td>:</td>
                <td>
                    Total Masuk: <b><?= $countTotal ?> Siswa</b> |
                    Selesai: <b><?= $countDone ?></b> |
                    Sedang Berjalan: <b><?= $countProgress ?></b> |
                    Terblokir: <b><?= $countBlocked ?></b> |
                    Rata-rata Nilai: <b><?= $avgScore ?></b>
                </td>
            </tr>
        </table>

        <div style="font-weight: bold; margin-top: 15px;">A. Catatan Pelanggaran dan Kejadian Ruang Ujian:</div>
        <?php if (empty($violationsLogs)): ?>
            <p style="font-style: italic; margin-left: 20px;">Ujian berlangsung dengan tertib dan lancar. Tidak ada pelanggaran fatal yang dicatat sistem.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">No</th>
                        <th>Waktu</th>
                        <th>NIS & Nama Siswa</th>
                        <th>Deskripsi Pelanggaran / Catatan Sistem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($violationsLogs as $idx => $vl): ?>
                        <tr>
                            <td style="text-align: center;"><?= $idx + 1 ?></td>
                            <td style="text-align: center;"><?= date('H:i:s', strtotime($vl['created_at'])) ?></td>
                            <td><b><?= htmlspecialchars($vl['student_name']) ?></b> (<?= htmlspecialchars($vl['nis']) ?>)</td>
                            <td><?= htmlspecialchars($vl['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="font-weight: bold; margin-top: 15px;">B. Daftar Kehadiran dan Status Peserta Ujian:</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 35px;">No</th>
                    <th>NIS</th>
                    <th>Nama Siswa</th>
                    <th>Kelas</th>
                    <th>Progress</th>
                    <th>Pelanggaran</th>
                    <th>Nilai Akhir</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $idx => $p): ?>
                    <tr>
                        <td style="text-align: center;"><?= $idx + 1 ?></td>
                        <td style="text-align: center;"><code><?= htmlspecialchars($p['nis']) ?></code></td>
                        <td><?= htmlspecialchars($p['student_name']) ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($p['class_name'] ?: 'Umum') ?></td>
                        <td style="text-align: center;"><?= (int)$p['answered_count'] ?> / <?= $totalQuestionsInBank ?></td>
                        <td style="text-align: center;"><?= (int)$p['total_violations'] ?>x</td>
                        <td style="text-align: center; font-weight: bold;"><?= $p['status'] === 'completed' ? number_format($p['score'], 1) : '-' ?></td>
                        <td style="text-align: center;"><?= strtoupper($p['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>Demikian berita acara pelaksanaan ujian ini dibuat dengan sesungguhnya untuk dapat dipergunakan sebagaimana mestinya.</p>

        <div class="signature-box">
            <div class="sig-col">
                <div>Mengetahui,</div>
                <div><b>Kepala Sekolah / Penanggung Jawab CBT</b></div>
                <div class="sig-space"></div>
                <div>( ............................................................ )</div>
                <div>NIP. ....................................................</div>
            </div>
            <div class="sig-col">
                <div><?= date('d F Y') ?></div>
                <div><b>Pengawas Ruang Ujian</b></div>
                <div class="sig-space"></div>
                <div><b><?= htmlspecialchars($user['name'] ?? 'Pengawas Ruangan') ?></b></div>
                <div>NIP/Username: <?= htmlspecialchars($user['username'] ?? '-') ?></div>
            </div>
        </div>
    </body>
    </html>
    <?php
exit;
}

// -----------------------------------------------------------------------------
// 4. PERSIAPAN DATA KPI LIVE MONITOR
// -----------------------------------------------------------------------------
$kpi = [
    'total'        => count($participants),
    'in_progress'  => 0,
    'completed'    => 0,
    'blocked'      => 0,
    'offline'      => 0,
    'online'       => 0,
    'avg_progress' => 0
];
$totalProgressPct = 0;
$now = time();

foreach ($participants as &$p) {
    $effEnd = strtotime($p['end_time']) + ((int)$p['extra_time_minutes'] * 60);
    $p['remSec'] = max(0, $effEnd - $now);
    $p['remaining_formatted'] = sprintf('%02d:%02d', floor($p['remSec'] / 60), $p['remSec'] % 60);

    $hasWebcam = !empty($p['last_webcam_frame']) && file_exists(__DIR__ . '/../' . $p['last_webcam_frame']);
    $isWebcamLive = !empty($p['last_webcam_at']) && ($now - strtotime($p['last_webcam_at']) <= 20);
    $p['has_webcam'] = $hasWebcam;
    $p['is_webcam_live'] = $isWebcamLive;
    // Lewat proxy terautentikasi (file asli diblokir .htaccess)
    $p['webcam_url'] = $hasWebcam
        ? ('webcam-frame.php?session_id=' . (int)$p['id'] . '&t=' . (int)strtotime($p['last_webcam_at']))
        : null;
    $p['webcam_status'] = $p['webcam_status'] ?? 'active';

    // Hitung status online / idle / offline
    if ($p['status'] === 'completed') {
        $p['online_status'] = 'completed';
        $p['online_text'] = 'Selesai';
        $kpi['completed']++;
    } elseif ($p['status'] === 'blocked') {
        $p['online_status'] = 'blocked';
        $p['online_text'] = 'Diblokir';
        $kpi['blocked']++;
    } else {
        $kpi['in_progress']++;
        if (!empty($p['last_activity'])) {
            $diff = $now - strtotime($p['last_activity']);
            if ($diff <= 25) {
                $p['online_status'] = 'online';
                $p['online_text'] = 'Online';
                $kpi['online']++;
            } elseif ($diff <= 75) {
                $p['online_status'] = 'idle';
                $p['online_text'] = 'Idle (' . $diff . 'd)';
            } else {
                $p['online_status'] = 'offline';
                $p['online_text'] = 'Offline (' . floor($diff / 60) . 'm lalu)';
                $kpi['offline']++;
            }
        } else {
            $p['online_status'] = 'offline';
            $p['online_text'] = 'Belum aktif';
            $kpi['offline']++;
        }
    }

    $answered = (int)$p['answered_count'];
    $pct = $totalQuestionsInBank > 0 ? min(100, round(($answered / $totalQuestionsInBank) * 100)) : 0;
    $p['progress_pct'] = $pct;
    $totalProgressPct += $pct;
}
unset($p);

if ($kpi['total'] > 0) {
    $kpi['avg_progress'] = round($totalProgressPct / $kpi['total']);
}

require_once __DIR__ . '/header.php';
?>

<style>
    .conn-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #6b7280; margin-right: 6px; transition: background 0.3s ease; }
    .conn-dot.connected { background: #10b981; box-shadow: 0 0 6px rgba(16,185,129,0.6); }
    .conn-dot.disconnected { background: #ef4444; }
    .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
    .status-dot.online { background: #10b981; box-shadow: 0 0 5px #10b981; }
    .status-dot.idle { background: #f59e0b; box-shadow: 0 0 5px #f59e0b; }
    .status-dot.offline { background: #ef4444; }
    .status-dot.completed { background: #3b82f6; }
    .row-update { animation: rowFlash 0.6s ease; }
    @keyframes rowFlash { 0% { background: rgba(37,99,235,0.15); } 100% { background: transparent; } }
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
    .kpi-card { background: #fff; border: 1px solid #eef2f7; border-radius: 12px; padding: 14px 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
    .kpi-card .val { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.2; }
    .kpi-card .lbl { font-size: 11.5px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
    .ticker-feed { max-height: 580px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px; }
    .ticker-item { background: #f8fafc; border: 1px solid #eef2f7; border-radius: 10px; padding: 10px 12px; font-size: 12px; line-height: 1.4; display: flex; flex-direction: column; gap: 2px; transition: transform 0.2s ease; }
    .ticker-item:hover { transform: translateX(2px); background: #f1f5f9; }
    .filter-chip { border: 1px solid #e2e8f0; background: #fff; border-radius: 20px; padding: 5px 14px; font-size: 12px; font-weight: 600; color: #475569; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 5px; }
    .filter-chip:hover, .filter-chip.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    /* CCTV GRID STYLES */
    .cctv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .cctv-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .cctv-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
    .cctv-header { padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #eef2f7; display: flex; justify-content: space-between; align-items: center; }
    .cctv-preview { position: relative; background: #0f172a; width: 100%; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer; }
    .cctv-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .cctv-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8; gap: 6px; padding: 20px; text-align: center; font-size: 12px; }
    .cctv-badge-live { position: absolute; top: 8px; left: 8px; background: rgba(16, 185, 129, 0.9); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; display: flex; align-items: center; gap: 5px; letter-spacing: 0.5px; backdrop-filter: blur(4px); }
    .cctv-badge-time { position: absolute; bottom: 8px; left: 8px; background: rgba(15, 23, 42, 0.85); color: #f8fafc; font-family: monospace; font-size: 11px; padding: 2px 7px; border-radius: 4px; backdrop-filter: blur(4px); }
    .cctv-badge-viol { position: absolute; top: 8px; right: 8px; background: rgba(239, 68, 68, 0.9); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; backdrop-filter: blur(4px); }
    .cctv-body { padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .cctv-footer { padding: 10px 14px; background: #fafafa; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 6px; }
    .pulse-dot { width: 6px; height: 6px; border-radius: 50%; background: #fff; animation: cctvPulse 1.2s infinite; }
    @keyframes cctvPulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.3; transform: scale(0.8); } 100% { opacity: 1; transform: scale(1); } }
    .view-btn { font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.2s ease; background: transparent; color: #475569; display: inline-flex; align-items: center; gap: 6px; }
    .view-btn.active { background: #2563eb; color: #fff; box-shadow: 0 1px 3px rgba(37,99,235,0.3); }

    /* ── RESPONSIVE: tablet & below ─────────────────────────────────────────── */
    @media (max-width: 900px) {
        .kpi-grid { grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; }
        .kpi-card { padding: 10px 10px; }
        .kpi-card .val { font-size: 18px; }
        .kpi-card .lbl { font-size: 10px; }
    }

    @media (max-width: 768px) {
        .kpi-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .kpi-card { padding: 8px 8px; }
        .kpi-card .val { font-size: 16px; }
        .kpi-card .lbl { font-size: 9px; letter-spacing: 0; text-transform: none; }

        /* Stack the main two-column layout: table + ticker → vertical */
        .monitor-main-grid {
            grid-template-columns: 1fr !important;
        }
        .monitor-ticker-col {
            order: -1 !important;   /* put ticker above table on mobile */
        }

        /* Make monitor table scroll horizontally on narrow screens */
        .monitor-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -4px;
            padding: 0 4px;
        }
        .monitor-table-wrap .table thead th,
        .monitor-table-wrap .table tbody td {
            white-space: nowrap;
            padding: 8px 10px;
            font-size: 12px;
        }
        /* Shrink controls column buttons on mobile */
        .prog-controls {
            min-width: 200px;
            white-space: nowrap;
        }

        /* Top control bar: stack selector + buttons vertically */
        .monitor-topbar {
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }
        .monitor-topbar select.form-control {
            min-width: 100% !important;
        }
        .monitor-topbar-btns {
            flex-wrap: wrap;
        }

        /* Filter chips row */
        .monitor-filter-row {
            flex-direction: column;
            align-items: flex-start !important;
        }
        .monitor-view-switcher {
            margin-top: 6px;
        }

        /* CCTV grid: single column on very small phones */
        .cctv-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        .kpi-card .val { font-size: 15px; }
        .kpi-card .lbl { font-size: 8.5px; }

        .filter-chip { padding: 4px 10px; font-size: 11px; }
        .view-btn { font-size: 11px; padding: 5px 9px; }
    }
</style>

<!-- TOP CONTROL BAR -->
    <div class="monitor-topbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0;">Live Proctoring Monitor</h2>
        <p style="color: #64748b; font-size: 13px; margin: 3px 0 0 0;">Pantau aktivitas pengerjaan, deteksi kecurangan, dan kendali pengawas real-time</p>
    </div>

    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <!-- Selector Ujian -->
        <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0;">
            <select name="exam_id" class="form-control" style="font-weight: 600; font-size: 13px; min-width: 220px;" onchange="this.form.submit()">
                <?php foreach ($examsList as $ex): ?>
                    <option value="<?= $ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ex['title']) ?> (Token: <?= $ex['token'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- Tombol Broadcast Pengawas -->
        <button type="button" class="btn btn-outline btn-sm" onclick="openBroadcastModal()">
            📢 Kirim Pengumuman
        </button>

        <!-- Tombol Aksi Massal Dropdown / Modal -->
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalBulkActions').classList.add('show')">
            ⚡ Aksi Massal
        </button>

        <!-- Tombol Cetak Berita Acara Ujian (BAU) -->
        <a href="monitor.php?exam_id=<?= $examId ?>&print_bau=1" target="_blank" class="btn btn-primary btn-sm" title="Buka dan cetak dokumen resmi Berita Acara Ujian">
            📄 Cetak Berita Acara (BAU)
        </a>

        <!-- Auto Refresh Toggle -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; display: flex; align-items: center; gap: 8px;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; cursor: pointer; white-space: nowrap; margin: 0;">
                <input type="checkbox" id="autoRefreshToggle"> Auto 5 dtk
            </label>
            <span class="conn-dot" id="connDot"></span>
            <span id="connText" style="font-size: 11.5px; font-weight: 600; color: #64748b;">Disconnected</span>
        </div>
    </div>
</div>

<?php if (!$selectedExam): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: 48px; color: var(--text-muted);">
            Belum ada ujian yang dipilih.
        </div>
    </div>
<?php else: ?>

    <!-- KPI SUMMARY METRICS -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="val" id="statTotal"><?= $kpi['total'] ?></div>
            <div class="lbl">Total Masuk</div>
        </div>
        <div class="kpi-card">
            <div class="val" style="color: #f59e0b;" id="statInProgress"><?= $kpi['in_progress'] ?></div>
            <div class="lbl">Sedang Berjalan</div>
        </div>
        <div class="kpi-card">
            <div class="val" style="color: #10b981;" id="statOnline"><?= $kpi['online'] ?></div>
            <div class="lbl">Online Aktif</div>
        </div>
        <div class="kpi-card">
            <div class="val" style="color: #ef4444;" id="statOffline"><?= $kpi['offline'] ?></div>
            <div class="lbl">Terputus / Idle</div>
        </div>
        <div class="kpi-card">
            <div class="val" style="color: #dc2626;" id="statBlocked"><?= $kpi['blocked'] ?></div>
            <div class="lbl">Terblokir Layar</div>
        </div>
        <div class="kpi-card">
            <div class="val" style="color: #2563eb;" id="statCompleted"><?= $kpi['completed'] ?></div>
            <div class="lbl">Telah Selesai</div>
        </div>
        <div class="kpi-card">
            <div class="val" style="color: #059669;" id="statAvgProgress"><?= $kpi['avg_progress'] ?>%</div>
            <div class="lbl">Rata-rata Progres</div>
        </div>
    </div>

    <!-- FILTER CHIPS & EXAM HEADER BAR -->
    <div class="monitor-filter-row" style="background: #fff; border: 1px solid #eef2f7; border-radius: 12px; padding: 12px 18px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span style="font-size: 12px; font-weight: 700; color: #475569; margin-right: 4px;">Filter:</span>
            <button type="button" class="filter-chip active" data-filter="all">Semua (<span id="chipAll"><?= $kpi['total'] ?></span>)</button>
            <button type="button" class="filter-chip" data-filter="in_progress">🟢 Mengerjakan (<span id="chipProgress"><?= $kpi['in_progress'] ?></span>)</button>
            <button type="button" class="filter-chip" data-filter="blocked">🔴 Terblokir (<span id="chipBlocked"><?= $kpi['blocked'] ?></span>)</button>
            <button type="button" class="filter-chip" data-filter="offline">⚠️ Offline (<span id="chipOffline"><?= $kpi['offline'] ?></span>)</button>
            <button type="button" class="filter-chip" data-filter="completed">✅ Selesai (<span id="chipDone"><?= $kpi['completed'] ?></span>)</button>
        </div>

        <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
            <!-- View Mode Switcher: Table vs CCTV Grid -->
            <div class="monitor-view-switcher" style="display: flex; background: #f1f5f9; padding: 3px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <button type="button" id="btnViewTable" class="view-btn active" onclick="switchMonitorView('table')">
                    📋 Tampilan Tabel
                </button>
                <button type="button" id="btnViewCctv" class="view-btn" onclick="switchMonitorView('cctv')">
                    📹 Kamera CCTV (Grid)
                </button>
            </div>

            <div style="font-size: 12.5px; color: #64748b;">
                TOKEN: <b style="color: #2563eb;"><?= htmlspecialchars($selectedExam['token']) ?></b> |
                Batas Layar: <b style="color: #d97706;"><?= (int)$selectedExam['max_violations'] ?>x</b> |
                Mapel: <b><?= htmlspecialchars($selectedExam['subject_name']) ?></b>
            </div>
        </div>
    </div>

        <!-- ALERT BANNER FOR NEW VIOLATIONS -->
        <div id="violationAlertBar" style="display:none; position:fixed; top:70px; left:50%; transform:translateX(-50%); background:linear-gradient(90deg,#dc2626,#b91c1c); color:#fff; padding:12px 24px; border-radius:12px; font-weight:700; font-size:14px; z-index:9999; box-shadow:0 4px 20px rgba(220,38,38,.4); align-items:center; gap:10px;">
            <span style="font-size:18px;">⚠️</span>
            <span id="violationAlertText">新违规行为发生!</span>
            <button onclick="this.parentElement.style.display='none'" style="margin-left:12px;background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;">✕</button>
        </div>

    <!-- MAIN TWO-COLUMN LAYOUT (TABLE/CCTV + TICKER FEED) -->
    <div class="monitor-main-grid" style="display: grid; grid-template-columns: 1fr 310px; gap: 16px; align-items: start;">
        
        <!-- LEFT: PARTICIPANT VIEWS (TABLE OR CCTV GRID) -->
        <div class="monitor-table-col">
            <!-- 1. TAMPILAN TABEL -->
            <div class="card" id="tableContainer" style="margin: 0;">
                <div class="card-body" style="padding: 0;">
                    <?php if (empty($participants)): ?>
                        <p style="padding: 40px; text-align: center; color: var(--text-muted);" id="emptyMsg">Belum ada siswa yang memulai ujian ini.</p>
                    <?php else: ?>
                        <div class="monitor-table-wrap">
                            <table class="table" id="monitorTable">
                                <thead>
                                    <tr>
                                        <th>NIS &amp; Nama Siswa</th>
                                        <th>Kelas</th>
                                        <th>Progress Soal</th>
                                        <th>Sisa Waktu</th>
                                        <th>Status</th>
                                        <th>Pelanggaran</th>
                                        <th style="text-align: right;">Kontrol Pengawas</th>
                                    </tr>
                                </thead>
                                <tbody id="monitorBody">
                                    <?php foreach ($participants as $p): ?>
                                        <tr data-session-id="<?= $p['id'] ?>" data-status="<?= $p['status'] ?>" data-online="<?= $p['online_status'] ?>">
                                            <td>
                                                <div style="display: flex; align-items: center;">
                                                    <span class="status-dot <?= $p['online_status'] ?>" title="Koneksi: <?= $p['online_text'] ?>"></span>
                                                    <div>
                                                        <b><?= htmlspecialchars($p['student_name']) ?></b>
                                                        <div style="font-size: 11px; color: var(--text-muted);">
                                                            <code><?= htmlspecialchars($p['nis']) ?></code> &bull; <span style="font-size: 10px;"><?= $p['online_text'] ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="badge badge-neutral"><?= htmlspecialchars($p['class_name'] ?: 'Umum') ?></span></td>
                                            <td>
                                                <b class="prog-answered"><?= (int)$p['answered_count'] ?></b> / <?= $totalQuestionsInBank ?> Soal (<?= $p['progress_pct'] ?>%)
                                                <div style="width: 100px; height: 5px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 3px;">
                                                    <div class="prog-bar" style="height: 100%; width: <?= $p['progress_pct'] ?>%; background: #10b981; transition: width 0.4s ease;"></div>
                                                </div>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <b class="timer-countdown" style="font-family: monospace; font-size: 13px;"><?= $p['remaining_formatted'] ?></b>
                                                <?php if ($p['extra_time_minutes'] > 0): ?>
                                                    <span class="badge badge-warning" style="font-size: 10px; padding: 2px 5px;" title="Kompensasi waktu ekstra">+<?= $p['extra_time_minutes'] ?>m</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="prog-status">
                                                <?php if ($p['status'] === 'in_progress'): ?>
                                                    <span class="badge badge-warning">&#9679; Mengerjakan</span>
                                                <?php elseif ($p['status'] === 'completed'): ?>
                                                    <span class="badge badge-success">&#10004; Selesai (<?= number_format($p['score'], 1) ?>)</span>
                                                <?php elseif ($p['status'] === 'blocked'): ?>
                                                    <span class="badge badge-danger">&#9888; Diblokir</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="prog-violations">
                                                <?php if ($p['total_violations'] > 0): ?>
                                                    <span class="badge badge-danger"><?= $p['total_violations'] ?>x Pindah</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">0 (Tertib)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;" class="prog-controls">
                                                <!-- Tombol Buka Kamera Live Siswa -->
                                                <button type="button" class="btn btn-outline btn-sm" onclick="openLiveCameraModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ', '<?= htmlspecialchars($p['nis']) ?>', '<?= htmlspecialchars($p['webcam_url'] ?? '') ?>')" title="Pantau feed kamera live siswa">
                                                    📷 Kamera
                                                </button>

                                                <!-- Tombol Tambah Waktu Kompensasi -->
                                                <button type="button" class="btn btn-outline btn-sm" onclick="openExtraTimeModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')" title="Tambah waktu kompensasi khusus siswa ini">
                                                    +Waktu
                                                </button>

                                                <!-- Tombol Kirim Pesan Privat -->
                                                <button type="button" class="btn btn-outline btn-sm" onclick="openBroadcastModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')" title="Kirim pesan langsung ke layar siswa ini">
                                                    Pesan
                                                </button>

                                                <?php if ($p['status'] === 'blocked'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <?= Helper::csrfField() ?>
                                                        <input type="hidden" name="action" value="unblock">
                                                        <input type="hidden" name="session_id" value="<?= $p['id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm">Buka Kunci</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($p['status'] === 'in_progress'): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Paksa kumpulkan ujian siswa ini sekarang?')">
                                                        <?= Helper::csrfField() ?>
                                                        <input type="hidden" name="action" value="force_finish">
                                                        <input type="hidden" name="session_id" value="<?= $p['id'] ?>">
                                                        <button type="submit" class="btn btn-outline btn-sm">Kumpulkan</button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="POST" style="display: inline;" onsubmit="return confirm('RESET penuh sesi siswa ini? Seluruh jawaban dihapus dan siswa bisa mengulang kembali.')">
                                                    <?= Helper::csrfField() ?>
                                                    <input type="hidden" name="action" value="reset_session">
                                                    <input type="hidden" name="session_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Reset sesi">Reset</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Hidden CSRF token untuk baris dinamis AJAX -->
            <input type="hidden" id="examCsrfToken" value="<?= Helper::csrfToken() ?>">

            <!-- 2. TAMPILAN CCTV GRID LIVE -->
            <div id="cctvContainer" style="display: none;">
                <div class="cctv-grid" id="cctvGrid">
                    <?php foreach ($participants as $p): ?>
                        <div class="cctv-card" data-session-id="<?= $p['id'] ?>" data-status="<?= $p['status'] ?>" data-online="<?= $p['online_status'] ?>">
                            <div class="cctv-header">
                                <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 65%;">
                                    <b style="font-size: 13px; color: #0f172a;" title="<?= htmlspecialchars($p['student_name']) ?>"><?= htmlspecialchars($p['student_name']) ?></b>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <code><?= htmlspecialchars($p['nis']) ?></code> &bull; <?= htmlspecialchars($p['class_name'] ?: 'Umum') ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($p['status'] === 'in_progress'): ?>
                                        <span class="badge badge-warning">&#9679; Mengerjakan</span>
                                    <?php elseif ($p['status'] === 'completed'): ?>
                                        <span class="badge badge-success">&#10004; Selesai</span>
                                    <?php elseif ($p['status'] === 'blocked'): ?>
                                        <span class="badge badge-danger">&#9888; Blokir</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="cctv-preview" onclick="openLiveCameraModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ', '<?= htmlspecialchars($p['nis']) ?>', '<?= htmlspecialchars($p['webcam_url'] ?? '') ?>')" title="Klik untuk memperbesar feed kamera live">
                                <?php if (!empty($p['has_webcam'])): ?>
                                    <img class="cctv-feed-img" src="<?= htmlspecialchars($p['webcam_url']) ?>" alt="Webcam <?= htmlspecialchars($p['student_name']) ?>">
                                    <div class="cctv-badge-live">
                                        <span class="pulse-dot"></span> LIVE
                                    </div>
                                <?php else: ?>
                                    <div class="cctv-placeholder">
                                        <div style="font-size: 26px;">📹</div>
                                        <div><?= ($p['webcam_status'] ?? '') === 'denied' ? 'Izin Kamera Ditolak Siswa' : 'Kamera Menunggu Frame' ?></div>
                                    </div>
                                <?php endif; ?>

                                <div class="cctv-badge-time timer-countdown"><?= $p['remaining_formatted'] ?></div>
                                <?php if ($p['total_violations'] > 0): ?>
                                    <div class="cctv-badge-viol"><?= $p['total_violations'] ?>x Pelanggaran</div>
                                <?php endif; ?>
                            </div>

                            <div class="cctv-body">
                                <div style="display: flex; justify-content: space-between; font-size: 11.5px; color: #475569;">
                                    <span>Progres: <b class="prog-answered"><?= (int)$p['answered_count'] ?></b> / <?= $totalQuestionsInBank ?> Soal</span>
                                    <span style="font-weight: 700; color: #10b981;"><?= $p['progress_pct'] ?>%</span>
                                </div>
                                <div style="width: 100%; height: 5px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                    <div class="prog-bar" style="height: 100%; width: <?= $p['progress_pct'] ?>%; background: #10b981; transition: width 0.4s ease;"></div>
                                </div>
                            </div>

                            <div class="cctv-footer">
                                <button type="button" class="btn btn-outline btn-sm" style="font-size: 11.5px;" onclick="openLiveCameraModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ', '<?= htmlspecialchars($p['nis']) ?>', '<?= htmlspecialchars($p['webcam_url'] ?? '') ?>')">
                                    🔍 Zoom Cam
                                </button>
                                <div style="display: flex; gap: 4px;">
                                    <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 6px;" onclick="openExtraTimeModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')" title="Tambah waktu">+Waktu</button>
                                    <button type="button" class="btn btn-outline btn-sm" style="font-size: 11px; padding: 3px 6px;" onclick="openBroadcastModal(<?= $p['id'] ?>, ' . htmlspecialchars(json_encode($p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')" title="Kirim pesan">Pesan</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: LIVE ACTIVITY & VIOLATION TICKER FEED -->
        <div class="card monitor-ticker-col" style="margin: 0;">
            <div class="card-header" style="padding: 12px 16px; border-bottom: 1px solid #eef2f7;">
                <h3 class="card-title" style="font-size: 13.5px; display: flex; align-items: center; gap: 6px; margin: 0;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #ef4444; animation: pulse 1.5s infinite;"></span>
                    Live Activity &amp; Violation Feed
                </h3>
            </div>
            <div class="card-body" style="padding: 12px;">
                <div class="ticker-feed" id="tickerFeed">
                    <?php if (empty($recentLogs)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 30px 10px; font-size: 12px;">
                            Belum ada aktivitas tercatat.
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="ticker-item">
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                                    <span style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($log['student_name'] ?: 'Sistem') ?></span>
                                    <span style="color: #94a3b8; font-family: monospace;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div style="color: #475569; font-size: 11.5px;">
                                    <?= htmlspecialchars($log['message']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

<?php endif; ?>

<!-- MODAL TAMBAH WAKTU KOMPENSASI (+WAKTU) -->
<div class="modal-backdrop" id="modalExtraTime">
    <div class="modal-dialog">
        <form method="POST" action="./monitor.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="add_extra_time">
            <input type="hidden" name="session_id" id="extraSessionId" value="0">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Tambah Waktu Kompensasi</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size: 13.5px; color: #475569; margin-bottom: 12px;">
                    Berikan waktu kompensasi tambahan untuk siswa: <b id="extraStudentName">-</b>
                </p>

                <div class="form-group">
                    <label class="form-label">Pilihan Cepat Menit Ekstra:</label>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="setExtraMins(5)">+5 Menit</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setExtraMins(10)">+10 Menit</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setExtraMins(15)">+15 Menit</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setExtraMins(30)">+30 Menit</button>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 14px;">
                    <label class="form-label">Jumlah Menit Tambahan (Custom):</label>
                    <input type="number" name="extra_minutes" id="extraMinutesInput" class="form-control" value="10" min="1" max="180" required>
                    <small style="color: #64748b; font-size: 11.5px;">Waktu timer di layar siswa akan otomatis bertambah secara langsung tanpa perlu refresh.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Tambahkan Waktu Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL BROADCAST PENGUMUMAN KE SISWA -->
<div class="modal-backdrop" id="modalBroadcast">
    <div class="modal-dialog">
        <form method="POST" action="./monitor.php" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="broadcast">
            <input type="hidden" name="target_session_id" id="broadcastSessionId" value="0">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Kirim Pengumuman / Pesan Pengawas</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; color: #1e40af; margin-bottom: 14px;" id="broadcastTargetNote">
                    📢 Pesan akan disiarkan ke <b>Seluruh Peserta</b> yang sedang mengerjakan ujian ini.
                </div>

                <div class="form-group">
                    <label class="form-label">Isi Pesan Pengawas:</label>
                    <textarea name="message" id="broadcastMessage" class="form-control" rows="3" placeholder="Contoh: Waktu tersisa 15 menit lagi, silakan periksa kembali jawaban Anda..." required></textarea>
                    <small style="color: #64748b; font-size: 11.5px;">Pesan akan langsung muncul sebagai popup/toast mengambang di layar laptop siswa.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Kirim Pesan Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL AKSI MASSAL (BULK ACTIONS) -->
<div class="modal-backdrop" id="modalBulkActions">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;">Aksi Massal Seluruh Peserta</h3>
            <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <div style="display: flex; flex-direction: column; gap: 14px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                    <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">1. Kumpulkan Semua Sesi yang Sedang Berjalan</div>
                    <p style="font-size: 12px; color: #64748b; margin: 0 0 10px 0;">Gunakan tombol ini saat bel ujian berakhir berbunyi untuk mengumpulkan jawaban seluruh siswa secara bersamaan.</p>
                    <form method="POST" action="./monitor.php" data-no-fetch onsubmit="return confirm('KUMPULKAN PAKSA seluruh siswa yang sedang berjalan sekarang?')">
                        <?= Helper::csrfField() ?>
                        <input type="hidden" name="action" value="bulk_force_finish">
                        <button type="submit" class="btn btn-outline btn-sm" style="color: #b91c1c; border-color: #f87171;">
                            Kumpulkan Seluruh Siswa Berjalan
                        </button>
                    </form>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                    <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">2. Buka Kunci Semua Siswa yang Terblokir</div>
                    <p style="font-size: 12px; color: #64748b; margin: 0 0 10px 0;">Mereset status siswa yang terkunci karena batas pelanggaran layar agar dapat melanjutkan ujian kembali.</p>
                    <form method="POST" action="./monitor.php" data-no-fetch onsubmit="return confirm('Buka kunci SELURUH siswa yang saat ini terblokir?')">
                        <?= Helper::csrfField() ?>
                        <input type="hidden" name="action" value="bulk_unblock">
                        <button type="submit" class="btn btn-success btn-sm">
                            Buka Kunci Semua Siswa Terblokir
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" data-close-modal>Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL LIVE WEBCAM PROCTORING -->
<div class="modal-backdrop" id="modalLiveCamera">
    <div class="modal-dialog" style="max-width: 680px;">
        <div class="modal-header" style="background: #0f172a; color: #fff; border-bottom: 1px solid #1e293b;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #10b981; animation: cctvPulse 1.2s infinite;"></span>
                <h3 style="font-size: 16px; font-weight: 700; color: #fff; margin: 0;">Live Camera Proctoring</h3>
            </div>
            <button type="button" class="btn btn-outline btn-sm" style="color: #94a3b8; border-color: #334155;" data-close-modal onclick="closeLiveCameraModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding: 18px; background: #0b1120;">
            <!-- Info Siswa -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; color: #e2e8f0; flex-wrap: wrap; gap: 8px;">
                <div>
                    <span style="font-size: 15px; font-weight: 700; color: #f8fafc;" id="modalCamStudentName">-</span>
                    <span style="font-size: 12px; color: #94a3b8; margin-left: 6px;" id="modalCamStudentNis">-</span>
                </div>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <span class="badge" id="modalCamStatusBadge" style="background: #1e293b; color: #94a3b8;">Aktif</span>
                    <span style="font-size: 11px; color: #64748b;" id="modalCamLastUpdate">-</span>
                </div>
            </div>

            <!-- Layar Tampilan Kamera Siswa -->
            <div style="position: relative; width: 100%; aspect-ratio: 4/3; background: #020617; border-radius: 10px; overflow: hidden; border: 1px solid #1e293b; display: flex; align-items: center; justify-content: center;">
                <img id="modalCamImage" src="" alt="Live Feed" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                <div id="modalCamPlaceholder" style="text-align: center; color: #64748b; padding: 20px;">
                    <div style="font-size: 36px; margin-bottom: 8px;">📹</div>
                    <div style="font-size: 13.5px; font-weight: 600; color: #94a3b8;" id="modalCamPlaceholderText">Menghubungkan ke kamera siswa...</div>
                    <div style="font-size: 11px; margin-top: 5px; color: #64748b;">Kamera menangkap frame snapshot otomatis setiap 3-4 detik</div>
                </div>

                <!-- Overlay Corner Indicators -->
                <div style="position: absolute; top: 10px; left: 10px; background: rgba(15,23,42,0.8); color: #10b981; font-weight: 700; font-size: 11px; padding: 4px 9px; border-radius: 4px; display: flex; align-items: center; gap: 6px; backdrop-filter: blur(4px);">
                    <span class="pulse-dot"></span> LIVE PROCTOR
                </div>
                <div style="position: absolute; bottom: 10px; right: 10px; background: rgba(15,23,42,0.8); color: #f8fafc; font-family: monospace; font-size: 11.5px; padding: 4px 9px; border-radius: 4px; backdrop-filter: blur(4px);" id="modalCamOverlayTime">
                    --:--
                </div>
            </div>

            <!-- Peringatan Pengawas Cepat Bar -->
            <div style="margin-top: 14px; background: #131d33; border: 1px solid #1e293b; border-radius: 8px; padding: 12px;">
                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; margin-bottom: 8px;">⚡ Peringatan Cepat Pengawas ke Layar Siswa Ini:</div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-outline btn-sm" style="color: #cbd5e1; border-color: #334155; font-size: 11.5px;" onclick="sendQuickWarning('Peringatan: Tolong posisikan wajah Anda tepat menghadap kamera ujian!')">
                        👤 Hadap Kamera
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" style="color: #cbd5e1; border-color: #334155; font-size: 11.5px;" onclick="sendQuickWarning('Peringatan: Dilarang menoleh atau melihat ke arah lain selama ujian!')">
                        👀 Jangan Menoleh
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" style="color: #cbd5e1; border-color: #334155; font-size: 11.5px;" onclick="sendQuickWarning('Peringatan: Kamera Anda tidak terdeteksi. Pastikan webcam tidak tertutup!')">
                        ⚠️ Kamera Tertutup
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" style="color: #60a5fa; border-color: #1e3a8a; font-size: 11.5px;" onclick="openBroadcastFromCam()">
                        💬 Pesan Kustom...
                    </button>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="background: #0f172a; border-top: 1px solid #1e293b; display: flex; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="conn-dot connected" style="margin: 0;"></span>
                <span style="font-size: 12px; color: #94a3b8;">Streaming Feed Aktif (Auto-refresh)</span>
            </div>
            <button type="button" class="btn btn-outline btn-sm" style="color: #cbd5e1; border-color: #334155;" data-close-modal onclick="closeLiveCameraModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- CLIENT JAVASCRIPT: POLLING & REAL-TIME INTERACTION -->
<script>
let activeCamSessionId = 0;
let camRefreshTimer = null;
let currentMonitorView = localStorage.getItem('cbt_monitor_view') || 'table';

function switchMonitorView(mode) {
    currentMonitorView = mode;
    localStorage.setItem('cbt_monitor_view', mode);
    const tableCont = document.getElementById('tableContainer');
    const cctvCont = document.getElementById('cctvContainer');
    const btnTable = document.getElementById('btnViewTable');
    const btnCctv = document.getElementById('btnViewCctv');

    if (mode === 'cctv') {
        if (tableCont) tableCont.style.display = 'none';
        if (cctvCont) cctvCont.style.display = 'block';
        if (btnTable) btnTable.classList.remove('active');
        if (btnCctv) btnCctv.classList.add('active');
    } else {
        if (tableCont) tableCont.style.display = 'block';
        if (cctvCont) cctvCont.style.display = 'none';
        if (btnTable) btnTable.classList.add('active');
        if (btnCctv) btnCctv.classList.remove('active');
    }
}
window.switchMonitorView = switchMonitorView;

function openLiveCameraModal(sessionId, studentName, nis, webcamUrl) {
    activeCamSessionId = sessionId;
    document.getElementById('modalCamStudentName').textContent = studentName || '-';
    document.getElementById('modalCamStudentNis').textContent = 'NIS: ' + (nis || '-');

    const modalImg = document.getElementById('modalCamImage');
    const modalPlaceholder = document.getElementById('modalCamPlaceholder');
    const modalPlaceholderText = document.getElementById('modalCamPlaceholderText');

    function loadFrame() {
        if (!activeCamSessionId) return;
        const testImg = new Image();
        const src = 'webcam-frame.php?session_id=' + activeCamSessionId + '&t=' + Date.now();
        testImg.onload = function() {
            modalImg.src = src;
            modalImg.style.display = 'block';
            modalPlaceholder.style.display = 'none';
            document.getElementById('modalCamLastUpdate').textContent = 'Update: ' + new Date().toLocaleTimeString();
        };
        testImg.onerror = function() {
            modalImg.style.display = 'none';
            modalPlaceholder.style.display = 'block';
            modalPlaceholderText.textContent = 'Menunggu frame kamera siswa...';
        };
    }

    loadFrame();
    if (camRefreshTimer) clearInterval(camRefreshTimer);
    camRefreshTimer = setInterval(loadFrame, 3000);

    document.getElementById('modalLiveCamera').classList.add('show');
}

function closeLiveCameraModal() {
    activeCamSessionId = 0;
    if (camRefreshTimer) {
        clearInterval(camRefreshTimer);
        camRefreshTimer = null;
    }
    const modal = document.getElementById('modalLiveCamera');
    if (modal) modal.classList.remove('show');
}

function sendQuickWarning(msg) {
    if (!activeCamSessionId) return;
    document.getElementById('broadcastSessionId').value = activeCamSessionId;
    document.getElementById('broadcastMessage').value = msg;
    document.getElementById('modalBroadcast').querySelector('form').submit();
}

function openBroadcastFromCam() {
    const sId = activeCamSessionId;
    const sName = document.getElementById('modalCamStudentName').textContent;
    closeLiveCameraModal();
    openBroadcastModal(sId, sName);
}

function openExtraTimeModal(sessionId, studentName) {
    document.getElementById('extraSessionId').value = sessionId;
    document.getElementById('extraStudentName').textContent = studentName;
    document.getElementById('modalExtraTime').classList.add('show');
}

function setExtraMins(mins) {
    document.getElementById('extraMinutesInput').value = mins;
}

function openBroadcastModal(sessionId, studentName) {
    sessionId = sessionId || 0;
    document.getElementById('broadcastSessionId').value = sessionId;
    const note = document.getElementById('broadcastTargetNote');
    if (sessionId > 0 && studentName) {
        note.innerHTML = '📢 Pesan akan dikirim khusus ke layar siswa: <b>' + studentName + '</b>';
    } else {
        note.innerHTML = '📢 Pesan akan disiarkan ke <b>Seluruh Peserta</b> yang sedang mengerjakan ujian ini.';
    }
    document.getElementById('modalBroadcast').classList.add('show');
}
window.openBroadcastModal = openBroadcastModal;

(function () {
    const toggle = document.getElementById('autoRefreshToggle');
    const connDot = document.getElementById('connDot');
    const connText = document.getElementById('connText');
    const monitorBody = document.getElementById('monitorBody');
    const cctvGrid = document.getElementById('cctvGrid');
    const emptyMsg = document.getElementById('emptyMsg');
    const tickerFeed = document.getElementById('tickerFeed');
    const KEY = 'cbt_monitor_autorefresh';
    const EXAM_ID = <?= (int)($examId ?? 0) ?>;
    const TOTAL_Q = <?= (int)$totalQuestionsInBank ?>;

    let timer = null;
    let lastData = null;
    let audioCtx = null;
    let activeFilter = 'all';
    let lastLogId = 0;        // untuk deteksi entri baru di ticker
    let notifPermission = 'default';

    function initAudio() {
        try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
    }

    function playBeep(freq, duration, delay) {
        if (!audioCtx) return;
        setTimeout(() => {
            try {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.frequency.value = freq;
                gain.gain.value = 0.25;
                osc.start();
                osc.stop(audioCtx.currentTime + duration);
            } catch (e) {}
        }, delay || 0);
    }

    function playFinishSound() { playBeep(880, 0.12, 0); playBeep(1100, 0.12, 150); playBeep(1320, 0.18, 300); }
    function playBlockSound() { playBeep(330, 0.2, 0); playBeep(220, 0.3, 220); }

    function setStatus(connected) {
        if (connected) {
            connDot.className = 'conn-dot connected';
            connText.textContent = 'Connected';
        } else {
            connDot.className = 'conn-dot disconnected';
            connText.textContent = 'Disconnected';
        }
    }

    function statusBadge(status, score) {
        if (status === 'in_progress') return '<span class="badge badge-warning">&#9679; Mengerjakan</span>';
        if (status === 'completed') return '<span class="badge badge-success">&#10004; Selesai (' + (Number(score).toFixed(1)) + ')</span>';
        if (status === 'blocked') return '<span class="badge badge-danger">&#9888; Diblokir</span>';
        return '<span class="badge badge-neutral">' + status + '</span>';
    }

    function renderRow(p) {
        const violations = p.total_violations > 0
            ? '<span class="badge badge-danger">' + p.total_violations + 'x Pindah</span>'
            : '<span class="badge badge-success">0 (Tertib)</span>';
        const controls = buildControls(p);
        const extraBadge = p.extra_time_minutes > 0 ? ' <span class="badge badge-warning" style="font-size:10px;padding:2px 5px;">+' + p.extra_time_minutes + 'm</span>' : '';

        return '<tr data-session-id="' + p.id + '" data-status="' + p.status + '" data-online="' + p.online_status + '">' +
            '<td><div style="display:flex;align-items:center;">' +
            '<span class="status-dot ' + p.online_status + '" title="Koneksi: ' + esc(p.online_text) + '"></span>' +
            '<div><b>' + esc(p.student_name) + '</b><div style="font-size:11px;color:var(--text-muted);"><code>' + esc(p.nis) + '</code> &bull; <span style="font-size:10px;">' + esc(p.online_text) + '</span></div></div>' +
            '</div></td>' +
            '<td><span class="badge badge-neutral">' + esc(p.class_name || 'Umum') + '</span></td>' +
            '<td><b>' + p.answered_count + '</b> / ' + TOTAL_Q + ' (' + p.progress_pct + '%)<div style="width:100px;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-top:3px;"><div style="height:100%;width:' + p.progress_pct + '%;background:#10b981;transition:width 0.4s ease;"></div></div></td>' +
            '<td style="white-space:nowrap;"><b style="font-family:monospace;font-size:13px;">' + (p.remaining_formatted || '00:00') + '</b>' + extraBadge + '</td>' +
            '<td>' + statusBadge(p.status, p.score) + '</td>' +
            '<td>' + violations + '</td>' +
            '<td style="text-align:right;white-space:nowrap;">' + controls + '</td>' +
            '</tr>';
    }

    function renderCctvCard(p) {
        const isLive = p.has_webcam;
        const camImg = p.webcam_url ? ('<img class="cctv-feed-img" src="' + esc(p.webcam_url) + '" alt="Webcam ' + esc(p.student_name) + '">') : '';
        const placeholderMsg = p.webcam_status === 'denied' ? 'Izin Kamera Ditolak Siswa' : 'Kamera Menunggu Frame';
        
        let previewContent = '';
        if (isLive) {
            previewContent = camImg + '<div class="cctv-badge-live"><span class="pulse-dot"></span> LIVE</div>';
        } else {
            previewContent = '<div class="cctv-placeholder"><div style="font-size:26px;">📹</div><div>' + esc(placeholderMsg) + '</div></div>';
        }

        const violBadge = p.total_violations > 0 ? ('<div class="cctv-badge-viol">' + p.total_violations + 'x Pelanggaran</div>') : '';
        const safeName = jsesc(p.student_name);
        const safeNis = jsesc(p.nis);
        const safeUrl = jsesc(p.webcam_url || '');

        return '<div class="cctv-card" data-session-id="' + p.id + '" data-status="' + p.status + '" data-online="' + p.online_status + '">' +
            '<div class="cctv-header">' +
            '<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:65%;">' +
            '<b style="font-size:13px;color:#0f172a;" title="' + esc(p.student_name) + '">' + esc(p.student_name) + '</b>' +
            '<div style="font-size:11px;color:#64748b;"><code>' + esc(p.nis) + '</code> &bull; ' + esc(p.class_name || 'Umum') + '</div>' +
            '</div>' +
            '<div>' + statusBadge(p.status, p.score) + '</div>' +
            '</div>' +
            '<div class="cctv-preview" onclick="openLiveCameraModal(' + p.id + ', \'' + safeName + '\', \'' + safeNis + '\', \'' + safeUrl + '\')" title="Klik untuk memperbesar feed kamera">' +
            previewContent +
            '<div class="cctv-badge-time timer-countdown">' + (p.remaining_formatted || '00:00') + '</div>' +
            violBadge +
            '</div>' +
            '<div class="cctv-body">' +
            '<div style="display:flex;justify-content:space-between;font-size:11.5px;color:#475569;">' +
            '<span>Progres: <b class="prog-answered">' + p.answered_count + '</b> / ' + TOTAL_Q + ' Soal</span>' +
            '<span style="font-weight:700;color:#10b981;">' + p.progress_pct + '%</span>' +
            '</div>' +
            '<div style="width:100%;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;">' +
            '<div class="prog-bar" style="height:100%;width:' + p.progress_pct + '%;background:#10b981;transition:width 0.4s ease;"></div>' +
            '</div>' +
            '</div>' +
            '<div class="cctv-footer">' +
            '<button type="button" class="btn btn-outline btn-sm" style="font-size:11.5px;" onclick="openLiveCameraModal(' + p.id + ', \'' + safeName + '\', \'' + safeNis + '\', \'' + safeUrl + '\')">🔍 Zoom Cam</button>' +
            '<div style="display:flex;gap:4px;">' +
            '<button type="button" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 6px;" onclick="openExtraTimeModal(' + p.id + ', \'' + safeName + '\')" title="Tambah waktu">+Waktu</button>' +
            '<button type="button" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 6px;" onclick="openBroadcastModal(' + p.id + ', \'' + safeName + '\')" title="Kirim pesan">Pesan</button>' +
            '</div>' +
            '</div>' +
            '</div>';
    }

    function buildControls(p) {
        const csrf = document.getElementById('examCsrfToken')?.value || '';
        let h = '';
        h += '<button type="button" class="btn btn-outline btn-sm" onclick="openLiveCameraModal(' + p.id + ', \'' + jsesc(p.student_name) + '\', \'' + jsesc(p.nis) + '\', \'' + jsesc(p.webcam_url || '') + '\')" title="Pantau feed kamera live siswa">📷 Kamera</button> ';
        h += '<button type="button" class="btn btn-outline btn-sm" onclick="openExtraTimeModal(' + p.id + ', \'' + jsesc(p.student_name) + '\')" title="Tambah waktu kompensasi">+Waktu</button> ';
        h += '<button type="button" class="btn btn-outline btn-sm" onclick="openBroadcastModal(' + p.id + ', \'' + jsesc(p.student_name) + '\')" title="Kirim pesan langsung">Pesan</button> ';

        if (p.status === 'blocked') {
            h += '<form method="POST" style="display:inline;"><input type="hidden" name="_csrf" value="' + csrf + '">';
            h += '<input type="hidden" name="action" value="unblock"><input type="hidden" name="session_id" value="' + p.id + '">';
            h += '<button type="submit" class="btn btn-success btn-sm">Buka Kunci</button></form> ';
        }
        if (p.status === 'in_progress') {
            h += '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Paksa kumpulkan ujian siswa ini sekarang?\')">';
            h += '<input type="hidden" name="_csrf" value="' + csrf + '">';
            h += '<input type="hidden" name="action" value="force_finish"><input type="hidden" name="session_id" value="' + p.id + '">';
            h += '<button type="submit" class="btn btn-outline btn-sm">Kumpulkan</button></form> ';
        }
        h += '<form method="POST" style="display:inline;" onsubmit="return confirm(\'RESET penuh sesi siswa ini?\')">';
        h += '<input type="hidden" name="_csrf" value="' + csrf + '">';
        h += '<input type="hidden" name="action" value="reset_session"><input type="hidden" name="session_id" value="' + p.id + '">';
        h += '<button type="submit" class="btn btn-danger btn-sm">Reset</button></form>';
        return h;
    }

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function jsesc(s) {
        // json_encode PHP → hex unicode escape (\u0027, \u0022, etc.)
        // Kemudian htmlsc agar aman di atribut HTML tanpa merusak escape JS.
        var raw = String(s || '');
        try { raw = JSON.stringify(raw).slice(1, -1); } catch(e) {}
        return raw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function applyFilter() {
        const filterElements = document.querySelectorAll('#monitorBody tr, #cctvGrid .cctv-card');
        filterElements.forEach(el => {
            const status = el.getAttribute('data-status');
            const online = el.getAttribute('data-online');
            let show = false;
            if (activeFilter === 'all') show = true;
            else if (activeFilter === 'in_progress') show = (status === 'in_progress');
            else if (activeFilter === 'blocked') show = (status === 'blocked');
            else if (activeFilter === 'offline') show = (online === 'offline');
            else if (activeFilter === 'completed') show = (status === 'completed');
            el.style.display = show ? '' : 'none';
        });
    }

    // Filter Chips Event Listener
    document.querySelectorAll('.filter-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = btn.getAttribute('data-filter');
            applyFilter();
        });
    });

    function poll() {
        if (!toggle.checked) return;
        fetch('api-monitor.php?exam_id=' + EXAM_ID)
            .then(r => r.json())
            .then(data => {
                setStatus(true);
                if (!data.participants || data.participants.length === 0) {
                    if (emptyMsg) emptyMsg.style.display = 'block';
                    if (monitorBody) monitorBody.innerHTML = '';
                    if (cctvGrid) cctvGrid.innerHTML = '';
                    return;
                }
                if (emptyMsg) emptyMsg.style.display = 'none';

                // ── Deteksi pelanggaran / blokir baru → notifikasi visual+suara
                const NEW_IDS = new Set();
                if (Array.isArray(data.recent_logs)) {
                    data.recent_logs.forEach(l => { if (l.id > lastLogId) NEW_IDS.add(l.id); });
                }
                let hasNewViolation = false;
                // Bandingkan participant status berubah ke blocked
                const prevBlockedIds = new Set();
                if (lastData && Array.isArray(lastData.participants)) {
                    lastData.participants.forEach(p => { if (p.status === 'blocked') prevBlockedIds.add(p.id); });
                }
                if (Array.isArray(data.participants)) {
                    data.participants.forEach(p => {
                        if (p.status === 'blocked' && !prevBlockedIds.has(p.id)) hasNewViolation = true;
                    });
                }
                // Cek juga log baru bertipe violation
                if (Array.isArray(data.recent_logs)) {
                    data.recent_logs.forEach(l => {
                        if ((l.event_type === 'violation' || l.event_type === 'violation_blocked') && NEW_IDS.has(l.id)) {
                            hasNewViolation = true;
                        }
                    });
                }

                // ── Browser Notification untuk pelanggaran baru
                if (data.kpi) {
                    const k = data.kpi;
                    document.getElementById('statTotal').textContent = k.total;
                    document.getElementById('statInProgress').textContent = k.in_progress;
                    document.getElementById('statOnline').textContent = k.online;
                    document.getElementById('statOffline').textContent = k.offline;
                    document.getElementById('statBlocked').textContent = k.blocked;
                    document.getElementById('statCompleted').textContent = k.completed;
                    document.getElementById('statAvgProgress').textContent = k.avg_progress + '%';

                    document.getElementById('chipAll').textContent = k.total;
                    document.getElementById('chipProgress').textContent = k.in_progress;
                    document.getElementById('chipBlocked').textContent = k.blocked;
                    document.getElementById('chipOffline').textContent = k.offline;
                    document.getElementById('chipDone').textContent = k.completed;
                }

                // Check sound alerts based on status changes
                const prevMap = {};
                if (lastData && lastData.participants) {
                    lastData.participants.forEach(p => { prevMap[p.id] = p; });
                }

                // ── Browser Notification untuk pelanggaran baru
                if (hasNewViolation && notifPermission === 'granted') {
                    try {
                        new Notification('⚠️ CBT Online — Pelanggaran Terdeteksi', {
                            body: 'Ada siswa yang melanggar tata tertib ujian. Buka Live Monitor.',
                            icon: '../assets/img/favicon.png',
                            tag: 'cbt-violation-' + Date.now()
                        });
                    } catch(e) {}
                }

                // ── Flash banner pelanggaran di bagian atas halaman
                const alertBar = document.getElementById('violationAlertBar');
                if (alertBar) {
                    if (hasNewViolation || k.blocked > 0) {
                        alertBar.style.display = 'flex';
                        alertBar.innerHTML =
                            '<span style="font-weight:800;color:#dc2626;">&#9888; Perhatian:</span> ' +
                            (k.blocked > 0 ? '<b>' + k.blocked + ' siswa terblokir</b> — ' : '') +
                            'cek ticker aktivitas di sebelah kanan.';
                        setTimeout(() => { alertBar.style.display = 'none'; }, 8000);
                    } else {
                        alertBar.style.display = 'none';
                    }
                }

                let htmlTable = '';
                let htmlCctv = '';
                data.participants.forEach(p => {
                    const prev = prevMap[p.id];
                    if (!prev) playBeep(660, 0.1, 0); // Siswa baru masuk
                    else if (prev.status !== p.status) {
                        if (p.status === 'completed') playFinishSound();
                        if (p.status === 'blocked') playBlockSound();
                    }
                    htmlTable += renderRow(p);
                    htmlCctv += renderCctvCard(p);

                    // Update live modal overlay if open for this participant
                    if (activeCamSessionId === p.id) {
                        document.getElementById('modalCamOverlayTime').textContent = p.remaining_formatted || '--:--';
                    }
                });

                if (monitorBody) monitorBody.innerHTML = htmlTable;
                if (cctvGrid) cctvGrid.innerHTML = htmlCctv;
                applyFilter();

                // Update Live Activity Feed
                if (tickerFeed && Array.isArray(data.recent_logs)) {
                    let logHtml = '';
                    if (data.recent_logs.length === 0) {
                        logHtml = '<div style="text-align:center;color:var(--text-muted);padding:30px 10px;font-size:12px;">Belum ada aktivitas tercatat.</div>';
                    } else {
                        data.recent_logs.forEach(l => {
                            logHtml += '<div class="ticker-item">' +
                                '<div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;">' +
                                '<span style="font-weight:700;color:#0f172a;">' + esc(l.student_name || 'Sistem') + '</span>' +
                                '<span style="color:#94a3b8;font-family:monospace;">' + esc(l.time) + '</span>' +
                                '</div>' +
                                '<div style="color:#475569;font-size:11.5px;">' + esc(l.message) + '</div>' +
                                '</div>';
                        });
                    }
                    tickerFeed.innerHTML = logHtml;
                    // Auto-scroll ke paling atas (log terbaru)
                    tickerFeed.scrollTop = 0;
                    // Update lastLogId agar deteksi berikutnya akurat
                    if (data.recent_logs.length > 0) {
                        lastLogId = Math.max(lastLogId, ...data.recent_logs.map(l => l.id));
                    }
                }

                lastData = data;

                // Sesuaikan interval polling berdasarkan jumlah siswa aktif
                if (timer) { clearInterval(timer); timer = null; }
                const activeCount = data.kpi ? (data.kpi.in_progress || 0) : 0;
                const newInterval = activeCount > 0 ? 3000 : 10000;
                timer = setInterval(poll, newInterval);
            })
            .catch(() => {
                setStatus(false);
            });
    }

    function apply() {
        if (timer) { clearInterval(timer); timer = null; }
        if (toggle.checked) {
            initAudio();
            poll();
            // Polling interval: 3 detik jika ada siswa aktif, 10 detik jika tidak
            let currentInterval = (lastData && lastData.kpi && lastData.kpi.in_progress > 0) ? 3000 : 10000;
            timer = setInterval(poll, currentInterval);
        }
        try { localStorage.setItem(KEY, toggle.checked ? '1' : '0'); } catch (e) {}
    }

    // Initialize View Mode (Table or CCTV)
    switchMonitorView(currentMonitorView);

    try { toggle.checked = localStorage.getItem(KEY) === '1'; } catch (e) {}
    toggle.addEventListener('change', apply);
    
    // Request browser notification permission for violation alerts
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().then(function(perm) { notifPermission = perm; });
    }

    apply();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
