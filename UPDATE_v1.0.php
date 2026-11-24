<?php
/**
 * UPDATE PATCH v1.0 — CBT Online
 * 
 * Cara pakai: upload file ini ke root website yang di-hosting, 
 * akses via browser satu kali, lalu HAPUS file ini.
 */

$root = __DIR__;
$errors = [];
$notes  = [];

// Helper: cari & ganti string dalam file
function doReplace(string $path, string $old, string $new): void {
    global $root, $errors, $notes;
    $fullPath = $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    if (!file_exists($fullPath)) {
        $errors[] = "TIDAK DITEMUKAN: {$path}";
        return;
    }
    $content = file_get_contents($fullPath);
    if ($content === false) {
        $errors[] = "GAGAL BACA: {$path}";
        return;
    }
    if (strpos($content, $old) === false) {
        $notes[] = "SKIP -> {$path} (pattern tidak ditemukan / sudah ter-patch)";
        return;
    }
    $updated = str_replace($old, $new, $content);
    if (!file_put_contents($fullPath, $updated, LOCK_EX)) {
        $errors[] = "GAGAL TULIS: {$path}";
        return;
    }
    $notes[] = "OK -> {$path}";
}

// ============================================================
// PATCH 1a: admin/monitor.php — Ganti jsesc($p['student_name'])
// ============================================================
$monPath = $root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'monitor.php';
if (file_exists($monPath)) {
    $mc = file_get_contents($monPath);
    if ($mc !== false) {
        // Cari dan ganti semua/jsesc($p['student_name']) dengan escaping yang benar
        // Kita gunakan preg_replace karena polanya variatif (spasi/tab berbeda-beda)
        $jsescCount = 0;
        $newMc = preg_replace_callback(
            '/\?=\s*jsesc\s*\(\s*\$p\s*\[\s*[\'"]student_name[\'"]\s*\]\s*\)/',
            function() use (&$jsescCount) {
                $jsescCount++;
                return "<?php echo htmlspecialchars(json_encode(\$p['student_name'], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>";
            },
            $mc
        );
        if ($jsescCount > 0) {
            if (file_put_contents($monPath, $newMc, LOCK_EX)) {
                $notes[] = "OK -> admin/monitor.php ({$jsescCount}x jsesc diperbaiki)";
            } else {
                $errors[] = "GAGAL TULIS -> admin/monitor.php";
            }
            $mc = $newMc;
        } else {
            $notes[] = "SKIP -> admin/monitor.php (jsesc sudah tidak ada)";
        }

        // PATCH 1b: window.openBroadcastModal — gunakan strpos agar lebih handal
        if (strpos($mc, 'window.openBroadcastModal') === false) {
            // Cari baris "}" penutup fungsi openBroadcastModal, sisipkan tepat setelahnya
            $searchMarker = "    document.getElementById('modalBroadcast').classList.add('show');\n}";
            $replaceMarker = "    document.getElementById('modalBroadcast').classList.add('show');\n}\nwindow.openBroadcastModal = openBroadcastModal;";
            $newMc2 = str_replace($searchMarker, $replaceMarker, $mc);
            if ($newMc2 !== $mc) {
                if (file_put_contents($monPath, $newMc2, LOCK_EX)) {
                    $notes[] = "OK -> admin/monitor.php (window.openBroadcastModal ditambahkan)";
                } else {
                    $errors[] = "GAGAL TULIS -> admin/monitor.php (window)";
                }
            } else {
                // Fallback: cari baris } terakhir sebelum (function () {
                $fallbackSearch = "}\n\n(function ()";
                $fallbackReplace = "}\nwindow.openBroadcastModal = openBroadcastModal;\n\n(function ()";
                $newMc3 = str_replace($fallbackSearch, $fallbackReplace, $mc);
                if ($newMc3 !== $mc) {
                    if (file_put_contents($monPath, $newMc3, LOCK_EX)) {
                        $notes[] = "OK -> admin/monitor.php (window.openBroadcastModal via fallback)";
                    } else {
                        $errors[] = "GAGAL TULIS -> admin/monitor.php (window fallback)";
                    }
                } else {
                    $notes[] = "SKIP -> admin/monitor.php (pattern window.openBroadcastModal tidak ditemukan di file hosting)";
                }
            }
        } else {
            $notes[] = "SKIP -> admin/monitor.php (window.openBroadcastModal sudah ada)";
        }
    }
} else {
    $errors[] = "FILE TIDAK DITEMUKAN: admin/monitor.php";
}

