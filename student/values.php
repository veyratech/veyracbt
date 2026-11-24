<?php
/**
 * Student Values Page — Nilai Semua Mapel
 */
$etag = hash('md5', filemtime(__FILE__) . ':' . time());
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Nilai Semua Mapel';
$pageKey   = 'values';
require_once __DIR__ . '/header.php';

// Fetch completed exam sessions grouped by subject
$sessions = Database::fetchAll(
    "SELECT es.*, e.title as exam_title, s.name as subject_name, s.code as subject_code,
            qb.title as bank_title
     FROM exam_sessions es
     JOIN exams e ON es.exam_id = e.id
     JOIN question_banks qb ON e.bank_id = qb.id
     JOIN subjects s ON qb.subject_id = s.id
     WHERE es.student_id = ? AND es.tenant_id = ? AND es.status = 'completed'
     ORDER BY s.name ASC, es.finish_time DESC",
    [(int)$student['id'], $tenantId]
);

// Group by subject
$bySubject = [];
foreach ($sessions as $sess) {
    $subj = $sess['subject_name'] ?? 'Umum';
    if (!isset($bySubject[$subj])) {
        $bySubject[$subj] = [
            'code'      => $sess['subject_code'] ?? '',
            'sessions'  => [],
            'totalScore' => 0,
            'count'      => 0,
        ];
    }
    $bySubject[$subj]['sessions'][] = $sess;
    $bySubject[$subj]['totalScore'] += (float)($sess['score'] ?? 0);
    $bySubject[$subj]['count']++;
}

// Compute averages
foreach ($bySubject as &$subj) {
    $subj['avg'] = $subj['count'] > 0 ? round($subj['totalScore'] / $subj['count'], 1) : 0;
}
unset($subj);
?>

<style>
.val-section { margin-bottom: 24px; }
.val-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:12px; }
.val-title { font-size:15px;font-weight:800;color:var(--text); }
.val-subj-code { font-size:11px;color:var(--muted);font-weight:600;background:var(--bg);padding:3px 8px;border-radius:6px; }
.val-card { background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:8px; }
.val-card-top { display:flex;align-items:center;justify-content:space-between;margin-bottom:6px; }
.val-exam-name { font-size:14px;font-weight:700;color:var(--text); }
.val-date { font-size:11px;color:var(--muted); }
.val-score-row { display:flex;align-items:center;gap:12px; }
.val-badge { background:#ecfdf5;border:1px solid #a7f3d0;color:#059669;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:800; }
html[data-theme="dark"] .val-badge { background:rgba(16,185,129,.15);color:#6ee7b7;border-color:rgba(16,185,129,.3); }
.val-link { font-size:12px;color:var(--primary);text-decoration:none;font-weight:600; }
.val-empty { background:var(--card);border:1.5px dashed var(--border);border-radius:14px;padding:28px;text-align:center;color:var(--muted);font-size:13px; }
</style>

<div class="val-section">
    <div class="val-header">
        <div class="val-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:6px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Nilai per Mata Pelajaran</div>
    </div>
    
    <?php if (empty($bySubject)): ?>
        <div class="val-empty">Belum ada nilai yang tercatat. Selesaikan ujian untuk melihat hasil.</div>
    <?php else: foreach ($bySubject as $subjName => $subj): ?>
        <div style="margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <span style="font-size:14px;font-weight:800;color:var(--text);"><?= htmlspecialchars($subjName) ?></span>
                <?php if ($subj['code']): ?><span class="val-subj-code"><?= htmlspecialchars($subj['code']) ?></span><?php endif; ?>
                <span style="margin-left:auto;font-size:13px;font-weight:800;color:#059669;"><?= number_format($subj['avg'], 1) ?></span>
            </div>
            <?php foreach ($subj['sessions'] as $s): ?>
                <div class="val-card">
                    <div class="val-card-top">
                        <span class="val-exam-name"><?= htmlspecialchars($s['exam_title']) ?></span>
                        <span class="val-date"><?= date('d M Y, H:i', strtotime($s['finish_time'])) ?></span>
                    </div>
                    <div class="val-score-row">
                        <span class="val-badge">Nilai: <?= number_format($s['score'], 1) ?></span>
                        <a href="result.php?session_id=<?= (int)$s['id'] ?>" class="val-link">Lihat Pembahasan →</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
