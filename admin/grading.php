<?php
/**
 * Koreksi Manual Essay — Penilaian Jawaban Essay oleh Guru
 *
 * Fitur:
 * - Daftar sesi ujian (selesai/blokir) beserta progres koreksi essay
 * - Form input skor per jawaban essay (0 s/d poin soal, boleh desimal)
 * - Update exam_answers.essay_score + is_correct, lalu hitung ulang skor sesi
 *
 * Catatan penilaian:
 * - is_correct = 1 hanya untuk skor penuh (essay_score >= poin soal),
 *   skor parsial tetap menambah skor sesi lewat kolom essay_score.
 * - Jawaban essay yang belum dinilai (essay_score IS NULL) tidak dihitung
 *   benar maupun salah, dan ditandai "Belum Dinilai" pada rekap.
 */

$pageTitle = "Koreksi Essay";
require_once __DIR__ . '/_bootstrap.php';

/* ---------------------------------------------------------------
 * 1. HANDLE POST — Simpan Nilai Essay (sebelum header tampil)
 * --------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'grade') {
        $examIdPost = (int)($_POST['exam_id'] ?? 0);
        $sessionId  = (int)($_POST['session_id'] ?? 0);

        $session = Database::fetch(
            "SELECT * FROM exam_sessions WHERE id = ? AND tenant_id = ?",
            [$sessionId, $tenantId]
        );

        if (!$session) {
            Helper::setFlash('danger', 'Sesi ujian tidak ditemukan.');
            Helper::redirect('admin/grading.php');
        }

        // Siswa masih bisa mengubah jawaban selama ujian berjalan,
        // sehingga koreksi hanya diizinkan setelah ujian dikumpulkan.
        if ($session['status'] === 'in_progress') {
            Helper::setFlash('warning', 'Ujian masih berjalan. Koreksi essay hanya dapat dilakukan setelah siswa mengumpulkan ujian.');
            Helper::redirect("admin/grading.php?exam_id={$examIdPost}&session_id={$sessionId}");
        }

        // Kumpulkan & validasi semua skor dulu sebelum menyimpan
        $answerRows = Database::fetchAll(
            "SELECT ea.id, ea.answer_text, q.points
             FROM exam_answers ea
             JOIN questions q ON ea.question_id = q.id
             WHERE ea.session_id = ? AND q.question_type = 'essay' AND ea.answer_text IS NOT NULL",
            [$sessionId]
        );

        $posted  = $_POST['score'] ?? [];
        $updates = [];
        $invalid = null;

        foreach ($answerRows as $row) {
            $answerId = (int)$row['id'];
            if (!isset($posted[$answerId])) {
                continue; // baris tanpa input (mis. sudah dinilai sebelumnya dan tidak diubah)
            }

            $raw = str_replace(',', '.', trim((string)$posted[$answerId]));
            if ($raw === '') {
                continue; // dikosongkan → biarkan nilai lama
            }
            if (!is_numeric($raw)) {
                $invalid = 'Skor harus berupa angka.';
                break;
            }

            $val = (float)$raw;
            $max = (float)$row['points'];
            if ($val < 0)   $val = 0.0;
            if ($val > $max) $val = $max;

            $updates[$answerId] = [
                'essay_score' => $val,
                'is_correct'  => ($val >= $max) ? 1 : 0,
            ];
        }

        if ($invalid !== null) {
            Helper::setFlash('warning', $invalid . ' Silakan periksa kembali isian Anda.');
            Helper::redirect("admin/grading.php?exam_id={$examIdPost}&session_id={$sessionId}");
        }

        foreach ($updates as $answerId => $data) {
            Database::update('exam_answers', $data, 'id = :id', ['id' => $answerId]);
        }

        // Hitung ulang skor sesi agar essay ikut terhitung
        $newScore = (float)Database::fetchColumn(
            "SELECT COALESCE(SUM(CASE WHEN q.question_type = 'essay' THEN COALESCE(ea.essay_score, 0)
                                     WHEN ea.is_correct = 1 THEN q.points ELSE 0 END), 0)
             FROM exam_answers ea
             JOIN questions q ON ea.question_id = q.id
             WHERE ea.session_id = ?",
            [$sessionId]
        );

        Database::update('exam_sessions', [
            'score' => $newScore,
        ], 'id = :id AND tenant_id = :tenant_id', [
            'id'        => $sessionId,
            'tenant_id' => $tenantId,
        ]);

        if (Database::tableExists('activity_logs')) {
            Database::insert('activity_logs', [
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
                'event_type' => 'grade_essay',
                'message'    => "Guru menilai " . count($updates) . " jawaban essay. Skor sesi diperbarui menjadi {$newScore}",
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }

        Helper::setFlash(
            'success',
            count($updates) . ' jawaban essay berhasil dinilai. Skor sesi diperbarui menjadi ' . number_format($newScore, 1) . '.'
        );
        Helper::redirect("admin/grading.php?exam_id={$examIdPost}&session_id={$sessionId}");
    }

    Helper::setFlash('warning', 'Aksi tidak dikenal.');
    Helper::redirect('admin/grading.php');
}

/* ---------------------------------------------------------------
 * 2. DATA UNTUK TAMPILAN
 * --------------------------------------------------------------- */