// ============================================================
// PATCH 2: student/exam.php — Sembunyikan widget webcam
// ============================================================
$examPath = $root . DIRECTORY_SEPARATOR . 'student' . DIRECTORY_SEPARATOR . 'exam.php';
if (file_exists($examPath)) {
    $exc = file_get_contents($examPath);
    if ($exc !== false) {
        if (strpos($exc, 'webcam-box" style="margin-top: 16px; display: none;"') !== false) {
            $notes[] = "SKIP -> student/exam.php (webcam widget sudah disembunyikan)";
        } elseif (strpos($exc, 'class="webcam-box"') !== false) {
            // Gunakan regex agar toleran terhadap perbedaan spasi/formatting
            $newExc = preg_replace(
                '/class="webcam-box"[^>]*style="[^"]*margin-top:[^;]*"[^>]*/',
                'class="webcam-box" style="margin-top: 16px; display: none;"',
                $exc,
                1
            );
            if ($newExc !== $exc) {
                if (file_put_contents($examPath, $newExc, LOCK_EX)) {
                    $notes[] = "OK -> student/exam.php (webcam widget disembunyikan)";
                } else {
                    $errors[] = "GAGAL TULIS -> student/exam.php";
                }
            } else {
                $notes[] = "SKIP -> student/exam.php (pattern webcam box tidak cocok)";
            }
        } else {
            $notes[] = "SKIP -> student/exam.php (pattern webcam box tidak dikenali)";
        }
    }
} else {
    $errors[] = "FILE TIDAK DITEMUKAN: student/exam.php";
}

// ============================================================
// PATCH 3: assets/js/exam-engine.js — Hide video element
// ============================================================
$enginePath = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'exam-engine.js';
if (file_exists($enginePath)) {
    $engc = file_get_contents($enginePath);
    if ($engc !== false) {
        if (strpos($engc, "video.style.display = 'none'") !== false) {
            $notes[] = "SKIP -> assets/js/exam-engine.js (video sudah di-hide)";
        } else {
            // Regex toleran whitespace
            $newEng = preg_replace(
                '/(video\.srcObject\s*=\s*this\.webcamStream;\s*\n)\s*(video\.onloadedmetadata)/',
                "$1            video.style.display = 'none';\n            $2",
                $engc,
                1,
                $cnt
            );
            if ($cnt > 0 && file_put_contents($enginePath, $newEng, LOCK_EX)) {
                $notes[] = "OK -> assets/js/exam-engine.js (video di-hide setelah stream terhubung)";
            } else {
                $notes[] = "SKIP -> assets/js/exam-engine.js (pattern tidak cocok)";
            }
        }
    }
} else {
    $errors[] = "FILE TIDAK DITEMUKAN: assets/js/exam-engine.js";
}

// ============================================================
// PATCH 4: database/migrate-indexes.php — Fix logika index check
// ============================================================
$migPath = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrate-indexes.php';
if (file_exists($migPath)) {
    $mgc = file_get_contents($migPath);
    if ($mgc !== false) {
        // Gunakan pendekatan baris-per-baris untuk menghindari masalah escaping regex multi-line
        $lines = explode("\n", $mgc);
        $newLines = [];
        $replaced = false;
        $skipNext = 0;
        foreach ($lines as $i => $line) {
            if ($skipNext > 0) {
                $skipNext--;
                continue;
            }
            if (!$replaced && strpos($line, "explode(' ', explode('ADD INDEX'") !== false) {
                // Ganti baris rusak ini + baris berikutnya dengan kode baru
                array_push($newLines,
                    '        preg_match(\'/ALTER TABLE\\s+(\\w+)\\s+ADD INDEX\\s+(\\w+)/i\', $m[\'sql\'], $matches);',
                    '        if (!$matches) continue;',
                    '        $idxTable = $matches[1];',
                    '        $idxName  = $matches[2];',
                    '        $check = $pdo->query("SHOW INDEX FROM `{$idxTable}` WHERE Key_name = \'{\\$idxName}\'")->fetch();'
                );
                // Lewati baris berikutnya yang juga rusak (pattern kedua)
                if (isset($lines[$i + 1]) && strpos($lines[$i + 1], "explode('('") !== false) {
                    $skipNext = 1;
                }
                $replaced = true;
            } else {
                $newLines[] = $line;
            }
        }
        if ($replaced) {
            $newMig = implode("\n", $newLines);
            if (file_put_contents($migPath, $newMig, LOCK_EX)) {
                $notes[] = "OK -> database/migrate-indexes.php (logika index check diperbaiki)";
            } else {
                $errors[] = "GAGAL TULIS -> database/migrate-indexes.php";
            }
        } else {
            $notes[] = "SKIP -> database/migrate-indexes.php (tidak ada pola rusak terdeteksi)";
        }
    }
} else {
    $errors[] = "FILE TIDAK DITEMUKAN: database/migrate-indexes.php";
}

