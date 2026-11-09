<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
requireLogin();

// Role-based filtering
$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// Search / filter support
$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$smsFilter = $_GET['sms'] ?? '';                    // NEW

$where = [];
$params = [];

// Build query based on role hierarchy
if ($currentRole === 'admin') {
    // Admin can see all users
} elseif ($currentRole === 'manager') {
    $where[] = "role = 'user'";
} else {
    $where[] = "id = :self_id";
    $params[':self_id'] = $currentUserId;
}

if ($search !== '') {
    $where[] = "(full_name LIKE :search OR email LIKE :search OR username LIKE :search OR phone_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($currentRole !== 'user') {
    if ($roleFilter !== '' && in_array($roleFilter, ['admin', 'manager', 'user'])) {
        $where[] = "role = :role_filter";
        $params[':role_filter'] = $roleFilter;
    }
    if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'])) {
        $where[] = "status = :status_filter";
        $params[':status_filter'] = $statusFilter;
    }
    // NEW: SMS filter
    if ($smsFilter === 'with_phone') {
        $where[] = "phone_number IS NOT NULL AND phone_number != ''";
    } elseif ($smsFilter === 'sms_on') {
        $where[] = "phone_number IS NOT NULL AND phone_number != '' AND sms_opt_in = 1";
    } elseif ($smsFilter === 'no_phone') {
        $where[] = "(phone_number IS NULL OR phone_number = '')";
    }
}

// NEW: Added phone_number, barangay, municipality, sms_opt_in sa SELECT
$sql = "SELECT id, full_name, username, email, phone_number, barangay, municipality, sms_opt_in, role, status, is_verified, created_at, updated_at FROM users";
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY
    CASE role
        WHEN 'admin' THEN 1
        WHEN 'manager' THEN 2
        WHEN 'user' THEN 3
    END, full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total count for summary
if ($currentRole === 'admin') {
    $totalVisible = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} elseif ($currentRole === 'manager') {
    $totalVisible = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
} else {
    $totalVisible = 1;
}

// NEW: SMS stats
$smsStats = [
    'with_phone' => 0,
    'sms_on' => 0,
    'no_phone' => 0,
];

if ($currentRole !== 'user') {
    $statsSql = "SELECT 
        SUM(CASE WHEN phone_number IS NOT NULL AND phone_number != '' THEN 1 ELSE 0 END) as with_phone,
        SUM(CASE WHEN phone_number IS NOT NULL AND phone_number != '' AND sms_opt_in = 1 THEN 1 ELSE 0 END) as sms_on,
        SUM(CASE WHEN phone_number IS NULL OR phone_number = '' THEN 1 ELSE 0 END) as no_phone
        FROM users";
    if ($currentRole === 'manager') {
        $statsSql .= " WHERE role = 'user'";
    }
    $smsStats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: $smsStats;
}