$examId    = (int)($_GET['exam_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);

// Hanya ujian yang memiliki soal essay
$examsList = Database::fetchAll(
    "SELECT DISTINCT e.id, e.title, e.token
     FROM exams e
     JOIN questions q ON q.bank_id = e.bank_id AND q.question_type = 'essay'
     WHERE e.tenant_id = ?
     ORDER BY e.id DESC",
    [$tenantId]
);

if ($examId === 0 && !empty($examsList)) {
    $examId = (int)$examsList[0]['id'];
}

$selectedExam = null;
if ($examId > 0) {
    $selectedExam = Database::fetch(
        "SELECT e.*, qb.title as bank_title, s.name as subject_name
         FROM exams e
         JOIN question_banks qb ON e.bank_id = qb.id
         JOIN subjects s ON qb.subject_id = s.id
         WHERE e.id = ? AND e.tenant_id = ?",
        [$examId, $tenantId]
    );
}

// Daftar sesi beserta progres koreksi essay
$sessions = [];
if ($selectedExam) {
    $sessions = Database::fetchAll(
        "SELECT es.id, es.status, es.score, es.finish_time,
                st.name as student_name, st.nis, c.name as class_name,
                (SELECT COUNT(*) FROM exam_answers ea
                 JOIN questions q ON ea.question_id = q.id
                 WHERE ea.session_id = es.id AND q.question_type = 'essay') as essay_total,
                (SELECT COUNT(*) FROM exam_answers ea
                 JOIN questions q ON ea.question_id = q.id
                 WHERE ea.session_id = es.id AND q.question_type = 'essay'
                   AND ea.answer_text IS NOT NULL) as essay_answered,
                (SELECT COUNT(*) FROM exam_answers ea
                 JOIN questions q ON ea.question_id = q.id
                 WHERE ea.session_id = es.id AND q.question_type = 'essay'
                   AND ea.answer_text IS NOT NULL AND ea.essay_score IS NOT NULL) as essay_graded
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.exam_id = ? AND es.tenant_id = ? AND es.status IN ('completed', 'blocked')
         ORDER BY st.name ASC",
        [$examId, $tenantId]
    );
}

// Detail sesi yang sedang dikoreksi
$gradingSession = null;
$sessionNotFound = false;
$essayItems = [];

if ($selectedExam && $sessionId > 0) {
    $gradingSession = Database::fetch(
        "SELECT es.*, st.name as student_name, st.nis, c.name as class_name
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.id = ? AND es.exam_id = ? AND es.tenant_id = ?",
        [$sessionId, $examId, $tenantId]
    );

    if ($gradingSession) {
        $essayItems = Database::fetchAll(
            "SELECT ea.id as answer_id, ea.answer_text, ea.essay_score, ea.is_correct, ea.is_doubt,
                    q.id as question_id, q.question_text, q.points, q.explanation, q.question_image
             FROM exam_answers ea
             JOIN questions q ON ea.question_id = q.id
             WHERE ea.session_id = ? AND q.question_type = 'essay'
             ORDER BY q.id ASC",
            [$sessionId]
        );

        // Urutkan sesuai urutan pengerjaan siswa
        $orderIds = json_decode($gradingSession['question_order'] ?? '[]', true) ?: [];
        $position = [];
        foreach ($orderIds as $i => $qid) {
            $position[(int)$qid] = $i;
        }
        foreach ($essayItems as &$it) {
            $it['no'] = isset($position[(int)$it['question_id']]) ? $position[(int)$it['question_id']] + 1 : 0;
        }
        unset($it);
        usort($essayItems, fn($a, $b) => $a['no'] <=> $b['no']);
    } else {
        $sessionNotFound = true;
    }
}

require_once __DIR__ . '/header.php';

/** Format angka skor untuk value input (8.00 → "8", 8.50 → "8.5") */
function gradingInputValue($v): string {
    if ($v === null) return '';
    return (string)(float)$v;
}
?>

<style>
/* ============================================
   STYLE 1 — CLEAN & MINIMALIS
   ============================================ */
.g-page { max-width: 840px; margin: 0 auto; }

.g-topbar { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
.g-title  { font-size: 22px; font-weight: 800; color: var(--text-heading); margin: 0 0 4px; letter-spacing: -0.3px; }
.g-sub    { font-size: 13px; color: var(--text-muted); margin: 0; }
.g-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.g-select { padding: 8px 12px; border-radius: 8px; border: 1.5px solid var(--border-color); background: var(--bg-card); color: var(--text-heading); font-weight: 600; font-size: 13px; cursor: pointer; }
.g-btn    { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; transition: all .15s ease; border: 1.5px solid transparent; cursor: pointer; background: none; }
.g-btn-outline { border-color: var(--border-color); color: var(--text-body); background: var(--bg-card); }
.g-btn-outline:hover { border-color: #94a3b8; background: var(--bg-subtle); }
.g-btn-save  { background: #059669; color: #fff; border-color: #059669; padding: 10px 24px; font-size: 14px; }
.g-btn-save:hover { background: #047857; border-color: #047857; }

/* Stat cards */
.g-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
@media (max-width: 640px) { .g-stats { grid-template-columns: repeat(2, 1fr); } }
.g-stat  { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px 18px; text-align: center; }
.g-stat-num { font-size: 26px; font-weight: 800; color: var(--text-heading); line-height: 1; }
.g-stat-lbl { font-size: 11px; color: var(--text-muted); margin-top: 6px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.g-stat-accent-green .g-stat-num { color: #059669; }
.g-stat-accent-amber .g-stat-num { color: #d97706; }
.g-stat-accent-blue  .g-stat-num { color: #6366f1; }

/* Session info card */
.g-sess-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 18px 22px; margin-bottom: 20px; }
.g-sess-top  { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; }
.g-sess-name { font-size: 17px; font-weight: 700; color: var(--text-heading); margin: 0 0 4px; }
.g-sess-meta { font-size: 12.5px; color: var(--text-muted); margin: 0; }
.g-sess-badges { display: flex; gap: 6px; flex-wrap: wrap; }

/* Question card */
.g-q-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 22px 24px; margin-bottom: 16px; transition: box-shadow .15s ease; }
.g-q-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06); }
.g-q-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
.g-q-num    { font-size: 11px; font-weight: 800; color: #6366f1; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 6px; }
.g-q-text   { font-size: 14.5px; font-weight: 600; color: var(--text-heading); line-height: 1.6; margin: 0; }
.g-q-badges { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }

/* Answer box */
.g-section-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .6px; margin-bottom: 8px; }
.g-answer-box    { background: var(--bg-subtle); border: 1.5px solid var(--border-color); border-radius: 10px; padding: 14px 16px; font-size: 13.5px; line-height: 1.7; color: var(--text-body); white-space: pre-wrap; }

/* Explanation */
.g-explain summary { cursor: pointer; font-size: 12px; font-weight: 700; color: #6366f1; user-select: none; }
.g-explain-box   { margin-top: 8px; background: rgba(99,102,241,.06); border-left: 3px solid #6366f1; border-radius: 0 8px 8px 0; padding: 12px 16px; font-size: 13px; color: var(--text-body); line-height: 1.65; }

/* Score input row */
.g-score-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-color); }
.g-score-label { font-size: 13.5px; font-weight: 700; color: var(--text-heading); white-space: nowrap; }
.g-score-input { width: 120px; text-align: center; font-size: 17px; font-weight: 700; padding: 9px 10px; border-radius: 8px; border: 1.5px solid var(--border-color); background: var(--bg-subtle); color: var(--text-heading); transition: border-color .15s; }
.g-score-input:focus { outline: none; border-color: #6366f1; background: var(--bg-card); }
.g-score-hint  { font-size: 12px; color: var(--text-muted); }
.g-blank-msg   { font-size: 13px; color: var(--text-muted); padding: 8px 0; }

/* Bottom action row */
.g-footer { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-color); }
.g-footer-hint { font-size: 12px; color: var(--text-muted); margin-left: auto; }

/* Table overrides */
.g-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.g-table th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); padding: 12px 14px; border-bottom: 2px solid var(--border-color); text-align: left; background: var(--bg-subtle); }
.g-table td { padding: 12px 14px; border-bottom: 1px solid var(--border-color); vertical-align: middle; font-size: 13.5px; }
.g-table tbody tr:hover { background: var(--bg-subtle); }
.g-table tbody tr:last-child td { border-bottom: none; }
.g-table-center { text-align: center; }
.g-nis-code { font-family: 'JetBrains Mono', monospace; font-size: 12px; background: var(--bg-subtle); padding: 3px 8px; border-radius: 6px; color: var(--text-muted); }
</style>

<div class="g-page">

<!-- TOP BAR -->
<div class="g-topbar">
    <div>
        <h1 class="g-title">Koreksi Jawaban Essay</h1>
        <p class="g-sub">Nilai jawaban essay siswa — skor ujian dihitung ulang otomatis setelah disimpan</p>
    </div>
    <div class="g-actions">
        <form method="GET" style="display:flex;gap:8px;">
            <?php if ($sessionId > 0): ?><input type="hidden" name="session_id" value="<?= $sessionId ?>"><?php endif; ?>
            <select name="exam_id" class="g-select" onchange="this.form.submit()">
                <?php if (empty($examsList)): ?>
                    <option value="0">— Belum ada ujian dengan soal essay —</option>
                <?php endif; ?>
                <?php foreach ($examsList as $ex): ?>
                    <option value="<?= $ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ex['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($selectedExam): ?>
            <a href="results.php?exam_id=<?= $examId ?>" class="g-btn g-btn-outline">Rekap Nilai</a>
        <?php endif; ?>
        <?php if ($gradingSession): ?>
            <a href="grading.php?exam_id=<?= $examId ?>" class="g-btn g-btn-outline">&larr; Daftar Sesi</a>
        <?php endif; ?>
    </div>
</div>

<!-- =================== EMPTY EXAM LIST =================== -->
<?php if (empty($examsList)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted);">
            Belum ada ujian dengan soal essay pada bank soal Anda.<br>
            <small style="color:var(--text-muted);">Tambahkan butir soal bertipe <b>Essay</b> terlebih dahulu di menu Bank Soal.</small>
        </div>
    </div>

<!-- =================== SESSION NOT FOUND =================== -->
<?php elseif ($sessionNotFound): ?>
    <div class="alert alert-warning" style="border-radius:14px;">
        Sesi ujian tidak ditemukan pada ujian ini. Silakan pilih sesi dari daftar.
    </div>

<!-- =================== GRADING FORM (per-session) =================== -->
<?php elseif ($gradingSession): ?>
    <?php
        $gradedCount  = 0;
        $ungradedCount = 0;
        foreach ($essayItems as $it) {
            if ($it['answer_text'] === null || trim($it['answer_text']) === '') continue;
            $gradedCount++;
        }
        foreach ($essayItems as $it) {
            if ($it['answer_text'] === null || trim($it['answer_text']) === '') continue;
            if ($it['essay_score'] === null) $ungradedCount++;
        }
    ?>

    <!-- Stats cards -->
    <div class="g-stats">
        <div class="g-stat g-stat-accent-green">
            <div class="g-stat-num"><?= $gradedCount - $ungradedCount ?></div>
            <div class="g-stat-lbl">Sudah Dinilai</div>
        </div>
        <div class="g-stat g-stat-accent-amber">
            <div class="g-stat-num"><?= $ungradedCount ?></div>
            <div class="g-stat-lbl">Belum Dinilai</div>
        </div>
        <div class="g-stat g-stat-accent-blue">
            <div class="g-stat-num"><?= count(array_filter($essayItems, fn($i)=>($i['answer_text']??'')!==null&&trim($i['answer_text'])!=='')) ?></div>
            <div class="g-stat-lbl">Soal Dijawab</div>
        </div>
        <div class="g-stat">
            <div class="g-stat-num" style="color:<?= (float)$gradingSession['score'] >= 75 ? '#059669' : ((float)$gradingSession['score'] >= 50 ? '#d97706' : '#dc2626') ?>">
                <?= number_format((float)$gradingSession['score'], 1) ?>
            </div>
            <div class="g-stat-lbl">Skor Sesi</div>
        </div>
    </div>

    <!-- Session info -->
    <div class="g-sess-card">
        <div class="g-sess-top">
            <div>
                <div class="g-sess-name">
                    <?= htmlspecialchars($gradingSession['student_name']) ?>
                    <span class="badge badge-neutral" style="margin-left:8px;font-size:11px;vertical-align:middle;"><?= htmlspecialchars($gradingSession['nis']) ?></span>
                    <?php if (!empty($gradingSession['class_name'])): ?>
                        <span class="badge badge-neutral" style="margin-left:4px;font-size:11px;vertical-align:middle;"><?= htmlspecialchars($gradingSession['class_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="g-sess-meta">
                    <?= htmlspecialchars($selectedExam['title']) ?>
                    &middot; Selesai: <b><?= $gradingSession['finish_time'] ? date('d M Y, H:i', strtotime($gradingSession['finish_time'])) : '-' ?></b>
                </div>
            </div>
            <div class="g-sess-badges">
                <?php if ($gradingSession['status'] === 'completed'): ?>
                    <span class="badge badge-success">Selesai</span>
                <?php else: ?>
                    <span class="badge badge-danger">Diblokir</span>
                <?php endif; ?>
                <span class="badge badge-neutral"><?= count($essayItems) ?> soal essay</span>
            </div>
        </div>
        <?php if ($gradingSession['status'] === 'in_progress'): ?>
            <div style="margin-top:12px;padding:10px 14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;font-size:12.5px;color:#92400e;">
                ⚠ Ujian masih berjalan — koreksi akan tersimpan, tetapi skor dapat berubah lagi bila siswa mengubah jawabannya.
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($essayItems)): ?>
        <div class="card">
            <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted);">
                Tidak ada soal essay pada sesi ini.
            </div>
        </div>
    <?php else: ?>
        <form method="POST" id="gradingForm">
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="grade">
            <input type="hidden" name="exam_id" value="<?= $examId ?>">
            <input type="hidden" name="session_id" value="<?= (int)$gradingSession['id'] ?>">

            <?php foreach ($essayItems as $idx => $it):
                $answered   = $it['answer_text'] !== null && trim($it['answer_text']) !== '';
                $isGraded   = $it['essay_score'] !== null;
                $maxPoints  = (float)$it['points'];
                $curVal     = $isGraded ? (float)$it['essay_score'] : null;
            ?>
                <div class="g-q-card">
                    <div class="g-q-header">
                        <div>
                            <div class="g-q-num">Soal #<?= (int)$it['no'] ?></div>
                            <p class="g-q-text"><?= nl2br(htmlspecialchars($it['question_text'])) ?></p>
                        </div>
                        <div class="g-q-badges">
                            <span class="badge badge-neutral">Poin: <?= (int)$it['points'] ?></span>
                            <?php if ((int)($it['is_doubt'] ?? 0) === 1): ?>
                                <span class="badge badge-warning">Ragu-ragu</span>
                            <?php endif; ?>
                            <?php if (!$answered): ?>
                                <span class="badge badge-neutral">Tidak Dijawab</span>
                            <?php elseif ($isGraded): ?>
                                <span class="badge badge-success">Dinilai: <?= gradingInputValue($curVal) ?>/<?= (int)$it['points'] ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning">Belum Dinilai</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($it['question_image'])): ?>
                        <div style="margin-bottom:16px;">
                            <img src="../<?= htmlspecialchars($it['question_image']) ?>" alt="Gambar soal" style="max-width:100%;max-height:320px;border-radius:12px;border:1px solid var(--border-color);">
                        </div>
                    <?php endif; ?>

                    <!-- Jawaban siswa -->
                    <div class="g-section-label">Jawaban Siswa</div>
                    <div class="g-answer-box">
                        <?= $answered ? nl2br(htmlspecialchars($it['answer_text'])) : '<span style="color:var(--text-muted);font-style:italic;">(tidak dijawab — dinilai 0)</span>' ?>
                    </div>

                    <?php if (!empty($it['explanation'])): ?>
                        <details class="g-explain" style="margin-top:14px;">
                            <summary>Lihat pembahasan / pedoman penilaian</summary>
                            <div class="g-explain-box"><?= nl2br(htmlspecialchars($it['explanation'])) ?></div>
                        </details>
                    <?php endif; ?>

                    <!-- Input skor -->
                    <?php if ($answered): ?>
                        <div class="g-score-row">
                            <label for="score_<?= (int)$it['answer_id'] ?>" class="g-score-label">Skor Essay:</label>
                            <input type="number"
                                   id="score_<?= (int)$it['answer_id'] ?>"
                                   name="score[<?= (int)$it['answer_id'] ?>]"
                                   class="g-score-input"
                                   min="0"
                                   max="<?= $maxPoints ?>"
                                   step="0.01"
                                   value="<?= gradingInputValue($curVal) ?>"
                                   placeholder="0">
                            <span class="g-score-hint">maksimal <?= (int)$it['points'] ?> poin<?= $isGraded ? '' : ' — kosongkan agar tetap belum dinilai' ?></span>
                        </div>
                    <?php else: ?>
                        <div class="g-blank-msg">Siswa tidak menjawab soal ini — skor otomatis <b>0</b> dan tidak perlu dikoreksi.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="g-footer">
                <button type="submit" class="g-btn g-btn-save">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Simpan Nilai Essay
                </button>
                <a href="grading.php?exam_id=<?= $examId ?>" class="g-btn g-btn-outline">Batal &amp; Kembali</a>
                <span class="g-footer-hint">Skor penuh = benar · skor parsial menambah nilai tanpa mengubah status</span>
            </div>
        </form>
    <?php endif; ?>

<!-- =================== SESI LIST (default view) =================== -->
<?php else: ?>
    <?php if (!$selectedExam): ?>
        <div class="card">
            <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted);">
                Ujian tidak ditemukan.
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="overflow:hidden;">
            <div style="padding:18px 22px;border-bottom:1px solid var(--border-color);background:var(--bg-subtle);">
                <h3 class="card-title" style="margin:0 0 4px;font-size:16px;">
                    <?= htmlspecialchars($selectedExam['title']) ?>
                </h3>
                <small style="color:var(--text-muted);font-size:12px;">
                    Mata Pelajaran: <b><?= htmlspecialchars($selectedExam['subject_name']) ?></b>
                    &middot; Bank Soal: <?= htmlspecialchars($selectedExam['bank_title']) ?>
                </small>
            </div>
            <?php if (empty($sessions)): ?>
                <p style="padding:36px;text-align:center;color:var(--text-muted);">Belum ada peserta yang mengumpulkan ujian ini.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="g-table">
                        <thead>
                            <tr>
                                <th style="width:44px;">No</th>
                                <th>NIS</th>
                                <th>Nama Lengkap Siswa</th>
                                <th>Kelas</th>
                                <th class="g-table-center">Essay Dijawab</th>
                                <th class="g-table-center">Belum Dinilai</th>
                                <th class="g-table-center">Status</th>
                                <th class="g-table-center" style="text-align:right;">Skor</th>
                                <th class="g-table-center" style="width:140px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $idx => $s):
                                $answeredN = (int)$s['essay_answered'];
                                $gradedN   = (int)$s['essay_graded'];
                                $pending   = $answeredN - $gradedN;
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:13px;"><?= $idx + 1 ?></td>
                                    <td><span class="g-nis-code"><?= htmlspecialchars($s['nis']) ?></span></td>
                                    <td><b style="color:var(--text-heading);"><?= htmlspecialchars($s['student_name']) ?></b></td>
                                    <td><span class="badge badge-neutral" style="font-size:11px;"><?= htmlspecialchars($s['class_name'] ?: 'Umum') ?></span></td>
                                    <td class="g-table-center" style="font-weight:600;"><?= $answeredN ?>/<sub style="font-size:10px;"><?= (int)$s['essay_total'] ?></sub></td>
                                    <td class="g-table-center">
                                        <?php if ($pending > 0): ?>
                                            <span class="badge badge-warning" style="font-size:12px;min-width:28px;display:inline-block;"><?= $pending ?></span>
                                        <?php else: ?>
                                            <span style="color:#059669;font-size:16px;line-height:1;">✓</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="g-table-center">
                                        <?php if ($s['status'] === 'completed'): ?>
                                            <span class="badge badge-success" style="font-size:11px;">Selesai</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" style="font-size:11px;">Diblokir</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="g-table-center">
                                        <b style="font-size:15px;color:<?= $s['score'] >= 75 ? '#059669' : ($s['score'] >= 50 ? '#d97706' : '#dc2626') ?>;">
                                            <?= number_format((float)$s['score'], 1) ?>
                                        </b>
                                    </td>
                                    <td class="g-table-center">
                                        <a href="grading.php?exam_id=<?= $examId ?>&session_id=<?= (int)$s['id'] ?>"
                                           class="g-btn <?= $pending > 0 ? 'btn-primary' : 'g-btn-outline' ?>"
                                           style="font-size:12px;padding:7px 14px;border-radius:8px;font-weight:700;<?= $pending > 0 ? 'background:#6366f1;color:#fff;border-color:#6366f1;' : '' ?>">
                                            <?= $pending > 0 ? 'Koreksi (' . $pending . ')' : 'Lihat / Ubah' ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div><!-- /.g-page -->

<?php require_once __DIR__ . '/footer.php'; ?>