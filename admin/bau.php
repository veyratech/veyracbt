<?php
/**
 * Berita Acara Pelaksanaan Ujian CBT — Dokumen Resmi
 * Multi-Tenant CBT Online
 */
$pageTitle = "Berita Acara Ujian";
require_once __DIR__ . '/_bootstrap.php';

// Ambil semua ujian tenant ini
$allExams = Database::fetchAll(
    "SELECT e.id, e.title, e.token, e.start_time, e.end_time, e.duration_minutes, e.max_violations,
            qb.title as bank_title, s.name as subject_name, c.name as class_name,
            (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id AND es.status = 'completed') as completed_cnt,
            (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id) as total_sessions
     FROM exams e
     JOIN question_banks qb ON e.bank_id = qb.id
     JOIN subjects s ON qb.subject_id = s.id
     LEFT JOIN classes c ON e.class_id = c.id
     WHERE e.tenant_id = ?
     ORDER BY e.start_time DESC",
    [$tenantId]
);

$examId      = (int)($_GET['exam_id'] ?? 0);
$printBau    = isset($_GET['print']);
$selectedExam = null;
$participants = [];
$totalQuestionsInBank = 0;
$violationsLogs = [];

if ($examId > 0) {
    $selectedExam = Database::fetch(
        "SELECT e.*, qb.title as bank_title, s.name as subject_name, c.name as class_name
         FROM exams e
         JOIN question_banks qb ON e.bank_id = qb.id
         JOIN subjects s ON qb.subject_id = s.id
         LEFT JOIN classes c ON e.class_id = c.id
         WHERE e.id = ? AND e.tenant_id = ?",
        [$examId, $tenantId]
    );

    if ($selectedExam) {
        $totalQuestionsInBank = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM questions WHERE bank_id = ?",
            [$selectedExam['bank_id']]
        );

        $participants = Database::fetchAll(
            "SELECT es.id, es.status, es.total_violations, es.start_time, es.finish_time,
                    es.extra_time_minutes, es.score, es.ip_address,
                    st.name as student_name, st.nis, c.name as class_name,
                    (SELECT COUNT(*) FROM exam_answers ea
                     WHERE ea.session_id = es.id AND (ea.student_answer IS NOT NULL OR ea.answer_text IS NOT NULL)) as answered_count
             FROM exam_sessions es
             JOIN students st ON es.student_id = st.id
             LEFT JOIN classes c ON st.class_id = c.id
             WHERE es.exam_id = ? AND es.tenant_id = ?
             ORDER BY es.status DESC, es.total_violations DESC, es.start_time DESC",
            [$examId, $tenantId]
        );

        try {
            $violationsLogs = Database::fetchAll(
                "SELECT al.*, st.name as student_name, st.nis
                 FROM activity_logs al
                 JOIN exam_sessions es ON al.session_id = es.id
                 JOIN students st ON es.student_id = st.id
                 WHERE es.exam_id = ? AND al.tenant_id = ? AND al.event_type = 'violation'
                 ORDER BY al.created_at ASC",
                [$examId, $tenantId]
            );
        } catch (Throwable $e) {
            $violationsLogs = [];
        }
    }
}

// Hitung statistik BAU
$countTotal   = count($participants);
$countDone    = 0;
$countBlocked = 0;
$countProgress = 0;
$totalScore   = 0.0;
foreach ($participants as $p) {
    if ($p['status'] === 'completed')     { $countDone++;    $totalScore += (float)$p['score']; }
    elseif ($p['status'] === 'blocked')   { $countBlocked++; }
    else                                  { $countProgress++; }
}
$avgScore = $countDone > 0 ? round($totalScore / $countDone, 1) : 0;

/* ── Helper: tanggal Indonesia ─────────────────────────────────────────── */
function tanggalIndo(string $d): string {
    $dt = new DateTime($d);
    $days  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $months = ['Januari','Februari','Maret','April','Mei','Juni',
               'Juli','Agustus','September','Oktober','November','Desember'];
    return sprintf('%s, %d %s %d',
        $days[$dt->format('w')],
        $dt->format('j'),
        $months[$dt->format('n')-1],
        $dt->format('Y')
    );
}

