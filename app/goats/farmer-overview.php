<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-functions.php';
requireAnyRole(['manager', 'admin']);

$currentRole = $_SESSION['role'];

$search = trim($_GET['q'] ?? '');

$where = "WHERE u.role = 'user'";
$params = [];
if ($search !== '') {
    $where .= " AND (u.full_name LIKE :search OR u.username LIKE :search OR u.email LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$sql = "
    SELECT
        u.id, u.full_name, u.username, u.email,
        COUNT(CASE WHEN g.status = 'active' THEN 1 END) AS active_count,
        COUNT(CASE WHEN g.status = 'active' AND g.sex = 'male' THEN 1 END) AS active_male,
        COUNT(CASE WHEN g.status = 'active' AND g.sex = 'female' THEN 1 END) AS active_female,
        COUNT(CASE WHEN g.status = 'active' AND g.health_status = 'healthy' THEN 1 END) AS healthy_count,
        COUNT(CASE WHEN g.status = 'active' AND g.health_status = 'sick' THEN 1 END) AS sick_count,
        COUNT(CASE WHEN g.status = 'sold' THEN 1 END) AS sold_count,
        COUNT(CASE WHEN g.status = 'deceased' THEN 1 END) AS deceased_count
    FROM users u
    LEFT JOIN goats g ON g.user_id = u.id
    $where
    GROUP BY u.id, u.full_name, u.username, u.email
    ORDER BY active_count DESC, u.full_name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalFarmers = count($pdo->query("SELECT id FROM users WHERE role = 'user'")->fetchAll());
$totalActiveGoats = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'active'")->fetchColumn();

$title = 'Farmer Inventory Overview';
renderHeader($title);
$isFarmerOverview = true;
?>

<!-- ============================================================
     ICON HELPER
============================================================ -->
<?php
if (!function_exists('icon')) {
    function icon(string $name, int $size = 16, string $class = '', string $strokeWidth = '2'): string
    {
        static $paths = [
            'menu'          => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'x'             => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'calendar'      => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
            'home'          => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user-plus'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'list-check'    => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m15 9 2 2 4-4"/>',
            'tractor'       => '<path d="m10 11 11 .9a1 1 0 0 1 .9 1v1.6a1 1 0 0 1-1 1H15"/><path d="M3 4h9l1 7"/><path d="M4 11V4"/><circle cx="7" cy="17" r="3"/><circle cx="18" cy="17" r="3"/>',
            'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'eye'           => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'activity'      => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        ];

        $path = $paths[$name] ?? $paths['home'];
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%2$s" stroke-linecap="round" stroke-linejoin="round"%3$s aria-hidden="true" style="display:inline-block;vertical-align:middle;flex-shrink:0;">%4$s</svg>',
            $size,
            htmlspecialchars($strokeWidth, ENT_QUOTES, 'UTF-8'),
            $classAttr,
            $path
        );
    }
}
?>

<style>
    /* ==========================================================
       DESIGN TOKENS
    ========================================================== */
    :root {
        --gf-primary: #16a34a;
        --gf-primary-dark: #14532d;
        --gf-primary-mid: #15803d;
        --gf-primary-light: #dcfce7;
        --gf-primary-lighter: #f0fdf4;

        --gf-success: #22c55e;
        --gf-warning: #f59e0b;
        --gf-danger: #ef4444;
        --gf-info: #3b82f6;

        --gf-bg: #f1f5f9;
        --gf-surface: #ffffff;
        --gf-text: #0f172a;
        --gf-text-secondary: #475569;
        --gf-text-muted: #94a3b8;
        --gf-border: #e2e8f0;

        --gf-navbar-height: 68px;
        --gf-sidebar-width: 264px;
        --gf-radius-lg: 18px;
        --gf-radius: 14px;
        --gf-radius-sm: 10px;

        --gf-shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
        --gf-shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
        --gf-shadow-md: 0 4px 12px rgba(15, 23, 42, 0.06), 0 2px 4px rgba(15, 23, 42, 0.04);
        --gf-shadow-lg: 0 12px 28px rgba(15, 23, 42, 0.10), 0 4px 10px rgba(15, 23, 42, 0.06);

        --gf-ease: cubic-bezier(0.4, 0, 0.2, 1);
        --gf-ease-out: cubic-bezier(0.16, 1, 0.3, 1);
    }

    *, *::before, *::after { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: var(--gf-bg);
        color: var(--gf-text);
        font-family: system-ui, -apple-system, BlinkMacSystemFont,
            "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 15px;
        line-height: 1.5;
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }

    img, svg { max-width: 100%; height: auto; display: block; }
    button { font-family: inherit; }

    :focus-visible {
        outline: 2px solid var(--gf-primary);
        outline-offset: 2px;
        border-radius: 4px;
    }

    .gf-skip-link {
        position: absolute;
        top: -100px;
        left: 16px;
        z-index: 10000;
        padding: 10px 16px;
        background: var(--gf-primary-dark);
        color: #fff;
        text-decoration: none;
        border-radius: var(--gf-radius-sm);
        font-weight: 700;
        transition: top 0.2s var(--gf-ease);
    }
    .gf-skip-link:focus { top: 16px; }

    .main-content,
    .main-content .container {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: var(--gf-bg) !important;
        display: block !important;
    }

    /* ---------- NAVBAR ---------- */
    .gf-navbar {
        position: fixed;
        top: 0; left: 0; right: 0;
        height: var(--gf-navbar-height);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 24px;
        z-index: 1050;
        box-shadow: 0 2px 12px rgba(15, 23, 42, 0.15);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .gf-navbar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: #fff;
        flex-shrink: 0;
    }

    .gf-navbar-logo {
        width: 46px; height: 46px;
        border-radius: 13px;
        background: rgba(255, 255, 255, 0.14);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.16);
        display: grid;
        place-items: center;
        font-size: 24px;
        flex-shrink: 0;
        overflow: hidden;
    }
    .gf-navbar-logo img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }

    .gf-navbar-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
    .gf-navbar-title { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
    .gf-navbar-sub {
        font-size: 10px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.65);
        text-transform: uppercase;
        letter-spacing: 0.12em;
        margin-top: 2px;
    }

    .gf-navbar-links {
        display: flex;
        align-items: center;
        gap: 4px;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .gf-navbar-links li a {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 16px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.82);
        text-decoration: none;
        transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease);
        white-space: nowrap;
    }

    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }

    .gf-navbar-links li a.active {
        background: #fff;
        color: var(--gf-primary-dark);
        font-weight: 700;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    }
    .gf-navbar-links li a.logout { color: #fca5a5; }
    .gf-navbar-links li a.logout:hover { background: rgba(220, 38, 38, 0.18); color: #fff; }

    .gf-navbar-toggle {
        display: none;
        width: 42px; height: 42px;
        place-items: center;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 10px;
        cursor: pointer;
        color: #fff;
        padding: 0;
        transition: background 0.15s var(--gf-ease);
    }
    .gf-navbar-toggle:hover { background: rgba(255, 255, 255, 0.2); }

    /* ---------- SIDEBAR ---------- */
    .gf-sidebar {
        position: fixed;
        top: 0; right: 0; bottom: 0;
        width: var(--gf-sidebar-width);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        padding: 24px 18px 40px;
        color: #fff;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        z-index: 1060;
        display: flex;
        flex-direction: column;
        transform: translateX(100%);
        transition: transform 0.32s var(--gf-ease-out);
        will-change: transform;
        box-shadow: -8px 0 32px rgba(0, 0, 0, 0.35);
    }
    body.gf-sidebar-open .gf-sidebar { transform: translateX(0); }

    .gf-sidebar-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 6px 8px 22px;
        margin-bottom: 14px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        flex-shrink: 0;
    }

    .gf-sidebar-close {
        width: 36px; height: 36px;
        display: grid;
        place-items: center;
        background: rgba(255, 255, 255, 0.1);
        border: none;
        border-radius: 10px;
        color: #fff;
        cursor: pointer;
        padding: 0;
        transition: background 0.15s var(--gf-ease);
    }
    .gf-sidebar-close:hover { background: rgba(255, 255, 255, 0.2); }

    .gf-brand { display: flex; align-items: center; gap: 12px; }
    .gf-brand-icon {
        width: 46px; height: 46px;
        border-radius: 13px;
        background: rgba(255, 255, 255, 0.14);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.16);
        display: grid;
        place-items: center;
        font-size: 24px;
        flex-shrink: 0;
    }
    .gf-brand-text { font-size: 16px; font-weight: 800; line-height: 1.2; letter-spacing: -0.01em; }
    .gf-brand-sub {
        font-size: 10px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.65);
        margin-top: 3px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }

    .gf-nav-section { display: flex; flex-direction: column; gap: 4px; flex: 1; overflow-y: auto; }

    .gf-nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 14px;
        border-radius: 11px;
        color: rgba(255, 255, 255, 0.82);
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease), transform 0.15s var(--gf-ease);
        position: relative;
        flex-shrink: 0;
    }
    .gf-nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; transform: translateX(-2px); }
    .gf-nav-link.active { background: #fff; color: var(--gf-primary-dark); font-weight: 700; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
    .gf-nav-link.active::before {
        content: '';
        position: absolute;
        right: -18px;
        top: 50%;
        transform: translateY(-50%);
        width: 4px;
        height: 60%;
        background: var(--gf-success);
        border-radius: 4px 0 0 4px;
    }
    .gf-nav-link.logout {
        color: #fca5a5;
        margin-top: 12px;
        padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 0;
    }
    .gf-nav-link.logout:hover { background: rgba(220, 38, 38, 0.15); color: #fff; border-radius: 11px; }

    /* ---------- BACKDROP ---------- */
    .gf-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        backdrop-filter: blur(2px);
        z-index: 1055;
        cursor: pointer;
    }
    body.gf-sidebar-open .gf-backdrop { display: block; animation: gfFadeIn 0.2s var(--gf-ease); }
    @keyframes gfFadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* ---------- MAIN ---------- */
    .gf-main {
        padding: calc(var(--gf-navbar-height) + 28px) clamp(20px, 3vw, 40px) 60px;
        min-height: 100vh;
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
        animation: gfFadeInUp 0.4s var(--gf-ease-out);
    }
    @keyframes gfFadeInUp {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ---------- PAGE HEADER ---------- */
    .gf-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }
    .gf-page-header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .gf-page-header h1 { margin: 0; font-size: 25px; font-weight: 800; letter-spacing: -0.025em; line-height: 1.15; }
    .gf-page-header .gf-welcome { margin: 5px 0 0; font-size: 13px; color: var(--gf-text-muted); }
    .gf-page-header .gf-welcome strong { color: var(--gf-primary-dark); font-weight: 700; }
    .gf-page-header-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .gf-date-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 15px;
        border-radius: 999px;
        background: #fff;
        border: 1px solid var(--gf-border);
        font-size: 12px;
        font-weight: 700;
        color: var(--gf-text-secondary);
        white-space: nowrap;
        box-shadow: var(--gf-shadow-xs);
    }
    .gf-date-badge svg { color: var(--gf-success); }

    /* ---------- STATS ---------- */
    .gf-stat-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }
    @media (max-width: 520px) { .gf-stat-row { grid-template-columns: 1fr; } }

    .gf-stat-box {
        background: #fff;
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius-sm);
        padding: 16px 18px;
        position: relative;
        transition: all 0.15s var(--gf-ease);
        box-shadow: var(--gf-shadow-xs);
    }
    .gf-stat-box:hover {
        border-color: #cbd5e1;
        box-shadow: var(--gf-shadow-md);
        transform: translateY(-2px);
    }
    .gf-stat-box .stat-label {
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        color: var(--gf-text-muted);
        margin-bottom: 10px;
    }
    .gf-stat-box .stat-value {
        font-size: 26px;
        font-weight: 800;
        color: var(--gf-text);
        line-height: 1;
        letter-spacing: -0.5px;
    }
    .gf-stat-box .stat-icon {
        position: absolute;
        top: 14px; right: 14px;
        width: 34px; height: 34px;
        border-radius: 9px;
        display: grid;
        place-items: center;
    }
    .gf-stat-box .stat-icon.blue  { background: #dbeafe; color: #2563eb; }
    .gf-stat-box .stat-icon.green { background: #dcfce7; color: #16a34a; }

    /* ---------- CARD ---------- */
    .gf-card {
        background: #fff;
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius-sm);
        padding: 20px 22px;
        box-shadow: var(--gf-shadow-xs);
        margin-bottom: 20px;
    }

    .gf-card-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
        font-weight: 700;
        color: var(--gf-text);
        margin: 0 0 18px;
        padding-bottom: 14px;
        border-bottom: 1px solid #eef2f6;
        flex-wrap: wrap;
    }
    .gf-card-title svg { color: var(--gf-primary); }
    .gf-card-title .gf-card-chip {
        margin-left: auto;
        font-size: 11.5px;
        padding: 4px 12px;
        border-radius: 999px;
        background: var(--gf-primary-light);
        color: var(--gf-primary-dark);
        font-weight: 700;
        letter-spacing: 0.3px;
    }

    /* ---------- SEARCH FORM ---------- */
    .gf-search-form {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: stretch;
    }

    .gf-search-input-wrap {
        position: relative;
        flex: 1 1 240px;
        min-width: 200px;
    }
    .gf-search-input-wrap svg {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--gf-text-muted);
        pointer-events: none;
    }
    .gf-search-input-wrap input {
        width: 100%;
        height: 42px;
        padding: 0 14px 0 40px;
        border: 1px solid var(--gf-border);
        border-radius: 10px;
        font-size: 14px;
        font-family: inherit;
        color: var(--gf-text);
        background: #fff;
        outline: none;
        transition: border-color 0.15s var(--gf-ease), box-shadow 0.15s var(--gf-ease);
    }
    .gf-search-input-wrap input:focus {
        border-color: var(--gf-primary);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }
    .gf-search-input-wrap input::placeholder { color: #cbd5e1; }

    /* ---------- BUTTONS ---------- */
    .gf-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: 42px;
        padding: 0 20px;
        border-radius: 10px;
        font-size: 13.5px;
        font-weight: 700;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.15s var(--gf-ease);
        font-family: inherit;
        white-space: nowrap;
    }
    .gf-btn-primary {
        background: linear-gradient(145deg, #22C55E, #15803D);
        color: #fff;
        box-shadow: 0 4px 10px rgba(34, 197, 94, 0.25);
    }
    .gf-btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(34, 197, 94, 0.3);
        color: #fff;
    }
    .gf-btn-secondary {
        background: #f1f5f9;
        color: var(--gf-text-secondary);
        border: 1px solid var(--gf-border);
    }
    .gf-btn-secondary:hover { background: #e2e8f0; color: var(--gf-text); }

    .gf-btn-xs {
        height: 30px;
        padding: 0 12px;
        font-size: 12px;
        border-radius: 7px;
        gap: 4px;
    }
    .gf-btn-xs svg { width: 12px; height: 12px; }

    .gf-btn-view {
        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }
    .gf-btn-view:hover { background: #bfdbfe; color: #1e40af; }

    /* ---------- TABLE ---------- */
    .gf-table-wrapper {
        overflow-x: auto;
        margin: 0 -22px;
        padding: 0 22px;
    }
    @media (max-width: 640px) {
        .gf-table-wrapper { margin: 0 -16px; padding: 0 16px; }
    }

    .gf-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
        min-width: 850px;
    }

    .gf-table thead th {
        text-align: left;
        padding: 12px 14px;
        background: #fafbfc;
        color: var(--gf-text-muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #eef2f6;
        white-space: nowrap;
    }

    .gf-table tbody td {
        padding: 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: var(--gf-text-secondary);
    }

    .gf-table tbody tr:last-child td { border-bottom: none; }
    .gf-table tbody tr:hover { background: #fafbfc; }

    .gf-table-farmer {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 200px;
    }
    .gf-table-avatar {
        width: 34px; height: 34px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-size: 12px;
        font-weight: 800;
        color: #fff;
        flex-shrink: 0;
        text-transform: uppercase;
        background: linear-gradient(135deg, #22C55E, #15803D);
    }
    .gf-table-farmer-info { min-width: 0; }
    .gf-table-farmer-name {
        font-weight: 700;
        color: var(--gf-text);
        font-size: 13.5px;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }
    .gf-table-farmer-sub {
        font-size: 11.5px;
        color: var(--gf-text-muted);
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }

    /* ---------- BADGES ---------- */
    .gf-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.2px;
        white-space: nowrap;
    }
    .gf-badge .dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .gf-badge.green      { background: #dcfce7; color: #166534; }
    .gf-badge.green .dot { background: #16a34a; }
    .gf-badge.amber      { background: #fef3c7; color: #92400e; }
    .gf-badge.amber .dot { background: #d97706; }
    .gf-badge.red        { background: #fee2e2; color: #b91c1c; }
    .gf-badge.red .dot   { background: #dc2626; }
    .gf-badge.slate      { background: #f1f5f9; color: #64748b; }
    .gf-badge.slate .dot { background: #94a3b8; }
    .gf-badge.blue       { background: #dbeafe; color: #1e40af; }
    .gf-badge.blue .dot  { background: #2563eb; }

    .gf-stat-pair {
        display: inline-flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }

    /* ---------- EMPTY STATE ---------- */
    .gf-empty {
        padding: 50px 20px;
        text-align: center;
        color: var(--gf-text-muted);
    }
    .gf-empty svg {
        margin: 0 auto 12px;
        color: #cbd5e1;
    }
    .gf-empty p {
        margin: 0;
        font-size: 13.5px;
        color: var(--gf-text-secondary);
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 991.98px) {
        .gf-navbar { padding: 0 16px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
        .gf-main { padding-top: calc(var(--gf-navbar-height) + 20px); }
    }
    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-logo { width: 38px; height: 38px; border-radius: 11px; font-size: 20px; }
        .gf-navbar-title { font-size: 17px; }
        .gf-navbar-sub { font-size: 9px; }
        .gf-main { padding: calc(var(--gf-navbar-height) + 14px) 16px 60px; }
        .gf-page-header { flex-direction: column; align-items: stretch; gap: 14px; }
        .gf-page-header h1 { font-size: 22px; }
        .gf-page-header-right { justify-content: space-between; }
        .gf-card { padding: 16px 14px; }
        .gf-table-wrapper { margin: 0 -14px; padding: 0 14px; }
        .gf-search-form { flex-direction: column; }
        .gf-search-input-wrap { flex: 1 1 auto; }
        .gf-search-form .gf-btn { width: 100%; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
        }
    }
</style>

<a href="#main-content" class="gf-skip-link">Skip to main content</a>

<!-- ============================================================
     NAVBAR
============================================================ -->
<nav class="gf-navbar" aria-label="Main navigation">
    <a href="<?php echo BASE_URL; ?>/app/<?php echo $currentRole; ?>/dashboard.php" class="gf-navbar-brand">
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
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'farmers'); ?>
    </ul>

    <button class="gf-navbar-toggle" type="button" aria-label="Open menu"
            aria-controls="gfSidebar" aria-expanded="false" onclick="toggleSidebar();">
        <?php echo icon('menu', 22); ?>
    </button>
</nav>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<aside class="gf-sidebar" id="gfSidebar" aria-label="Main navigation">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon" aria-hidden="true">
                <?php if (file_exists(__DIR__ . '/../../assets/img/goatpro-logo.png')): ?>
                    <img src="<?php echo BASE_URL; ?>/assets/img/goatpro-logo.png" alt="" style="width:100%;height:100%;object-fit:contain;padding:5px;">
                <?php else: ?>
                    🐐
                <?php endif; ?>
            </div>
            <div>
                <div class="gf-brand-text">GoatPro</div>
                <div class="gf-brand-sub"><?php echo ucfirst($currentRole); ?></div>
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" aria-label="Close menu" onclick="closeSidebar();">
            <?php echo icon('x', 18); ?>
        </button>
    </div>

    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'farmers'); ?>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- ============================================================
     MAIN
============================================================ -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="gf-main">

            <!-- ===== PAGE HEADER ===== -->
            <header class="gf-page-header">
                <div class="gf-page-header-left">
                    <div>
                        <h1>Farmer Inventory Overview</h1>
                        <p class="gf-welcome">
                            Overview of all farmers and their goat inventory
                        </p>
                    </div>
                </div>
                <div class="gf-page-header-right">
                    <span class="gf-date-badge">
                        <?php echo icon('calendar', 14); ?>
                        <?php echo date('M d, Y'); ?>
                    </span>
                </div>
            </header>

            <!-- ===== SUMMARY STATS ===== -->
            <div class="gf-stat-row">
                <div class="gf-stat-box">
                    <div class="stat-label">Total Registered Farmers</div>
                    <div class="stat-value"><?php echo number_format($totalFarmers); ?></div>
                    <span class="stat-icon blue"><?php echo icon('users', 16); ?></span>
                </div>
                <div class="gf-stat-box">
                    <div class="stat-label">Total Active Goats</div>
                    <div class="stat-value"><?php echo number_format($totalActiveGoats); ?></div>
                    <span class="stat-icon green"><?php echo icon('check-circle', 16); ?></span>
                </div>
            </div>

            <!-- ===== SEARCH FORM ===== -->
            <div class="gf-card">
                <h2 class="gf-card-title">
                    <?php echo icon('search', 16); ?>
                    Search Farmers
                </h2>

                <form method="GET" class="gf-search-form">
                    <div class="gf-search-input-wrap">
                        <?php echo icon('search', 16); ?>
                        <input type="text" id="q" name="q"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name, username, or email…">
                    </div>

                    <button type="submit" class="gf-btn gf-btn-primary">
                        <?php echo icon('search', 14); ?>
                        Search
                    </button>

                    <?php if ($search !== ''): ?>
                        <a href="<?php echo BASE_URL; ?>/app/goats/farmer-overview.php" class="gf-btn gf-btn-secondary">
                            <?php echo icon('x', 14); ?>
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- ===== FARMERS TABLE ===== -->
            <div class="gf-card">
                <h2 class="gf-card-title">
                    <?php echo icon('tractor', 16); ?>
                    Farmer Inventory List
                    <span class="gf-card-chip">
                        <?php echo count($farmers); ?> farmer<?php echo count($farmers) !== 1 ? 's' : ''; ?> found
                    </span>
                </h2>

                <?php if (empty($farmers)): ?>
                    <div class="gf-empty">
                        <?php echo icon('users', 44); ?>
                        <p>No farmers found matching your search.</p>
                    </div>
                <?php else: ?>
                    <div class="gf-table-wrapper">
                        <table class="gf-table">
                            <thead>
                                <tr>
                                    <th>Farmer</th>
                                    <th>Active Goats</th>
                                    <th>Male / Female</th>
                                    <th>Healthy / Sick</th>
                                    <th>Sold</th>
                                    <th>Deceased</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($farmers as $f):
                                    $initials = strtoupper(substr($f['full_name'] ?: $f['username'], 0, 1));
                                    if (!empty($f['full_name'])) {
                                        $parts = preg_split('/\s+/', trim($f['full_name']));
                                        if (count($parts) >= 2) {
                                            $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                                        }
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="gf-table-farmer">
                                            <div class="gf-table-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                            <div class="gf-table-farmer-info">
                                                <div class="gf-table-farmer-name"><?php echo htmlspecialchars($f['full_name']); ?></div>
                                                <div class="gf-table-farmer-sub">@<?php echo htmlspecialchars($f['username']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="gf-badge green">
                                            <span class="dot"></span>
                                            <?php echo (int)$f['active_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="gf-stat-pair">
                                            <?php echo (int)$f['active_male']; ?> <span style="color:var(--gf-text-muted);">/</span> <?php echo (int)$f['active_female']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="gf-stat-pair">
                                            <span class="gf-badge green">
                                                <span class="dot"></span>
                                                <?php echo (int)$f['healthy_count']; ?>
                                            </span>
                                            <span class="gf-badge red">
                                                <span class="dot"></span>
                                                <?php echo (int)$f['sick_count']; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="gf-badge amber">
                                            <span class="dot"></span>
                                            <?php echo (int)$f['sold_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="gf-badge slate">
                                            <span class="dot"></span>
                                            <?php echo (int)$f['deceased_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php?farmer_id=<?php echo $f['id']; ?>"
                                           class="gf-btn gf-btn-xs gf-btn-view">
                                            <?php echo icon('eye', 12); ?>
                                            View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<script>
function toggleSidebar() {
    document.body.classList.toggle('gf-sidebar-open');
    var isOpen = document.body.classList.contains('gf-sidebar-open');
    var toggleBtn = document.querySelector('.gf-navbar-toggle');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}
function closeSidebar() {
    document.body.classList.remove('gf-sidebar-open');
    var toggleBtn = document.querySelector('.gf-navbar-toggle');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
});
</script>

<?php renderFooter(); ?>