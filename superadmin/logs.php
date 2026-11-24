<?php
/**
 * Log Aktivitas Platform (audit trail) — Superadmin Enhanced
 */

$pageTitle = "Log Aktivitas";
require_once __DIR__ . '/_bootstrap.php';

$tenantFilter = (int)($_GET['tenant_id'] ?? 0);
$eventType = trim($_GET['event'] ?? '');
$searchKeyword = trim($_GET['search'] ?? '');
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$csvExport = (isset($_GET['export']) && $_GET['export'] === 'csv');

$tenants = Database::fetchAll("SELECT id, name, code FROM tenants ORDER BY name ASC");

$eventTypes = Database::fetchAll(
    "SELECT DISTINCT event_type FROM activity_logs ORDER BY event_type ASC"
);

if ($csvExport) {
    $exportSql = "SELECT al.*, t.name as tenant_name, t.code as tenant_code,
                st.name as student_name, st.nis
             FROM activity_logs al
             LEFT JOIN tenants t ON al.tenant_id = t.id
             LEFT JOIN exam_sessions es ON al.session_id = es.id
             LEFT JOIN students st ON es.student_id = st.id
             WHERE 1=1";
    $exportParams = [];
    if ($tenantFilter > 0) {
        $exportSql .= " AND al.tenant_id = :tid";
        $exportParams['tid'] = $tenantFilter;
    }
    if ($eventType !== '') {
        $exportSql .= " AND al.event_type = :ev";
        $exportParams['ev'] = $eventType;
    }
    if ($searchKeyword !== '') {
        $exportSql .= " AND al.message LIKE :kw";
        $exportParams['kw'] = '%'.$searchKeyword.'%';
    }
    if ($fromDate !== '') {
        $exportSql .= " AND al.created_at >= :from";
        $exportParams['from'] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '') {
        $exportSql .= " AND al.created_at <= :to";
        $exportParams['to'] = $toDate . ' 23:59:59';
    }
    $exportSql .= " ORDER BY al.id DESC";
    $allLogs = Database::fetchAll($exportSql, $exportParams);

    $header = ['ID', 'Waktu', 'Sekolah', 'Kode', 'Event', 'Siswa', 'NIS', 'IP Address', 'Keterangan'];
    $rows = [];
    foreach ($allLogs as $lg) {
        $rows[] = [
            $lg['id'],
            $lg['created_at'],
            $lg['tenant_name'] ?? '',
            $lg['tenant_code'] ?? '',
            $lg['event_type'],
            $lg['student_name'] ?? '',
            $lg['nis'] ?? '',
            $lg['ip_address'] ?? '-',
            $lg['message']
        ];
    }
    Helper::downloadCsv('log-aktivitas-' . date('Y-m-d') . '.csv', $header, $rows);
}

