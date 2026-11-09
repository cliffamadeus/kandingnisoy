<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/activity-logger.php';
require_once '../../includes/goat-functions.php';
requireRole('admin');

// ---- Goat Inventory summary ----
$goatsTableExists = $pdo->query("SHOW TABLES LIKE 'goats'")->fetch();
$totalActiveGoats = 0;
$totalSoldGoats = 0;
$totalDeceasedGoats = 0;
$totalFarmersWithGoats = 0;
if ($goatsTableExists) {
    $totalActiveGoats = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'active'")->fetchColumn();
    $totalSoldGoats = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'sold'")->fetchColumn();
    $totalDeceasedGoats = (int)$pdo->query("SELECT COUNT(*) FROM goats WHERE status = 'deceased'")->fetchColumn();
    $totalFarmersWithGoats = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM goats")->fetchColumn();
}

// Get statistics
$totalUsers        = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins       = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$totalManagers     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn();
$totalRegularUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$unverifiedUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_verified = 0")->fetchColumn();

$stmt = $pdo->query("
    SELECT DATE(created_at) as date, COUNT(*) as count 
    FROM activity_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$dailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT action, COUNT(*) as count 
    FROM activity_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY action 
    ORDER BY count DESC 
    LIMIT 5
");
$actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Farmers list ----
$farmersList = $pdo->query("
    SELECT 
        u.id, u.full_name, u.email, u.role, u.is_verified, u.created_at,
        COUNT(g.id) AS total_goats,
        SUM(CASE WHEN g.status = 'active'   THEN 1 ELSE 0 END) AS active_goats,
        SUM(CASE WHEN g.status = 'sold'     THEN 1 ELSE 0 END) AS sold_goats,
        SUM(CASE WHEN g.status = 'deceased' THEN 1 ELSE 0 END) AS deceased_goats
    FROM users u
    LEFT JOIN goats g ON g.user_id = u.id
    GROUP BY u.id
    ORDER BY total_goats DESC, u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sessionEmail = $_SESSION['email'] ?? '';
$sessionEmailShort = htmlspecialchars(substr($sessionEmail, 0, 20)) . (strlen($sessionEmail) > 20 ? '...' : '');

$title = 'Admin Dashboard';
renderHeader($title);
$isDashboard = (basename($_SERVER['PHP_SELF']) == 'dashboard.php');
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
            'calendar-days' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>',
            'home'          => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-plus'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'user-cog'      => '<circle cx="18" cy="15" r="3"/><circle cx="9" cy="7" r="4"/><path d="M10 15H6a4 4 0 0 0-4 4v2"/>',
            'user-clock'    => '<circle cx="12" cy="7" r="4"/><path d="M5.3 21a7.6 7.6 0 0 1 9.4-6.3"/><circle cx="17.5" cy="17.5" r="4.5"/><path d="M17.5 15.5v2l1.5 1"/>',
            'paw-print'     => '<circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/>',
            'tractor'       => '<path d="m10 11 11 .9a1 1 0 0 1 .9 1v1.6a1 1 0 0 1-1 1H15"/><path d="M3 4h9l1 7"/><path d="M4 11V4"/><circle cx="7" cy="17" r="3"/><circle cx="18" cy="17" r="3"/>',
            'history'       => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
            'chart-line'    => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
            'chart-column'  => '<path d="M3 3v18h18"/><path d="M13 17V9"/><path d="M18 17V5"/><path d="M8 17v-3"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'plus-circle'   => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="16"/><line x1="8" x2="16" y1="12" y2="12"/>',
            'list-check'    => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m15 9 2 2 4-4"/>',
            'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'spinner'       => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
            'inbox'         => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'file-pdf'      => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15h1.5a1.5 1.5 0 1 0 0-3H9v6"/>',
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

    /* Force parent containers */
    .main-content,
    .main-content .container {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: var(--gf-bg) !important;
        display: block !important;
    }

    /* ---------- NAVBAR (Dark Green) ---------- */
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

    /* ---------- SECTION ---------- */
    .gf-section { margin-bottom: 34px; }

    .gf-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 16px;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--gf-text-secondary);
    }
    .gf-section-title-icon {
        color: var(--gf-primary);
        background: var(--gf-primary-light);
        border-radius: 8px;
        width: 30px;
        height: 30px;
        display: inline-grid;
        place-items: center;
    }
    .gf-section-hint {
        margin-left: auto;
        font-size: 11px;
        color: var(--gf-text-secondary);
        text-transform: none;
        letter-spacing: 0;
        font-weight: 700;
        background: #fff;
        padding: 5px 12px;
        border-radius: 999px;
        border: 1px solid var(--gf-border);
        display: inline-flex;
        align-items: center;
        gap: 4px;
        box-shadow: var(--gf-shadow-xs);
    }

    /* ---------- STAT CARDS ---------- */
    .gf-stat-row {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 12px;
    }
    @media (max-width: 900px) { .gf-stat-row { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 520px) { .gf-stat-row { grid-template-columns: repeat(2, 1fr); } }

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
        background: #f1f5f9;
        color: var(--gf-text-muted);
    }
    .gf-stat-box .stat-icon.blue   { background: #dbeafe; color: #2563eb; }
    .gf-stat-box .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
    .gf-stat-box .stat-icon.green  { background: #dcfce7; color: #16a34a; }
    .gf-stat-box .stat-icon.amber  { background: #fef3c7; color: #d97706; }
    .gf-stat-box .stat-icon.red    { background: #fee2e2; color: #dc2626; }
    .gf-stat-box .stat-icon.teal   { background: #ccfbf1; color: #0d9488; }

    /* ---------- PANELS ---------- */
    .gf-panel {
        background: #fff;
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius-sm);
        padding: 18px 20px 20px;
        box-shadow: var(--gf-shadow-xs);
        margin-bottom: 20px;
        overflow: visible;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }
    .gf-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 6px;
        flex-wrap: wrap;
    }
    .gf-panel-head-title { display: flex; align-items: center; gap: 10px; }
    .gf-panel-head-title svg { color: var(--gf-primary); }
    .gf-panel-head-title h3 { font-size: 14.5px; font-weight: 700; margin: 0; color: var(--gf-text); }
    .gf-panel-head-sub {
        font-size: 11.5px;
        color: var(--gf-text-muted);
        margin: 2px 0 12px 24px;
        line-height: 1.4;
    }
    .gf-panel-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        background: #f1f5f9;
        border-radius: 6px;
        font-size: 11.5px;
        color: var(--gf-text-secondary);
        font-weight: 600;
        white-space: nowrap;
    }

    /* ---------- ANALYTICS GRID (FIXED) ---------- */
    .gf-analytics-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        align-items: stretch;
    }
    @media (max-width: 900px) { .gf-analytics-grid { grid-template-columns: 1fr; } }

    .gf-chart-wrap {
        position: relative;
        height: 260px;
        margin-top: 8px;
        min-width: 0;
        width: 100%;
    }
    .gf-chart-wrap canvas { display: block; max-width: 100%; }

    /* ---------- ACTIVITY LOGS ---------- */
    .gf-activity-toolbar {
        display: grid;
        grid-template-columns: minmax(200px, 1fr) 140px 140px 140px 110px;
        align-items: center;
        gap: 8px;
        padding: 0 0 14px 0;
        border-bottom: 1px solid #eef2f6;
    }
    .gf-act-search { position: relative; width: 100%; }
    .gf-act-search svg {
        position: absolute; left: 12px; top: 50%;
        transform: translateY(-50%);
        color: #9aa5b1; pointer-events: none;
    }
    .gf-act-search input {
        width: 100%; height: 36px;
        padding: 0 34px 0 34px;
        border: 1px solid var(--gf-border); border-radius: 8px;
        font-size: 13px; color: var(--gf-text);
        background: #fff; outline: none;
        transition: border-color 0.15s;
        font-family: inherit;
    }
    .gf-act-search input::placeholder { color: #9aa5b1; }
    .gf-act-search input:focus { border-color: var(--gf-primary); }
    .gf-act-search .kbd {
        position: absolute; right: 10px; top: 50%;
        transform: translateY(-50%);
        font-size: 10px; color: #9aa5b1;
        background: #f3f6f9; border: 1px solid var(--gf-border);
        border-radius: 4px; padding: 1px 6px;
        font-weight: 600;
    }
    .gf-act-select {
        height: 36px; width: 100%;
        padding: 0 30px 0 12px;
        border: 1px solid var(--gf-border); border-radius: 8px;
        font-size: 13px; color: var(--gf-text-secondary);
        background: #fff url("data:image/svg+xml;charset=US-ASCII,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center;
        -webkit-appearance: none; -moz-appearance: none; appearance: none;
        outline: none; cursor: pointer;
        font-family: inherit;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .gf-act-select:focus { border-color: var(--gf-primary); }

    @media (max-width: 1024px) {
        .gf-activity-toolbar { grid-template-columns: 1fr 1fr 1fr; }
        .gf-activity-toolbar .gf-act-search { grid-column: 1 / -1; }
    }
    @media (max-width: 640px) {
        .gf-activity-toolbar { grid-template-columns: 1fr 1fr; padding: 12px 0; gap: 8px; }
        .gf-activity-toolbar .gf-act-search { grid-column: 1 / -1; }
        .gf-activity-toolbar .gf-act-select:last-child { grid-column: 1 / -1; }
    }

    .gf-activity-table-wrap { overflow-x: auto; margin: 14px -20px 0; padding: 0 20px; }
    .gf-activity-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .gf-activity-table thead th {
        text-align: left; padding: 11px 14px;
        font-size: 11px; font-weight: 700;
        color: var(--gf-text-muted);
        text-transform: uppercase; letter-spacing: 0.5px;
        background: #fafbfc;
        border-bottom: 1px solid #eef2f6;
        white-space: nowrap;
    }
    .gf-activity-table thead th.sortable { cursor: pointer; user-select: none; }
    .gf-activity-table thead th.sortable:hover { color: var(--gf-primary); }
    .gf-activity-table thead th svg { margin-left: 4px; opacity: 0.5; }

    .gf-activity-table tbody td {
        padding: 13px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: var(--gf-text-secondary);
    }
    .gf-activity-table tbody tr:last-child td { border-bottom: none; }
    .gf-activity-table tbody tr:hover { background: #fafbfc; }

    .gf-act-user { display: flex; align-items: center; gap: 10px; min-width: 180px; }
    .gf-act-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px; font-weight: 700; color: #fff;
        flex-shrink: 0; text-transform: uppercase;
    }
    .gf-act-avatar.pink   { background: #f3a8c4; }
    .gf-act-avatar.blue   { background: #8fc4e8; }
    .gf-act-avatar.green  { background: #86efac; color: #065f46; }
    .gf-act-avatar.purple { background: #c4b5fd; color: #5b21b6; }
    .gf-act-avatar.amber  { background: #fcd34d; color: #78350f; }
    .gf-act-avatar.slate  { background: #cbd5e1; color: #334155; }

    .gf-act-user-info { min-width: 0; }
    .gf-act-user-name {
        font-weight: 600; color: var(--gf-text); font-size: 13px;
        line-height: 1.25;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 240px;
    }
    .gf-act-user-sub {
        font-size: 11.5px; color: var(--gf-text-muted); line-height: 1.3;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 240px;
    }

    .gf-role-pill {
        display: inline-block; padding: 3px 10px;
        border-radius: 6px; font-size: 10.5px;
        font-weight: 700; letter-spacing: 0.4px;
        text-transform: uppercase;
    }
    .gf-role-pill.admin    { background: #fce7f3; color: #be185d; }
    .gf-role-pill.manager  { background: #dbeafe; color: #1d4ed8; }
    .gf-role-pill.user     { background: #e0e7ff; color: #4338ca; }
    .gf-role-pill.system   { background: #f1f5f9; color: #64748b; }

    .gf-act-action {
        display: inline-flex; align-items: center; gap: 8px;
        color: var(--gf-text-secondary); font-weight: 500; font-size: 13px;
    }
    .gf-act-action svg { color: var(--gf-text-muted); }

    .gf-status-pill {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 3px 11px; border-radius: 999px;
        font-size: 11.5px; font-weight: 600; white-space: nowrap;
    }
    .gf-status-pill .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .gf-status-pill.success  { background: #dcfce7; color: #15803d; }
    .gf-status-pill.success .dot { background: var(--gf-success); }
    .gf-status-pill.progress { background: #fef3c7; color: #92400e; }
    .gf-status-pill.progress .dot { background: var(--gf-warning); }
    .gf-status-pill.queued   { background: #fef9c3; color: #854d0e; }
    .gf-status-pill.queued .dot { background: #eab308; }
    .gf-status-pill.failed   { background: #fee2e2; color: #b91c1c; }
    .gf-status-pill.failed .dot { background: var(--gf-danger); }

    .gf-act-time { font-size: 13px; color: var(--gf-text-secondary); white-space: nowrap; }

    .gf-activity-footer {
        display: flex; flex-wrap: wrap;
        align-items: center; justify-content: space-between;
        gap: 12px; padding: 14px 0 0 0;
        border-top: 1px solid #eef2f6;
        margin-top: 14px;
    }
    .gf-act-showing { font-size: 12.5px; color: var(--gf-text-secondary); }
    .gf-act-showing strong { color: var(--gf-text); font-weight: 700; }

    .gf-act-pagination { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
    .gf-page-btn {
        min-width: 30px; height: 30px; padding: 0 8px;
        border-radius: 6px; border: none;
        background: transparent; color: var(--gf-text-secondary);
        font-size: 12.5px; font-weight: 600;
        cursor: pointer; transition: all 0.15s;
        display: inline-flex; align-items: center; justify-content: center;
        font-family: inherit;
    }
    .gf-page-btn:hover:not(:disabled) { background: var(--gf-primary-lighter); color: var(--gf-primary-dark); }
    .gf-page-btn.active { background: var(--gf-primary); color: #fff; }
    .gf-page-btn:disabled { color: #cbd5e1; cursor: not-allowed; }
    .gf-page-ellipsis { color: #cbd5e1; padding: 0 4px; font-size: 12.5px; }

    @media (max-width: 760px) {
        .gf-activity-table thead th:nth-child(2),
        .gf-activity-table tbody td:nth-child(2) { display: none; }
        .gf-act-user-name, .gf-act-user-sub { max-width: 160px; }
    }

    /* ---------- FARMER TABLE ---------- */
    .gf-table-simple { overflow-x: auto; margin-top: 14px; }
    .gf-table-simple table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .gf-table-simple thead th {
        text-align: left; padding: 11px 14px;
        font-size: 11px; font-weight: 700;
        color: var(--gf-text-muted);
        text-transform: uppercase; letter-spacing: 0.5px;
        background: #fafbfc;
        border-bottom: 1px solid #eef2f6;
        white-space: nowrap;
    }
    .gf-table-simple tbody td {
        padding: 13px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: var(--gf-text-secondary);
    }
    .gf-table-simple tbody tr:last-child td { border-bottom: none; }
    .gf-table-simple tbody tr:hover { background: #fafbfc; }

    .gf-badge {
        display: inline-block; padding: 3px 10px;
        border-radius: 6px; font-weight: 700; font-size: 11px;
    }
    .gf-badge-success { background: #dcfce7; color: #166534; }
    .gf-badge-danger  { background: #fef3c7; color: #92400e; }
    .gf-badge-warning { background: #fef9c3; color: #854d0e; }
    .gf-badge-secondary { background: #f1f5f9; color: #64748b; }
    .gf-badge-manager { background: #dbeafe; color: #1e40af; }
    .gf-badge-admin { background: #fce7f3; color: #9d174d; }
    .gf-badge-user { background: #e0e7ff; color: #3730a3; }

    /* ---------- EXPORT BAR ---------- */
    .gf-export-bar {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 12px;
        padding: 14px 16px;
        background: #fef2f2; border: 1px solid #fecaca;
        border-radius: var(--gf-radius-sm); margin-top: 16px;
    }
    .gf-export-bar .bar-title { font-weight: 700; font-size: 13.5px; color: var(--gf-text); }
    .gf-export-bar .bar-sub { font-size: 12px; color: var(--gf-text-secondary); margin-top: 2px; }
    .gf-btn-pdf {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 8px; padding: 10px 20px; border-radius: 8px;
        font-weight: 700; font-size: 13px; text-decoration: none;
        background: #dc2626; color: #fff; border: none;
        cursor: pointer; transition: all 0.15s; white-space: nowrap;
    }
    .gf-btn-pdf:hover { background: #b91c1c; color: #fff; text-decoration: none; }
    @media (max-width: 640px) {
        .gf-export-bar { flex-direction: column; align-items: stretch; text-align: center; }
        .gf-btn-pdf { width: 100%; }
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
        .gf-page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
        .gf-page-header h1 { font-size: 22px; }
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
     NAVBAR (DARK GREEN)
============================================================ -->
<nav class="gf-navbar" aria-label="Main navigation">
    <a href="dashboard.php" class="gf-navbar-brand">
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
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'dashboard'); ?>
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
              
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" aria-label="Close menu" onclick="closeSidebar();">
            <?php echo icon('x', 18); ?>
        </button>
    </div>

    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'dashboard'); ?>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="gf-main">

            <!-- ===== PAGE HEADER ===== -->
            <header class="gf-page-header">
                <div class="gf-page-header-left">
                    <div>
                        <h1>Admin Dashboard</h1>
                        <p class="gf-welcome">
                            Welcome back, <strong><?php echo $sessionEmailShort; ?></strong>
                        </p>
                    </div>
                </div>
                <span class="gf-date-badge">
                    <?php echo icon('calendar', 14); ?>
                    <?php echo date('M d, Y'); ?>
                </span>
            </header>

            <!-- ===== USER STATISTICS ===== -->
            <section class="gf-section">
                <h2 class="gf-section-title">
                    User Statistics
                </h2>
                <div class="gf-stat-row">
                    <div class="gf-stat-box">
                        <div class="stat-label">Total Users</div>
                        <div class="stat-value"><?php echo number_format($totalUsers); ?></div>
                        <span class="stat-icon blue"><?php echo icon('users', 16); ?></span>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Admins</div>
                        <div class="stat-value"><?php echo number_format($totalAdmins); ?></div>
                        <span class="stat-icon purple"><?php echo icon('shield', 16); ?></span>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Managers</div>
                        <div class="stat-value"><?php echo number_format($totalManagers); ?></div>
                        <span class="stat-icon purple"><?php echo icon('user-cog', 16); ?></span>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Regular</div>
                        <div class="stat-value"><?php echo number_format($totalRegularUsers); ?></div>
                        <span class="stat-icon green"><?php echo icon('user', 16); ?></span>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Unverified</div>
                        <div class="stat-value"><?php echo number_format($unverifiedUsers); ?></div>
                        <span class="stat-icon amber"><?php echo icon('user-clock', 16); ?></span>
                    </div>
                </div>
            </section>

            <!-- ===== ACTIVITY ANALYTICS ===== -->
            <section class="gf-section">
                <h2 class="gf-section-title">
                    Activity Analytics
                </h2>
                <div class="gf-analytics-grid">
                    <div class="gf-panel">
                        <div class="gf-panel-head">
                            <div class="gf-panel-head-title">
                                <?php echo icon('chart-line', 16); ?>
                                <h3>Daily Trend</h3>
                            </div>
                            <span class="gf-panel-chip">
                                <?php echo icon('calendar-days', 12); ?> Last 7 days
                            </span>
                        </div>
                        <div class="gf-panel-head-sub">Activity across the last 7 calendar days</div>
                        <div class="gf-chart-wrap"><canvas id="dailyChart"></canvas></div>
                    </div>

                    <div class="gf-panel">
                        <div class="gf-panel-head">
                            <div class="gf-panel-head-title">
                                <?php echo icon('chart-column', 16); ?>
                                <h3>Top Actions</h3>
                            </div>
                            <span class="gf-panel-chip">
                                <?php echo icon('calendar-days', 12); ?> 30 days
                            </span>
                        </div>
                        <div class="gf-panel-head-sub">Total Number of Activities</div>
                        <div class="gf-chart-wrap"><canvas id="actionChart"></canvas></div>
                    </div>
                </div>
            </section>

            <!-- ===== USER ACTIVITY LOGS ===== -->
            <section class="gf-section">
                <h2 class="gf-section-title">
                    User Activity Logs
                    <span class="gf-section-hint" id="activityCount">—</span>
                </h2>

                <div class="gf-panel">
                    <div class="gf-activity-toolbar">
                        <div class="gf-act-search">
                            <?php echo icon('search', 14); ?>
                            <input type="text" id="actSearch" placeholder="Search by email or action...">
                           
                        </div>

                        <select class="gf-act-select" id="filterRole">
                            <option value="">All Roles</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="user">User</option>
                        </select>

                        <select class="gf-act-select" id="filterStatus">
                            <option value="">All Statuses</option>
                            <option value="success">Success</option>
                            <option value="in_progress">In Progress</option>
                            <option value="failed">Failed</option>
                        </select>

                        <select class="gf-act-select" id="filterDate">
                            <option value="">All Dates</option>
                            <option value="today">Today</option>
                            <option value="7days">Last 7 Days</option>
                            <option value="30days">Last 30 Days</option>
                            <option value="90days">Last 90 Days</option>
                        </select>

                        <select class="gf-act-select" id="filterLength">
                            <option value="5">Show: 5</option>
                            <option value="10">Show: 10</option>
                            <option value="25">Show: 25</option>
                            <option value="50">Show: 50</option>
                            <option value="100">Show: 100</option>
                        </select>
                    </div>

                    <div class="gf-activity-table-wrap">
                        <table class="gf-activity-table">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="user">User
                                    <th class="sortable" data-sort="role">Role 
                                    <th class="sortable" data-sort="action">Action 
                                    <th class="sortable" data-sort="status">Status 
                                    <th class="sortable" data-sort="created_at">Timestamp 
                                </tr>
                            </thead>
                            <tbody id="activityBody">
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:40px;color:#94a3b8;">
                                        <?php echo icon('spinner', 22); ?>
                                        <div style="margin-top:8px;font-size:13px;">Loading activities...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="gf-activity-footer">
                        <div class="gf-act-showing" id="actShowing">
                            Showing <strong>0</strong> to <strong>0</strong> of <strong>0</strong> events
                        </div>
                        <div class="gf-act-pagination" id="actPagination"></div>
                    </div>
                </div>
            </section>

            <!-- ===== GOAT INVENTORY (walay icons) ===== -->
            <section class="gf-section">
                <h2 class="gf-section-title">
                    Goat Inventory
                </h2>
                <div class="gf-stat-row" style="grid-template-columns: repeat(4, 1fr);">
                    <div class="gf-stat-box">
                        <div class="stat-label">Active</div>
                        <div class="stat-value"><?php echo number_format($totalActiveGoats); ?></div>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Sold</div>
                        <div class="stat-value"><?php echo number_format($totalSoldGoats); ?></div>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Deceased</div>
                        <div class="stat-value"><?php echo number_format($totalDeceasedGoats); ?></div>
                    </div>
                    <div class="gf-stat-box">
                        <div class="stat-label">Farmers</div>
                        <div class="stat-value"><?php echo number_format($totalFarmersWithGoats); ?></div>
                    </div>
                </div>
            </section>

            <!-- ===== FARMERS ===== -->
            <section class="gf-section">
                <h2 class="gf-section-title">
                    <span class="gf-section-title-icon"><?php echo icon('tractor', 16); ?></span>
                    Farmers
                </h2>

                <div class="gf-panel">
                    <div class="gf-panel-head">
                        <div class="gf-panel-head-title">
                            <?php echo icon('list-check', 16); ?>
                            <h3>Farmer List &amp; Goat Counts</h3>
                        </div>
                    </div>

                    <div class="gf-table-simple">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Farmer</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Total Goats</th>
                                    <th>Active</th>
                                    <th>Sold</th>
                                    <th>Deceased</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($farmersList)): ?>
                                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:20px;">No farmers found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($farmersList as $i => $farmer): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td><strong><?php echo htmlspecialchars($farmer['full_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($farmer['email']); ?></td>
                                            <td>
                                                <?php
                                                    $roleClass = 'gf-badge-secondary';
                                                    if ($farmer['role'] === 'admin')   $roleClass = 'gf-badge-admin';
                                                    if ($farmer['role'] === 'manager') $roleClass = 'gf-badge-manager';
                                                    if ($farmer['role'] === 'user')    $roleClass = 'gf-badge-user';
                                                ?>
                                                <span class="gf-badge <?php echo $roleClass; ?>">
                                                    <?php echo strtoupper(htmlspecialchars($farmer['role'])); ?>
                                                </span>
                                            </td>
                                            <td><strong><?php echo (int)$farmer['total_goats']; ?></strong></td>
                                            <td><span class="gf-badge gf-badge-success"><?php echo (int)$farmer['active_goats']; ?></span></td>
                                            <td><span class="gf-badge gf-badge-warning"><?php echo (int)$farmer['sold_goats']; ?></span></td>
                                            <td><span class="gf-badge gf-badge-danger"><?php echo (int)$farmer['deceased_goats']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="gf-export-bar">
                        <div>
                            <div class="bar-title">Export All Farmers to PDF</div>
                            <div class="bar-sub">
                                I-download ang kompleto nga listahan sa tanan farmer (<?php echo count($farmersList); ?> ka farmer) uban sa ilang goat counts.
                            </div>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/app/admin/export-all-farmers-pdf.php"
                           target="_blank"
                           class="gf-btn-pdf">
                            <?php echo icon('file-pdf', 14); ?> Export All to PDF
                        </a>
                    </div>
                </div>
            </section>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ============================================================
// SIDEBAR TOGGLE
// ============================================================
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

// ============================================================
// CHARTS
// ============================================================
const dailyData = <?php echo json_encode($dailyActivity); ?>;
const dayNames  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const todayStr  = new Date().toDateString();

const dailyLabels = dailyData.map(d => {
    const dt = new Date(d.date.replace(' ', 'T'));
    return dt.toDateString() === todayStr
        ? dayNames[dt.getDay()] + ' (Today)'
        : dayNames[dt.getDay()];
});

new Chart(document.getElementById('dailyChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: [{
            data: dailyData.map(d => +d.count),
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
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9', drawBorder: false },
                ticks: { color: '#94a3b8', font: { size: 11 }, padding: 6, precision: 0 }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { size: 11 }, padding: 6 }
            }
        }
    }
});

// ---------- TOP ACTIONS (FIXED) ----------
const actionData = <?php echo json_encode($actionStats); ?>;
const actionPalette = ['#16a34a', '#22c55e', '#4ade80', '#86efac', '#facc15'];

new Chart(document.getElementById('actionChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: actionData.map(d => {
            const a = d.action || '';
            return a.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }),
        datasets: [{
            data: actionData.map(d => +d.count),
            backgroundColor: actionPalette.slice(0, actionData.length),
            borderRadius: 6,
            borderSkipped: false,
            categoryPercentage: 0.7,
            barPercentage: 0.85
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 8, right: 8, bottom: 0, left: 0 } },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function (ctx) { return ' ' + ctx.parsed.y + ' actions'; }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9', drawBorder: false },
                ticks: {
                    color: '#94a3b8',
                    font: { size: 10 },
                    padding: 6,
                    precision: 0
                }
            },
            x: {
                grid: { display: false },
                ticks: {
                    color: '#64748b',
                    font: { size: 10 },
                    padding: 6,
                    autoSkip: false,
                    maxRotation: 30,
                    minRotation: 0,
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
// ACTIVITY LOGS
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
        return { cls: 'queued', label: status || 'Unknown' };
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
        fetch('<?php echo BASE_URL; ?>/api/get-activity-logs.php', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(json => {
            state.rows = Array.isArray(json) ? json : (json.data || []);
            applyFilters();
        })
        .catch(err => {
            console.error('Failed to load activities:', err);
            document.getElementById('activityBody').innerHTML =
                '<tr><td colspan="5" style="text-align:center;padding:40px;color:#ef4444;">' +
                '<div style="font-size:13px;">Failed to load activities.</div>' +
                '</td></tr>';
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
                '<div style="margin-top:10px;font-size:13px;">No activity logs found</div></td></tr>';
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
                        <div class="gf-act-user">
                            <div class="gf-act-avatar ${avColor}">${esc(initials)}</div>
                            <div class="gf-act-user-info">
                                <div class="gf-act-user-name">${esc(displayName)}</div>
                                <div class="gf-act-user-sub">${esc(subLabel)}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="gf-role-pill ${rc}">${esc(roleLabel)}</span></td>
                    <td><span class="gf-act-action">${esc(formatAction(r.action))}</span></td>
                    <td><span class="gf-status-pill ${si.cls}"><span class="dot"></span>${esc(si.label)}</span></td>
                    <td><span class="gf-act-time">${esc(ts)}</span></td>
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

        let html = `<button class="gf-page-btn" data-page="prev" ${state.page === 1 ? 'disabled' : ''}>Previous</button>`;
        buildPageList(state.page, totalPages).forEach(p => {
            html += p === '...'
                ? `<span class="gf-page-ellipsis">…</span>`
                : `<button class="gf-page-btn ${p === state.page ? 'active' : ''}" data-page="${p}">${p}</button>`;
        });
        html += `<button class="gf-page-btn" data-page="next" ${state.page === totalPages ? 'disabled' : ''}>Next</button>`;
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

        document.querySelectorAll('.gf-activity-table th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.getAttribute('data-sort');
                if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                else { state.sortKey = key; state.sortDir = key === 'created_at' ? 'desc' : 'asc'; }
                applyFilters();
            });
        });

        document.getElementById('actPagination').addEventListener('click', e => {
            const btn = e.target.closest('.gf-page-btn');
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