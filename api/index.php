<?php
/**
 * REST API Gateway
 */

require_once __DIR__ . '/bootstrap.php';
apiAuth();
apiRequireTenant();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/#', '/', $path);
$path = explode('/', trim($path, '/'));

$method = $_SERVER['REQUEST_METHOD'];

switch (true) {

    case ($method === 'GET' && count($path) === 2 && $path[0] === 'students'):
        handleListStudents();
        break;

    case ($method === 'GET' && count($path) === 2 && $path[0] === 'exams'):
        handleListExams();
        break;

    case ($method === 'GET' && count($path) === 4 && $path[0] === 'exams' && $path[2] === 'results'):
        handleExamResults((int)$path[1]);
        break;

    case ($method === 'POST' && count($path) === 4 && $path[0] === 'exams' && $path[2] === 'finish'):
        handleFinishExam((int)$path[1]);
        break;

    case ($method === 'GET' && count($path) === 3 && $path[0] === 'monitor' && is_numeric($path[1])):
        handleMonitor((int)$path[1]);
        break;

    default:
        apiError('Endpoint not found.', 404);
}

/* ------------------------------------------------------------------ */
/* ROUTE HANDLERS                                                      */
/* ------------------------------------------------------------------ */

function handleListStudents(): void {
    apiRequireRole('admin', 'teacher', 'superadmin');

    $tenantId = $GLOBALS['apiTenantId'];
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 15)));
    $q        = trim($_GET['q'] ?? '');
    $classId  = (int)($_GET['class_id'] ?? 0);

    $countSql = "SELECT COUNT(*) FROM students s WHERE s.tenant_id = :tid";
    $countParams = ['tid' => $tenantId];
    if ($classId > 0) {
        $countSql .= " AND s.class_id = :cid";
        $countParams['cid'] = $classId;
    }
    if ($q !== '') {
        $countSql .= " AND (s.name LIKE :q OR s.nis LIKE :qn)";
        $countParams['q'] = '%' . $q . '%';
        $countParams['qn'] = '%' . $q . '%';
    }
    $total = (int)Database::fetchColumn($countSql, $countParams);
    $pg    = Helper::paginate($total, $perPage, $page);

    $sql = "SELECT s.id, s.nis, s.name, s.gender,
                   c.name AS class_name,
                   CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END AS is_active
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.tenant_id = :tid";
    $params = ['tid' => $tenantId];
    if ($classId > 0) {
        $sql .= " AND s.class_id = :cid";
        $params['cid'] = $classId;
    }
    if ($q !== '') {
        $sql .= " AND (s.name LIKE :q OR s.nis LIKE :qn)";
        $params['q'] = '%' . $q . '%';
        $params['qn'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY s.nis ASC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
    $students = Database::fetchAll($sql, $params);

    apiResponse($students, 200, [
        'page'      => $pg['page'],
        'pages'     => $pg['pages'],
        'per_page'  => $pg['per_page'],
        'total'     => $pg['total'],
    ]);
}

function handleListExams(): void {
    $tenantId = $GLOBALS['apiTenantId'];
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 15)));
    $q        = trim($_GET['q'] ?? '');

    $countSql = "SELECT COUNT(*) FROM exams e WHERE e.tenant_id = :tid";
    $countParams = ['tid' => $tenantId];
    if ($q !== '') {
        $countSql .= " AND e.title LIKE :qt";
        $countParams['qt'] = '%' . $q . '%';
    }
    $total = (int)Database::fetchColumn($countSql, $countParams);
    $pg    = Helper::paginate($total, $perPage, $page);

    $sql = "SELECT e.id, e.title, e.token, e.status AS exam_status,
                   e.duration_minutes, e.start_time, e.end_time,
                   e.max_violations, e.is_active,
                   qb.title AS bank_title,
                   s.name AS subject_name,
                   c.name AS class_name,
                   (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id) AS total_participants
            FROM exams e
            JOIN question_banks qb ON e.bank_id = qb.id
            JOIN subjects s ON qb.subject_id = s.id
            LEFT JOIN classes c ON e.class_id = c.id
            WHERE e.tenant_id = :tid";
    $params = ['tid' => $tenantId];
    if ($q !== '') {
        $sql .= " AND e.title LIKE :qt";
        $params['qt'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY e.id DESC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
    $exams = Database::fetchAll($sql, $params);

    apiResponse($exams, 200, [
        'page'     => $pg['page'],
        'pages'    => $pg['pages'],
        'per_page' => $pg['per_page'],
        'total'    => $pg['total'],
    ]);
}

function handleExamResults(int $examId): void {
    apiRequireRole('admin', 'teacher', 'superadmin');

    $tenantId = $GLOBALS['apiTenantId'];
    $exam = Database::fetch(
        "SELECT * FROM exams WHERE id = ? AND tenant_id = ?",
        [$examId, $tenantId]
    );
    if (!$exam) {
        apiError('Exam not found.', 404);
    }

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 15)));

    $countSql = "SELECT COUNT(*) FROM exam_sessions es
                 JOIN students st ON es.student_id = st.id
                 WHERE es.exam_id = :eid AND es.tenant_id = :tid AND es.status = 'completed'";
    $countParams = ['eid' => $examId, 'tid' => $tenantId];
    $total = (int)Database::fetchColumn($countSql, $countParams);
    $pg    = Helper::paginate($total, $perPage, $page);

    $sql = "SELECT es.id AS session_id, es.score, es.finish_time, es.total_violations,
                   st.nis, st.name AS student_name,
                   c.name AS class_name,
                   (SELECT COUNT(*) FROM exam_answers ea WHERE ea.session_id = es.id AND ea.is_correct = 1) AS correct_count,
                   (SELECT COUNT(*) FROM exam_answers ea WHERE ea.session_id = es.id
                        AND (ea.student_answer IS NOT NULL OR ea.answer_text IS NOT NULL) AND ea.is_correct = 0) AS wrong_count,
                   (SELECT COUNT(*) FROM exam_answers ea WHERE ea.session_id = es.id
                        AND ea.student_answer IS NULL AND ea.answer_text IS NULL) AS blank_count,
                   (SELECT COUNT(*) FROM exam_answers ea WHERE ea.session_id = es.id
                        AND ea.answer_text IS NOT NULL AND ea.essay_score IS NULL) AS ungraded_count
            FROM exam_sessions es
            JOIN students st ON es.student_id = st.id
            LEFT JOIN classes c ON st.class_id = c.id
            WHERE es.exam_id = :eid AND es.tenant_id = :tid AND es.status = 'completed'
            ORDER BY es.score DESC, st.name ASC
            LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
    $results = Database::fetchAll($sql, ['eid' => $examId, 'tid' => $tenantId]);

    apiResponse($results, 200, [
        'page'     => $pg['page'],
        'pages'    => $pg['pages'],
        'per_page' => $pg['per_page'],
        'total'    => $pg['total'],
        'exam'     => [
            'id'   => $exam['id'],
            'title' => $exam['title'],
        ],
    ]);
}

