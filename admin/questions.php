<?php
/**
 * Butir Soal per Bank Soal
 */

$pageTitle = "Kelola Butir Soal";
require_once __DIR__ . '/_bootstrap.php';

$bankId = (int)($_GET['bank_id'] ?? 0);

// Ambil data bank soal dan pastikan milik tenant aktif
$bank = Database::fetch(
    "SELECT qb.*, s.name as subject_name 
     FROM question_banks qb 
     JOIN subjects s ON qb.subject_id = s.id 
     WHERE qb.id = ? AND qb.tenant_id = ?",
    [$bankId, $tenantId]
);

if (!$bank) {
    Helper::setFlash('danger', 'Bank soal tidak ditemukan.');
    Helper::redirect('admin/question-banks.php');
}

// Auto-migration kolom question_audio, audio_play_limit, question_video jika belum ada
try {
    $hasAudioCol = Database::fetch("SHOW COLUMNS FROM questions LIKE 'question_audio'");
    if (!$hasAudioCol) {
        Database::query("ALTER TABLE questions 
            ADD COLUMN question_audio VARCHAR(255) NULL AFTER question_image,
            ADD COLUMN audio_play_limit INT NOT NULL DEFAULT 0 AFTER question_audio,
            ADD COLUMN question_video VARCHAR(500) NULL AFTER audio_play_limit");
    }
} catch (Throwable $dbErr) {
    // Abaikan jika sudah ada atau izin terbatas
}

/** Helper ekstraksi embed URL YouTube */
function getYouTubeEmbedUrl(?string $url): ?string {
    if (!$url) return null;
    $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';
    if (preg_match($pattern, $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    }
    return null;
}

/** Upload gambar butir soal; return path relatif atau null. */
function handleQuestionImage(int $tenantId, ?array $file): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // tidak ada upload baru
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gambar gagal (kode ' . $file['error'] . ').');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran gambar maksimal 2 MB.');
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Format gambar harus berupa file gambar valid (JPG, PNG, WEBP, atau GIF).');
    }
    $ext = $allowed[$mime];
    $name = 'q' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destDir = __DIR__ . '/../assets/uploads/questions';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $dest = $destDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal memindahkan file gambar ke server.');
    }
    Helper::optimizeQuestionImage($dest);
    return 'assets/uploads/questions/' . $name;
}

/** Upload audio listening butir soal; return path relatif atau null. */
function handleQuestionAudio(int $tenantId, ?array $file): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload audio gagal (kode ' . $file['error'] . ').');
    }
    if ($file['size'] > 15 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file audio maksimal 15 MB.');
    }
    $allowed = [
        'audio/mpeg'        => 'mp3',
        'audio/mp3'         => 'mp3',
        'audio/wav'         => 'wav',
        'audio/x-wav'       => 'wav',
        'audio/ogg'         => 'ogg',
        'audio/x-m4a'       => 'm4a',
        'audio/m4a'         => 'm4a',
        'audio/mp4'         => 'm4a',
        'audio/aac'         => 'aac',
        'audio/x-aac'       => 'aac',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $origExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $ext = $allowed[$mime] ?? (in_array($origExt, ['mp3', 'wav', 'ogg', 'm4a', 'aac'], true) ? $origExt : null);
    if (!$ext) {
        throw new RuntimeException('Format audio harus MP3, WAV, OGG, M4A, atau AAC.');
    }

    $name = 'qa' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destDir = __DIR__ . '/../assets/uploads/questions';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $dest = $destDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal memindahkan file audio ke server.');
    }
    return 'assets/uploads/questions/' . $name;
}

/** Upload video butir soal atau simpan URL YouTube / video eksternal. */
function handleQuestionVideo(int $tenantId, ?array $file, ?string $videoUrl): ?string {
    $videoUrl = trim((string)$videoUrl);

    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload video gagal (kode ' . $file['error'] . ').');
        }
        if ($file['size'] > 30 * 1024 * 1024) {
            throw new RuntimeException('Ukuran file video maksimal 30 MB. Untuk video ukuran besar disarankan menggunakan Link YouTube.');
        }
        $allowed = [
            'video/mp4'       => 'mp4',
            'video/webm'      => 'webm',
            'video/ogg'       => 'ogv',
            'video/quicktime' => 'mov',
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $origExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $ext = $allowed[$mime] ?? (in_array($origExt, ['mp4', 'webm', 'ogv', 'mov'], true) ? $origExt : null);
        if (!$ext) {
            throw new RuntimeException('Format file video harus MP4 atau WebM.');
        }

        $name = 'qv' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = __DIR__ . '/../assets/uploads/questions';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $dest = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Gagal memindahkan file video ke server.');
        }
        return 'assets/uploads/questions/' . $name;
    }

    if ($videoUrl !== '') {
        if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL Video tidak valid. Masukkan URL lengkap (contoh: https://www.youtube.com/watch?v=...)');
        }
        return $videoUrl;
    }

    return null;
}

