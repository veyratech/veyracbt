<?php
/**
 * Student Exam Page — Ujian Berjalan (CBT Exam Engine)
 *
 * Halaman ini menjadi host bagi assets/js/exam-engine.js:
 * autosave real-time, timer server-side, deteksi pelanggaran
 * (tab switch/fullscreen), heartbeat monitor, broadcast pengawas,
 * dukungan essay, ragu-ragu, zoom gambar, dan antrian offline.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Ujian Berjalan';
$pageKey   = 'exam';
require_once __DIR__ . '/header.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) { Helper::redirect('dashboard.php'); exit; }

// Ambil sesi + info ujian
$session = Database::fetch(
    "SELECT es.*, e.title as exam_title, e.duration_minutes, e.max_violations,
            e.show_score_immediately, qb.title as bank_title, s.name as subject_name
     FROM exam_sessions es
     JOIN exams e ON es.exam_id = e.id
     JOIN question_banks qb ON e.bank_id = qb.id
     JOIN subjects s ON qb.subject_id = s.id
     WHERE es.id = ? AND es.student_id = ? AND es.tenant_id = ?",
    [$sessionId, (int)$student['id'], $tenantId]
);
if (!$session) { Helper::redirect('dashboard.php'); exit; }

if ($session['status'] === 'completed') { Helper::redirect('result.php?session_id=' . $sessionId); exit; }

$effEndTs       = strtotime($session['end_time']) + ((int)$session['extra_time_minutes'] * 60);
$remainingSeconds = max(0, $effEndTs - time());

// Waktu habis → kumpulkan (finish.php menilai & menyelesaikan)
if ($session['status'] === 'in_progress' && $remainingSeconds <= 0) {
    Helper::redirect('finish.php?session_id=' . $sessionId); exit;
}

// Sesi diblokir pengawas/sistem → tampilkan layar blokir tanpa engine
if ($session['status'] === 'blocked'): ?>
    <style>
    .blocked-card { max-width:560px;margin:48px auto;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 28px;text-align:center; }
    .blocked-icon { width:64px;height:64px;border-radius:50%;background:#fef2f2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 16px; }
    .blocked-title { font-size:19px;font-weight:900;color:var(--text);margin-bottom:8px; }
    .blocked-text { font-size:13.5px;color:var(--muted);line-height:1.7;margin-bottom:22px; }
    </style>
    <div class="blocked-card">
        <div class="blocked-icon">&#9888;</div>
        <div class="blocked-title">Ujian Dihentikan Sementara</div>
        <div class="blocked-text">
            Sesi ujian Anda dihentikan karena mencapai batas pelanggaran
            (<?= (int)$session['total_violations'] ?>/<?= (int)$session['max_violations'] ?>).
            Hubungi pengawas ruang untuk membuka kembali sesi Anda,
            lalu masuk ulang dari dashboard.
        </div>
        <a href="dashboard.php" class="btn btn-primary" style="padding:11px 26px;font-weight:700;">Kembali ke Dashboard</a>
    </div>
<?php
    require_once __DIR__ . '/footer.php';
    exit;
endif;

// ── Susun data soal untuk engine (TANPA kunci jawaban/pembahasan) ──
$questionIds = json_decode($session['question_order'] ?? '[]', true) ?: [];
$questionIds = array_values(array_filter(array_map('intval', $questionIds)));
if (empty($questionIds)) {
    Helper::setFlash('danger', 'Data urutan soal sesi tidak valid. Hubungi guru.');
    Helper::redirect('dashboard.php');
    exit;
}

$placeholders = implode(',', array_fill(0, count($questionIds), '?'));
$questions = Database::fetchAll(
    "SELECT id, question_type, question_text, question_image, question_video, question_audio,
            audio_play_limit, option_a, option_b, option_c, option_d, option_e
     FROM questions
     WHERE id IN ($placeholders) AND tenant_id = ?",
    array_merge($questionIds, [$tenantId])
);
$qMap = [];
foreach ($questions as $q) $qMap[(int)$q['id']] = $q;

$answerRows = Database::fetchAll(
    "SELECT question_id, student_answer, answer_text, is_doubt FROM exam_answers WHERE session_id = ?",
    [$sessionId]
);
$aMap = [];
foreach ($answerRows as $ar) $aMap[(int)$ar['question_id']] = $ar;

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$engineQuestions = [];
foreach ($questionIds as $qid) {
    $q = $qMap[$qid] ?? null;
    if (!$q) continue;
    $a = $aMap[$qid] ?? [];
    $engineQuestions[] = [
        'id'              => $qid,
        'question_type'   => $q['question_type'],
        'question_text'   => $esc($q['question_text']),
        'question_image'  => $q['question_image'] ?: null,
        'question_video'  => $q['question_video'] ?: null,
        'question_audio'  => $q['question_audio'] ?: null,
        'audio_play_limit'=> (int)($q['audio_play_limit'] ?? 0),
        'option_a'        => $esc($q['option_a']),
        'option_b'        => $esc($q['option_b']),
        'option_c'        => $esc($q['option_c']),
        'option_d'        => $esc($q['option_d']),
        'option_e'        => $q['option_e'] !== null && $q['option_e'] !== '' ? $esc($q['option_e']) : null,
        'student_answer'  => $a['student_answer'] ?? null,
        'answer_text'     => isset($a['answer_text']) ? $esc($a['answer_text']) : null,
        'is_doubt'        => (int)($a['is_doubt'] ?? 0),
    ];
}
if (empty($engineQuestions)) {
    Helper::setFlash('danger', 'Soal untuk sesi ini tidak ditemukan. Hubungi guru.');
    Helper::redirect('dashboard.php');
    exit;
}

$questionCount   = count($engineQuestions);
$questionsJson   = json_encode($engineQuestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$engineFile      = __DIR__ . '/../assets/js/exam-engine.js';
$engineV         = file_exists($engineFile) ? filemtime($engineFile) : 0;
$examCssV        = file_exists(__DIR__ . '/../assets/css/exam.css') ? filemtime(__DIR__ . '/../assets/css/exam.css') : 0;
$violationsNow   = (int)$session['total_violations'];
$maxViolations   = (int)$session['max_violations'];
?>

<link rel="stylesheet" href="../assets/css/exam.css?v=<?= $examCssV ?>&t=<?= time() ?>">
<style>
    /* Penyesuaian lokal host engine */
    .webcam-box { font-size:11px;color:var(--muted); }
    .webcam-box video { width:100%;border-radius:10px;display:block; }
    #modalImageZoom .modal-dialog { background:transparent;border:none;box-shadow:none;max-width:min(920px,94vw); }
    .exam-side-info { margin-top:14px;font-size:11.5px;line-height:1.8;color:var(--muted); }
    .exam-side-info b { color:var(--text); }
