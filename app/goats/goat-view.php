<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-logger.php';
require_once '../../includes/goat-functions.php';
requireLogin();

$currentRole   = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// Lookup by primary key `id`
$goatPk = (int) trim($_GET['id'] ?? '');

$goat = null;
if ($goatPk > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT g.*, u.full_name AS owner_name, u.username AS owner_username, u.email AS owner_email
            FROM goats g
            JOIN users u ON u.id = g.user_id
            WHERE g.id = ?
        ");
        $stmt->execute([$goatPk]);
        $goat = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('[Goat View] Lookup failed: ' . $e->getMessage());
        $goat = null;
    }
}

$title = 'Goat Details';
renderHeader($title);
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
            'arrow-left'    => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
            'eye'           => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'pencil'        => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
            'history'       => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
            'paw-print'     => '<circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/>',
            'shield-alert'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
            'search-x'      => '<path d="m13.5 8.5-5 5"/><path d="m8.5 8.5 5 5"/><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'lock'          => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'fingerprint'   => '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
            'tag'           => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
            'qrcode'        => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
            'venus-mars'    => '<path d="M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M12 16v6"/><path d="M9 19h6"/>',
            'dna'           => '<path d="m10 16 1.5 1.5"/><path d="m14 8-1.5-1.5"/><path d="M15 2c-1.798 1.998-2.518 3.995-2.807 5.993"/><path d="m16.5 10.5 1 1"/><path d="m17 6-2.891-2.891"/><path d="M2 15c6.667-6 13.333 0 20-6"/><path d="m20 9 .891.891"/><path d="M3.109 14.109 4 15"/><path d="m6.5 12.5 1 1"/><path d="m7 18 2.891 2.891"/><path d="M9 22c1.798-1.998 2.518-3.995 2.807-5.993"/>',
            'palette'       => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
            'pen-tool'      => '<path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="m2 2 7.586 7.586"/><circle cx="11" cy="11" r="2"/>',
            'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'heart-pulse'   => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>',
            'circle'        => '<circle cx="12" cy="12" r="10"/>',
            'edit'          => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'plus-circle'   => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="16"/><line x1="8" x2="16" y1="12" y2="12"/>',
            'sync'          => '<path d="M12 2v4"/><path d="m16.2 7.8 2.9-2.9"/><path d="M18 12h4"/><path d="m16.2 16.2 2.9 2.9"/><path d="M12 18v4"/><path d="m4.9 19.1 2.9-2.9"/><path d="M2 12h4"/><path d="m4.9 4.9 2.9 2.9"/>',
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
        max-width: 1000px;
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
    .gf-page-header h1 { margin: 0; font-size: 25px; font-weight: 800; letter-spacing: -0.025em; line-height: 1.15; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .gf-page-header .gf-welcome { margin: 5px 0 0; font-size: 13px; color: var(--gf-text-muted); }

    .gf-page-header-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .gf-goat-id-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 14px;
        border-radius: 999px;
        background: linear-gradient(145deg, #22C55E, #15803D);
        color: #fff;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        box-shadow: 0 4px 10px rgba(34, 197, 94, 0.3);
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

    /* ---------- CARD ---------- */
    .gf-card {
        background: #fff;
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius-sm);
        padding: 22px 24px;
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
        margin: 0 0 20px;
        padding-bottom: 14px;
        border-bottom: 1px solid #eef2f6;
        flex-wrap: wrap;
    }
    .gf-card-title svg { color: var(--gf-primary); }

    /* ---------- DETAILS GRID ---------- */
    .gf-details-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 4px 24px;
    }
    @media (min-width: 640px) {
        .gf-details-grid {
            grid-template-columns: 1fr 1fr;
        }
        .gf-details-grid .full { grid-column: 1 / -1; }
    }

    .gf-detail-row {
        display: grid;
        grid-template-columns: 190px 1fr;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
        align-items: start;
    }
    @media (max-width: 639.98px) {
        .gf-detail-row {
            grid-template-columns: 1fr;
            gap: 4px;
            padding: 12px 0;
        }
    }
    .gf-detail-row:last-child { border-bottom: none; }

    .gf-detail-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--gf-text-muted);
    }
    .gf-detail-label svg { color: var(--gf-primary); flex-shrink: 0; }

    .gf-detail-value {
        font-size: 14px;
        font-weight: 500;
        color: var(--gf-text);
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .gf-detail-value.muted { color: var(--gf-text-muted); }

    /* ---------- BADGES ---------- */
    .gf-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.3px;
        white-space: nowrap;
    }
    .gf-badge .dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .gf-badge.badge-success      { background: #dcfce7; color: #166534; }
    .gf-badge.badge-success .dot { background: #16a34a; }
    .gf-badge.badge-danger       { background: #fef3c7; color: #92400e; }
    .gf-badge.badge-danger .dot  { background: #d97706; }
    .gf-badge.badge-warning      { background: #fef9c3; color: #854d0e; }
    .gf-badge.badge-warning .dot { background: #ca8a04; }
    .gf-badge.badge-secondary       { background: #f1f5f9; color: #64748b; }
    .gf-badge.badge-secondary .dot  { background: #94a3b8; }

    /* ---------- BUTTONS ---------- */
    .gf-form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid #eef2f6;
    }

    .gf-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
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
    .gf-btn-outline {
        background: #fff;
        color: var(--gf-text-secondary);
        border: 1px solid var(--gf-border);
    }
    .gf-btn-outline:hover { background: #f8fafc; color: var(--gf-text); }

    /* ---------- EMPTY / ERROR STATE ---------- */
    .gf-empty {
        padding: 50px 20px;
        text-align: center;
        color: var(--gf-text-muted);
    }
    .gf-empty svg {
        margin: 0 auto 16px;
        color: #cbd5e1;
    }
    .gf-empty.error svg { color: #fca5a5; }
    .gf-empty p {
        margin: 0;
        color: var(--gf-text);
        font-size: 16px;
        font-weight: 700;
    }
    .gf-empty .sub {
        margin: 6px 0 20px;
        font-size: 13px;
        color: var(--gf-text-muted);
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
        .gf-form-actions { flex-direction: column; }
        .gf-form-actions .gf-btn { width: 100%; }
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
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'inventory'); ?>
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
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'inventory'); ?>
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
                        <h1>
                            Goat Details
                            <?php if ($goat): ?>
                                <span class="gf-goat-id-badge">
                                    <?php echo icon('fingerprint', 12); ?>
                                    <?php echo htmlspecialchars($goat['goat_id']); ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="gf-welcome">
                            Complete information about this goat
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

            <!-- ===== DETAILS CARD ===== -->
            <div class="gf-card">
                <h2 class="gf-card-title">
                    <?php echo icon('paw-print', 18); ?>
                    Goat Information
                </h2>

                <?php if (!$goat): ?>
                    <div class="gf-empty error">
                        <?php echo icon('search-x', 60, '', '1.5'); ?>
                        <p>Goat record not found.</p>
                        <div class="sub">The requested goat does not exist or may have been removed.</div>
                        <a href="dashboard.php" class="gf-btn gf-btn-primary">
                            <?php echo icon('arrow-left', 16); ?>
                            Back to Inventory
                        </a>
                    </div>
                <?php elseif (!canAccessGoat($currentRole, $currentUserId, $goat['user_id'])): ?>
                    <div class="gf-empty error">
                        <?php echo icon('lock', 60, '', '1.5'); ?>
                        <p>You are not authorized to access this goat record.</p>
                        <div class="sub">If you believe this is an error, please contact an administrator.</div>
                        <a href="dashboard.php" class="gf-btn gf-btn-primary">
                            <?php echo icon('arrow-left', 16); ?>
                            Back to Inventory
                        </a>
                    </div>
                <?php else: ?>
                    <?php
                    // Log that this goat record was viewed (audit trail).
                    try {
                        logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_viewed', 'success', $goat['goat_id']);
                    } catch (Exception $e) {
                        error_log('[Goat View] Activity log failed: ' . $e->getMessage());
                    }

                    // Null-safe date formatter
                    $fmtDate = function (?string $value, string $format = 'F j, Y'): string {
                        if ($value === null || $value === '') return '—';
                        $ts = strtotime($value);
                        return $ts ? date($format, $ts) : '—';
                    };
                    ?>

                    <div class="gf-details-grid">

                        <!-- Goat ID -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('fingerprint', 14); ?>
                                Goat ID
                            </div>
                            <div class="gf-detail-value"><?php echo htmlspecialchars($goat['goat_id']); ?></div>
                        </div>

                        <!-- Owner (admin/manager only) -->
                        <?php if ($currentRole !== 'user'): ?>
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('user', 14); ?>
                                Owner (Farmer)
                            </div>
                            <div class="gf-detail-value">
                                <?php echo htmlspecialchars($goat['owner_name']); ?>
                                <span style="color:var(--gf-text-muted); font-weight:400;">
                                    (<?php echo htmlspecialchars($goat['owner_username']); ?>)
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Goat Name -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('tag', 14); ?>
                                Goat Name / Tag
                            </div>
                            <div class="gf-detail-value"><?php echo htmlspecialchars($goat['goat_name']); ?></div>
                        </div>

                        <!-- Ear Tag -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('qrcode', 14); ?>
                                Ear Tag Number
                            </div>
                            <div class="gf-detail-value <?php echo !$goat['tag_number'] ? 'muted' : ''; ?>">
                                <?php echo $goat['tag_number'] ? htmlspecialchars($goat['tag_number']) : '—'; ?>
                            </div>
                        </div>

                        <!-- Sex -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('venus-mars', 14); ?>
                                Sex
                            </div>
                            <div class="gf-detail-value"><?php echo ucfirst(htmlspecialchars($goat['sex'])); ?></div>
                        </div>

                        <!-- Breed -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('dna', 14); ?>
                                Breed
                            </div>
                            <div class="gf-detail-value"><?php echo htmlspecialchars($goat['breed']); ?></div>
                        </div>

                        <!-- DOB -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('calendar', 14); ?>
                                Date of Birth
                            </div>
                            <div class="gf-detail-value"><?php echo $fmtDate($goat['date_of_birth']); ?></div>
                        </div>

                        <!-- Color -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('palette', 14); ?>
                                Color
                            </div>
                            <div class="gf-detail-value <?php echo !$goat['color'] ? 'muted' : ''; ?>">
                                <?php echo $goat['color'] ? htmlspecialchars($goat['color']) : '—'; ?>
                            </div>
                        </div>

                        <!-- Identifying Characteristics (full width) -->
                        <div class="gf-detail-row full">
                            <div class="gf-detail-label">
                                <?php echo icon('pen-tool', 14); ?>
                                Identifying Characteristics
                            </div>
                            <div class="gf-detail-value <?php echo !$goat['identifying_characteristics'] ? 'muted' : ''; ?>">
                                <?php echo $goat['identifying_characteristics'] ? nl2br(htmlspecialchars($goat['identifying_characteristics'])) : '—'; ?>
                            </div>
                        </div>

                        <!-- Date Acquired -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('clock', 14); ?>
                                Date Acquired
                            </div>
                            <div class="gf-detail-value"><?php echo $fmtDate($goat['date_acquired']); ?></div>
                        </div>

                        <!-- Health Status -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('heart-pulse', 14); ?>
                                Health Status
                            </div>
                            <div class="gf-detail-value">
                                <span class="gf-badge <?php echo healthStatusBadgeClass($goat['health_status']); ?>">
                                    <span class="dot"></span>
                                    <?php echo healthStatusLabel($goat['health_status']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Goat Status -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('circle', 14); ?>
                                Goat Status
                            </div>
                            <div class="gf-detail-value">
                                <span class="gf-badge <?php echo goatStatusBadgeClass($goat['status']); ?>">
                                    <span class="dot"></span>
                                    <?php echo goatStatusLabel($goat['status']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Date Registered -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('edit', 14); ?>
                                Date Registered
                            </div>
                            <div class="gf-detail-value"><?php echo $fmtDate($goat['date_registered'], 'F j, Y, g:i a'); ?></div>
                        </div>

                        <!-- Created -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('plus-circle', 14); ?>
                                Created
                            </div>
                            <div class="gf-detail-value"><?php echo $fmtDate($goat['created_at'], 'F j, Y, g:i a'); ?></div>
                        </div>

                        <!-- Last Updated -->
                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('sync', 14); ?>
                                Last Updated
                            </div>
                            <div class="gf-detail-value"><?php echo $fmtDate($goat['updated_at'], 'F j, Y, g:i a'); ?></div>
                        </div>

                    </div>

                    <!-- Action Buttons -->
                    <div class="gf-form-actions">
                        <?php if (canEditGoat($currentRole, $currentUserId, $goat['user_id']) || canUpdateHealthStatus($currentRole, $currentUserId, $goat['user_id'])): ?>
                            <a href="goat-update.php?id=<?php echo urlencode($goat['id']); ?>" class="gf-btn gf-btn-primary">
                                <?php echo icon('pencil', 16); ?>
                                Edit Goat
                            </a>
                        <?php endif; ?>

                        <a href="goat-history.php?id=<?php echo urlencode($goat['id']); ?>" class="gf-btn gf-btn-secondary">
                            <?php echo icon('history', 16); ?>
                            View History
                        </a>

                        <a href="dashboard.php" class="gf-btn gf-btn-outline">
                            <?php echo icon('arrow-left', 16); ?>
                            Back to List
                        </a>
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