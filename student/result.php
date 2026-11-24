<?php
/**
 * Student Result Page — Hasil Ujian
 */
$etag = hash('md5', filemtime(__FILE__) . ':' . time());
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Hasil Ujian';
$pageKey   = 'result';
require_once __DIR__ . '/header.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) { Helper::redirect('dashboard.php'); exit; }

$session = Database::fetch(
    "SELECT es.*, e.title as exam_title, s.name as subject_name, qb.title as bank_title
     FROM exam_sessions es
     JOIN exams e ON es.exam_id = e.id
     JOIN question_banks qb ON e.bank_id = qb.id
     JOIN subjects s ON qb.subject_id = s.id
     WHERE es.id = ? AND es.student_id = ? AND es.status = 'completed'",
    [$sessionId, (int)$student['id']]
);

if (!$session) { Helper::redirect('dashboard.php'); exit; }

// Fetch answers with questions (tanpa kolom q.options — pakai option_a..e)
$answers = Database::fetchAll(
    "SELECT ea.*, q.question_text, q.question_type, q.option_a, q.option_b, q.option_c,
            q.option_d, q.option_e, q.correct_answer, q.points
     FROM exam_answers ea
     JOIN questions q ON ea.question_id = q.id
     WHERE ea.session_id = ? ORDER BY ea.id ASC",
    [$sessionId]
);
$totalQuestions = count($answers);
$correctCount = 0;
foreach ($answers as $a) {
    if (($a['is_correct'] ?? null) == 1) $correctCount++;
}
$score = $session['score'] ?? 0;
$percentage = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100, 1) : 0;
?>

<style>
.res-hero { background:linear-gradient(135deg,#059669,#10b981);border-radius:20px;padding:28px;color:#fff;text-align:center;margin-bottom:20px;position:relative;overflow:hidden; }
.res-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:rgba(255,255,255,.1);border-radius:50%;}
.res-score { font-size:52px;font-weight:900;line-height:1;text-shadow:0 2px 16px rgba(0,0,0,.2); }
.res-label { font-size:13px;opacity:.85;margin-top:4px; }
.res-details { display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px; }
.res-stat { background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;text-align:center; }
.res-stat-val { font-size:22px;font-weight:900;color:var(--text); }
.res-stat-lbl { font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:2px; }
.res-q-card { background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:10px; }
.res-q-num { font-size:11px;font-weight:800;color:var(--primary);text-transform:uppercase;margin-bottom:6px; }
.res-q-text { font-size:14px;font-weight:600;color:var(--text);margin-bottom:10px;line-height:1.5; }
.res-opt { display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;margin-bottom:4px;font-size:13px; }
.res-opt.correct { background:#ecfdf5;border:1px solid #a7f3d0;color:#059669; }
.res-opt.wrong { background:#fef2f2;border:1px solid #fecaca;color:#dc2626; }
.res-opt.skipped { background:var(--bg);border:1px solid var(--border);color:var(--muted); }
.res-opt-letter { font-weight:800;width:20px;flex-shrink:0; }
.btn-back { display:inline-block;padding:11px 24px;background:#0284c7;color:#fff;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none; }
@media(max-width:640px){ .res-details{grid-template-columns:1fr;} }
</style>

<!-- HERO -->
<div class="res-hero">
    <div style="font-size:14px;opacity:.8;margin-bottom:4px;">Nilai Kamu</div>
    <div class="res-score"><?= number_format($score, 1) ?></div>
    <div class="res-label"><?= htmlspecialchars($session['exam_title']) ?></div>
</div>

<!-- STATS -->
<div class="res-details">
    <div class="res-stat"><div class="res-stat-val"><?= $correctCount ?></div><div class="res-stat-lbl">Benar</div></div>
    <div class="res-stat"><div class="res-stat-val" style="color:#dc2626;"><?= $totalQuestions - $correctCount ?></div><div class="res-stat-lbl">Salah</div></div>
    <div class="res-stat"><div class="res-stat-val" style="color:#d97706;"><?= $percentage ?>%</div><div class="res-stat-lbl">Akurasi</div></div>
</div>

<!-- QUESTION REVIEW -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
    <div style="font-size:15px;font-weight:800;color:var(--text);">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Pembahasan</div>
    <a href="history.php?tab=ujian" class="btn-back">← Kembali</a>
</div>

<?php foreach ($answers as $qIdx => $a):
    $correctLetter = strtoupper($a['correct_answer'] ?? '');
    $userLetter = $a['student_answer'] ?? null;
    $isCorrect = (($a['is_correct'] ?? null) == 1);
    $isEssay = ($a['question_type'] ?? 'pg') === 'essay';
    $letters = ['A','B','C','D','E','F'];
    $optionList = [];
    foreach ($letters as $l) {
        $val = $a['option_' . strtolower($l)] ?? null;
        if ($val !== null && trim($val) !== '') $optionList[] = $val;
    }
?>
    <div class="res-q-card">
        <div class="res-q-num">Soal <?= $qIdx + 1 ?> <?php if ($isCorrect): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="3" style="vertical-align:-2px;margin-left:6px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?php elseif (!$isEssay && $a['is_correct'] !== null): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="3" style="vertical-align:-2px;margin-left:6px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?php endif; ?></div>
        <div class="res-q-text"><?= nl2br(htmlspecialchars($a['question_text'])) ?></div>
        <?php if ($isEssay): ?>
            <div class="res-opt <?= $isCorrect ? 'correct' : (($a['essay_score'] ?? null) !== null ? 'wrong' : 'skipped') ?>">
                <span style="font-weight:700;flex-shrink:0;">Jawaban Anda:</span>
                <span><?= ($a['answer_text'] ?? '') !== '' ? nl2br(htmlspecialchars($a['answer_text'])) : '<em style="color:var(--muted);">Tidak dijawab</em>' ?></span>
            </div>
            <div class="res-opt skipped" style="margin-top:6px;">
                <span style="font-weight:700;">
                    <?= ($a['essay_score'] ?? null) !== null
                        ? 'Nilai: ' . htmlspecialchars((string)(float)$a['essay_score']) . ' / ' . (int)$a['points']
                        : 'Belum dinilai oleh guru' ?>
                </span>
            </div>
        <?php else: ?>
        <?php foreach ($optionList as $idx => $opt):
            $ltr = $letters[$idx] ?? chr(65+$idx);
            $cls = '';
            if ($ltr === $correctLetter) $cls = 'correct';
            elseif ($ltr === $userLetter && !$isCorrect) $cls = 'wrong';
        ?>
            <div class="res-opt <?= $cls ?>">
                <span class="res-opt-letter"><?= $ltr ?></span>
                <span><?= htmlspecialchars($opt) ?></span>
                <?php if ($cls === 'correct'): ?><span style="margin-left:auto;font-size="11px;font-weight:700;color:#059669;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Benar</span><?php elseif ($cls === 'wrong' && $userLetter === $ltr): ?><span style="margin-left:auto;font-size:11px;font-weight:700;color:#dc2626;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Salah</span><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