</style>

<!-- BANNER: pelanggaran / waktu / offline -->
<div class="violation-banner" id="violationBanner" style="display:none;"><span id="violationText"></span></div>
<div class="timer-warning-banner" id="timerWarningBanner" style="display:none;"></div>
<div class="offline-banner" id="offlineBanner" style="display:none;">Koneksi terputus — jawaban disimpan lokal dan akan disinkronkan otomatis.</div>

<!-- NAVBAR UJIAN -->
<div class="exam-navbar">
    <div class="exam-nav-left">
        <span class="school-tag"><?= $esc(mb_substr($school['name'] ?? 'Sekolah', 0, 22)) ?></span>
        <div>
            <div class="exam-title-header">
                <?= $esc($session['exam_title']) ?><br>
                <span><?= $esc($session['subject_name'] ?? '-') ?></span>
            </div>
            <div class="timer-inline-mobile timer-box" id="timerBoxMobile">
                <span class="timer-label">&#9201; Sisa</span>
                <span class="timer-digits" id="timerDisplayMobile">00:00:00</span>
            </div>
        </div>
    </div>

    <div class="exam-nav-center">
        <div class="timer-box" id="timerBox">
            <span class="timer-label">&#9201; Sisa Waktu</span>
            <span class="timer-digits" id="timerDisplay">00:00:00</span>
        </div>
    </div>

    <div class="exam-nav-right">
        <div class="fullscreen-badge" id="fullscreenBadge"><span class="fs-dot"></span>Fullscreen</div>
        <div class="network-badge" id="networkBadge"><span class="net-dot"></span><span id="networkText">ONLINE</span></div>
        <div class="student-pill"><b><?= $esc($student['name']) ?></b><span> · <?= $esc($student['nis'] ?? '') ?></span></div>
    </div>
</div>