/* ══════════════════════════════════════════════════════════════════════════
   MODE CETAK BAU — dokumen resmi siap print / PDF
   Dipanggil lewat: bau.php?exam_id=X&print=1
   ══════════════════════════════════════════════════════════════════════════ */
if ($printBau && $selectedExam): ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Berita Acara — <?= htmlspecialchars($selectedExam['title']) ?></title>
<style>
*{box-sizing:border-box;}
body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#000;margin:0;padding:20px 30px;line-height:1.5;}

/* KOP SURAT */
.kop-surat{text-align:center;margin-bottom:24px;border-bottom:3px double #000;padding-bottom:14px;}
.kop-row{display:flex;align-items:center;justify-content:center;gap:18px;}
.kop-logo{width:80px;height:80px;flex-shrink:0;}
.kop-logo img{width:100%;height:100%;object-fit:contain;}
.kop-logo-emblem{width:60px;height:60px;border-radius:50%;background:#1a1a2e;color:#fff;font-size:18pt;font-weight:bold;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.kop-text{text-align:left;}
.kop-instansi{font-size:10pt;letter-spacing:1px;margin-bottom:2px;}
.kop-sekolah{font-size:14pt;font-weight:bold;margin:2px 0;text-transform:uppercase;}
.kop-alamat,.kop-kontak{font-size:10pt;margin:1px 0;}
.kop-kontak{font-size:9pt;color:#444;}

/* JUDUL */
.bau-title{text-align:center;margin:20px 0 6px;font-size:14pt;font-weight:bold;text-decoration:underline;letter-spacing:1px;}
.bau-subtitle{text-align:center;font-size:11pt;margin-bottom:22px;color:#333;}

/* TABEL INFO */
table.info-meta{width:100%;border-collapse:collapse;margin-bottom:22px;}
table.info-meta td{padding:4px 8px;vertical-align:top;font-size:12pt;}
table.info-meta td:first-child{width:22%;font-weight:bold;}
table.info-meta td:nth-child(2){width:2%;text-align:center;}
table.info-meta td:last-child{width:76%;}

/* TABEL DATA */
table.data-table{width:100%;border-collapse:collapse;margin:12px 0 22px;font-size:11pt;}
table.data-table th,table.data-table td{border:1px solid #000;padding:5px 8px;}
table.data-table th{background:#eee;text-align:center;font-weight:bold;}
table.data-table td.center{text-align:center;}
table.data-table tr:nth-child(even){background:#f9f9f9;}

/* PARAGRAF */
p.intro{text-align:justify;margin:0 0 16px;text-indent:40px;}

/* TANDA TANGAN */
.signature-area{display:flex;justify-content:space-between;margin-top:50px;}
.sig-col{width:45%;text-align:center;}
.sig-space{height:80px;}

/* NO-PRINT TOOLBAR */
.no-print{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 20px;margin:-20px -30px 24px;-30px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-family:Inter,sans-serif;}
.btn-print{padding:9px 20px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;}
.btn-print:hover{background:#047857;}
.btn-back{padding:9px 20px;background:#64748b;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;}

@media print{body{padding:0;}.no-print{display:none!important;}}
</style>
</head>
<body>

<!-- TOOLBAR -->
<div class="no-print">
    <div><strong>SURAT BERITA ACARA PELAKSANAAN UJIAN CBT</strong></div>
    <div style="display:flex;gap:8px;">
        <button type="button" onclick="window.print()" class="btn-print">&#128436; Cetak / Simpan PDF</button>
        <a href="bau.php" class="btn-back">&#8592; Kembali</a>
    </div>
</div>

<!-- KOP SURAT -->
<div class="kop-surat">
    <div class="kop-row">
        <?php if (!empty($school['logo_url'])): ?>
            <div class="kop-logo">
                <img src="../<?= htmlspecialchars(ltrim(str_replace('\\', '/', $school['logo_url']), '/')) ?>" alt="Logo">
            </div>
        <?php else: ?>
            <div class="kop-logo-emblem"><?= strtoupper(substr($school['name'] ?: 'CBT', 0, 2)) ?></div>
        <?php endif; ?>
        <div class="kop-text">
            <div class="kop-instansi">PEMERINTAH PROVINSI DAERAH KHUSUS &bull; DINAS PENDIDIKAN</div>
            <div class="kop-sekolah"><?= strtoupper(htmlspecialchars($school['name'] ?? 'CBT Online')) ?></div>
            <div class="kop-alamat"><?= htmlspecialchars($school['address'] ?? '-') ?>&bull; Telp: <?= htmlspecialchars($school['phone'] ?? '-') ?></div>
            <div class="kop-kontak">KODE TENANT: <?= htmlspecialchars($school['code']) ?>&bull; Sistem Ujian Berbasis Komputer (CBT)</div>
        </div>
    </div>
    <div style="margin-top:8px;padding-top:6px;border-top:1px solid #000;"></div>
</div>

<!-- JUDUL -->
<div class="bau-title">BERITA ACARA PELAKSANAAN UJIAN</div>
<div class="bau-subtitle">
    Mapel: <?= htmlspecialchars($selectedExam['subject_name']) ?><br>
    Kelas: <?= htmlspecialchars($selectedExam['class_name'] ?: 'Umum') ?> &bull; Tanggal: <?= tanggalIndo($selectedExam['start_time']) ?>
</div>

<!-- PEMBUKA -->
<p class="intro">
    Pada hari <b><?= date('l', strtotime($selectedExam['start_time'])) ?></b>,
    tanggal <b><?= date('d', strtotime($selectedExam['start_time'])) ?></b>
    bulan <b><?= date('F', strtotime($selectedExam['start_time'])) ?></b>
    tahun <b><?= date('Y', strtotime($selectedExam['start_time'])) ?></b>,
    telah diselenggarakan Ujian Berbasis Komputer (CBT) dengan data pelaksanaan sebagai berikut:
</p>

<!-- META -->
<table class="info-meta">
    <tr><td>Judul Ujian</td><td>:</td><td><?= htmlspecialchars($selectedExam['title']) ?></td></tr>
    <tr><td>Bank Soal</td><td>:</td><td><?= htmlspecialchars($selectedExam['bank_title']) ?></td></tr>
    <tr><td>Mata Pelajaran</td><td>:</td><td><?= htmlspecialchars($selectedExam['subject_name']) ?></td></tr>
    <tr><td>Kelas</td><td>:</td><td><?= htmlspecialchars($selectedExam['class_name'] ?: 'Umum (Semua Kelas)') ?></td></tr>
    <tr><td>Token Ujian</td><td>:</td><td><b><?= htmlspecialchars($selectedExam['token']) ?></b></td></tr>
    <tr><td>Durasi</td><td>:</td><td><?= (int)$selectedExam['duration_minutes'] ?> menit</td></tr>
    <tr><td>Waktu Pelaksanaan</td><td>:</td><td><?= date('d/m/Y H:i', strtotime($selectedExam['start_time'])) ?> — <?= date('d/m/Y H:i', strtotime($selectedExam['end_time'])) ?></td></tr>
    <tr><td>Batas Pelanggaran</td><td>:</td><td><?= (int)$selectedExam['max_violations'] ?> kali pindah tab / keluar layar penuh</td></tr>
    <tr><td>Total Peserta Terdaftar</td><td>:</td><td><b><?= $countTotal ?> siswa</b></td></tr>
    <tr><td>Selesai Ujian</td><td>:</td><td><b style="color:#059669;"><?= $countDone ?> siswa</b> &middot; Rata-rata nilai: <b><?= $avgScore ?></b></td></tr>
    <tr><td>Sedang Berjalan</td><td>:</td><td><b style="color:#d97706;"><?= $countProgress ?> siswa</b></td></tr>
    <tr><td>Terblokir</td><td>:</td><td><b style="color:#dc2626;"><?= $countBlocked ?> siswa</b></td></tr>
</table>

<!-- A. PELANGGARAN -->
<p style="font-weight:bold;margin-top:18px;margin-bottom:6px;">A. Catatan Pelanggaran dan Kejadian Ruang Ujian:</p>
<?php if (empty($violationsLogs)): ?>
    <p style="font-style:italic;margin-left:18px;">Ujian berlangsung dengan tertib dan lancar. Tidak ada pelanggaran yang dicatat sistem.</p>
<?php else: ?>
    <table class="data-table">
        <thead><tr><th style="width:40px;">No</th><th>Waktu</th><th>NIS / Nama Siswa</th><th>Deskripsi Pelanggaran / Catatan</th></tr></thead>
        <tbody>
        <?php foreach ($violationsLogs as $idx => $vl): ?>
            <tr>
                <td class="center"><?= $idx + 1 ?></td>
                <td class="center"><?= date('H:i:s', strtotime($vl['created_at'])) ?></td>
                <td><b><?= htmlspecialchars($vl['student_name']) ?></b> <small>(<?= htmlspecialchars($vl['nis']) ?>)</small></td>
                <td><?= htmlspecialchars($vl['message']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- B. KEHADIRAN PESERTA -->
<p style="font-weight:bold;margin-top:18px;margin-bottom:6px;">B. Daftar Kehadiran dan Status Peserta Ujian:</p>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:35px;">No</th><th>NIS</th><th>Nama Peserta</th><th>Kelas</th>
            <th>Terjawab</th><th>Pelanggaran</th><th>Nilai</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($participants as $idx => $p): ?>
        <tr>
            <td class="center"><?= $idx + 1 ?></td>
            <td class="center"><code><?= htmlspecialchars($p['nis']) ?></code></td>
            <td><?= htmlspecialchars($p['student_name']) ?></td>
            <td class="center"><?= htmlspecialchars($p['class_name'] ?: 'Umum') ?></td>
            <td class="center"><?= (int)$p['answered_count'] ?> / <?= $totalQuestionsInBank ?></td>
            <td class="center"><?= $p['status'] === 'blocked' ? (int)$p['total_violations'] . '&#xd7;' : '-' ?></td>
            <td class="center" style="font-weight:bold;"><?= $p['status'] === 'completed' ? number_format($p['score'], 1) : '-' ?></td>
            <td class="center" style="font-weight:bold;"><?= strtoupper($p['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- PENUTUP -->
<p style="text-align:justify;margin:18px 0 0;">
    Demikian berita acara pelaksanaan ujian ini dibuat dengan sesungguhnya untuk dapat dipergunakan sebagaimana mestinya.
</p>

<!-- TANDA TANGAN -->
<div class="signature-area">
    <div class="sig-col">
        <div>Mengetahui,</div>
        <div style="font-weight:bold;">Kepala Sekolah / Penanggung Jawab CBT</div>
        <div class="sig-space"></div>
        <div style="text-decoration:underline;font-weight:bold;">( ............................................................ )</div>
        <div>NIP. ....................................................</div>
    </div>
    <div class="sig-col">
        <div><?= tanggalIndo($selectedExam['start_time']) ?></div>
        <div style="font-weight:bold;">Pengawas Ruang Ujian</div>
        <div class="sig-space"></div>
        <div style="text-decoration:underline;font-weight:bold;">
            <?= htmlspecialchars($user['name'] ?? '_________________________') ?>
        </div>
        <div>NIP/Username: <?= htmlspecialchars($user['username'] ?? '-') ?></div>
    </div>
</div>

</body>
</html>
<?php exit; ?>

<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════════════════ */ ?>
<?php /*  ZYZ   HALAMAN UTAMA: PILIH UJIAN UNTUK BAU                     */ ?>
<?php /* ═══════════════════════════════════════════════════════════════════ */ ?>
<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:12px;">
        <div>
            <h3 class="card-title" style="margin:0;">Berita Acara Pelaksanaan Ujian</h3>
            <p style="font-size:11.5px;color:var(--text-muted);margin:3px 0 0;">Pilih ujian untuk mencetak dokumen resmi berita acara pelaksanaan CBT</p>
        </div>
    </div>
    <div class="card-body" style="padding:20px;">

    <?php if (empty($allExams)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.4;margin-bottom:12px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>Belum ada jadwal ujian yang tersedia.<br>Silakan buat ujian terlebih dahulu di halaman <a href="exams.php">Jadwal Ujian</a>.</p>
        </div>
    <?php else: ?>

        <!-- Pilihan ujian -->
        <div style="margin-bottom:24px;">
            <label style="font-size:13px;font-weight:700;color:var(--text-heading);display:block;margin-bottom:6px;">Pilih Ujian</label>
            <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <select name="exam_id" id="examSelect" class="form-control" style="min-width:340px;font-weight:600;" onchange="this.form.submit()">
                    <option value="">&#8212; Pilih Jadwal Ujian &#8212;</option>
                    <?php foreach ($allExams as $ex): ?>
                        <option value="<?= $ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ex['title']) ?>
                            &bull; <?= htmlspecialchars($ex['subject_name']) ?>
                            &bull; <?= htmlspecialchars($ex['class_name'] ?: 'Semua Kelas') ?>
                            &bull; <?= date('d/m/Y H:i', strtotime($ex['start_time'])) ?>
                            &nbsp;<small style="color:#64748b;">(&#x60e8;<?= $ex['total_sessions'] ?> sesi)</small>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Pilih</button>
            </form>
        </div>

        <?php if ($selectedExam): ?>
            <!-- Ringkasan -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
                <div><div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Judul</div><div style="font-size:14px;font-weight:700;color:var(--text-heading);margin-top:2px;"><?= htmlspecialchars($selectedExam['title']) ?></div></div>
                <div><div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Mapel / Kelas</div><div style="font-size:14px;font-weight:600;margin-top:2px;"><?= htmlspecialchars($selectedExam['subject_name']) ?> &bull; <?= htmlspecialchars($selectedExam['class_name'] ?: 'Umum') ?></div></div>
                <div><div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Tanggal</div><div style="font-size:14px;font-weight:600;margin-top:2px;"><?= tanggalIndo($selectedExam['start_time']) ?></div></div>
                <div><div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Peserta</div><div style="font-size:14px;font-weight:600;margin-top:2px;"><?= $countTotal ?> siswa</div></div>
                <div><div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Selesai</div><div style="font-size:14px;font-weight:600;color:#059669;margin-top:2px;"><?= $countDone ?> siswa</div></div>
                <div><div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Rata-rata Nilai</div><div style="font-size:14px;font-weight:700;margin-top:2px;"><?= $countDone > 0 ? $avgScore : '-' ?></div></div>
            </div>

            <!-- Tombol cetak -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
                <a href="bau.php?exam_id=<?= $examId ?>&print=1" target="_blank" class="btn btn-primary btn-sm">
                    &#128436; Cetak Berita Acara
                </a>
                <?php if ($countDone > 0): ?>
                <a href="results-pdf.php?exam_id=<?= $examId ?>" target="_blank" class="btn btn-outline btn-sm">&#128202; Rekap Nilai -></a>
                <?php endif; ?>
            </div>

            <!-- Preview ringkas peserta -->
            <details style="margin-top:10px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:700;color:var(--primary-blue);">&#128203; Lihat daftar peserta (&lt;?= $countTotal ?&gt; siswa)</summary>
                <table class="data-table" style="margin-top:10px;font-size:10pt;">
                    <thead><tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Terjawab</th><th>Pelanggaran</th><th>Nilai</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($participants as $idx => $p): ?>
                        <tr>
                            <td class="center"><?= $idx+1 ?></td>
                            <td class="center"><code><?= htmlspecialchars($p['nis']) ?></code></td>
                            <td><?= htmlspecialchars($p['student_name']) ?></td>
                            <td class="center"><?= htmlspecialchars($p['class_name']?:'Umum') ?></td>
                            <td class="center"><?= (int)$p['answered_count'] ?>/<?= $totalQuestionsInBank ?></td>
                            <td class="center"><?= $p['status']==='blocked'? (int)$p['total_violations'].'x':'-' ?></td>
                            <td class="center" style="font-weight:bold;"><?= $p['status']==='completed'?number_format($p['score'],1):'-' ?></td>
                            <td class="center" style="font-weight:bold;color:<?= $p['status']==='completed'?'#059669':($p['status']==='blocked'?'#dc2626':'#d97706') ?>"><?= strtoupper($p['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

    <?php endif; ?>

    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>