// ============================================================
// GENERATE: File SQL untuk dijalankan manual via phpMyAdmin
// ============================================================
$sqlContent = "-- =============================================
-- CBT Online Update Patch v1.0 -- SQL Section
-- Jalankan melalui phpMyAdmin / MySQL CLI
-- =============================================

CREATE TABLE IF NOT EXISTS `exam_broadcasts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NOT NULL,
  `exam_id` INT NOT NULL,
  `session_id` INT NULL DEFAULT NULL COMMENT 'NULL = siaran ke seluruh peserta',
  `message` TEXT NOT NULL,
  `sender_name` VARCHAR(100) NOT NULL DEFAULT 'Pengawas Ruang',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_broadcast_tenant_exam` (`tenant_id`, `exam_id`),
  INDEX `idx_broadcast_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_tenant_status (tenant_id, status);
ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_exam        (exam_id);
ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_student     (student_id);
ALTER TABLE exam_sessions ADD INDEX IF NOT EXISTS idx_es_last_activity (last_activity);
ALTER TABLE exam_answers  ADD INDEX IF NOT EXISTS idx_ea_session (session_id);
ALTER TABLE exam_answers  ADD INDEX IF NOT EXISTS idx_ea_question (question_id);
ALTER TABLE students      ADD INDEX IF NOT EXISTS idx_st_tenant_class (tenant_id, class_id);
ALTER TABLE students      ADD INDEX IF NOT EXISTS idx_st_nis (tenant_id, nis);
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_al_tenant_time (tenant_id, created_at);
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_al_event (event_type);
ALTER TABLE questions     ADD INDEX IF NOT EXISTS idx_q_bank (bank_id, tenant_id);
ALTER TABLE exams         ADD INDEX IF NOT EXISTS idx_ex_tenant_active (tenant_id, is_active);
ALTER TABLE exams         ADD INDEX IF NOT EXISTS idx_ex_time (start_time, end_time);
ALTER TABLE users         ADD INDEX IF NOT EXISTS idx_u_tenant_user (tenant_id, username);
";

$sqlPath = $root . DIRECTORY_SEPARATOR . 'UPDATE_SQL_v1.0.sql';
if (file_put_contents($sqlPath, $sqlContent, LOCK_EX) !== false) {
    $notes[] = "GENERATED -> UPDATE_SQL_v1.0.sql";
} else {
    $errors[] = "GAGAL TULIS -> UPDATE_SQL_v1.0.sql";
}

// ============================================================
// OUTPUT HTML HASIL
// ============================================================
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>CBT Online — Update Patch v1.0</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:32px;}
h1{color:#34d399;border-bottom:2px solid #1e293b;padding-bottom:12px;}
.ok{color:#10b981;padding:6px 0;border-bottom:1px solid #1e293b;}
.err{color:#ef4444;padding:6px 0;border-bottom:1px solid #1e293b;}
.skip{color:#f59e0b;padding:6px 0;border-bottom:1px solid #1e293b;}
.footer{margin-top:24px;padding:16px;background:#1e293b;border-radius:8px;font-size:13px;color:#94a3b8;}
</style>
</head>
<body>
<h1>&#x1F504; CBT Online Update Patch v1.0</h1>
<p>Root: <code><?= htmlspecialchars($root) ?></code></p>
<h2>Hasil Penerapan</h2>
<?php foreach ($notes as $n): ?>
  <div class="<?= strpos($n,'OK')===0 ? 'ok' : (strpos($n,'SKIP')===0 ? 'skip' : 'ok') ?>">
    &#x25B6; <?= htmlspecialchars($n) ?>
  </div>
<?php endforeach; ?>
<?php if (!empty($errors)): ?>
<h2>Error</h2>
<?php foreach ($errors as $e): ?>
  <div class="err">&#x2717; <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php endif; ?>
<div class="footer">
  <strong>PENTING:</strong> Setelah selesai, <u>HAPUS file ini</u> dari server.<br>
  File SQL tambahan tersedia di: <code><?= htmlspecialchars(basename($sqlPath)) ?></code><br>
  Buka file tersebut di phpMyAdmin jika diperlukan.
</div>
<?php if (empty($errors)): ?>
<p style="color:#34d399;font-size:16px;margin-top:16px;">&#x2705; Semua patch berhasil diterapkan.</p>
<?php else: ?>
<p style="color:#ef4444;font-size:16px;margin-top:16px;">&#x26A0; Terdapat error. Periksa list di atas.</p>
<?php endif; ?>
</body>
</html>