function syncBankCount(int $bankId, ?int $tenantId = null): void {
    if ($tenantId !== null) {
        $actualCount = Database::fetchColumn("SELECT COUNT(*) FROM questions WHERE bank_id = ? AND tenant_id = ?", [$bankId, $tenantId]);
        Database::update('question_banks', ['total_questions' => (int)$actualCount], 'id = :id AND tenant_id = :tid', ['id' => $bankId, 'tid' => $tenantId]);
    } else {
        $actualCount = Database::fetchColumn("SELECT COUNT(*) FROM questions WHERE bank_id = ?", [$bankId]);
        Database::update('question_banks', ['total_questions' => (int)$actualCount], 'id = :id', ['id' => $bankId]);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Helper::verifyCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $qType = ($_POST['question_type'] ?? 'pg') === 'essay' ? 'essay' : 'pg';
            $qText = trim($_POST['question_text'] ?? '');
            $optA = trim($_POST['option_a'] ?? '');
            $optB = trim($_POST['option_b'] ?? '');
            $optC = trim($_POST['option_c'] ?? '');
            $optD = trim($_POST['option_d'] ?? '');
            $optE = trim($_POST['option_e'] ?? '');
            $correct = strtoupper(trim($_POST['correct_answer'] ?? 'A'));
            $points = max(1, (int)($_POST['points'] ?? 10));
            $explanation = trim($_POST['explanation'] ?? '');
            $imagePath = handleQuestionImage($tenantId, $_FILES['question_image'] ?? null);
            $audioPath = handleQuestionAudio($tenantId, $_FILES['question_audio'] ?? null);
            $audioLimit = max(0, (int)($_POST['audio_play_limit'] ?? 0));
            $videoSource = handleQuestionVideo($tenantId, $_FILES['question_video_file'] ?? null, $_POST['question_video_url'] ?? null);

            if ($qType === 'essay') {
                $valid = !empty($qText);
                $optA = $optB = $optC = $optD = $optE = '';
                $correct = '-';
            } else {
                $valid = !empty($qText) && !empty($optA) && !empty($optB) && in_array($correct, ['A', 'B', 'C', 'D', 'E'], true);
            }

            if ($valid) {
                Database::insert('questions', [
                    'tenant_id'        => $tenantId,
                    'bank_id'          => $bankId,
                    'question_type'    => $qType,
                    'question_text'    => $qText,
                    'question_image'   => $imagePath,
                    'question_audio'   => $audioPath,
                    'audio_play_limit' => $audioLimit,
                    'question_video'   => $videoSource,
                    'option_a'         => $optA,
                    'option_b'         => $optB,
                    'option_c'         => $optC,
                    'option_d'         => $optD,
                    'option_e'         => $optE,
                    'correct_answer'   => $correct,
                    'points'           => $points,
                    'explanation'      => $explanation !== '' ? $explanation : null
                ]);
                syncBankCount($bankId, $tenantId);
                Helper::setFlash('success', 'Butir soal baru berhasil ditambahkan' . ($qType === 'essay' ? ' (essay — dikoreksi manual).' : '.'));
            } else {
                Helper::setFlash('danger', $qType === 'essay'
                    ? 'Teks soal essay wajib diisi.'
                    : 'Teks soal, opsi A, opsi B, dan kunci jawaban (A–E) wajib diisi.');
            }
            Helper::redirect("admin/questions.php?bank_id={$bankId}");

        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $qType = ($_POST['question_type'] ?? 'pg') === 'essay' ? 'essay' : 'pg';
            $qText = trim($_POST['question_text'] ?? '');
            $optA = trim($_POST['option_a'] ?? '');
            $optB = trim($_POST['option_b'] ?? '');
            $optC = trim($_POST['option_c'] ?? '');
            $optD = trim($_POST['option_d'] ?? '');
            $optE = trim($_POST['option_e'] ?? '');
            $correct = strtoupper(trim($_POST['correct_answer'] ?? 'A'));
            $points = max(1, (int)($_POST['points'] ?? 10));
            $explanation = trim($_POST['explanation'] ?? '');
            $removeImage = isset($_POST['remove_image']);
            $removeAudio = isset($_POST['remove_audio']);
            $removeVideo = isset($_POST['remove_video']);

            $existing = Database::fetch(
                "SELECT * FROM questions WHERE id = ? AND tenant_id = ? AND bank_id = ?",
                [$id, $tenantId, $bankId]
            );
            if (!$existing) {
                Helper::setFlash('danger', 'Butir soal tidak ditemukan.');
                Helper::redirect("admin/questions.php?bank_id={$bankId}");
            }

            // Gambar
            $imagePath = $existing['question_image'] ?? null;
            if ($removeImage) {
                $imagePath = null;
            }
            $newImage = handleQuestionImage($tenantId, $_FILES['question_image'] ?? null);
            if ($newImage !== null) {
                $imagePath = $newImage;
            }

            // Audio & Play Limit
            $audioPath = $existing['question_audio'] ?? null;
            $audioLimit = max(0, (int)($_POST['audio_play_limit'] ?? 0));
            if ($removeAudio) {
                $audioPath = null;
            }
            $newAudio = handleQuestionAudio($tenantId, $_FILES['question_audio'] ?? null);
            if ($newAudio !== null) {
                $audioPath = $newAudio;
            }

            // Video
            $videoSource = $existing['question_video'] ?? null;
            if ($removeVideo) {
                $videoSource = null;
            }
            $newVideo = handleQuestionVideo($tenantId, $_FILES['question_video_file'] ?? null, $_POST['question_video_url'] ?? null);
            if ($newVideo !== null) {
                $videoSource = $newVideo;
            } elseif (isset($_POST['video_url_cleared']) && !isset($_POST['remove_video'])) {
                $videoSource = null;
            }

            if ($qType === 'essay') {
                $valid = !empty($qText);
                $optA = $optB = $optC = $optD = $optE = '';
                $correct = '-';
            } else {
                $valid = !empty($qText) && !empty($optA) && !empty($optB) && in_array($correct, ['A', 'B', 'C', 'D', 'E'], true);
            }

            if ($valid) {
                Database::update('questions', [
                    'question_type'    => $qType,
                    'question_text'    => $qText,
                    'question_image'   => $imagePath,
                    'question_audio'   => $audioPath,
                    'audio_play_limit' => $audioLimit,
                    'question_video'   => $videoSource,
                    'option_a'         => $optA,
                    'option_b'         => $optB,
                    'option_c'         => $optC,
                    'option_d'         => $optD,
                    'option_e'         => $optE,
                    'correct_answer'   => $correct,
                    'points'           => $points,
                    'explanation'      => $explanation !== '' ? $explanation : null
                ], 'id = :id AND tenant_id = :tenant_id AND bank_id = :bank_id', [
                    'id' => $id, 'tenant_id' => $tenantId, 'bank_id' => $bankId
                ]);
                Helper::setFlash('success', 'Butir soal berhasil diperbarui.');
            } else {
                Helper::setFlash('danger', $qType === 'essay'
                    ? 'Teks soal essay wajib diisi.'
                    : 'Teks soal, opsi A, opsi B, dan kunci jawaban (A–E) wajib diisi.');
            }
            Helper::redirect("admin/questions.php?bank_id={$bankId}");

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            Database::delete('questions', 'id = :id AND tenant_id = :tenant_id AND bank_id = :bank_id', [
                'id' => $id, 'tenant_id' => $tenantId, 'bank_id' => $bankId
            ]);
            syncBankCount($bankId, $tenantId);
            Helper::setFlash('success', 'Butir soal berhasil dihapus.');
            Helper::redirect("admin/questions.php?bank_id={$bankId}");

        } elseif ($action === 'import_csv' || $action === 'import_excel') {
            $uploadedFile = $_FILES['csv_file'] ?? ($_FILES['file_soal'] ?? null);
            if (!isset($uploadedFile['name']) || $uploadedFile['error'] !== UPLOAD_ERR_OK || $uploadedFile['size'] === 0) {
                throw new RuntimeException('Silakan pilih file Excel (.xlsx) atau CSV terlebih dahulu.');
            }
            [$header, $rows] = Helper::parseUpload($uploadedFile);

            // Alias normalization
            $aliases = [
                'nomor'          => 'nomor',
                'no'             => 'nomor',
                'jenis_soal'     => 'question_type',
                'tipe_soal'      => 'question_type',
                'tipe'           => 'question_type',
                'type'           => 'question_type',
                'question_type'  => 'question_type',
                'soal'           => 'question_text',
                'pertanyaan'     => 'question_text',
                'teks_soal'      => 'question_text',
                'question_text'  => 'question_text',
                'opsi_a'         => 'option_a',
                'pilihan_a'      => 'option_a',
                'jawaban_a'      => 'option_a',
                'a'              => 'option_a',
                'option_a'       => 'option_a',
                'opsi_b'         => 'option_b',
                'pilihan_b'      => 'option_b',
                'jawaban_b'      => 'option_b',
                'b'              => 'option_b',
                'option_b'       => 'option_b',
                'opsi_c'         => 'option_c',
                'pilihan_c'      => 'option_c',
                'jawaban_c'      => 'option_c',
                'c'              => 'option_c',
                'option_c'       => 'option_c',
                'opsi_d'         => 'option_d',
                'pilihan_d'      => 'option_d',
                'jawaban_d'      => 'option_d',
                'd'              => 'option_d',
                'option_d'       => 'option_d',
                'opsi_e'         => 'option_e',
                'pilihan_e'      => 'option_e',
                'jawaban_e'      => 'option_e',
                'e'              => 'option_e',
                'option_e'       => 'option_e',
                'kunci_jawaban'  => 'correct_answer',
                'kunci'          => 'correct_answer',
                'jawaban_benar'  => 'correct_answer',
                'jawaban'        => 'correct_answer',
                'correct'        => 'correct_answer',
                'correct_answer' => 'correct_answer',
                'bobot'          => 'points',
                'poin'           => 'points',
                'nilai'          => 'points',
                'points'         => 'points',
                'pembahasan'     => 'explanation',
                'penjelasan'     => 'explanation',
                'keterangan'     => 'explanation',
                'explanation'    => 'explanation'
            ];

            // Alias normalization (pre-collect map before applying to avoid index shift)
            $renameMap = [];
            foreach ($aliases as $orig => $canon) {
                $idx = array_search($orig, $header, true);
                if ($idx !== false && !in_array($canon, $header, true)) {
                    $renameMap[$idx] = [$orig, $canon];
                }
            }
            foreach ($renameMap as $idx => [$orig, $canon]) {
                $header[$idx] = $canon;
                foreach ($rows as &$r) {
                    if (!isset($r[$canon]) && isset($r[$orig])) {
                        $r[$canon] = $r[$orig];
                    }
                }
                unset($r);
            }

            if (!in_array('question_text', $header, true)) {
                throw new RuntimeException("Kolom pertanyaan/soal tidak ditemukan di file. Kolom terdeteksi: " . implode(', ', $header));
            }

            // Opsi replace/append
            $importMode = $_POST['import_mode'] ?? 'append';
            if ($importMode === 'replace') {
                Database::delete('questions', 'bank_id = :bid AND tenant_id = :tid', ['bid' => $bankId, 'tid' => $tenantId]);
            }

            $imported = 0; $skipped = 0;
            foreach ($rows as $row) {
                $qText = trim($row['question_text'] ?? '');
                if ($qText === '') { $skipped++; continue; }

                $rawType = strtolower(trim($row['question_type'] ?? 'pg'));
                $qType = (strpos($rawType, 'essay') !== false || strpos($rawType, 'uraian') !== false) ? 'essay' : 'pg';

                $optA = trim($row['option_a'] ?? '');
                $optB = trim($row['option_b'] ?? '');
                $optC = trim($row['option_c'] ?? '');
                $optD = trim($row['option_d'] ?? '');
                $optE = trim($row['option_e'] ?? '');
                $correct = strtoupper(trim($row['correct_answer'] ?? ''));

                if ($qType === 'essay') {
                    $optA = $optB = $optC = $optD = $optE = '';
                    $correct = '-';
                } else {
                    if ($optA === '' || $optB === '' || !in_array($correct, ['A', 'B', 'C', 'D', 'E'], true)) {
                        $skipped++;
                        continue;
                    }
                }

                $points = max(1, (int)($row['points'] ?? 10));
                $explanation = trim($row['explanation'] ?? '');

                Database::insert('questions', [
                    'tenant_id'      => $tenantId,
                    'bank_id'        => $bankId,
                    'question_type'  => $qType,
                    'question_text'  => $qText,
                    'question_image' => null,
                    'option_a'       => $optA,
                    'option_b'       => $optB,
                    'option_c'       => $optC,
                    'option_d'       => $optD,
                    'option_e'       => $optE,
                    'correct_answer' => $correct,
                    'points'         => $points,
                    'explanation'    => $explanation !== '' ? $explanation : null
                ]);
                $imported++;
            }
            syncBankCount($bankId, $tenantId);
            $msg = "Impor selesai: <b>{$imported} butir soal</b> berhasil ditambahkan ke bank soal ini";
            if ($skipped > 0) $msg .= " ({$skipped} baris dilewati karena format tidak lengkap)";
            Helper::setFlash('success', $msg . '.');
            Helper::redirect("admin/questions.php?bank_id={$bankId}");
        }
    } catch (Throwable $e) {
        Helper::setFlash('danger', 'Gagal: ' . $e->getMessage());
        Helper::redirect("admin/questions.php?bank_id={$bankId}");
    }
}

