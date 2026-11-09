<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';

// ADMIN ONLY
$currentRole = $_SESSION['role'] ?? null;
if ($currentRole !== 'admin' && $currentRole !== 'manager') {
    http_response_code(403);
    exit('Access denied. Required role: admin or manager.');
}

// ============================================================
// FILTERS
// ============================================================
$typeFilter     = $_GET['type'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$statusFilter   = $_GET['status'] ?? '';
$dateFrom       = $_GET['date_from'] ?? '';
$dateTo         = $_GET['date_to'] ?? '';
$search         = trim($_GET['q'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 15;
$offset         = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($typeFilter !== '' && in_array($typeFilter, ['rain', 'heat', 'wind', 'general'])) {
    $where[] = "advisory_type = :type";
    $params[':type'] = $typeFilter;
}
if ($priorityFilter !== '' && in_array($priorityFilter, ['low', 'medium', 'high'])) {
    $where[] = "priority = :priority";
    $params[':priority'] = $priorityFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'sent', 'failed', 'partial'])) {
    $where[] = "status = :status";
    $params[':status'] = $statusFilter;
}
if ($dateFrom !== '') {
    $where[] = "created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($search !== '') {
    $where[] = "(message LIKE :search OR CAST(id AS CHAR) LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

// ============================================================
// PAGINATION
// ============================================================
$countSql = "SELECT COUNT(*) FROM advisory_logs" . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ============================================================
// FETCH LOGS
// ============================================================
$sql = "SELECT 
            al.*,
            u.full_name AS sender_name,
            u.email AS sender_email
        FROM advisory_logs al
        LEFT JOIN users u ON al.sent_by = u.id
        $whereSql
        ORDER BY al.created_at DESC
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// STATS
// ============================================================
$stats = [
    'total' => 0,
    'sent' => 0,
    'failed' => 0,
    'recipients' => 0,
];

$statsSql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
    SUM(success_count) as recipients
    FROM advisory_logs";
$statsResult = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);
if ($statsResult) $stats = array_merge($stats, $statsResult);

$title = 'Advisory Logs';
renderHeader($title);
?>

<style>
    :root {
        --bg: #f5f7fa; --surface: #ffffff;
        --text: #0f172a; --text-2: #475569; --text-3: #94a3b8;
        --border: #e5e9ef; --border-2: #f1f5f9;
        --primary: #2563eb; --primary-light: #dbeafe; --primary-dark: #1d4ed8;
        --green: #16a34a; --green-light: #dcfce7;
        --amber: #d97706; --amber-light: #fef3c7;
        --red: #dc2626; --red-light: #fee2e2;
        --navbar-height: 68px; --sidebar-width: 264px;
        --radius: 14px; --radius-sm: 10px;
        --ease: cubic-bezier(0.4, 0, 0.2, 1);
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; background: var(--bg); color: var(--text);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px; line-height: 1.5; overflow-x: hidden; -webkit-font-smoothing: antialiased;
    }
    img, svg { max-width: 100%; display: block; }
    button { font-family: inherit; }
    a { color: inherit; text-decoration: none; }
    .main-content, .main-content .container {
        width: 100% !important; max-width: 100% !important; margin: 0 !important;
        padding: 0 !important; background: var(--bg) !important; display: block !important;
    }

    /* NAVBAR */
    .gf-navbar {
        position: fixed; top: 0; left: 0; right: 0; height: var(--navbar-height);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 24px; z-index: 1050; box-shadow: 0 2px 12px rgba(15, 23, 42, 0.15);
    }
    .gf-navbar-brand { display: flex; align-items: center; gap: 12px; color: #fff; flex-shrink: 0; }
    .gf-navbar-logo {
        width: 46px; height: 46px; border-radius: 13px; background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.16); display: grid; place-items: center;
        font-size: 24px; flex-shrink: 0; overflow: hidden;
    }
    .gf-navbar-logo img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
    .gf-navbar-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
    .gf-navbar-title { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
    .gf-navbar-sub { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.65); text-transform: uppercase; letter-spacing: 0.12em; margin-top: 2px; }
    .gf-navbar-links { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; }
    .gf-navbar-links li a {
        display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px;
        border-radius: 10px; font-size: 14px; font-weight: 600;
        color: rgba(255, 255, 255, 0.82); white-space: nowrap; transition: all 0.2s var(--ease);
    }
    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-navbar-links li a.active { background: #fff; color: #14532d; font-weight: 700; }
    .gf-navbar-links li a.logout { color: #fca5a5; }

    /* MAIN */
    .main {
        padding: calc(var(--navbar-height) + 24px) clamp(16px, 2vw, 28px) 60px;
        max-width: 1400px; margin: 0 auto; width: 100%; min-height: 100vh;
    }

    /* PAGE HEADER */
    .page-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
    }
    .page-head-left h1 { font-size: 22px; font-weight: 800; margin: 0 0 3px; letter-spacing: -0.02em; }
    .page-head-left p { font-size: 12.5px; color: var(--text-3); margin: 0; font-weight: 600; }
    .back-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 14px; background: var(--surface); color: var(--text-2);
        border: 1px solid var(--border); border-radius: 8px;
        font-size: 12.5px; font-weight: 700; transition: all 0.15s var(--ease);
    }
    .back-btn:hover { background: #f1f5f9; color: var(--text); }

    /* STATS GRID */
    .stats-grid {
        display: grid; grid-template-columns: repeat(4, 1fr);
        gap: 12px; margin-bottom: 20px;
    }
    @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    .stat-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 16px 18px;
    }
    .stat-label {
        font-size: 10.5px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px;
    }
    .stat-value {
        font-size: 26px; font-weight: 800; line-height: 1;
        letter-spacing: -0.03em; color: var(--text);
    }
    .stat-value.green { color: var(--green); }
    .stat-value.red { color: var(--red); }

    /* FILTER CARD */
    .filter-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 18px 20px; margin-bottom: 18px;
    }
    .filter-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto;
        gap: 12px; align-items: end;
    }
    @media (max-width: 1200px) { .filter-grid { grid-template-columns: 1fr 1fr 1fr; } }
    @media (max-width: 640px) { .filter-grid { grid-template-columns: 1fr; } }
    .field { display: flex; flex-direction: column; gap: 5px; }
    .field label {
        font-size: 11.5px; font-weight: 700; color: var(--text-2);
        text-transform: uppercase; letter-spacing: 0.04em;
    }
    .field input, .field select {
        width: 100%; padding: 9px 12px;
        border: 1px solid var(--border); border-radius: 8px;
        font-size: 13px; font-family: inherit; color: var(--text);
        background: #fff; outline: none;
        transition: border-color 0.15s var(--ease);
    }
    .field input:focus, .field select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .filter-actions { display: flex; gap: 8px; }
    .btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 16px; border-radius: 8px;
        font-size: 13px; font-weight: 700; cursor: pointer;
        border: none; transition: all 0.15s var(--ease);
        white-space: nowrap;
    }
    .btn-primary {
        background: var(--primary); color: #fff;
    }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-secondary {
        background: #f1f5f9; color: var(--text-2); border: 1px solid var(--border);
    }
    .btn-secondary:hover { background: #e2e8f0; }

    /* TABLE */
    .table-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); overflow: hidden;
    }
    .table-wrapper { overflow-x: auto; }
    .logs-table {
        width: 100%; border-collapse: collapse; font-size: 13px;
        min-width: 900px;
    }
    .logs-table thead th {
        text-align: left; padding: 12px 16px;
        background: #fafbfc; color: var(--text-3);
        font-size: 10.5px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }
    .logs-table tbody td {
        padding: 14px 16px; border-bottom: 1px solid var(--border-2);
        vertical-align: middle; color: var(--text-2);
    }
    .logs-table tbody tr:last-child td { border-bottom: none; }
    .logs-table tbody tr:hover { background: #fafbfc; }

    .msg-cell {
        max-width: 380px;
    }
    .msg-text {
        font-size: 12.5px; color: var(--text); line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .msg-id {
        font-size: 10.5px; color: var(--text-3); font-weight: 700;
        margin-top: 3px;
    }

    /* BADGES */
    .badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 9px; border-radius: 6px;
        font-size: 10.5px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.04em;
        white-space: nowrap;
    }
    .badge.rain      { background: #dbeafe; color: #1e40af; }
    .badge.heat      { background: #fee2e2; color: #b91c1c; }
    .badge.wind      { background: #e0e7ff; color: #3730a3; }
    .badge.general   { background: #f1f5f9; color: #475569; }

    .badge.high      { background: #fee2e2; color: #b91c1c; }
    .badge.medium    { background: #fef3c7; color: #92400e; }
    .badge.low       { background: #f1f5f9; color: #64748b; }

    .badge.sent      { background: var(--green-light); color: #166534; }
    .badge.failed    { background: var(--red-light); color: #b91c1c; }
    .badge.pending   { background: #fef3c7; color: #92400e; }
    .badge.partial   { background: #fef3c7; color: #92400e; }

    /* DELIVERY STATS */
    .delivery-stats {
        display: flex; gap: 8px; font-size: 11.5px; font-weight: 700;
    }
    .delivery-stat {
        display: inline-flex; align-items: center; gap: 3px;
    }
    .delivery-stat.success { color: var(--green); }
    .delivery-stat.failed  { color: var(--red); }
    .delivery-stat.total   { color: var(--text-2); }

    /* VIEW BUTTON */
    .btn-view {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 6px 12px; border-radius: 7px;
        background: var(--primary-light); color: var(--primary-dark);
        border: 1px solid #bfdbfe;
        font-size: 11.5px; font-weight: 700;
        transition: all 0.15s var(--ease);
    }
    .btn-view:hover { background: #bfdbfe; }

    /* EMPTY STATE */
    .empty {
        padding: 60px 20px; text-align: center;
    }
    .empty-icon {
        font-size: 48px; margin-bottom: 12px; opacity: 0.4;
    }
    .empty p { margin: 0; font-size: 14px; color: var(--text-3); font-weight: 600; }

    /* PAGINATION */
    .pagination {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 14px 18px;
        border-top: 1px solid var(--border-2); flex-wrap: wrap;
    }
    .pagination-info {
        font-size: 12px; color: var(--text-3); font-weight: 600;
    }
    .pagination-links {
        display: flex; gap: 4px; flex-wrap: wrap;
    }
    .page-link {
        min-width: 34px; height: 34px;
        display: inline-flex; align-items: center; justify-content: center;
        padding: 0 10px; border-radius: 7px;
        font-size: 12.5px; font-weight: 700;
        background: var(--surface); color: var(--text-2);
        border: 1px solid var(--border);
        transition: all 0.15s var(--ease);
    }
    .page-link:hover { background: #f1f5f9; color: var(--text); }
    .page-link.active {
        background: var(--primary); color: #fff;
        border-color: var(--primary);
    }
    .page-link.disabled {
        opacity: 0.4; pointer-events: none;
    }

    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
        .main { padding: calc(var(--navbar-height) + 16px) 12px 60px; }
        .page-head-left h1 { font-size: 18px; }
    }

    .gf-navbar-toggle { display: none; width: 42px; height: 42px; place-items: center; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 10px; cursor: pointer; color: #fff; padding: 0; }
    .gf-sidebar {
        position: fixed; top: 0; right: 0; bottom: 0; width: var(--sidebar-width);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        padding: 24px 18px 40px; color: #fff; overflow-y: auto; z-index: 1060;
        display: flex; flex-direction: column; transform: translateX(100%);
        transition: transform 0.32s var(--ease); box-shadow: -8px 0 32px rgba(0, 0, 0, 0.35);
    }
    body.gf-sidebar-open .gf-sidebar { transform: translateX(0); }
    .gf-sidebar-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 8px 22px; margin-bottom: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.12); }
    .gf-sidebar-close { width: 36px; height: 36px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.1); border: none; border-radius: 10px; color: #fff; cursor: pointer; padding: 0; }
    .gf-brand { display: flex; align-items: center; gap: 12px; }
    .gf-brand-icon { width: 46px; height: 46px; border-radius: 13px; background: rgba(255, 255, 255, 0.14); display: grid; place-items: center; font-size: 24px; flex-shrink: 0; }
    .gf-brand-text { font-size: 16px; font-weight: 800; line-height: 1.2; }
    .gf-brand-sub { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.65); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.1em; }
    .gf-nav-section { display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .gf-nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 11px; color: rgba(255, 255, 255, 0.82); font-size: 14px; font-weight: 600; text-decoration: none; transition: all 0.2s var(--ease); }
    .gf-nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-nav-link.active { background: #fff; color: #14532d; font-weight: 700; }
    .gf-nav-link.logout { color: #fca5a5; margin-top: 12px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.12); border-radius: 0; }
    .gf-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(2px); z-index: 1055; }
    body.gf-sidebar-open .gf-backdrop { display: block; }
    @media (max-width: 767.98px) { .gf-navbar-toggle { display: grid; } }
</style>

<!-- NAVBAR -->
<nav class="gf-navbar">
    <a href="<?php echo BASE_URL; ?>/app/admin/dashboard.php" class="gf-navbar-brand">
        <div class="gf-navbar-logo">
              <?php if (file_exists(__DIR__ . '/../../assets/images/goat-logo.png')): ?>
                <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png" alt="GoatPro Logo">
            <?php else: ?>
                <span aria-hidden="true">🐐</span>
            <?php endif; ?>
        </div>
        <div class="gf-navbar-brand-text">
            <span class="gf-navbar-title">GoatPro</span>
          
        </div>
    </a>
    <ul class="gf-navbar-links">
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'advisory'); ?>
    </ul>
    <button class="gf-navbar-toggle" type="button" onclick="toggleSidebar();" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
</nav>

<aside class="gf-sidebar" id="gfSidebar">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon">🐐</div>
            <div>
                <div class="gf-brand-text">GoatPro</div>
                <div class="gf-brand-sub"><?php echo ucfirst($currentRole); ?> · Advisory</div>
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" onclick="closeSidebar();">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'advisory'); ?>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<script>
function toggleSidebar() { document.body.classList.toggle('gf-sidebar-open'); }
function closeSidebar() { document.body.classList.remove('gf-sidebar-open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });
</script>


<!-- MAIN -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="main">

            <!-- PAGE HEADER -->
            <div class="page-head">
                <div class="page-head-left">
                    <h1>Advisory Logs</h1>
                    <p>History sa tanan SMS advisories nga gipadala sa farmers</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/app/weather/dashboard.php" class="back-btn">
                    ← Back to Weather
                </a>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Advisories</div>
                    <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Successful</div>
                    <div class="stat-value green"><?php echo (int)$stats['sent']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Failed</div>
                    <div class="stat-value red"><?php echo (int)$stats['failed']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Recipients</div>
                    <div class="stat-value"><?php echo (int)$stats['recipients']; ?></div>
                </div>
            </div>

            <!-- FILTER -->
            <div class="filter-card">
                <form method="GET">
                    <div class="filter-grid">
                        <div class="field">
                            <label for="q">Search</label>
                            <input type="text" id="q" name="q"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Message content or ID…">
                        </div>
                        <div class="field">
                            <label for="type">Type</label>
                            <select id="type" name="type">
                                <option value="">All Types</option>
                                <option value="rain"    <?php echo $typeFilter === 'rain'    ? 'selected' : ''; ?>>Rain</option>
                                <option value="heat"    <?php echo $typeFilter === 'heat'    ? 'selected' : ''; ?>>Heat</option>
                                <option value="wind"    <?php echo $typeFilter === 'wind'    ? 'selected' : ''; ?>>Wind</option>
                                <option value="general" <?php echo $typeFilter === 'general' ? 'selected' : ''; ?>>General</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="priority">Priority</label>
                            <select id="priority" name="priority">
                                <option value="">All</option>
                                <option value="high"   <?php echo $priorityFilter === 'high'   ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $priorityFilter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low"    <?php echo $priorityFilter === 'low'    ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="">All</option>
                                <option value="sent"    <?php echo $statusFilter === 'sent'    ? 'selected' : ''; ?>>Sent</option>
                                <option value="failed"  <?php echo $statusFilter === 'failed'  ? 'selected' : ''; ?>>Failed</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="date_from">From</label>
                            <input type="date" id="date_from" name="date_from"
                                   value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="field">
                            <label for="date_to">To</label>
                            <input type="date" id="date_to" name="date_to"
                                   value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <?php if ($search || $typeFilter || $priorityFilter || $statusFilter || $dateFrom || $dateTo): ?>
                                <a href="<?php echo BASE_URL; ?>/app/weather/advisory-logs.php" class="btn btn-secondary">Clear</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- TABLE -->
            <div class="table-card">
                <?php if (empty($logs)): ?>
                    <div class="empty">
                        <div class="empty-icon"></div>
                        <p>Walay advisory logs nga naka-record.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="logs-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Advisory</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Delivery</th>
                                    <th>Status</th>
                                    <th>Sent By</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>
                                            <span style="font-family: monospace; font-weight: 700; color: var(--text);">
                                                #<?php echo (int)$log['id']; ?>
                                            </span>
                                        </td>
                                        <td class="msg-cell">
                                            <div class="msg-text"><?php echo htmlspecialchars($log['message']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo htmlspecialchars($log['advisory_type']); ?>">
                                                <?php echo ucfirst($log['advisory_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo htmlspecialchars($log['priority']); ?>">
                                                <?php echo ucfirst($log['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="delivery-stats">
                                                <span class="delivery-stat success">✓ <?php echo (int)$log['success_count']; ?></span>
                                                <?php if ((int)$log['failed_count'] > 0): ?>
                                                    <span class="delivery-stat failed">✗ <?php echo (int)$log['failed_count']; ?></span>
                                                <?php endif; ?>
                                                <span class="delivery-stat total">/ <?php echo (int)$log['recipient_count']; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo htmlspecialchars($log['status']); ?>">
                                                <?php echo ucfirst($log['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['sender_name']): ?>
                                                <div style="font-size: 12.5px; font-weight: 700; color: var(--text);">
                                                    <?php echo htmlspecialchars($log['sender_name']); ?>
                                                </div>
                                                <div style="font-size: 11px; color: var(--text-3);">
                                                    <?php echo htmlspecialchars($log['sender_email']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size: 12px; color: var(--text-3); font-style: italic;">Auto/System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div style="font-size: 12.5px; font-weight: 700; color: var(--text);">
                                                <?php echo date('M j, Y', strtotime($log['created_at'])); ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-3);">
                                                <?php echo date('g:i A', strtotime($log['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="advisory-view.php?id=<?php echo (int)$log['id']; ?>" class="btn-view">
                                                👁 View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $perPage, $totalRows); ?>
                                of <?php echo $totalRows; ?> advisories
                            </div>
                            <div class="pagination-links">
                                <?php
                                $queryBase = $_GET;
                                function pageUrl($p, $base) {
                                    $base['page'] = $p;
                                    return '?' . http_build_query($base);
                                }
                                ?>
                                <a href="<?php echo pageUrl(max(1, $page - 1), $queryBase); ?>"
                                   class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">‹ Prev</a>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                if ($start > 1): ?>
                                    <a href="<?php echo pageUrl(1, $queryBase); ?>" class="page-link">1</a>
                                    <?php if ($start > 2): ?>
                                        <span class="page-link disabled">…</span>
                                    <?php endif; ?>
                                <?php endif;

                                for ($p = $start; $p <= $end; $p++): ?>
                                    <a href="<?php echo pageUrl($p, $queryBase); ?>"
                                       class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor;

                                if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?>
                                        <span class="page-link disabled">…</span>
                                    <?php endif; ?>
                                    <a href="<?php echo pageUrl($totalPages, $queryBase); ?>" class="page-link">
                                        <?php echo $totalPages; ?>
                                    </a>
                                <?php endif; ?>

                                <a href="<?php echo pageUrl(min($totalPages, $page + 1), $queryBase); ?>"
                                   class="page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next ›</a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<?php renderFooter(); ?>