$countSql = "SELECT COUNT(*) FROM activity_logs al WHERE 1=1";
$countParams = [];
if ($tenantFilter > 0) {
    $countSql .= " AND al.tenant_id = :tid";
    $countParams['tid'] = $tenantFilter;
}
if ($eventType !== '') {
    $countSql .= " AND al.event_type = :ev";
    $countParams['ev'] = $eventType;
}
if ($searchKeyword !== '') {
    $countSql .= " AND al.message LIKE :kw";
    $countParams['kw'] = '%'.$searchKeyword.'%';
}
if ($fromDate !== '') {
    $countSql .= " AND al.created_at >= :from";
    $countParams['from'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $countSql .= " AND al.created_at <= :to";
    $countParams['to'] = $toDate . ' 23:59:59';
}
$totalLogs = (int)Database::fetchColumn($countSql, $countParams);
$pg = Helper::paginate($totalLogs, 30);

$listSql = "SELECT al.*, t.name as tenant_name, t.code as tenant_code,
            st.name as student_name, st.nis
     FROM activity_logs al
     LEFT JOIN tenants t ON al.tenant_id = t.id
     LEFT JOIN exam_sessions es ON al.session_id = es.id
     LEFT JOIN students st ON es.student_id = st.id
     WHERE 1=1";
$listParams = $countParams;
if ($tenantFilter > 0) {
    $listSql .= " AND al.tenant_id = :tid";
}
if ($eventType !== '') {
    $listSql .= " AND al.event_type = :ev";
}
if ($searchKeyword !== '') {
    $listSql .= " AND al.message LIKE :kw";
}
if ($fromDate !== '') {
    $listSql .= " AND al.created_at >= :from";
}
if ($toDate !== '') {
    $listSql .= " AND al.created_at <= :to";
}
$listSql .= " ORDER BY al.id DESC LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$logs = Database::fetchAll($listSql, $listParams);

$keepQuery = array_filter([
    'tenant_id' => $tenantFilter ?: null,
    'event'     => $eventType !== '' ? $eventType : null,
    'search'    => $searchKeyword !== '' ? $searchKeyword : null,
    'from_date' => $fromDate !== '' ? $fromDate : null,
    'to_date'   => $toDate !== '' ? $toDate : null,
]);

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">Log Aktivitas Platform (<?= number_format($totalLogs) ?> Entri)</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">Audit trail sistem, insiden anti-cheat, otentikasi, dan riwayat pengerjaan ujian</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="?export=csv&<?= http_build_query($keepQuery) ?>" class="btn btn-outline btn-sm" style="border-radius: 9999px; font-weight: 600;">
                &#128229; Ekspor CSV
            </a>
            <a href="backup.php" class="btn btn-primary btn-sm" style="border-radius: 9999px; font-weight: 700;" onclick="return confirm('Unduh seluruh database sebagai file .sql?')">Backup Database (.sql)</a>
            <a href="logs-cleanup.php" class="btn btn-danger btn-sm" style="border-radius: 9999px; font-weight: 600;">&#128465; Bersihkan Log Lama</a>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div style="padding: 18px 20px 0 20px;">
            <form method="GET" class="toolbar-row" style="margin-bottom: 0; flex-wrap: wrap; gap: 10px;">
                <select name="tenant_id" class="form-control" style="width: auto; font-size: 12.5px; border-radius: 9999px; padding: 6px 14px;">
                    <option value="">-- Semua Sekolah --</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $tenantFilter === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name'] ?? '-') ?> (<?= htmlspecialchars($t['code'] ?? '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="event" class="form-control" style="width: auto; font-size: 12.5px; border-radius: 9999px; padding: 6px 14px;">
                    <option value="">-- Semua Event --</option>
                    <?php foreach ($eventTypes as $et): ?>
                        <option value="<?= htmlspecialchars($et['event_type']) ?>" <?= $eventType === $et['event_type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($et['event_type']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="search" class="form-control" style="width: 220px; font-size: 12.5px; border-radius: 9999px; padding: 6px 14px;" placeholder="Cari keterangan..." value="<?= htmlspecialchars($searchKeyword) ?>">
                <input type="date" name="from_date" class="form-control" style="width: auto; font-size: 12.5px; border-radius: 9999px; padding: 6px 14px;" value="<?= htmlspecialchars($fromDate) ?>" title="Tanggal mulai">
                <input type="date" name="to_date" class="form-control" style="width: auto; font-size: 12.5px; border-radius: 9999px; padding: 6px 14px;" value="<?= htmlspecialchars($toDate) ?>" title="Tanggal akhir">
                <button type="submit" class="btn btn-primary btn-sm" style="border-radius: 9999px; font-weight: 700; padding: 6px 18px;">Terapkan</button>
                <?php if (!empty($keepQuery)): ?>
                    <a href="logs.php" class="btn btn-outline btn-sm" style="border-radius: 9999px;">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($logs)): ?>
            <p style="padding: 32px; text-align: center; color: var(--text-muted);">Belum ada log aktivitas.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Waktu</th>
                            <th>Sekolah</th>
                            <th>Event</th>
                            <th>Pengguna</th>
                            <th>IP Address</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $idx => $lg): ?>
                            <tr>
                                <td><?= $pg['offset'] + $idx + 1 ?></td>
                                <td style="font-size: 12px; color: var(--text-muted); white-space: nowrap;"><?= $lg['created_at'] ?></td>
                                <td>
                                    <?php if ($lg['tenant_name']): ?>
                                        <span class="badge badge-neutral"><?= htmlspecialchars($lg['tenant_code']) ?></span>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($lg['tenant_name']) ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-primary"><?= htmlspecialchars($lg['event_type']) ?></span></td>
                                <td style="font-size: 12.5px;">
                                    <?php if ($lg['student_name']): ?>
                                        <?= htmlspecialchars($lg['student_name']) ?> <span style="color: var(--text-muted);">(<?= htmlspecialchars($lg['nis']) ?>)</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 11.5px; color: var(--text-muted); font-family: monospace;"><?= htmlspecialchars($lg['ip_address'] ?? '-') ?></td>
                                <td style="font-size: 12.5px; color: var(--text-body);"><?= htmlspecialchars($lg['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <?= Helper::paginationLinks($pg, $keepQuery) ?>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted);">
                    <label>Lompat ke halaman:</label>
                    <input type="number" min="1" max="<?= $pg['pages'] ?>" id="jumpPageInput" class="form-control" style="width: 70px; padding: 4px 8px; font-size: 13px;" value="<?= $pg['page'] ?>">
                    <button type="button" class="btn btn-outline btn-sm" onclick="jumpPage(<?= $pg['pages'] ?>)">Go</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function jumpPage(maxPages) {
    var input = document.getElementById('jumpPageInput');
    var page = parseInt(input.value, 10);
    if (isNaN(page) || page < 1) page = 1;
    if (page > maxPages) page = maxPages;
    var params = new URLSearchParams(window.location.search);
    params.set('page', page);
    window.location.search = params.toString();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
