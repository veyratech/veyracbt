<?php
/**
 * Migrasi Index & Struktur Database CBT Online
 * Jalankan sekali setelah deploy: php database/migrate-indexes.php
 */

require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getConnection();

$migrations = [
    // --- exam_sessions: kolom yang sering di-filter & join ---
    [
        'sql' => "ALTER TABLE exam_sessions ADD INDEX idx_es_tenant_status (tenant_id, status)",
        'label' => 'exam_sessions: tenant_id + status',
    ],
    [
        'sql' => "ALTER TABLE exam_sessions ADD INDEX idx_es_exam (exam_id)",
        'label' => 'exam_sessions: exam_id',
    ],
    [
        'sql' => "ALTER TABLE exam_sessions ADD INDEX idx_es_student (student_id)",
        'label' => 'exam_sessions: student_id',
    ],
    [
        'sql' => "ALTER TABLE exam_sessions ADD INDEX idx_es_last_activity (last_activity)",
        'label' => 'exam_sessions: last_activity (monitor polling)',
    ],

    // --- exam_answers: agregasi skor & pencarian per sesi ---
    [
        'sql' => "ALTER TABLE exam_answers ADD INDEX idx_ea_session (session_id)",
        'label' => 'exam_answers: session_id',
    ],
    [
        'sql' => "ALTER TABLE exam_answers ADD INDEX idx_ea_question (question_id)",
        'label' => 'exam_answers: question_id',
    ],

    // --- students: filter per kelas & tenant ---
    [
        'sql' => "ALTER TABLE students ADD INDEX idx_st_tenant_class (tenant_id, class_id)",
        'label' => 'students: tenant_id + class_id',
    ],
    [
        'sql' => "ALTER TABLE students ADD INDEX idx_st_nis (tenant_id, nis)",
        'label' => 'students: nis lookup',
    ],

    // --- activity_logs: audit trail filter tanggal ---
    [
        'sql' => "ALTER TABLE activity_logs ADD INDEX idx_al_tenant_time (tenant_id, created_at)",
        'label' => 'activity_logs: tenant_id + created_at',
    ],
    [
        'sql' => "ALTER TABLE activity_logs ADD INDEX idx_al_event (event_type)",
        'label' => 'activity_logs: event_type',
    ],

    // --- questions: filter per bank soal ---
    [
        'sql' => "ALTER TABLE questions ADD INDEX idx_q_bank (bank_id, tenant_id)",
        'label' => 'questions: bank_id + tenant_id',
    ],

    // --- exams: filter tenant & waktu ---
    [
        'sql' => "ALTER TABLE exams ADD INDEX idx_ex_tenant_active (tenant_id, is_active)",
        'label' => 'exams: tenant_id + is_active',
    ],
    [
        'sql' => "ALTER TABLE exams ADD INDEX idx_ex_time (start_time, end_time)",
        'label' => 'exams: waktu ujian',
    ],

    // --- users: login lookup ---
    [
        'sql' => "ALTER TABLE users ADD INDEX idx_u_tenant_user (tenant_id, username)",
        'label' => 'users: tenant + username',
    ],
];

$applied = 0;
$skipped = 0;
$errors = [];

foreach ($migrations as $m) {
    try {
        // Cek apakah index sudah ada — parse tabel & nama index dari SQL
        preg_match('/ALTER TABLE\s+(\w+)\s+ADD INDEX\s+(\w+)/i', $m['sql'], $matches);
        if (!$matches) continue;
        $idxTable = $matches[1];
        $idxName  = $matches[2];
        $check = $pdo->query("SHOW INDEX FROM `{$idxTable}` WHERE Key_name = '{$idxName}'")->fetch();
        if ($check) {
            $skipped++;
            continue;
        }
        $pdo->exec($m['sql']);
        echo "[OK]  {$m['label']}\n";
        $applied++;
    } catch (PDOException $e) {
        // Index mungkin sudah ada atau syntax issue
        if (strpos($e->getMessage(), 'Duplicate key') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            $skipped++;
        } else {
            echo "[ERR] {$m['label']}: " . $e->getMessage() . "\n";
            $errors[] = $m['label'];
        }
    }
}

// Tambahan: cek dan tambahkan kolom yang mungkin belum ada
$columnChecks = [
    'exam_sessions' => [
        'extra_time_minutes' => "ALTER TABLE exam_sessions ADD COLUMN extra_time_minutes INT NOT NULL DEFAULT 0 AFTER max_violations",
        'last_webcam_frame'  => "ALTER TABLE exam_sessions ADD COLUMN last_webcam_frame VARCHAR(500) NULL AFTER last_activity",
        'last_webcam_at'     => "ALTER TABLE exam_sessions ADD COLUMN last_webcam_at DATETIME NULL AFTER last_webcam_frame",
        'webcam_status'      => "ALTER TABLE exam_sessions ADD COLUMN webcam_status ENUM('active','denied','disabled') NULL AFTER last_webcam_at",
    ],
    'students' => [
        'photo' => "ALTER TABLE students ADD COLUMN photo VARCHAR(255) NULL AFTER gender",
    ],
];

// Special: CREATE TABLE IF NOT EXISTS (handled separately below)
$specialTables = [
    "CREATE TABLE IF NOT EXISTS `exam_broadcasts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tenant_id` INT NOT NULL,
        `exam_id` INT NOT NULL,
        `session_id` INT NULL COMMENT 'NULL = siaran ke seluruh peserta',
        `message` TEXT NOT NULL,
        `sender_name` VARCHAR(100) NOT NULL DEFAULT 'Pengawas Ruang',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_broadcast_tenant_exam` (`tenant_id`, `exam_id`),
        INDEX `idx_broadcast_session` (`session_id`),
        CONSTRAINT `fk_broadcast_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_broadcast_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB" => 'Tabel exam_broadcasts dibuat/dilewati (sudah ada)'
];

foreach ($columnChecks as $table => $cols) {
    foreach ($cols as $colName => $sql) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colName}'")->fetch();
            if ($existing) continue;
            $pdo->exec($sql);
            echo "[OK]  Kolom {$table}.{$colName} ditambahkan\n";
            $applied++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                // sudah ada
            } else {
                echo "[WARN] {$table}.{$colName}: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Handle special CREATE TABLE IF NOT EXISTS entries
foreach ($specialTables as $sql => $label) {
    try {
        $pdo->exec($sql);
        echo "[OK]  {$label}\n";
        $applied++;
    } catch (PDOException $e) {
        echo "[WARN] {$label}: " . $e->getMessage() . "\n";
    }
}

echo "\nSelesai. Diterapkan: {$applied}, Dilewati: {$skipped}";
if (!empty($errors)) {
    echo "\nError: " . implode(', ', $errors) . "\n";
    exit(1);
}
exit(0);