$title = 'User Management';
renderHeader($title);
$isUserManagement = true;
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
            'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-plus'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'list-check'    => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m15 9 2 2 4-4"/>',
            'tractor'       => '<path d="m10 11 11 .9a1 1 0 0 1 .9 1v1.6a1 1 0 0 1-1 1H15"/><path d="M3 4h9l1 7"/><path d="M4 11V4"/><circle cx="7" cy="17" r="3"/><circle cx="18" cy="17" r="3"/>',
            'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'filter'        => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'eye'           => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'pencil'        => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
            'trash'         => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
            'shield-check'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'x-circle'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
            'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'message-square'=> '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'map-pin'       => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
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

    /* Create User button in page header */
    .gf-btn-create {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 20px;
        border-radius: 10px;
        background: linear-gradient(145deg, #22C55E, #15803D);
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        transition: all 0.2s var(--gf-ease);
        white-space: nowrap;
    }
    .gf-btn-create:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(34, 197, 94, 0.4);
        color: #fff;
    }
    .gf-btn-create:active { transform: translateY(0); }

    /* ---------- INFO BOX ---------- */
    .gf-info-box {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 14px 16px;
        background: var(--gf-primary-lighter);
        border-left: 4px solid var(--gf-primary);
        border-radius: var(--gf-radius-sm);
        margin-bottom: 20px;
        font-size: 13.5px;
        color: #1f2937;
        line-height: 1.5;
    }
    .gf-info-box svg { color: var(--gf-primary); flex-shrink: 0; margin-top: 2px; }
    .gf-info-box strong { color: var(--gf-primary-dark); }

    /* ---------- STATS CARDS (SMS) ---------- */
    .gf-stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }
    @media (max-width: 640px) {
        .gf-stats-grid { grid-template-columns: 1fr; }
    }
    .gf-stat-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        background: #fff;
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius-sm);
        box-shadow: var(--gf-shadow-xs);
        text-decoration: none;
        color: inherit;
        transition: all 0.15s var(--gf-ease);
    }
    .gf-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--gf-shadow-sm);
    }
    .gf-stat-icon {
        width: 40px; height: 40px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .gf-stat-icon.green { background: #dcfce7; color: #16a34a; }
    .gf-stat-icon.blue  { background: #dbeafe; color: #2563eb; }
    .gf-stat-icon.gray  { background: #f1f5f9; color: #64748b; }
    .gf-stat-body { min-width: 0; }
    .gf-stat-value {
        font-size: 20px;
        font-weight: 800;
        color: var(--gf-text);
        letter-spacing: -0.02em;
        line-height: 1.1;
    }
    .gf-stat-label {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--gf-text-muted);
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

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

    /* ---------- FILTER FORM ---------- */
    .gf-filter-form {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px;
        align-items: end;
    }
    @media (min-width: 768px) {
        .gf-filter-form {
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
        }
    }

    .gf-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .gf-field label {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--gf-text);
        letter-spacing: 0.01em;
    }
    .gf-field input,
    .gf-field select {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--gf-border);
        border-radius: 10px;
        font-size: 14px;
        font-family: inherit;
        color: var(--gf-text);
        background: #fff;
        outline: none;
        transition: border-color 0.15s var(--gf-ease), box-shadow 0.15s var(--gf-ease);
    }
    .gf-field input:focus,
    .gf-field select:focus {
        border-color: var(--gf-primary);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }
    .gf-field select {
        cursor: pointer;
        background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        padding-right: 36px;
        appearance: none;
        -webkit-appearance: none;
    }

    .gf-filter-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    /* ---------- BUTTONS ---------- */
    .gf-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 40px;
        padding: 9px 18px;
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

    /* Small action buttons in table */
    .gf-btn-xs {
        min-height: 30px;
        padding: 5px 12px;
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

    .gf-btn-edit {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    .gf-btn-edit:hover { background: #fde68a; color: #78350f; }

    .gf-btn-delete {
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }
    .gf-btn-delete:hover { background: #fecaca; color: #991b1b; }

    .gf-btn-disabled {
        background: #f1f5f9;
        color: #cbd5e1;
        border: 1px solid var(--gf-border);
        cursor: not-allowed;
    }

    .gf-table-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }

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
        min-width: 1100px;
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

    .gf-table-user {
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
    .gf-table-user-info { min-width: 0; }
    .gf-table-user-name {
        font-weight: 700;
        color: var(--gf-text);
        font-size: 13.5px;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }
    .gf-table-user-sub {
        font-size: 11.5px;
        color: var(--gf-text-muted);
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }

    /* ---------- PHONE CELL ---------- */
    .gf-phone-cell {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 130px;
    }
    .gf-phone-number {
        font-family: 'SF Mono', 'Monaco', 'Menlo', 'Consolas', monospace;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--gf-text);
        white-space: nowrap;
    }
    .gf-phone-empty {
        color: var(--gf-text-muted);
        font-size: 12.5px;
        font-style: italic;
    }

    /* ---------- BADGES ---------- */
    .gf-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .gf-badge .dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .gf-badge.admin      { background: #fce7f3; color: #9d174d; }
    .gf-badge.admin .dot { background: #be185d; }
    .gf-badge.manager      { background: #dbeafe; color: #1e40af; }
    .gf-badge.manager .dot { background: #2563eb; }
    .gf-badge.user      { background: #e0e7ff; color: #3730a3; }
    .gf-badge.user .dot { background: #4f46e5; }

    .gf-badge.active      { background: #dcfce7; color: #166534; }
    .gf-badge.active .dot { background: #16a34a; }
    .gf-badge.inactive      { background: #f1f5f9; color: #64748b; }
    .gf-badge.inactive .dot { background: #94a3b8; }

    .gf-badge.verified      { background: #dcfce7; color: #166534; }
    .gf-badge.verified .dot { background: #16a34a; }
    .gf-badge.unverified      { background: #fef3c7; color: #92400e; }
    .gf-badge.unverified .dot { background: #d97706; }

    /* SMS badges */
    .gf-badge.sms-on      { background: #dcfce7; color: #166534; }
    .gf-badge.sms-on .dot { background: #16a34a; }
    .gf-badge.sms-off      { background: #f1f5f9; color: #64748b; }
    .gf-badge.sms-off .dot { background: #94a3b8; }
    .gf-badge.no-phone      { background: #fee2e2; color: #b91c1c; }
    .gf-badge.no-phone .dot { background: #dc2626; }

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
        .gf-btn-create { flex: 1; justify-content: center; }
        .gf-card { padding: 16px 14px; }
        .gf-table-wrapper { margin: 0 -14px; padding: 0 14px; }
        .gf-filter-actions { flex-direction: column; }
        .gf-filter-actions .gf-btn { width: 100%; }
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
                <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png" alt="GoatPro Logo"
                >
            <?php else: ?>
                <span aria-hidden="true">🐐</span>
            <?php endif; ?>
        </div>
        <div class="gf-navbar-brand-text">
            <span class="gf-navbar-title">GoatPro</span>
       
        </div>
    </a>

    <ul class="gf-navbar-links">
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'users'); ?>
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
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'users'); ?>
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
                        <h1>User Management</h1>
                        <p class="gf-welcome">
                            Manage users based on your <strong><?php echo ucfirst($currentRole); ?></strong> permissions
                        </p>
                    </div>
                </div>

                <div class="gf-page-header-right">
                    <span class="gf-date-badge">
                        <?php echo icon('calendar', 14); ?>
                        <?php echo date('M d, Y'); ?>
                    </span>

                    <?php if ($currentRole === 'admin' || $currentRole === 'manager'): ?>
                        <a href="<?php echo BASE_URL; ?>/app/users/user-create.php" class="gf-btn-create">
                            <?php echo icon('user-plus', 16); ?>
                            Create User
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <!-- ===== INFO BOX ===== -->
            <div class="gf-info-box">
                <?php echo icon('info', 18); ?>
                <div>
                    <strong>Your Role: <?php echo ucfirst($currentRole); ?></strong><br>
                    <?php if ($currentRole === 'admin'): ?>
                        You have full access to manage all users, assign roles, and activate or deactivate accounts.
                    <?php elseif ($currentRole === 'manager'): ?>
                        You can manage regular user (Farmer) accounts only. Admin and Manager accounts are hidden from your view.
                    <?php else: ?>
                        You can only view and update your own profile.
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== SMS STATS (admin/manager only) ===== -->
            <?php if ($currentRole !== 'user'): ?>
            <div class="gf-stats-grid">
                <a href="?sms=with_phone" class="gf-stat-card">
                    <div class="gf-stat-icon blue">
                        <?php echo icon('phone', 20); ?>
                    </div>
                    <div class="gf-stat-body">
                        <div class="gf-stat-value"><?php echo (int)($smsStats['with_phone'] ?? 0); ?></div>
                        <div class="gf-stat-label">With Phone</div>
                    </div>
                </a>
                <a href="?sms=sms_on" class="gf-stat-card">
                    <div class="gf-stat-icon green">
                        <?php echo icon('message-square', 20); ?>
                    </div>
                    <div class="gf-stat-body">
                        <div class="gf-stat-value"><?php echo (int)($smsStats['sms_on'] ?? 0); ?></div>
                        <div class="gf-stat-label">SMS Opted In</div>
                    </div>
                </a>
                <a href="?sms=no_phone" class="gf-stat-card">
                    <div class="gf-stat-icon gray">
                        <?php echo icon('x-circle', 20); ?>
                    </div>
                    <div class="gf-stat-body">
                        <div class="gf-stat-value"><?php echo (int)($smsStats['no_phone'] ?? 0); ?></div>
                        <div class="gf-stat-label">No Phone</div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <!-- ===== FILTERS (admin/manager only) ===== -->
            <?php if ($currentRole !== 'user'): ?>
            <div class="gf-card">
                <h2 class="gf-card-title">
                    <?php echo icon('filter', 16); ?>
                    Filter Users
                </h2>

                <form method="GET" class="gf-filter-form">
                    <div class="gf-field">
                        <label for="q">Search</label>
                        <input type="text" id="q" name="q"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Name, email, username, or phone…">
                    </div>

                    <?php if ($currentRole === 'admin'): ?>
                    <div class="gf-field">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="">All Roles</option>
                            <option value="admin"   <?php echo $roleFilter === 'admin'   ? 'selected' : ''; ?>>Admin</option>
                            <option value="manager" <?php echo $roleFilter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                            <option value="user"    <?php echo $roleFilter === 'user'    ? 'selected' : ''; ?>>User (Farmer)</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="gf-field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="active"   <?php echo $statusFilter === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="gf-field">
                        <label for="sms">SMS Status</label>
                        <select id="sms" name="sms">
                            <option value="">All SMS Statuses</option>
                            <option value="with_phone" <?php echo $smsFilter === 'with_phone' ? 'selected' : ''; ?>>With Phone</option>
                            <option value="sms_on"     <?php echo $smsFilter === 'sms_on'     ? 'selected' : ''; ?>>SMS Opted In</option>
                            <option value="no_phone"   <?php echo $smsFilter === 'no_phone'   ? 'selected' : ''; ?>>No Phone</option>
                        </select>
                    </div>

                    <div class="gf-filter-actions">
                        <button type="submit" class="gf-btn gf-btn-primary">
                            <?php echo icon('search', 14); ?>
                            Search
                        </button>
                        <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== '' || $smsFilter !== ''): ?>
                            <a href="<?php echo BASE_URL; ?>/app/users/dashboard.php" class="gf-btn gf-btn-secondary">
                                <?php echo icon('x', 14); ?>
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ===== USERS TABLE ===== -->
            <div class="gf-card">
                <h2 class="gf-card-title">
                    <?php echo icon('users', 16); ?>
                    Users
                    <span class="gf-card-chip">
                        <?php echo count($users); ?> of <?php echo $totalVisible; ?>
                    </span>
                </h2>

                <?php if (empty($users)): ?>
                    <div class="gf-empty">
                        <?php echo icon('users', 44); ?>
                        <p>No users to display based on your role permissions or search filters.</p>
                    </div>
                <?php else: ?>
                    <div class="gf-table-wrapper">
                        <table class="gf-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Phone</th>
                                    <th>SMS</th>
                                    <th>Location</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user):
                                    $initials = strtoupper(substr($user['full_name'] ?: $user['email'], 0, 1));
                                    if (!empty($user['full_name'])) {
                                        $parts = preg_split('/\s+/', trim($user['full_name']));
                                        if (count($parts) >= 2) {
                                            $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                                        }
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="gf-table-user">
                                            <div class="gf-table-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                            <div class="gf-table-user-info">
                                                <div class="gf-table-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                <div class="gf-table-user-sub"><?php echo htmlspecialchars($user['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>

                                    <!-- NEW: PHONE -->
                                    <td>
                                        <?php if (!empty($user['phone_number'])): ?>
                                            <div class="gf-phone-cell">
                                                <span class="gf-phone-number">
                                                    <?php echo htmlspecialchars(formatPhoneDisplay($user['phone_number'])); ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="gf-phone-empty">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- NEW: SMS STATUS -->
                                    <td>
                                        <?php if (empty($user['phone_number'])): ?>
                                            <span class="gf-badge no-phone">
                                                <span class="dot"></span>
                                                No phone
                                            </span>
                                        <?php elseif (!empty($user['sms_opt_in'])): ?>
                                            <span class="gf-badge sms-on">
                                                <span class="dot"></span>
                                                SMS ON
                                            </span>
                                        <?php else: ?>
                                            <span class="gf-badge sms-off">
                                                <span class="dot"></span>
                                                SMS OFF
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- NEW: LOCATION -->
                                    <td>
                                        <?php
                                        $location = [];
                                        if (!empty($user['barangay'])) $location[] = $user['barangay'];
                                        if (!empty($user['municipality'])) $location[] = $user['municipality'];
                                        if (empty($location)):
                                        ?>
                                            <span class="gf-phone-empty">—</span>
                                        <?php else: ?>
                                            <span style="font-size: 12.5px;">
                                                <?php echo htmlspecialchars(implode(', ', $location)); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="gf-badge <?php echo $user['role']; ?>">
                                            <span class="dot"></span>
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="gf-badge <?php echo $user['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                            <span class="dot"></span>
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="gf-table-actions">
                                            <a href="user-view.php?user_id=<?php echo $user['id']; ?>" class="gf-btn gf-btn-xs gf-btn-view">
                                                <?php echo icon('eye', 12); ?>
                                                View
                                            </a>
                                            <?php if (canManageTarget($currentRole, $currentUserId, $user['role'], $user['id'])): ?>
                                                <a href="user-update.php?user_id=<?php echo $user['id']; ?>" class="gf-btn gf-btn-xs gf-btn-edit">
                                                    <?php echo icon('pencil', 12); ?>
                                                    Edit
                                                </a>
                                            <?php endif; ?>
                                            <?php if (canDeleteTarget($currentRole, $currentUserId, $user['role'], $user['id'])): ?>
                                                <a href="user-delete.php?user_id=<?php echo $user['id']; ?>"
                                                   class="gf-btn gf-btn-xs gf-btn-delete"
                                                   onclick="return confirm('Are you sure you want to delete this user?')">
                                                    <?php echo icon('trash', 12); ?>
                                                    Delete
                                                </a>
                                            <?php elseif ($user['id'] == $currentUserId): ?>
                                                <span class="gf-btn gf-btn-xs gf-btn-disabled" title="You cannot delete your own account">
                                                    <?php echo icon('trash', 12); ?>
                                                    Delete
                                                </span>
                                            <?php endif; ?>
                                        </div>
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