function handleFinishExam(int $examId): void {
    global $apiUserId, $apiUserRole, $apiTenantId;
    apiRequireRole('admin', 'teacher', 'proctor', 'superadmin');

    $tenantId = $apiTenantId;
    $exam = Database::fetch(
        "SELECT * FROM exams WHERE id = ? AND tenant_id = ?",
        [$examId, $tenantId]
    );
    if (!$exam) {
        apiError('Exam not found.', 404);
    }

    $sessionId = (int)($_POST['session_id'] ?? 0);
    if ($sessionId <= 0) {
        apiError('session_id is required.', 400);
    }

    $session = Database::fetch(
        "SELECT * FROM exam_sessions WHERE id = ? AND exam_id = ? AND tenant_id = ?",
        [$sessionId, $examId, $tenantId]
    );
    if (!$session) {
        apiError('Session not found.', 404);
    }

    if ($session['status'] === 'completed') {
        apiResponse(['message' => 'Session already completed.', 'session_id' => $sessionId], 200);
    }

    $score = Database::fetchColumn(
        "SELECT COALESCE(SUM(CASE WHEN q.question_type = 'essay' THEN COALESCE(ea.essay_score, 0)
                                 WHEN ea.is_correct = 1 THEN q.points ELSE 0 END), 0)
         FROM exam_answers ea
         JOIN questions q ON ea.question_id = q.id
         WHERE ea.session_id = ?",
        [$sessionId]
    );

    Database::update('exam_sessions', [
        'status'      => 'completed',
        'score'       => (float)$score,
        'finish_time' => date('Y-m-d H:i:s')
    ], 'id = :id AND tenant_id = :tenant_id', [
        'id'        => $sessionId,
        'tenant_id' => $tenantId
    ]);

    Database::insert('activity_logs', [
        'tenant_id'  => $tenantId,
        'session_id' => $sessionId,
        'event_type' => 'api_force_finish',
        'message'    => "Sesi #{$sessionId} dikumpulkan paksa via API oleh user #{$apiUserId} (role: {$apiUserRole})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    apiResponse([
        'session_id' => $sessionId,
        'score'      => (float)$score,
        'status'     => 'completed',
        'finished_by' => $apiUserId,
    ], 200);
}

