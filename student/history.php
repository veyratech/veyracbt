<?php
/**
 * Student History Page — Riwayat Ujian & Latihan
 */
$etag = hash('md5', filemtime(__FILE__) . ':' . time());
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Riwayat Ujian';
$pageKey   = 'history';
require_once __DIR__ . '/header.php';

$tab = ($_GET['tab'] ?? 'ujian') === 'latihan' ? 'latihan' : 'ujian';
$search = trim($_GET['search'] ?? '');

// ── EXAM HISTORY ──
$history = [];
if ($tab === 'ujian') {
    $history = Database::fetchAll(
        "SELECT es.*, e.title as exam_title, s.name as subject_name, qb.title as bank_title
         FROM exam_sessions es
         JOIN exams e ON es.exam_id = e.id
         JOIN question_banks qb ON e.bank_id = qb.id
         JOIN subjects s ON qb.subject_id = s.id
         WHERE es.student_id = ? AND es.tenant_id = ?
         ORDER BY es.start_time DESC LIMIT 50",
        [(int)$student['id'], $tenantId]
    );
}

// ── PRACTICE HISTORY ──
$practiceList = [];
if ($tab === 'latihan') {
    $countSql = "SELECT COUNT(*) FROM practice_sessions ps
                 JOIN question_banks qb ON ps.bank_id = qb.id
                 JOIN subjects s ON qb.subject_id = s.id
                 WHERE ps.student_id = ? AND ps.tenant_id = ?";
    $params = [(int)$student['id'], $tenantId];
    if ($search !== '') {
        $countSql .= " AND (qb.title LIKE ? OR s.name LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $totalPractice = (int)Database::fetchColumn($countSql, $params);
    
    $practiceSql = "SELECT ps.*, qb.title as bank_title, s.name as subject_name
                    FROM practice_sessions ps
                    JOIN question_banks qb ON ps.bank_id = qb.id
                    JOIN subjects s ON qb.subject_id = s.id
                    WHERE ps.student_id = ? AND ps.tenant_id = ?";
    if ($search !== '') {
        $practiceSql .= " AND (qb.title LIKE ? OR s.name LIKE ?)";
        $params = array_merge($params, ['%' . $search . '%', '%' . $search . '%']);
    }
    $practiceSql .= " ORDER BY ps.created_at DESC LIMIT 20";
    $practiceList = Database::fetchAll($practiceSql, $params);
}
?>

<style>
.tab-nav { display:flex;gap:8px;margin-bottom:20px; }
.tab-btn { padding:8px 18px;border-radius:10px;font-size:13px;font-weight:700;border:1.5px solid var(--border);background:var(--card);color:var(--muted);cursor:pointer;transition:all .15s;text-decoration:none; }
.tab-btn.active { background:#0284c7;color:#fff;border-color:#0284c7; }
.hist-card { background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px; }
.hist-info { flex:1;min-width:0; }
.hist-title { font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.hist-meta { font-size:11px;color:var(--muted);margin-top:2px; }
.hist-score { text-align:right;flex-shrink:0; }
.hist-score-val { font-size:20px;font-weight:900;color:#059669;line-height:1; }
.hist-score-lbl { font-size:10px;color:var(--muted);font-weight:600; }
.hist-inprog { font-size:12px;font-weight:700;color:#d97706; }
.empty-msg { background:var(--card);border:1.5px dashed var(--border);border-radius:14px;padding:28px;text-align:center;color:var(--muted);font-size:13px; }
.search-box { width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;background:var(--card);margin-bottom:16px;outline:none; }
.search-box:focus { border-color:#0284c7; }
</style>

<div class="tab-nav">
    <a href="?tab=ujian<?= $search ? '&search='.urlencode($search) : '' ?>" class="tab-btn <?= $tab === 'ujian' ? 'active' : '' ?>">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:5px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Ujian</a>
    <a href="?tab=latihan<?= $search ? '&search='.urlencode($search) : '' ?>" class="tab-btn <?= $tab === 'latihan' ? 'active' : '' ?>">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:5px;"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Latihan</a>
</div>

<?php if ($tab === 'ujian'): ?>
    <?php if (empty($history)): ?>
        <div class="empty-msg">Belum ada riwayat ujian. Mulai ujian pertamamu!</div>
    <?php else: foreach ($history as $h): ?>
        <div class="hist-card">
            <div class="hist-info">
                <div class="hist-title"><?= htmlspecialchars($h['exam_title']) ?></div>
                <div class="hist-meta"><?= htmlspecialchars($h['subject_name']) ?> · <?= date('d M Y, H:i', strtotime($h['start_time'])) ?></div>
            </div>
            <div class="hist-score">
                <?php if ($h['status'] === 'completed'): ?>
                    <div class="hist-score-val"><?= number_format($h['score'], 1) ?></div>
                    <div class="hist-score-lbl">NILAI</div>
                <?php elseif ($h['status'] === 'in_progress'): ?>
                    <div class="hist-inprog">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Berjalan</div>
                <?php else: ?>
                    <div style="font-size:12px;color:var(--muted);text-transform:uppercase;font-weight:700;"><?= htmlspecialchars($h['status']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
<?php else: ?>
    <input type="text" class="search-box" placeholder="Cari latihan..." id="searchInput" value="<?= htmlspecialchars($search) ?>">
    <script>
        document.getElementById('searchInput').addEventListener('input', function(){
            var q=this.value;location.href='?tab=latihan&search='+encodeURIComponent(q);
        });
    </script>
    <?php if (empty($practiceList)): ?>
        <div class="empty-msg">Belum ada riwayat latihan.</div>
    <?php else: foreach ($practiceList as $p): ?>
        <div class="hist-card">
            <div class="hist-info">
                <div class="hist-title"><?= htmlspecialchars($p['bank_title']) ?></div>
                <div class="hist-meta"><?= htmlspecialchars($p['subject_name']) ?> · <?= date('d M Y', strtotime($p['created_at'])) ?></div>
            </div>
            <div class="hist-score">
                <div style="font-size:13px;font-weight:700;color:var(--text);"><?= (int)$p['total_questions'] ?> Soal</div>
            </div>
        </div>
    <?php endforeach; endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
