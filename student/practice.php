<?php
/**
 * Practice Mode — Landing Page
 * Menampilkan daftar bank soal yang tersedia untuk latihan.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Latihan Soal';
$pageKey   = 'practice';
require_once __DIR__ . '/header.php';

// Fetch question banks for this tenant with question counts
$banks = Database::fetchAll(
    "SELECT qb.id, qb.title, s.name as subject_name,
            (SELECT COUNT(*) FROM questions q WHERE q.bank_id = qb.id AND q.tenant_id = ?) AS total_questions
     FROM question_banks qb
     JOIN subjects s ON qb.subject_id = s.id
     WHERE qb.tenant_id = ?
     ORDER BY s.name ASC, qb.title ASC",
    [$tenantId, $tenantId]
);

$emptyMsg = empty($banks)
    ? 'Belum ada bank soal yang tersedia. Hubungi guru pengampu.'
    : null;
?>

<style>
.practice-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 16px; }
.practice-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; transition: box-shadow .15s, transform .15s; }
.practice-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.08); transform: translateY(-2px); }
.practice-bar   { height: 4px; background: linear-gradient(90deg, #059669, #0ea5e9); }
.practice-body  { padding: 18px 16px 14px; }
.practice-subj  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #059669; margin-bottom: 4px; }
.practice-title { font-size: 15px; font-weight: 800; color: var(--text); line-height: 1.3; margin-bottom: 6px; }
.practice-meta  { font-size: 12px; color: var(--muted); margin-bottom: 14px; }
.practice-meta b{ color: var(--text); }
.practice-btn   { display: block; width: 100%; text-align: center; padding: 10px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; color: #fff; background: linear-gradient(135deg, #059669, #0d9488); border: none; cursor: pointer; transition: opacity .15s; }
.practice-btn:hover { opacity: .88; }
</style>

<div class="card" style="margin-bottom: 0;">
    <div class="card-header">
        <h3 class="card-title">&#128218; Pilih Bank Soal untuk Latihan</h3>
        <p style="font-size:12px;color:var(--muted);margin-top:4px;">
            Latihan tidak terikat waktu dan tidak mencatat pelanggaran. Jawaban PG langsung dikoreksi.
        </p>
    </div>
    <div class="card-body" style="padding: 20px 16px;">
        <?php if ($emptyMsg): ?>
            <div style="background:var(--bg);border:1.5px dashed var(--border);border-radius:12px;padding:24px;text-align:center;color:var(--muted);">
                <?= htmlspecialchars($emptyMsg) ?>
            </div>
        <?php else: ?>
            <div class="practice-grid">
            <?php foreach ($banks as $b): ?>
                <div class="practice-card">
                    <div class="practice-bar"></div>
                    <div class="practice-body">
                        <div class="practice-subj"><?= htmlspecialchars($b['subject_name'] ?? '-') ?></div>
                        <div class="practice-title"><?= htmlspecialchars($b['title']) ?></div>
                        <div class="practice-meta">
                            <b><?= (int)$b['total_questions'] ?></b> soal tersedia
                        </div>
                        <a href="practice-start.php?bank_id=<?= (int)$b['id'] ?>" class="practice-btn">
                            &#9654; Mulai Latihan
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