// Search + Pagination
$q = trim($_GET['q'] ?? '');
$countParams = ['bank_id' => $bankId, 'tenant_id' => $tenantId];
$countSql = "SELECT COUNT(*) FROM questions WHERE bank_id = :bank_id AND tenant_id = :tenant_id";
if ($q !== '') {
    $countSql .= " AND question_text LIKE :qt";
    $countParams['qt'] = '%' . $q . '%';
}
$totalQuestions = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalQuestions, 10);

$listParams = ['bank_id' => $bankId, 'tenant_id' => $tenantId];
$listSql = "SELECT * FROM questions WHERE bank_id = :bank_id AND tenant_id = :tenant_id";
if ($q !== '') {
    $listSql .= " AND question_text LIKE :qt";
    $listParams['qt'] = '%' . $q . '%';
}
$listSql .= " ORDER BY id ASC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$questions = Database::fetchAll($listSql, $listParams);

// Data untuk modal edit
$editQuestion = null;
if (isset($_GET['edit'])) {
    $editQuestion = Database::fetch(
        "SELECT * FROM questions WHERE id = ? AND tenant_id = ? AND bank_id = ?",
        [(int)$_GET['edit'], $tenantId, $bankId]
    );
}

$keepQuery = array_filter(['bank_id' => $bankId, 'q' => $q]);