<!-- KONTEN UTAMA -->
<div class="exam-container">
    <!-- KARTU SOAL -->
    <div class="question-card font-medium">
        <div class="question-card-header">
            <span class="q-number-pill" id="qNumberDisplay">Soal No. 1 dari <?= $questionCount ?></span>
            <div class="font-resizer-group" title="Ukuran teks">
                <button type="button" class="btn-font-resizer" data-font="small">A&minus;</button>
                <button type="button" class="btn-font-resizer" data-font="medium">A</button>
                <button type="button" class="btn-font-resizer" data-font="large">A+</button>
            </div>
        </div>
        <div class="question-card-body">
            <div class="q-text" id="qTextDisplay"></div>
            <div class="options-list" id="optionsContainer"></div>
        </div>
        <div class="question-card-footer">
            <button type="button" class="btn-doubt" id="btnDoubt"><span>&#9744;</span> Ragu-ragu</button>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn" id="btnPrev">&larr; Sebelumnya</button>
                <button type="button" class="btn btn-primary" id="btnNext">Selanjutnya &rarr;</button>
                <button type="button" class="btn btn-primary" id="btnFinish">Selesai &amp; Kumpulkan</button>
            </div>
        </div>
    </div>

    <!-- SIDEBAR: NAVIGASI + INFO + WEBCAM -->
    <div class="nav-card">
        <div class="nav-card-title">Navigasi Soal</div>
        <div class="legend-list">
            <span class="legend-item"><span class="legend-color color-answered"></span>Terjawab</span>
            <span class="legend-item"><span class="legend-color color-doubt"></span>Ragu-ragu</span>
            <span class="legend-item"><span class="legend-color color-empty"></span>Belum dijawab</span>
        </div>
        <div class="q-grid" id="questionGrid"></div>

        <div class="exam-side-info">
            <div>Mata Pelajaran: <b><?= $esc($session['subject_name'] ?? '-') ?></b></div>
            <div>Jumlah Soal: <b><?= $questionCount ?></b></div>
            <div>Durasi: <b><?= (int)$session['duration_minutes'] ?> menit</b></div>
            <div>Pelanggaran: <b><?= $violationsNow ?> / <?= $maxViolations ?></b></div>
        </div>

        <!-- Widget webcam: kamera aktif untuk pengawasan, feed disembunyikan dari siswa -->
        <div class="webcam-box" style="margin-top: 16px; display: none;">
            <video id="cbtWebcamVideo" playsinline muted></video>
            <canvas id="cbtWebcamCanvas" width="320" height="240" style="display:none;"></canvas>
            <div id="cbtWebcamPlaceholder">Kamera belum aktif</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                <span id="webcamIndicatorDot" style="width:8px;height:8px;border-radius:50%;background:#94a3b8;display:inline-block;"></span>
                <span id="webcamStatusBadge">Memuat kamera&hellip;</span>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: KONFIRMASI SELESAI -->
<div class="modal-backdrop" id="modalConfirmFinish">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>Kumpulkan Ujian?</h3>
            <button type="button" class="btn btn-sm" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--text);line-height:1.7;margin-bottom:16px;">
                Pastikan semua jawaban sudah tersimpan. Setelah dikumpulkan, jawaban tidak dapat diubah lagi.
            </p>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center;">
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:12px;">
                    <div style="font-size:22px;font-weight:900;color:#059669;" id="statAnswered">0</div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">Terjawab</div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:12px;">
                    <div style="font-size:22px;font-weight:900;color:#d97706;" id="statDoubt">0</div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">Ragu-ragu</div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:12px;">
                    <div style="font-size:22px;font-weight:900;color:#dc2626;" id="statEmpty">0</div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">Kosong</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-close-modal>Batal</button>
            <button type="button" class="btn btn-primary" id="btnSubmitFinal">Ya, Kumpulkan Sekarang</button>
        </div>
    </div>
</div>

<!-- MODAL: PERINGATAN PELANGGARAN -->
<div class="modal-backdrop" id="modalWarningViolation">
    <div class="modal-dialog" style="max-width:460px;">
        <div class="modal-header" style="background:#7f1d1d;border-bottom-color:#991b1b;">
            <h3 style="color:#fecaca;">&#9888; Pelanggaran Terdeteksi</h3>
            <button type="button" class="btn btn-sm" data-close-modal style="color:#fecaca;">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--text);line-height:1.7;margin-bottom:10px;" id="vioCountText"></p>
            <p style="font-size:13px;color:var(--muted);line-height:1.7;">
                Jangan berpindah tab, membuka aplikasi lain, atau keluar dari mode fullscreen.
                Pelanggaran berulang akan menghentikan ujian Anda secara otomatis.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-close-modal>Mengerti</button>
        </div>
    </div>
</div>

<!-- MODAL: ZOOM GAMBAR SOAL -->
<div class="modal-backdrop" id="modalImageZoom">
    <div class="modal-dialog">
        <img id="zoomImageTarget" style="width:100%;border-radius:14px;display:block;" alt="Gambar soal">
        <div style="text-align:center;margin-top:12px;">
            <button type="button" class="btn" data-close-modal>Tutup</button>
        </div>
    </div>
</div>

<script src="../assets/js/exam-engine.js?v=<?= $engineV ?>&t=<?= time() ?>"></script>
<script>
(function () {
    window.engine = new CBTEngine({
        sessionId:        <?= $sessionId ?>,
        tenantId:         <?= (int)$tenantId ?>,
        examId:           <?= (int)$session['exam_id'] ?>,
        questions:        <?= $questionsJson ?>,
        remainingSeconds: <?= $remainingSeconds ?>,
        maxViolations:    <?= $maxViolations ?>,
        violations:       <?= $violationsNow ?>,
        apiUrl:           'api-save.php',
        finishUrl:        'finish.php',
        csrfToken:        <?= json_encode(Helper::csrfToken()) ?>,
        extraTimeMinutes: <?= (int)$session['extra_time_minutes'] ?>
    });

    // Tombol layar penuh di topbar portal (jika ikut dirender)
    var btnFs = document.getElementById('btnFullscreen');
    if (btnFs) {
        btnFs.addEventListener('click', function () {
            var el = document.documentElement;
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            } else if (el.requestFullscreen) {
                el.requestFullscreen().catch(function () {});
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