function handleMonitor(int $examId): void {
    apiRequireRole('admin', 'teacher', 'proctor', 'superadmin');

    $tenantId = $GLOBALS['apiTenantId'];
    $exam = Database::fetch(
        "SELECT e.*, qb.title AS bank_title, s.name AS subject_name
         FROM exams e
         JOIN question_banks qb ON e.bank_id = qb.id
         JOIN subjects s ON qb.subject_id = s.id
         WHERE e.id = ? AND e.tenant_id = ?",
        [$examId, $tenantId]
    );
    if (!$exam) {
        apiError('Exam not found.', 404);
    }

    $totalQuestionsInBank = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM questions WHERE bank_id = ?",
        [$exam['bank_id']]
    );

    $participants = Database::fetchAll(
        "SELECT es.id AS session_id, es.status, es.score, es.total_violations,
                es.start_time, es.finish_time, es.ip_address,
                st.id AS student_id, st.nis, st.name AS student_name,
                c.name AS class_name,
                (SELECT COUNT(*) FROM exam_answers WHERE session_id = es.id AND (student_answer IS NOT NULL OR answer_text IS NOT NULL)) AS answered_count
         FROM exam_sessions es
         JOIN students st ON es.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE es.exam_id = ? AND es.tenant_id = ?
         ORDER BY es.status DESC, es.total_violations DESC, es.start_time DESC",
        [$examId, $tenantId]
    );

    $inProgress  = 0;
    $completed   = 0;
    $blocked     = 0;
    foreach ($participants as $p) {
        if ($p['status'] === 'in_progress') $inProgress++;
        elseif ($p['status'] === 'completed') $completed++;
        elseif ($p['status'] === 'blocked') $blocked++;
    }

    apiResponse([
        'exam' => [
            'id'          => $exam['id'],
            'title'       => $exam['title'],
            'token'       => $exam['token'],
            'subject'     => $exam['subject_name'],
            'bank_title'  => $exam['bank_title'],
            'max_violations' => $exam['max_violations'],
            'total_questions_in_bank' => $totalQuestionsInBank,
        ],
        'summary' => [
            'total_participants' => count($participants),
            'in_progress'        => $inProgress,
            'completed'          => $completed,
            'blocked'            => $blocked,
        ],
        'participants' => $participants,
    ], 200, [
        'exam_id'     => $examId,
        'updated_at'  => date('Y-m-d H:i:s'),
        'source'      => 'live',
    ]);
}