// Unduh template Excel / CSV impor soal
if (isset($_GET['template'])) {
    $tplHeader = ['nomor', 'jenis_soal', 'soal', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban', 'bobot', 'pembahasan'];
    $tplRows = [
        [
            '1',
            'PG',
            'Berikut ini yang merupakan tag HTML untuk membuat judul tingkat pertama (heading utama) adalah...',
            '<head>',
            '<h1>',
            '<header>',
            '<title>',
            '<p>',
            'B',
            '10',
            'Tag <h1> digunakan untuk judul atau heading paling utama dalam hierarki dokumen HTML.'
        ],
        [
            '2',
            'PG',
            'Perintah CSS manakah yang digunakan untuk mengubah warna latar belakang sebuah elemen web?',
            'font-color',
            'color',
            'background-color',
            'bgcolor',
            'canvas-color',
            'C',
            '10',
            'background-color adalah properti resmi CSS untuk menentukan warna latar belakang elemen.'
        ],
        [
            '3',
            'PG',
            'Karakter atau simbol manakah yang wajib digunakan untuk mengawali penamaan variabel dalam bahasa PHP?',
            '&',
            '@',
            '#',
            '$',
            '%',
            'D',
            '10',
            'Di PHP, semua variabel selalu diawali dengan tanda dollar ($), contohnya $nama atau $nilai.'
        ],
        [
            '4',
            'PG',
            'Protokol jaringan yang digunakan untuk mengamankan pertukaran data web melalui enkripsi SSL/TLS adalah...',
            'FTP',
            'HTTP',
            'HTTPS',
            'SMTP',
            'DNS',
            'C',
            '10',
            'HTTPS (HTTP Secure) mengenkripsi seluruh komunikasi data antara browser klien dan server.'
        ],
        [
            '5',
            'Essay',
            'Jelaskan perbedaan mendasar antara basis data relasional (SQL) dan basis data non-relasional (NoSQL)!',
            '',
            '',
            '',
            '',
            '',
            '',
            '20',
            'SQL menggunakan skema tabel tetap bertipe relasi, sedangkan NoSQL fleksibel berbasis dokumen JSON, key-value, dsb.'
        ]
    ];
    if ($_GET['template'] === 'csv') {
        Helper::downloadCsv('template-bank-soal.csv', $tplHeader, $tplRows);
    } else {
        Helper::downloadXlsx('template-bank-soal.xlsx', $tplHeader, $tplRows);
    }
}

// Ekspor Butir Soal Bank Ini ke Excel / CSV
if (isset($_GET['export'])) {
    $allQ = Database::fetchAll(
        "SELECT * FROM questions WHERE bank_id = ? AND tenant_id = ? ORDER BY id ASC",
        [$bankId, $tenantId]
    );
    $expHeader = ['nomor', 'jenis_soal', 'soal', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban', 'bobot', 'pembahasan'];
    $expRows = [];
    foreach ($allQ as $i => $item) {
        $expRows[] = [
            (string)($i + 1),
            strtoupper($item['question_type'] ?? 'PG'),
            $item['question_text'] ?? '',
            $item['option_a'] ?? '',
            $item['option_b'] ?? '',
            $item['option_c'] ?? '',
            $item['option_d'] ?? '',
            $item['option_e'] ?? '',
            $item['correct_answer'] ?? '',
            (string)($item['points'] ?? 10),
            $item['explanation'] ?? ''
        ];
    }
    $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $bank['title']);
    if ($_GET['export'] === 'csv') {
        Helper::downloadCsv('soal-' . $safeTitle . '.csv', $expHeader, $expRows);
    } else {
        Helper::downloadXlsx('soal-' . $safeTitle . '.xlsx', $expHeader, $expRows);
    }
}

require_once __DIR__ . '/header.php';
?>

<div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div>
        <a href="question-banks.php" style="font-size: 13px; color: var(--text-muted); font-weight: 600;">&larr; Kembali ke Bank Soal</a>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin-top: 4px;">
            <?= htmlspecialchars($bank['title']) ?>
            <span style="font-size: 14px; font-weight: normal; color: var(--text-muted);">(<?= htmlspecialchars($bank['subject_name']) ?>)</span>
        </h2>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <!-- Unduh Template Excel -->
        <a href="questions.php?bank_id=<?= $bankId ?>&template=xlsx" class="btn btn-outline btn-sm" title="Unduh template format Excel (.xlsx)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Template Excel (.xlsx)
        </a>

        <!-- Impor Excel / CSV -->
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalImportQuestions').classList.add('show')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            Impor Excel / CSV
        </button>

        <!-- Ekspor Butir Soal -->
        <a href="questions.php?bank_id=<?= $bankId ?>&export=xlsx" class="btn btn-outline btn-sm" title="Ekspor butir soal ini ke Excel">
            Ekspor Soal (.xlsx)
        </a>

        <!-- Tambah Manual -->
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAddQuestion').classList.add('show')">
            + Tambah Butir Soal
        </button>
    </div>
</div>

<form method="GET" class="toolbar-row">
    <input type="hidden" name="bank_id" value="<?= $bankId ?>">
    <div class="search-inline">
        <input type="text" name="q" class="form-control" placeholder="Cari teks soal..." value="<?= htmlspecialchars($q) ?>">
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Cari</button>
    <?php if ($q !== ''): ?>
        <a href="questions.php?bank_id=<?= $bankId ?>" class="btn btn-outline btn-sm">Reset</a>
    <?php endif; ?>
</form>

<?php if (empty($questions)): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: 48px;">
            <div style="font-size: 40px; margin-bottom: 12px;">&#128221;</div>
            <h4 style="font-size: 16px; color: var(--text-heading); margin-bottom: 6px;">Belum Ada Butir Soal</h4>
            <p style="color: var(--text-muted); font-size: 13.5px; margin-bottom: 20px;">Tambahkan butir soal pilihan ganda (A-E) untuk bank soal ini.</p>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('modalAddQuestion').classList.add('show')">
                + Tambah Soal Pertama
            </button>
        </div>
    </div>
<?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 16px;">
        <?php foreach ($questions as $idx => $qst): ?>
            <div class="card">
                <div class="card-header" style="background: var(--bg-subtle);">
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span class="badge badge-primary">No. <?= $pg['offset'] + $idx + 1 ?></span>
                        <?php if (($qst['question_type'] ?? 'pg') === 'essay'): ?>
                            <span class="badge badge-warning">Essay</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Pilihan Ganda</span>
                            <span class="badge badge-success">Kunci: <?= $qst['correct_answer'] ?></span>
                        <?php endif; ?>
                        <span class="badge badge-neutral">Bobot: <?= $qst['points'] ?> Poin</span>
                        <?php if (!empty($qst['question_image'])): ?>
                            <span class="badge badge-neutral">🖼️ Gambar</span>
                        <?php endif; ?>
                        <?php if (!empty($qst['question_audio'])): ?>
                            <span class="badge badge-info" style="background: rgba(14,165,233,0.15); color: #0284c7; border: 1px solid rgba(14,165,233,0.3);">
                                🎧 Audio <?= (int)$qst['audio_play_limit'] > 0 ? '(' . (int)$qst['audio_play_limit'] . 'x putar)' : '(Bebas)' ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($qst['question_video'])): ?>
                            <span class="badge badge-info" style="background: rgba(168,85,247,0.15); color: #9333ea; border: 1px solid rgba(168,85,247,0.3);">
                                🎬 Video
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <a href="questions.php?bank_id=<?= $bankId ?>&edit=<?= $qst['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-outline btn-sm">Edit Soal</a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus butir soal nomor <?= $pg['offset'] + $idx + 1 ?>?')">
                            <?= Helper::csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $qst['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Hapus Soal</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <p style="font-size: 15px; font-weight: 500; color: var(--text-heading); line-height: 1.6; margin-bottom: 12px;">
                        <?= nl2br(htmlspecialchars($qst['question_text'])) ?>
                    </p>

                    <?php if (!empty($qst['question_image'])): ?>
                        <div style="margin-bottom: 16px;">
                            <img src="../<?= htmlspecialchars($qst['question_image']) ?>" alt="Gambar soal" style="max-width: 380px; max-height: 240px; border-radius: 10px; border: 1px solid var(--border-light);">
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($qst['question_video'])): 
                        $ytEmbed = getYouTubeEmbedUrl($qst['question_video']);
                    ?>
                        <div style="margin-bottom: 16px; max-width: 520px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border-light); background: #0f172a;">
                            <div style="padding: 6px 12px; font-size: 11.5px; font-weight: 700; color: #94a3b8; background: rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.08);">
                                🎬 Video Soal
                            </div>
                            <?php if ($ytEmbed): ?>
                                <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                                    <iframe src="<?= htmlspecialchars($ytEmbed) ?>" style="position: absolute; top:0; left:0; width:100%; height:100%; border:0;" allowfullscreen></iframe>
                                </div>
                            <?php else: ?>
                                <video controls controlsList="nodownload" style="width: 100%; max-height: 290px; display: block;" src="<?= str_starts_with($qst['question_video'], 'http') ? htmlspecialchars($qst['question_video']) : '../' . htmlspecialchars($qst['question_video']) ?>"></video>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($qst['question_audio'])): ?>
                        <div style="margin-bottom: 16px; max-width: 520px; background: var(--bg-subtle); border: 1px solid var(--border-light); border-radius: 12px; padding: 12px 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div style="font-size: 13px; font-weight: 700; color: var(--text-heading); display: flex; align-items: center; gap: 6px;">
                                    <span>🎧</span> Audio Listening
                                </div>
                                <span style="font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 12px; background: rgba(14,165,233,0.12); color: #0284c7;">
                                    Batas Putar: <?= (int)$qst['audio_play_limit'] > 0 ? (int)$qst['audio_play_limit'] . 'x di siswa' : 'Tak Terbatas' ?>
                                </span>
                            </div>
                            <audio controls controlsList="nodownload" src="../<?= htmlspecialchars($qst['question_audio']) ?>" style="width: 100%; height: 38px; border-radius: 6px;"></audio>
                        </div>
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px;">
                        <?php foreach (['A', 'B', 'C', 'D', 'E'] as $letter):
                            $optText = $qst['option_' . strtolower($letter)] ?? '';
                            if ($letter === 'E' && ($optText === '' || $optText === null)) continue;
                            $isCorrect = $qst['correct_answer'] === $letter;
                        ?>
                            <div style="padding: 10px 14px; border: 1px solid <?= $isCorrect ? '#10b981' : 'var(--border-light)' ?>; background: <?= $isCorrect ? 'rgba(16,185,129,0.12)' : 'transparent' ?>; border-radius: 8px; font-size: 13.5px;">
                                <b><?= $letter ?>.</b> <?= htmlspecialchars($optText) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($qst['explanation'])): ?>
                        <div style="margin-top: 14px; padding: 12px 14px; background: var(--bg-subtle); border-radius: 8px; font-size: 13px; color: var(--text-muted);">
                            <b style="color: var(--text-heading);">Pembahasan:</b> <?= nl2br(htmlspecialchars($qst['explanation'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?= Helper::paginationLinks($pg, $keepQuery) ?>
<?php endif; ?>

<!-- MODAL TAMBAH / EDIT BUTIR SOAL -->
<div class="modal-backdrop <?= $editQuestion ? 'show' : '' ?>" id="modalAddQuestion">
    <div class="modal-dialog" style="max-width: 680px;">
        <form method="POST" action="./questions.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="<?= $editQuestion ? 'update' : 'create' ?>">
            <?php if ($editQuestion): ?>
                <input type="hidden" name="id" value="<?= $editQuestion['id'] ?>">
            <?php endif; ?>
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;"><?= $editQuestion ? 'Edit Butir Soal' : 'Tambah Butir Soal Baru' ?></h3>
                <?php if ($editQuestion): ?>
                    <a href="questions.php?bank_id=<?= $bankId ?>" class="btn btn-outline btn-sm">&times;</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                <?php endif; ?>
            </div>
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tipe Soal</label>
                        <select name="question_type" id="questionTypeSelect" class="form-control">
                            <option value="pg" <?= ($editQuestion['question_type'] ?? 'pg') === 'pg' ? 'selected' : '' ?>>Pilihan Ganda (koreksi otomatis)</option>
                            <option value="essay" <?= ($editQuestion['question_type'] ?? '') === 'essay' ? 'selected' : '' ?>>Essay (koreksi manual guru)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bobot Nilai (Poin)</label>
                        <input type="number" name="points" class="form-control" value="<?= htmlspecialchars($editQuestion['points'] ?? '20') ?>" min="1" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Teks Pertanyaan Soal</label>
                    <textarea name="question_text" class="form-control" rows="3" placeholder="Ketik pertanyaan soal disini..." required><?= htmlspecialchars($editQuestion['question_text'] ?? '') ?></textarea>
                </div>

                <!-- MEDIA PENDUKUNG SOAL (GAMBAR, AUDIO LISTENING, VIDEO) -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                    <div style="font-size: 13.5px; font-weight: 700; color: var(--text-heading); margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                        <span>🎨</span> Media Pendukung Soal (Opsional)
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">
                        Lengkapi butir soal dengan gambar diagram, audio listening exam, atau video studi kasus.
                    </div>

                    <!-- 1. GAMBAR SOAL -->
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label" style="font-weight: 600; font-size: 12.5px;">
                            🖼️ Gambar Diagram / Ilustrasi <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">(JPG, PNG, WEBP, GIF maks 2 MB)</span>
                        </label>
                        <input type="file" name="question_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                        <?php if (!empty($editQuestion['question_image'])): ?>
                            <div style="margin-top: 8px; display: flex; align-items: center; gap: 12px; background: rgba(0,0,0,0.03); padding: 8px; border-radius: 8px;">
                                <img src="../<?= htmlspecialchars($editQuestion['question_image']) ?>" alt="Gambar saat ini" style="max-height: 70px; border-radius: 6px; border: 1px solid var(--border-light);">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--danger); cursor: pointer;">
                                    <input type="checkbox" name="remove_image" value="1"> Hapus gambar ini
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. AUDIO LISTENING -->
                    <div class="form-group" style="margin-bottom: 14px; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                        <label class="form-label" style="font-weight: 600; font-size: 12.5px;">
                            🎧 Audio Listening Exam <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">(MP3, WAV, OGG, M4A, AAC maks 15 MB)</span>
                        </label>
                        <input type="file" name="question_audio" class="form-control" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac">
                        <div style="margin-top: 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <label style="font-size: 12px; font-weight: 600; color: var(--text-muted);">
                                Batas Pemutaran oleh Siswa:
                            </label>
                            <select name="audio_play_limit" class="form-control" style="width: auto; font-size: 12.5px; padding: 4px 10px;">
                                <option value="0" <?= ((int)($editQuestion['audio_play_limit'] ?? 0) === 0) ? 'selected' : '' ?>>♾️ Bebas (Tak terbatas)</option>
                                <option value="1" <?= ((int)($editQuestion['audio_play_limit'] ?? 0) === 1) ? 'selected' : '' ?>>1x Saja (Ujian Ketat)</option>
                                <option value="2" <?= ((int)($editQuestion['audio_play_limit'] ?? 0) === 2) ? 'selected' : '' ?>>2x Saja (Standar UN / TOEFL Listening)</option>
                                <option value="3" <?= ((int)($editQuestion['audio_play_limit'] ?? 0) === 3) ? 'selected' : '' ?>>3x Saja</option>
                            </select>
                        </div>
                        <?php if (!empty($editQuestion['question_audio'])): ?>
                            <div style="margin-top: 10px; background: rgba(0,0,0,0.03); padding: 8px 12px; border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <span style="font-size: 12px; font-weight: 600; color: #0284c7;">File Audio Aktif:</span>
                                    <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--danger); cursor: pointer;">
                                        <input type="checkbox" name="remove_audio" value="1"> Hapus audio ini
                                    </label>
                                </div>
                                <audio controls src="../<?= htmlspecialchars($editQuestion['question_audio']) ?>" style="width: 100%; height: 34px;"></audio>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 3. VIDEO SOAL (UPLOAD ATAU YOUTUBE) -->
                    <div class="form-group" style="margin-bottom: 4px; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                        <label class="form-label" style="font-weight: 600; font-size: 12.5px;">
                            🎬 Video Pembelajaran / Soal
                        </label>
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 6px;">
                            Bisa menautkan Link YouTube (sangat disarankan untuk menghemat bandwidth & storage cPanel) atau mengunggah file MP4 langsung.
                        </div>
                        
                        <!-- Input Link YouTube -->
                        <div style="margin-bottom: 8px;">
                            <div style="font-size: 12px; font-weight: 600; color: var(--text-heading); margin-bottom: 4px;">Link YouTube / URL Video:</div>
                            <input type="url" name="question_video_url" class="form-control" placeholder="Contoh: https://www.youtube.com/watch?v=... atau https://youtu.be/..." 
                                   value="<?= (!empty($editQuestion['question_video']) && filter_var($editQuestion['question_video'], FILTER_VALIDATE_URL)) ? htmlspecialchars($editQuestion['question_video']) : '' ?>">
                        </div>

                        <!-- Atau Upload File MP4 -->
                        <div>
                            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Atau Unggah File Video Langsung (MP4 / WebM maks 30 MB):</div>
                            <input type="file" name="question_video_file" class="form-control" accept="video/mp4,video/webm">
                        </div>

                        <?php if (!empty($editQuestion['question_video'])): 
                            $ytEditEmbed = getYouTubeEmbedUrl($editQuestion['question_video']);
                        ?>
                            <div style="margin-top: 10px; background: rgba(0,0,0,0.03); padding: 8px 12px; border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <span style="font-size: 12px; font-weight: 600; color: #9333ea;">Video Aktif Saat Ini:</span>
                                    <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--danger); cursor: pointer;">
                                        <input type="checkbox" name="remove_video" value="1"> Hapus video ini
                                    </label>
                                </div>
                                <?php if ($ytEditEmbed): ?>
                                    <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px;">
                                        <iframe src="<?= htmlspecialchars($ytEditEmbed) ?>" style="position: absolute; top:0; left:0; width:100%; height:100%; border:0;" allowfullscreen></iframe>
                                    </div>
                                <?php else: ?>
                                    <video controls style="max-width: 100%; max-height: 200px; border-radius: 8px;" src="<?= str_starts_with($editQuestion['question_video'], 'http') ? htmlspecialchars($editQuestion['question_video']) : '../' . htmlspecialchars($editQuestion['question_video']) ?>"></video>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="pgFields">
                <div class="form-group">
                    <label class="form-label">Kunci Jawaban Benar</label>
                    <select name="correct_answer" class="form-control" required>
                        <?php foreach (['A', 'B', 'C', 'D', 'E'] as $letter): ?>
                            <option value="<?= $letter ?>" <?= ($editQuestion['correct_answer'] ?? 'A') === $letter ? 'selected' : '' ?>>Pilihan <?= $letter ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Pilihan A</label>
                    <input type="text" name="option_a" class="form-control" placeholder="Teks opsi A" value="<?= htmlspecialchars($editQuestion['option_a'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Pilihan B</label>
                    <input type="text" name="option_b" class="form-control" placeholder="Teks opsi B" value="<?= htmlspecialchars($editQuestion['option_b'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Pilihan C</label>
                    <input type="text" name="option_c" class="form-control" placeholder="Teks opsi C" value="<?= htmlspecialchars($editQuestion['option_c'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Pilihan D</label>
                    <input type="text" name="option_d" class="form-control" placeholder="Teks opsi D" value="<?= htmlspecialchars($editQuestion['option_d'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Pilihan E (Opsional)</label>
                    <input type="text" name="option_e" class="form-control" placeholder="Teks opsi E" value="<?= htmlspecialchars($editQuestion['option_e'] ?? '') ?>">
                </div>
                </div><!-- /pgFields -->

                <div class="form-group">
                    <label class="form-label">Pembahasan / Explanation (Opsional)</label>
                    <textarea name="explanation" class="form-control" rows="2" placeholder="Penjelasan kunci jawaban..."><?= htmlspecialchars($editQuestion['explanation'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($editQuestion): ?>
                    <a href="questions.php?bank_id=<?= $bankId ?>" class="btn btn-outline">Batal</a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editQuestion ? 'Simpan Perubahan' : 'Simpan Butir Soal' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('questionTypeSelect');
    var pg = document.getElementById('pgFields');
    if (!sel || !pg) return;
    function toggle() {
        var essay = sel.value === 'essay';
        pg.style.display = essay ? 'none' : '';
        pg.querySelectorAll('input, select').forEach(function (el) { el.disabled = essay; });
    }
    sel.addEventListener('change', toggle);
    toggle();
})();
</script>

