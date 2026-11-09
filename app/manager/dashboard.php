<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/activity-logger.php';
require_once '../../includes/goat-functions.php';
requireRole('manager');

// ---- Goat Inventory summary ----
$goatsTableExists = $pdo->query("SHOW TABLES LIKE 'goats'")->fetch();
$totalActiveGoats = 0;
$goatSexCounts = ['male' => 0, 'female' => 0];
$goatHealthCounts = ['healthy' => 0, 'sick' => 0, 'unknown' => 0];
$soldGoatsCount = 0;
$deceasedGoatsCount = 0;
$recentGoats = [];
if ($goatsTableExists) {
    $totalActiveGoats = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'active'")->fetchColumn();

    $stmt = $pdo->query("SELECT sex, COUNT(*) c FROM goats WHERE status = 'active' GROUP BY sex");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $goatSexCounts[$row['sex']] = (int)$row['c']; }

    $stmt = $pdo->query("SELECT health_status, COUNT(*) c FROM goats WHERE status = 'active' GROUP BY health_status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $goatHealthCounts[$row['health_status']] = (int)$row['c']; }

    $soldGoatsCount = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'sold'")->fetchColumn();
    $deceasedGoatsCount = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'deceased'")->fetchColumn();

    $stmt = $pdo->query("
        SELECT g.goat_id, g.goat_name, g.status, g.health_status, g.updated_at, u.full_name AS owner_name
        FROM goats g JOIN users u ON u.id = g.user_id
        ORDER BY g.updated_at DESC LIMIT 5
    ");
    $recentGoats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get statistics for manager
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'user'");
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'user' AND is_verified = 0");
$unverifiedUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get activity statistics for manager and users only
$stmt = $pdo->query("
    SELECT DATE(al.created_at) as date, COUNT(*) as count 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND (u.role IN ('manager', 'user') OR u.role IS NULL)
    GROUP BY DATE(al.created_at)
    ORDER BY date ASC
");
$dailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get action distribution for manager and users
$stmt = $pdo->query("
    SELECT al.action, COUNT(*) as count 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND (u.role IN ('manager', 'user') OR u.role IS NULL)
    GROUP BY al.action 
    ORDER BY count DESC 
    LIMIT 8
");
$actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Computed stats for goat inventory strip
$totalTracked = $totalActiveGoats + $soldGoatsCount + $deceasedGoatsCount;
$activePct    = $totalTracked > 0 ? round(($totalActiveGoats / $totalTracked) * 100) : 0;
$totalSexed   = $goatSexCounts['male'] + $goatSexCounts['female'];
$malePct      = $totalSexed > 0 ? round(($goatSexCounts['male'] / $totalSexed) * 100) : 0;
$healthyPct   = $totalActiveGoats > 0 ? round(($goatHealthCounts['healthy'] / $totalActiveGoats) * 100) : 0;
$sickCount    = $goatHealthCounts['sick'];

// Display name
$sessionEmail = $_SESSION['email'] ?? 'Manager';
$sessionEmailShort = htmlspecialchars(substr($sessionEmail, 0, 30));

$title = 'Manager Dashboard';
renderHeader($title);

$isDashboard = (basename($_SERVER['PHP_SELF']) == 'dashboard.php');
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
    a { color: inherit; }
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
    .gf-navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: #fff; flex-shrink: 0; }
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
        color: rgba(255, 255, 255, 0.82); text-decoration: none; white-space: nowrap; transition: all 0.2s var(--ease);
    }
    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-navbar-links li a.active { background: #fff; color: #14532d; font-weight: 700; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
    .gf-navbar-links li a.logout { color: #fca5a5; }
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
    .page-head h1 { font-size: 24px; font-weight: 800; margin: 0 0 4px; letter-spacing: -0.02em; }
    .page-head p { font-size: 13px; color: var(--text-2); margin: 0; font-weight: 500; }
    .date-info {
        font-size: 12.5px; color: var(--text-2); font-weight: 600;
        padding: 7px 14px; background: var(--surface);
        border: 1px solid var(--border); border-radius: 8px;
    }

    /* STAT STRIP */
    .stat-strip {
        display: grid; grid-template-columns: repeat(5, 1fr);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        overflow: hidden;
        margin-bottom: 20px;
    }
    @media (max-width: 900px) { .stat-strip { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 520px) { .stat-strip { grid-template-columns: repeat(2, 1fr); } }
    .stat-cell { padding: 16px 18px; border-right: 1px solid var(--border-2); }
    .stat-cell:last-child { border-right: none; }
    .stat-label {
        font-size: 10.5px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;
    }
    .stat-value {
        font-size: 26px; font-weight: 800; line-height: 1;
        letter-spacing: -0.03em; color: var(--text);
    }
    .stat-note { font-size: 11px; color: var(--text-3); margin-top: 6px; font-weight: 600; }

    /* GOAT INVENTORY STAT STRIP */
    .goat-stat-strip {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        overflow: hidden;
        margin-bottom: 24px;
    }
    @media (max-width: 900px) { .goat-stat-strip { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 520px) { .goat-stat-strip { grid-template-columns: repeat(2, 1fr); } }

    .goat-stat-cell {
        padding: 20px 22px;
        border-right: 1px solid var(--border-2);
        min-width: 0;
    }
    .goat-stat-cell:last-child { border-right: none; }

    .goat-stat-label {
        font-size: 11px;
        font-weight: 800;
        color: var(--text-3);
        text-transform: uppercase;
        letter-spacing: 0.12em;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .goat-stat-label .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--green);
        display: inline-block;
        flex-shrink: 0;
    }

    .goat-stat-value {
        font-size: 30px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.03em;
        color: var(--text);
        display: flex;
        align-items: baseline;
        gap: 8px;
        flex-wrap: wrap;
    }
    .goat-stat-value.green  { color: var(--green); }
    .goat-stat-value.orange { color: #d97706; }

    .goat-stat-suffix {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-3);
    }
    .goat-stat-suffix.green  { color: var(--green); }
    .goat-stat-suffix.orange { color: #d97706; }

    /* SECTION HEAD */
    .section-head {
        font-size: 15px; font-weight: 800; color: var(--text);
        margin: 0 0 14px 0;
        display: flex; align-items: center; gap: 8px;
    }
    .section-head .count-badge {
        font-size: 11.5px; font-weight: 700;
        background: var(--border-2); color: var(--text-2);
        padding: 3px 10px; border-radius: 999px;
    }

    /* CARD */
    .card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 18px 20px;
        margin-bottom: 20px;
    }
    .card-title {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 10px;
        font-size: 14.5px; font-weight: 800; color: var(--text);
        margin: 0 0 14px;
    }
    .card-title i { color: var(--green); }

    /* BUTTON */
    .btn {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 6px; padding: 9px 16px; border-radius: 8px;
        font-weight: 700; font-size: 12.5px; text-decoration: none;
        transition: all 0.15s var(--ease); border: none; cursor: pointer;
    }
    .btn-primary { background: var(--green); color: #fff; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25); }
    .btn-primary:hover { background: #15803d; transform: translateY(-1px); }

    /* TABLE */
    .table-wrapper { overflow-x: auto; }
    .table-wrapper table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .table-wrapper th {
        text-align: left; padding: 11px 14px;
        font-size: 11px; font-weight: 700;
        color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.05em;
        background: #fafbfc;
        border-bottom: 1px solid var(--border-2);
        white-space: nowrap;
    }
    .table-wrapper td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--border-2);
        vertical-align: middle;
        color: var(--text-2);
    }
    .table-wrapper tr:last-child td { border-bottom: none; }
    .table-wrapper tbody tr:hover { background: #fafbfc; }

    /* BADGE */
    .badge {
        display: inline-block; padding: 3px 10px;
        border-radius: 6px; font-weight: 700; font-size: 11px;
        text-transform: uppercase; letter-spacing: 0.03em;
    }
    .badge-success { background: var(--green-light); color: #166534; }
    .badge-danger  { background: var(--amber-light); color: #92400e; }
    .badge-warning { background: #fef9c3; color: #854d0e; }
    .badge-secondary { background: var(--border-2); color: #64748b; }
    .badge-manager { background: var(--primary-light); color: #1e40af; }
    .badge-admin { background: #fce7f3; color: #9d174d; }
    .badge-user { background: #e0e7ff; color: #3730a3; }

    /* CHARTS */
    .charts-grid {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 16px; margin-bottom: 20px;
    }
    @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }
    .chart-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 18px 20px;
    }
    .chart-card h3 {
        font-size: 14.5px; font-weight: 800; color: var(--text);
        margin: 0 0 4px;
        display: flex; align-items: center; gap: 8px;
    }
    .chart-card h3 i { color: var(--green); }
    .chart-sub { font-size: 11.5px; color: var(--text-3); margin: 0 0 14px; font-weight: 600; }
    .chart-wrap { position: relative; height: 220px; }

    /* ============================================================
       ACTIVITY LOGS
    ============================================================ */
    .activity-toolbar {
        display: grid;
        grid-template-columns: minmax(200px, 1fr) 140px 140px 140px 110px;
        align-items: center;
        gap: 8px;
        padding: 0 0 14px 0;
        border-bottom: 1px solid #eef2f6;
    }
    .act-search { position: relative; width: 100%; }
    .act-search svg {
        position: absolute; left: 12px; top: 50%;
        transform: translateY(-50%);
        color: #9aa5b1; pointer-events: none;
    }
    .act-search input {
        width: 100%; height: 36px;
        padding: 0 34px 0 34px;
        border: 1px solid var(--border); border-radius: 8px;
        font-size: 13px; color: var(--text);
        background: #fff; outline: none;
        transition: border-color 0.15s;
        font-family: inherit;
    }
    .act-search input::placeholder { color: #9aa5b1; }
    .act-search input:focus { border-color: var(--green); }
    .act-search .kbd {
        position: absolute; right: 10px; top: 50%;
        transform: translateY(-50%);
        font-size: 10px; color: #9aa5b1;
        background: #f3f6f9; border: 1px solid var(--border);
        border-radius: 4px; padding: 1px 6px;
        font-weight: 600;
    }
    .act-select {
        height: 36px; width: 100%;
        padding: 0 30px 0 12px;
        border: 1px solid var(--border); border-radius: 8px;
        font-size: 13px; color: var(--text-2);
        background: #fff url("data:image/svg+xml;charset=US-ASCII,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center;
        -webkit-appearance: none; -moz-appearance: none; appearance: none;
        outline: none; cursor: pointer;
        font-family: inherit;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .act-select:focus { border-color: var(--green); }

    @media (max-width: 1024px) {
        .activity-toolbar { grid-template-columns: 1fr 1fr 1fr; }
        .activity-toolbar .act-search { grid-column: 1 / -1; }
    }
    @media (max-width: 640px) {
        .activity-toolbar { grid-template-columns: 1fr 1fr; padding: 12px 0; gap: 8px; }
        .activity-toolbar .act-search { grid-column: 1 / -1; }
        .activity-toolbar .act-select:last-child { grid-column: 1 / -1; }
    }

    .activity-table-wrap { overflow-x: auto; margin: 14px -20px 0; padding: 0 20px; }
    .activity-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .activity-table thead th {
        text-align: left; padding: 11px 14px;
        font-size: 11px; font-weight: 700;
        color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.05em;
        background: #fafbfc;
        border-bottom: 1px solid #eef2f6;
        white-space: nowrap;
    }
    .activity-table thead th.sortable { cursor: pointer; user-select: none; }
    .activity-table thead th.sortable:hover { color: var(--green); }
    .activity-table thead th svg { margin-left: 4px; opacity: 0.5; }

    .activity-table tbody td {
        padding: 13px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: var(--text-2);
    }
    .activity-table tbody tr:last-child td { border-bottom: none; }
    .activity-table tbody tr:hover { background: #fafbfc; }

    .act-user { display: flex; align-items: center; gap: 10px; min-width: 180px; }
    .act-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px; font-weight: 700; color: #fff;
        flex-shrink: 0; text-transform: uppercase;
    }
    .act-avatar.pink   { background: #f3a8c4; }
    .act-avatar.blue   { background: #8fc4e8; }
    .act-avatar.green  { background: #86efac; color: #065f46; }
    .act-avatar.purple { background: #c4b5fd; color: #5b21b6; }
    .act-avatar.amber  { background: #fcd34d; color: #78350f; }
    .act-avatar.slate  { background: #cbd5e1; color: #334155; }

    .act-user-info { min-width: 0; }
    .act-user-name {
        font-weight: 700; color: var(--text); font-size: 13px;
        line-height: 1.25;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 240px;
    }
    .act-user-sub {
        font-size: 11.5px; color: var(--text-3); line-height: 1.3;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 240px;
    }

    .role-pill {
        display: inline-block; padding: 3px 10px;
        border-radius: 6px; font-size: 10.5px;
        font-weight: 800; letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .role-pill.admin   { background: #fce7f3; color: #be185d; }
    .role-pill.manager { background: #dbeafe; color: #1d4ed8; }
    .role-pill.user    { background: #e0e7ff; color: #4338ca; }
    .role-pill.system  { background: #f1f5f9; color: #64748b; }

    .act-action {
        display: inline-flex; align-items: center; gap: 8px;
        color: var(--text-2); font-weight: 600; font-size: 13px;
    }

    .status-pill {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 3px 11px; border-radius: 999px;
        font-size: 11.5px; font-weight: 700; white-space: nowrap;
    }
    .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .status-pill.success  { background: #dcfce7; color: #15803d; }
    .status-pill.success .dot { background: var(--green); }
    .status-pill.progress { background: #fef3c7; color: #92400e; }
    .status-pill.progress .dot { background: var(--amber); }
    .status-pill.queued   { background: #fef9c3; color: #854d0e; }
    .status-pill.queued .dot { background: #eab308; }
    .status-pill.failed   { background: #fee2e2; color: #b91c1c; }
    .status-pill.failed .dot { background: var(--red); }

    .act-time { font-size: 13px; color: var(--text-2); white-space: nowrap; }

    .activity-footer {
        display: flex; flex-wrap: wrap;
        align-items: center; justify-content: space-between;
        gap: 12px; padding: 14px 0 0 0;
        border-top: 1px solid #eef2f6;
        margin-top: 14px;
    }
    .act-showing { font-size: 12.5px; color: var(--text-2); }
    .act-showing strong { color: var(--text); font-weight: 700; }

    .act-pagination { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
    .page-btn {
        min-width: 30px; height: 30px; padding: 0 8px;
        border-radius: 6px; border: none;
        background: transparent; color: var(--text-2);
        font-size: 12.5px; font-weight: 700;
        cursor: pointer; transition: all 0.15s;
        display: inline-flex; align-items: center; justify-content: center;
        font-family: inherit;
    }
    .page-btn:hover:not(:disabled) { background: var(--green-light); color: #15803d; }
    .page-btn.active { background: var(--green); color: #fff; }
    .page-btn:disabled { color: #cbd5e1; cursor: not-allowed; }
    .page-ellipsis { color: #cbd5e1; padding: 0 4px; font-size: 12.5px; }

    @media (max-width: 760px) {
        .activity-table thead th:nth-child(2),
        .activity-table tbody td:nth-child(2) { display: none; }
        .act-user-name, .act-user-sub { max-width: 160px; }
    }

    @media (max-width: 991.98px) {
        .gf-navbar { padding: 0 16px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
    }
    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-logo { width: 38px; height: 38px; font-size: 20px; }
        .gf-navbar-title { font-size: 17px; }
        .main { padding: calc(var(--navbar-height) + 16px) 12px 60px; }
        .page-head h1 { font-size: 20px; }
    }
</style>

<!-- NAVBAR -->
<nav class="gf-navbar">
    <a href="<?php echo BASE_URL; ?>/app/manager/dashboard.php" class="gf-navbar-brand">
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
        <li><a href="dashboard.php" class="<?php echo $isDashboard ? 'active' : ''; ?>">Dashboard</a></li>
        <li><a href="<?php echo BASE_URL; ?>/app/users/dashboard.php">User Management</a></li>
        <li><a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php">Inventory</a></li>
        <li><a href="<?php echo BASE_URL; ?>/app/goats/farmer-overview.php">Farmers</a></li>
        <li><a href="<?php echo BASE_URL; ?>/app/auth/signout.php" class="logout">Logout</a></li>
    </ul>
    <button class="gf-navbar-toggle" type="button" onclick="toggleSidebar();" aria-label="Menu">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
    </button>
</nav>

<aside class="gf-sidebar" id="gfSidebar">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon">🐐</div>
            <div>
                <div class="gf-brand-text">GoatPro</div>
             
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" onclick="closeSidebar();">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
    <nav class="gf-nav-section">
        <a href="dashboard.php" class="gf-nav-link active"><span>Dashboard</span></a>
        <a href="<?php echo BASE_URL; ?>/app/users/dashboard.php" class="gf-nav-link"><span>User Management</span></a>
        <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php" class="gf-nav-link"><span>Inventory</span></a>
        <a href="<?php echo BASE_URL; ?>/app/goats/farmer-overview.php" class="gf-nav-link"><span>Farmers</span></a>
        <a href="<?php echo BASE_URL; ?>/app/auth/signout.php" class="gf-nav-link logout"><span>Logout</span></a>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- MAIN -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="main">

            <!-- PAGE HEADER -->
            <div class="page-head">
                <div>
                    <h1>Manager Dashboard</h1>
                    <p>Welcome back, <?php echo $sessionEmailShort; ?></p>
                </div>
                <div class="date-info"><?php echo date('D, M j, Y'); ?></div>
            </div>

            <!-- STAT STRIP -->
            <div class="stat-strip">
                <div class="stat-cell">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?php echo number_format($totalUsers); ?></div>
                    <div class="stat-note">Regular users</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-label">Unverified</div>
                    <div class="stat-value"><?php echo number_format($unverifiedUsers); ?></div>
                    <div class="stat-note">Pending approval</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-label">Active Goats</div>
                    <div class="stat-value"><?php echo number_format($totalActiveGoats); ?></div>
                    <div class="stat-note">All farmers</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-label">Sold</div>
                    <div class="stat-value"><?php echo number_format($soldGoatsCount); ?></div>
                    <div class="stat-note">Total sold</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-label">Deceased</div>
                    <div class="stat-value"><?php echo number_format($deceasedGoatsCount); ?></div>
                    <div class="stat-note">Total deceased</div>
                </div>
            </div>

            <!-- GOAT INVENTORY -->
            <h2 class="section-head">
                <i class="fas fa-goat" style="color:var(--green);"></i>
                Goat Inventory Breakdown
            </h2>

            <div class="goat-stat-strip">
                <!-- Total Goats -->
                <div class="goat-stat-cell">
                    <div class="goat-stat-label">Total Goats</div>
                    <div class="goat-stat-value">
                        <?php echo number_format($totalTracked); ?>
                        <span class="goat-stat-suffix">registered</span>
                    </div>
                </div>

                <!-- Active -->
                <div class="goat-stat-cell">
                    <div class="goat-stat-label">
                        <span class="dot"></span>
                        Active
                    </div>
                    <div class="goat-stat-value">
                        <?php echo number_format($totalActiveGoats); ?>
                        <span class="goat-stat-suffix green"><?php echo $activePct; ?>%</span>
                    </div>
                </div>

                <!-- Male / Female -->
                <div class="goat-stat-cell">
                    <div class="goat-stat-label">Male / Female</div>
                    <div class="goat-stat-value">
                        <?php echo $goatSexCounts['male']; ?> / <?php echo $goatSexCounts['female']; ?>
                        <span class="goat-stat-suffix"><?php echo $malePct; ?>% bucks</span>
                    </div>
                </div>

                <!-- Healthy -->
                <div class="goat-stat-cell">
                    <div class="goat-stat-label">Healthy</div>
                    <div class="goat-stat-value green">
                        <?php echo number_format($goatHealthCounts['healthy']); ?>
                        <span class="goat-stat-suffix green">(<?php echo $healthyPct; ?>%)</span>
                        <span class="goat-stat-suffix" style="font-weight:600;">· <?php echo $sickCount; ?> sick</span>
                    </div>
                </div>

                <!-- Sold -->
                <div class="goat-stat-cell">
                    <div class="goat-stat-label">Sold</div>
                    <div class="goat-stat-value orange">
                        <?php echo number_format($soldGoatsCount); ?>
                        <span class="goat-stat-suffix orange">recorded</span>
                    </div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Daily Activity Trend</h3>
                    <p class="chart-sub">Last 7 days · manager and user activities</p>
                    <div class="chart-wrap">
                        <canvas id="dailyActivityChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Top Actions</h3>
                    <p class="chart-sub">Last 30 days · aggregated frequency</p>
                    <div class="chart-wrap">
                        <canvas id="actionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- RECENT GOATS -->
            <div class="card">
                <div class="card-title">
                    <span><i class="fas fa-clock"></i> Recently Updated Goat Records</span>
                    <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php" class="btn btn-primary">
                        View Full Inventory
                    </a>
                </div>
                <?php if (empty($recentGoats)): ?>
                    <div style="text-align:center;padding:40px 20px;color:var(--text-3);">
                        <i class="fas fa-goat" style="font-size:40px;color:#bbf7d0;display:block;margin-bottom:10px;"></i>
                        <p style="font-size:13.5px;margin:0;">No goat records yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Goat ID</th>
                                    <th>Name</th>
                                    <th>Farmer</th>
                                    <th>Status</th>
                                    <th>Health</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentGoats as $g): ?>
                                <tr>
                                    <td><a href="<?php echo BASE_URL; ?>/app/goats/goat-view.php?goat_id=<?php echo urlencode($g['goat_id']); ?>" style="color:var(--green);font-weight:700;text-decoration:none;"><?php echo htmlspecialchars($g['goat_id']); ?></a></td>
                                    <td style="color:var(--text);font-weight:600;"><?php echo htmlspecialchars($g['goat_name']); ?></td>
                                    <td><?php echo htmlspecialchars($g['owner_name']); ?></td>
                                    <td><span class="badge <?php echo goatStatusBadgeClass($g['status']); ?>"><?php echo goatStatusLabel($g['status']); ?></span></td>
                                    <td><span class="badge <?php echo healthStatusBadgeClass($g['health_status']); ?>"><?php echo healthStatusLabel($g['health_status']); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($g['updated_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- USER ACTIVITY LOGS -->
            <h2 class="section-head">
                <i class="fas fa-history" style="color:var(--green);"></i>
                User Activity Logs
                <span class="count-badge" id="activityCount">—</span>
            </h2>

            <div class="card">
                <!-- TOOLBAR -->
                <div class="activity-toolbar">
                    <div class="act-search">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <input type="text" id="actSearch" placeholder="Search by email or action...">
                        <span class="kbd">/</span>
                    </div>

                    <select class="act-select" id="filterRole">
                        <option value="">All Roles</option>
                        <option value="user">User</option>
                        <option value="manager">Manager</option>
                    </select>

                    <select class="act-select" id="filterStatus">
                        <option value="">All Statuses</option>
                        <option value="success">Success</option>
                        <option value="failed">Failed</option>
                    </select>

                    <select class="act-select" id="filterDate">
                        <option value="">All Dates</option>
                        <option value="today">Today</option>
                        <option value="7days">Last 7 Days</option>
                        <option value="30days">Last 30 Days</option>
                        <option value="90days">Last 90 Days</option>
                    </select>

                    <select class="act-select" id="filterLength">
                        <option value="5">Show: 5</option>
                        <option value="10">Show: 10</option>
                        <option value="25">Show: 25</option>
                        <option value="50">Show: 50</option>
                        <option value="100">Show: 100</option>
                    </select>
                </div>

                <!-- TABLE -->
                <div class="activity-table-wrap">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="user">User / Initiator <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></th>
                                <th class="sortable" data-sort="role">Role <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></th>
                                <th class="sortable" data-sort="action">Action <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></th>
                                <th class="sortable" data-sort="status">Status <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></th>
                                <th class="sortable" data-sort="created_at">Timestamp <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></th>
                            </tr>
                        </thead>
                        <tbody id="activityBody">
                            <tr>
                                <td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">
                                    <div style="font-size:13px;">Loading activities...</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- FOOTER -->
                <div class="activity-footer">
                    <div class="act-showing" id="actShowing">
                        Showing <strong>0</strong> to <strong>0</strong> of <strong>0</strong> events
                    </div>
                    <div class="act-pagination" id="actPagination"></div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() { document.body.classList.toggle('gf-sidebar-open'); }
function closeSidebar() { document.body.classList.remove('gf-sidebar-open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

// ============================================================
// CHARTS
// ============================================================
const dailyCtx = document.getElementById('dailyActivityChart').getContext('2d');
const dailyData = <?php echo json_encode($dailyActivity); ?>;
new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: dailyData.map(d => d.date),
        datasets: [{
            data: dailyData.map(d => d.count),
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22,163,74,0.08)',
            borderWidth: 2, tension: 0.35, fill: true,
            pointRadius: 4, pointBackgroundColor: '#16a34a',
            pointBorderColor: '#ffffff', pointBorderWidth: 2,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9', drawBorder: false }, ticks: { color: '#94a3b8', font: { size: 11 }, padding: 6 } },
            x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 }, padding: 6 } }
        }
    }
});

const actionCtx = document.getElementById('actionChart').getContext('2d');
const actionData = <?php echo json_encode($actionStats); ?>;
const actionPalette = ['#16a34a','#22c55e','#4ade80','#86efac','#facc15','#3b82f6','#8b5cf6','#ec4899'];
new Chart(actionCtx, {
    type: 'bar',
    data: {
        labels: actionData.map(d => (d.action || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())),
        datasets: [{
            data: actionData.map(d => +d.count),
            backgroundColor: actionPalette.slice(0, actionData.length),
            borderRadius: 6, borderSkipped: false
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9', drawBorder: false }, ticks: { color: '#94a3b8', font: { size: 11 }, padding: 6 } },
            x: {
                grid: { display: false },
                ticks: {
                    color: '#64748b', font: { size: 10 }, padding: 6,
                    autoSkip: false, maxRotation: 30, minRotation: 0,
                    callback: function (value) {
                        const label = this.getLabelForValue(value);
                        if (label.length > 12) {
                            const words = label.split(' ');
                            if (words.length > 1) return words;
                            return label.match(/.{1,12}/g) || label;
                        }
                        return label;
                    }
                }
            }
        }
    }
});

// ============================================================
// USER ACTIVITY LOGS
// ============================================================
(function() {
    'use strict';

    const state = {
        rows: [], filtered: [], page: 1, perPage: 5,
        sortKey: 'created_at', sortDir: 'desc',
        search: '', role: '', status: '', date: ''
    };

    function getInitials(name) {
        if (!name) return '?';
        const clean = name.replace(/[^a-zA-Z0-9 ]/g, ' ').trim();
        if (!clean) return '?';
        const parts = clean.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
        return clean.substring(0, 2).toUpperCase();
    }

    function avatarColor(seed) {
        const palette = ['pink', 'blue', 'green', 'purple', 'amber', 'slate'];
        let hash = 0;
        const s = String(seed || 'x');
        for (let i = 0; i < s.length; i++) { hash = ((hash << 5) - hash) + s.charCodeAt(i); hash |= 0; }
        return palette[Math.abs(hash) % palette.length];
    }

    function formatAction(action) {
        if (!action) return 'Unknown';
        return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function roleClass(role) {
        const r = (role || '').toLowerCase();
        if (r === 'admin')   return 'admin';
        if (r === 'manager') return 'manager';
        if (r === 'user')    return 'user';
        return 'system';
    }

    function statusInfo(status) {
        const s = (status || '').toLowerCase();
        if (s === 'success') return { cls: 'success', label: 'Success' };
        if (s === 'in_progress' || s === 'processing') return { cls: 'progress', label: 'In Progress' };
        if (s === 'queued' || s === 'pending') return { cls: 'queued', label: 'Queued' };
        if (s === 'failed' || s === 'error') return { cls: 'failed', label: 'Failed' };
        return { cls: 'success', label: 'Success' };
    }

    function formatTimestamp(dateStr) {
        if (!dateStr) return '-';
        try {
            const d = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(d.getTime())) return dateStr;
            const mm = d.getMonth() + 1, dd = d.getDate(), yyyy = d.getFullYear();
            let hh = d.getHours();
            const min = String(d.getMinutes()).padStart(2, '0');
            const ss = String(d.getSeconds()).padStart(2, '0');
            const ampm = hh >= 12 ? 'PM' : 'AM';
            hh = hh % 12 || 12;
            return `${mm}/${dd}/${yyyy}, ${hh}:${min}:${ss} ${ampm}`;
        } catch (e) { return dateStr; }
    }

    function parseDate(dateStr) {
        if (!dateStr) return null;
        const d = new Date(dateStr.replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function loadData() {
        fetch('<?php echo BASE_URL; ?>/api/get-activity-logs.php', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(json => {
            let rows = Array.isArray(json) ? json : (json.data || []);
            rows = rows.filter(r => (r.user_role || '').toLowerCase() !== 'admin');
            state.rows = rows;
            applyFilters();
        })
        .catch(err => {
            console.error('Failed to load activities:', err);
            document.getElementById('activityBody').innerHTML =
                '<tr><td colspan="5" style="text-align:center;padding:40px;color:#ef4444;">' +
                '<div style="font-size:13px;">Failed to load activities.</div></td></tr>';
        });
    }

    function applyFilters() {
        let rows = state.rows.slice();

        if (state.search) {
            const q = state.search.toLowerCase();
            rows = rows.filter(r => {
                const hay = [r.email, r.full_name, r.action, r.user_role, r.status].filter(Boolean).join(' ').toLowerCase();
                return hay.includes(q);
            });
        }
        if (state.role)   rows = rows.filter(r => (r.user_role || '').toLowerCase() === state.role);
        if (state.status) rows = rows.filter(r => (r.status || '').toLowerCase().replace(/\s+/g, '_') === state.status);
        if (state.date) {
            const now = new Date();
            rows = rows.filter(r => {
                const d = parseDate(r.created_at);
                if (!d) return false;
                const diffDays = (now - d) / (1000 * 60 * 60 * 24);
                if (state.date === 'today')  return d.toDateString() === now.toDateString();
                if (state.date === '7days')  return diffDays <= 7;
                if (state.date === '30days') return diffDays <= 30;
                if (state.date === '90days') return diffDays <= 90;
                return true;
            });
        }

        rows.sort((a, b) => {
            const k = state.sortKey;
            let va, vb;
            if (k === 'user') {
                va = (a.full_name || a.email || '').toLowerCase();
                vb = (b.full_name || b.email || '').toLowerCase();
            } else if (k === 'created_at') {
                va = parseDate(a.created_at) || new Date(0);
                vb = parseDate(b.created_at) || new Date(0);
            } else {
                va = String(a[k] || '').toLowerCase();
                vb = String(b[k] || '').toLowerCase();
            }
            if (va < vb) return state.sortDir === 'asc' ? -1 : 1;
            if (va > vb) return state.sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        state.filtered = rows;
        state.page = 1;
        render();
    }

    function render() {
        const total = state.filtered.length;
        const perPage = state.perPage;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (state.page > totalPages) state.page = totalPages;

        const start = (state.page - 1) * perPage;
        const end = Math.min(start + perPage, total);
        const pageRows = state.filtered.slice(start, end);
        const tbody = document.getElementById('activityBody');

        const countBadge = document.getElementById('activityCount');
        if (countBadge) {
            const totalAll = state.rows.length;
            countBadge.textContent = total === totalAll ? totalAll + ' total' : total + ' of ' + totalAll;
        }

        if (total === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">' +
                '<div style="font-size:13px;">No activity logs found</div></td></tr>';
        } else {
            tbody.innerHTML = pageRows.map(r => {
                const displayName = r.full_name || r.email || 'System';
                const subLabel = r.full_name && r.email ? r.email : (r.user_role ? r.user_role.charAt(0).toUpperCase() + r.user_role.slice(1) : 'User');
                const initials = getInitials(r.full_name || r.email || 'S');
                const avColor = avatarColor(r.email || r.full_name);
                const rc = roleClass(r.user_role);
                const roleLabel = (r.user_role || 'system').toUpperCase();
                const si = statusInfo(r.status);
                const ts = formatTimestamp(r.created_at);

                return `<tr>
                    <td>
                        <div class="act-user">
                            <div class="act-avatar ${avColor}">${esc(initials)}</div>
                            <div class="act-user-info">
                                <div class="act-user-name">${esc(displayName)}</div>
                                <div class="act-user-sub">${esc(subLabel)}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="role-pill ${rc}">${esc(roleLabel)}</span></td>
                    <td><span class="act-action">${esc(formatAction(r.action))}</span></td>
                    <td><span class="status-pill ${si.cls}"><span class="dot"></span>${esc(si.label)}</span></td>
                    <td><span class="act-time">${esc(ts)}</span></td>
                </tr>`;
            }).join('');
        }

        document.getElementById('actShowing').innerHTML = total === 0
            ? 'Showing <strong>0</strong> to <strong>0</strong> of <strong>0</strong> events'
            : `Showing <strong>${start + 1}</strong> to <strong>${end}</strong> of <strong>${total}</strong> events`;

        renderPagination(totalPages, total);
    }

    function renderPagination(totalPages, total) {
        const wrap = document.getElementById('actPagination');
        if (total === 0) { wrap.innerHTML = ''; return; }

        let html = `<button class="page-btn" data-page="prev" ${state.page === 1 ? 'disabled' : ''}>Previous</button>`;
        buildPageList(state.page, totalPages).forEach(p => {
            html += p === '...'
                ? `<span class="page-ellipsis">…</span>`
                : `<button class="page-btn ${p === state.page ? 'active' : ''}" data-page="${p}">${p}</button>`;
        });
        html += `<button class="page-btn" data-page="next" ${state.page === totalPages ? 'disabled' : ''}>Next</button>`;
        wrap.innerHTML = html;
    }

    function buildPageList(current, total) {
        const pages = [];
        if (total <= 7) { for (let i = 1; i <= total; i++) pages.push(i); return pages; }
        pages.push(1);
        if (current > 3) pages.push('...');
        const start = Math.max(2, current - 1);
        const end = Math.min(total - 1, current + 1);
        for (let i = start; i <= end; i++) pages.push(i);
        if (current < total - 2) pages.push('...');
        pages.push(total);
        return pages;
    }

    function bindEvents() {
        let t;
        document.getElementById('actSearch').addEventListener('input', e => {
            clearTimeout(t);
            t = setTimeout(() => { state.search = e.target.value.trim(); applyFilters(); }, 180);
        });
        document.addEventListener('keydown', e => {
            if (e.key === '/' && !['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
                e.preventDefault();
                document.getElementById('actSearch').focus();
            }
        });
        document.getElementById('filterRole').addEventListener('change', e => { state.role = e.target.value; applyFilters(); });
        document.getElementById('filterStatus').addEventListener('change', e => { state.status = e.target.value; applyFilters(); });
        document.getElementById('filterDate').addEventListener('change', e => { state.date = e.target.value; applyFilters(); });
        document.getElementById('filterLength').addEventListener('change', e => { state.perPage = parseInt(e.target.value) || 5; state.page = 1; render(); });

        document.querySelectorAll('.activity-table th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.getAttribute('data-sort');
                if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                else { state.sortKey = key; state.sortDir = key === 'created_at' ? 'desc' : 'asc'; }
                applyFilters();
            });
        });

        document.getElementById('actPagination').addEventListener('click', e => {
            const btn = e.target.closest('.page-btn');
            if (!btn || btn.disabled) return;
            const p = btn.getAttribute('data-page');
            const totalPages = Math.max(1, Math.ceil(state.filtered.length / state.perPage));
            if (p === 'prev') state.page = Math.max(1, state.page - 1);
            else if (p === 'next') state.page = Math.min(totalPages, state.page + 1);
            else state.page = parseInt(p);
            render();
        });
    }

    document.addEventListener('DOMContentLoaded', () => { bindEvents(); loadData(); });
})();
</script>

<?php renderFooter(); ?>