<!-- MODAL IMPOR SOAL EXCEL / CSV -->
<div class="modal-backdrop" id="modalImportQuestions">
    <div class="modal-dialog">
        <form method="POST" action="./questions.php" enctype="multipart/form-data" data-no-fetch>
            <?= Helper::csrfField() ?>
            <input type="hidden" name="action" value="import_excel">
            <input type="hidden" name="bank_id" value="<?= $bankId ?>">
            <div class="modal-header">
                <h3 style="font-size: 16px; font-weight: 700;">Impor Butir Soal (Excel / CSV)</h3>
                <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Pilih File Soal (.xlsx atau .csv) <span style="color:var(--danger)">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Format yang didukung: Microsoft Excel (.xlsx) atau CSV (UTF-8).</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Metode Impor</label>
                    <select name="import_mode" class="form-control">
                        <option value="append">Tambahkan ke butir soal yang sudah ada (Append)</option>
                        <option value="replace">Ganti / Timpa semua butir soal di bank ini (Replace / Kosongkan dulu)</option>
                    </select>
                </div>

                <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; font-size: 12.5px; color: var(--text-muted); line-height: 1.6;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                        <b style="color: var(--text-heading);">Format Kolom Template:</b>
                        <div style="display: flex; gap: 6px;">
                            <a href="questions.php?bank_id=<?= $bankId ?>&template=xlsx" class="btn btn-primary btn-sm" style="padding: 3px 8px; font-size: 11px;">
                                Unduh .xlsx
                            </a>
                            <a href="questions.php?bank_id=<?= $bankId ?>&template=csv" class="btn btn-outline btn-sm" style="padding: 3px 8px; font-size: 11px;">
                                Unduh .csv
                            </a>
                        </div>
                    </div>
                    <code style="word-break: break-all;">nomor, jenis_soal, soal, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot, pembahasan</code>
                    <ul style="margin: 8px 0 0 16px; padding: 0;">
                        <li><b>jenis_soal</b>: diisi <code>pg</code> (pilihan ganda) atau <code>essay</code>.</li>
                        <li><b>kunci_jawaban</b>: untuk PG isi huruf <code>A</code>, <code>B</code>, <code>C</code>, <code>D</code>, atau <code>E</code> (essay boleh dikosongkan).</li>
                        <li><b>bobot</b>: nilai poin soal (contoh: <code>10</code> atau <code>20</code>).</li>
                        <li>Untuk format Excel (.xlsx), hanya sheet pertama yang akan diproses.</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Batal</button>
                <button type="button" class="btn btn-primary" id="btnMulaiImporSoal">Mulai Impor Soal</button>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    var modal = document.getElementById('modalImportQuestions');
    if (!modal) return;
    var form = modal.querySelector('form');
    var btn  = modal.querySelector('#btnMulaiImporSoal');
    if (!form || !btn) return;
    btn.addEventListener('click', function () {
        if (!form.checkValidity()) { form.reportValidity(); return; }
        btn.disabled = true;
        btn.textContent = 'Memproses...';
        new Image().src = '../api/ping.php?_=' + Date.now();
        var formData = new FormData(form);
        console.log('[CBT] Import soal → csrf=' + (!!formData.get('_csrf')), 'file=' + (formData.get('csv_file')?.name || 'none'));
        fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) {
                console.log('[CBT] Response status=' + r.status);
                var loc = r.headers.get('Location');
                if (r.ok && loc) { window.location.replace(loc); }
                else if (r.ok) { window.location.reload(); }
                else { return r.text().then(function(t){ throw new Error('HTTP '+r.status+': '+t.substring(0,300)); }); }
            })
            .catch(function(err) {
                console.error('[CBT] Error:', err);
                alert('Impor gagal — ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Mulai Impor Soal';
            });
    }, false);
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
