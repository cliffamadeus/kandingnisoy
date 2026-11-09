<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-logger.php';
require_once '../../includes/goat-functions.php';
requireLogin();

$currentRole   = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// ---------- Search / filter support ----------
$search        = trim($_GET['q'] ?? '');
$sexFilter     = normalizeGoatSex($_GET['sex'] ?? '') ?? '';
$healthFilter  = normalizeHealthStatus($_GET['health_status'] ?? '') ?? '';
$statusFilter  = normalizeGoatStatus($_GET['status'] ?? '') ?? '';
$farmerFilter  = ($currentRole !== 'user') ? (int)($_GET['farmer_id'] ?? 0) : 0;

$where  = [];
$params = [];

if ($currentRole === 'user') {
    $where[] = "g.user_id = :self_id";
    $params[':self_id'] = $currentUserId;
} elseif ($farmerFilter > 0) {
    $where[] = "g.user_id = :farmer_id";
    $params[':farmer_id'] = $farmerFilter;
}

if ($search !== '') {
    $where[] = "(g.goat_id LIKE :search OR g.goat_name LIKE :search OR g.tag_number LIKE :search OR g.breed LIKE :search"
             . ($currentRole !== 'user' ? " OR u.full_name LIKE :search" : "") . ")";
    $params[':search'] = '%' . $search . '%';
}

if ($sexFilter !== '') {
    $where[] = "g.sex = :sex";
    $params[':sex'] = $sexFilter;
}

if ($healthFilter !== '') {
    $where[] = "g.health_status = :health_status";
    $params[':health_status'] = $healthFilter;
}

if ($statusFilter !== '') {
    $where[] = "g.status = :status";
    $params[':status'] = $statusFilter;
}

$sql = "SELECT g.*, u.full_name AS owner_name, u.username AS owner_username
        FROM goats g
        JOIN users u ON u.id = g.user_id";
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY g.updated_at DESC";

$goats = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $goats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Goat Inventory] Main query failed: ' . $e->getMessage());
}

// ---------- Summary statistics ----------
$scopeWhere  = "";
$scopeParams = [];
if ($currentRole === 'user') {
    $scopeWhere = " WHERE user_id = :self_id";
    $scopeParams[':self_id'] = $currentUserId;
} elseif ($farmerFilter > 0) {
    $scopeWhere = " WHERE user_id = :farmer_id";
    $scopeParams[':farmer_id'] = $farmerFilter;
}

$activeCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM goats" . $scopeWhere . ($scopeWhere ? " AND status = 'active'" : " WHERE status = 'active'"));
    $stmt->execute($scopeParams);
    $activeCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Goat Inventory] Active count failed: ' . $e->getMessage());
}

$sexCounts = ['male' => 0, 'female' => 0];
try {
    $stmt = $pdo->prepare("SELECT sex, COUNT(*) as c FROM goats" . $scopeWhere . ($scopeWhere ? " AND status = 'active'" : " WHERE status = 'active'") . " GROUP BY sex");
    $stmt->execute($scopeParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['sex'], $sexCounts)) {
            $sexCounts[$row['sex']] = (int)$row['c'];
        }
    }
} catch (PDOException $e) {
    error_log('[Goat Inventory] Sex counts failed: ' . $e->getMessage());
}

$healthCounts = ['healthy' => 0, 'sick' => 0, 'unknown' => 0];
try {
    $stmt = $pdo->prepare("SELECT health_status, COUNT(*) as c FROM goats" . $scopeWhere . ($scopeWhere ? " AND status = 'active'" : " WHERE status = 'active'") . " GROUP BY health_status");
    $stmt->execute($scopeParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['health_status'], $healthCounts)) {
            $healthCounts[$row['health_status']] = (int)$row['c'];
        } else {
            $healthCounts['unknown'] += (int)$row['c'];
        }
    }
} catch (PDOException $e) {
    error_log('[Goat Inventory] Health counts failed: ' . $e->getMessage());
}

$soldCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM goats" . $scopeWhere . ($scopeWhere ? " AND status = 'sold'" : " WHERE status = 'sold'"));
    $stmt->execute($scopeParams);
    $soldCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Goat Inventory] Sold count failed: ' . $e->getMessage());
}

$deceasedCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM goats" . $scopeWhere . ($scopeWhere ? " AND status = 'deceased'" : " WHERE status = 'deceased'"));
    $stmt->execute($scopeParams);
    $deceasedCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Goat Inventory] Deceased count failed: ' . $e->getMessage());
}

// ---------- Farmer list ----------
$farmers = [];
if ($currentRole !== 'user') {
    try {
        $farmers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'user' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Goat Inventory] Farmer list failed: ' . $e->getMessage());
    }
}

$hasActiveFilters = (
    $search !== '' ||
    $sexFilter !== '' ||
    $healthFilter !== '' ||
    $statusFilter !== '' ||
    $farmerFilter > 0
);

$title = 'Goat Inventory';
renderHeader($title);
?>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

<style>
    /* ==========================================================
       DESIGN TOKENS
    ========================================================== */
    :root {
        --gf-primary: #16a34a;
        --gf-primary-dark: #14532d;
        --gf-primary-light: #dcfce7;
        --gf-bg: #f6f8fb;
        --gf-surface: #ffffff;
        --gf-text: #0f172a;
        --gf-text-secondary: #64748b;
        --gf-text-muted: #94a3b8;
        --gf-border: #eaedf2;
        --gf-border-strong: #dde3ea;
        --gf-success: #22c55e;
        --gf-danger: #ef4444;
        --gf-warning: #f59e0b;
        --gf-info: #3b82f6;
        --gf-pink: #ec4899;

        --gf-navbar-height: 68px;
        --gf-sidebar-width: 264px;
        --gf-radius: 14px;
        --gf-radius-sm: 10px;

        --gf-shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
        --gf-shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.05), 0 1px 2px rgba(15, 23, 42, 0.03);
        --gf-shadow-md: 0 4px 14px rgba(15, 23, 42, 0.06), 0 2px 6px rgba(15, 23, 42, 0.04);
        --gf-shadow-lg: 0 12px 28px rgba(15, 23, 42, 0.08), 0 4px 10px rgba(15, 23, 42, 0.05);

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
        -moz-osx-font-smoothing: grayscale;
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

    /* ==========================================================
       NAVBAR
    ========================================================== */
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
        width: 46px;
        height: 46px;
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
    .gf-navbar-logo img {
        width: 100%; height: 100%;
        object-fit: contain;
        padding: 6px;
    }

    .gf-navbar-brand-text {
        display: flex;
        flex-direction: column;
        line-height: 1.1;
    }
    .gf-navbar-title {
        font-size: 20px;
        font-weight: 800;
        color: #fff;
        letter-spacing: -0.02em;
    }
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
    .gf-navbar-links li a:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }
    .gf-navbar-links li a.active {
        background: #fff;
        color: var(--gf-primary-dark);
        font-weight: 700;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    }
    .gf-navbar-links li a.active i { color: var(--gf-primary); }
    .gf-navbar-links li a i {
        width: 16px;
        height: 16px;
        opacity: 0.85;
    }
    .gf-navbar-links li a.logout { color: #fca5a5; }
    .gf-navbar-links li a.logout:hover {
        background: rgba(220, 38, 38, 0.18);
        color: #fff;
    }

    .gf-navbar-toggle {
        display: none;
        width: 42px;
        height: 42px;
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

    /* ==========================================================
       SIDEBAR
    ========================================================== */
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
        width: 36px;
        height: 36px;
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

    .gf-brand {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .gf-brand-icon {
        width: 46px;
        height: 46px;
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
    .gf-brand-text {
        font-size: 16px;
        font-weight: 800;
        line-height: 1.2;
    }
    .gf-brand-sub {
        font-size: 10px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.65);
        margin-top: 3px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }

    .gf-nav-section {
        display: flex;
        flex-direction: column;
        gap: 4px;
        flex: 1;
        overflow-y: auto;
        padding-bottom: 10px;
    }

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
        transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease),
                    transform 0.15s var(--gf-ease);
        position: relative;
        flex-shrink: 0;
    }
    .gf-nav-link i {
        display: grid;
        place-items: center;
        width: 20px;
        height: 20px;
        flex-shrink: 0;
        opacity: 0.75;
    }
    .gf-nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
        transform: translateX(-2px);
    }
    .gf-nav-link:hover i { opacity: 1; }
    .gf-nav-link.active {
        background: #fff;
        color: var(--gf-primary-dark);
        font-weight: 700;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    }
    .gf-nav-link.active i { color: var(--gf-primary); opacity: 1; }
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
    .gf-nav-link.logout:hover {
        background: rgba(220, 38, 38, 0.15);
        color: #fff;
        border-radius: 11px;
    }

    /* ==========================================================
       MAIN
    ========================================================== */
    .gf-main {
        padding: calc(var(--gf-navbar-height) + 28px) clamp(16px, 3vw, 40px) 100px;
        min-height: 100vh;
        width: 100%;
        max-width: 1280px;
        margin: 0 auto;
        animation: gfFadeIn 0.4s var(--gf-ease-out);
    }
    @keyframes gfFadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .gf-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 22px;
        flex-wrap: wrap;
    }
    .gf-page-header h1 {
        margin: 0;
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -0.025em;
        line-height: 1.15;
    }
    .gf-page-header .gf-subtitle {
        margin: 6px 0 0;
        font-size: 13px;
        color: var(--gf-text-secondary);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .gf-page-header .gf-subtitle .live-dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--gf-success);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.6);
        animation: gfPulse 2s infinite;
    }
    @keyframes gfPulse {
        0%   { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); }
        70%  { box-shadow: 0 0 0 7px rgba(34, 197, 94, 0); }
        100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
    }

    /* ==========================================================
       SUMMARY BAR — enhanced
    ========================================================== */
    .summary-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 20px;
        padding: 12px 14px;
        background: var(--gf-surface);
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius);
        box-shadow: var(--gf-shadow-sm);
    }
    .summary-item {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--gf-text-secondary);
        background: #f8fafc;
        border: 1px solid transparent;
        white-space: nowrap;
        transition: background 0.18s var(--gf-ease), border-color 0.18s var(--gf-ease);
    }
    .summary-item:hover {
        background: #f1f5f9;
        border-color: var(--gf-border-strong);
    }
    .summary-item strong {
        color: var(--gf-text);
        font-weight: 800;
        font-size: 13px;
        letter-spacing: -0.01em;
    }
    .summary-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .dot-green  { background: var(--gf-success); }
    .dot-blue   { background: var(--gf-info); }
    .dot-pink   { background: var(--gf-pink); }
    .dot-red    { background: var(--gf-danger); }
    .dot-amber  { background: var(--gf-warning); }
    .dot-gray   { background: var(--gf-text-muted); }

    /* ==========================================================
       FILTER FORM — enhanced
    ========================================================== */
    .filter-form {
        background: var(--gf-surface);
        border-radius: var(--gf-radius);
        padding: 14px;
        border: 1px solid var(--gf-border);
        box-shadow: var(--gf-shadow-sm);
        margin-bottom: 20px;
    }
    .filter-group {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: flex-end;
    }
    .filter-group > div {
        flex: 1 1 150px;
        min-width: 120px;
    }
    .filter-group label {
        display: block;
        margin-bottom: 5px;
        color: var(--gf-text-secondary);
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .filter-group input,
    .filter-group select {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid var(--gf-border-strong);
        border-radius: var(--gf-radius-sm);
        font-size: 13.5px;
        font-family: inherit;
        background: #fff;
        color: var(--gf-text);
        transition: border-color 0.15s var(--gf-ease),
                    box-shadow 0.15s var(--gf-ease),
                    background 0.15s var(--gf-ease);
    }
    .filter-group input:hover,
    .filter-group select:hover {
        border-color: #c7d0db;
    }
    .filter-group input:focus,
    .filter-group select:focus {
        border-color: var(--gf-primary);
        outline: none;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.12);
    }
    .filter-group input::placeholder {
        color: var(--gf-text-muted);
    }
    .filter-actions {
        flex: 0 0 auto;
        display: flex;
        gap: 8px;
        align-items: center;
    }

    /* ==========================================================
       BUTTONS — enhanced
    ========================================================== */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        gap: 7px;
        padding: 9px 16px;
        border-radius: var(--gf-radius-sm);
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
        white-space: nowrap;
        -webkit-tap-highlight-color: transparent;
        transition: background 0.18s var(--gf-ease),
                    border-color 0.18s var(--gf-ease),
                    box-shadow 0.18s var(--gf-ease),
                    transform 0.12s var(--gf-ease);
    }
    .btn i { width: 14px; height: 14px; }
    .btn:active { transform: translateY(1px); }

    .btn-primary {
        background: var(--gf-primary);
        color: #fff;
        border-color: var(--gf-primary);
        box-shadow: 0 2px 6px rgba(22, 163, 74, 0.22);
    }
    .btn-primary:hover {
        background: #15803d;
        border-color: #15803d;
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.28);
        transform: translateY(-1px);
    }

    .btn-outline {
        background: #fff;
        color: var(--gf-primary);
        border-color: var(--gf-border-strong);
    }
    .btn-outline:hover {
        background: #f0fdf4;
        border-color: #86efac;
        color: #15803d;
        transform: translateY(-1px);
    }

    /* ==========================================================
       GOAT GRID — enhanced
    ========================================================== */
    .goat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 14px;
    }

    .goat-card {
        display: flex;
        flex-direction: column;
        background: var(--gf-surface);
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius);
        overflow: hidden;
        box-shadow: var(--gf-shadow-xs);
        transition: border-color 0.2s var(--gf-ease),
                    box-shadow 0.2s var(--gf-ease),
                    transform 0.2s var(--gf-ease-out);
        min-width: 0;
    }
    .goat-card:hover {
        border-color: #a7f3d0;
        box-shadow: var(--gf-shadow-md);
        transform: translateY(-3px);
    }

    .goat-card-photo {
        position: relative;
        width: 100%;
        height: 170px;
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
    .goat-card-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.4s var(--gf-ease-out);
    }
    .goat-card:hover .goat-card-photo img {
        transform: scale(1.04);
    }
    .goat-card-photo .no-photo {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        color: #86efac;
    }
    .goat-card-photo .no-photo i { width: 34px; height: 34px; }
    .goat-card-photo .no-photo span {
        font-size: 10px;
        color: var(--gf-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
    }

    /* Health indicator strip on top of the photo */
    .goat-card-photo .health-strip {
        position: absolute;
        top: 10px;
        left: 10px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
        color: var(--gf-text);
    }
    .goat-card-photo .health-strip .dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }

    .goat-card-body {
        padding: 14px 16px 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1;
    }

    .goat-card-id {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: var(--gf-primary);
        font-size: 11.5px;
        font-weight: 800;
        text-decoration: none;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        transition: color 0.15s var(--gf-ease);
    }
    .goat-card-id i { width: 12px; height: 12px; }
    .goat-card-id:hover { color: #15803d; }

    .goat-card-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--gf-text);
        line-height: 1.3;
        letter-spacing: -0.01em;
        overflow-wrap: anywhere;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .goat-card-name .tag-num {
        font-size: 12px;
        color: var(--gf-text-muted);
        font-weight: 500;
    }

    .goat-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 2px;
    }

    .meta-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 9px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid var(--gf-border);
        font-size: 11px;
        font-weight: 700;
        color: var(--gf-text-secondary);
        white-space: nowrap;
    }
    .meta-pill .dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .meta-pill.owner {
        background: #f1f5f9;
        color: #475569;
    }

    .goat-card-actions {
        display: flex;
        gap: 8px;
        margin-top: auto;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
    }
    .goat-card-actions .btn {
        flex: 1;
        min-height: 36px;
        font-size: 12.5px;
        padding: 7px 12px;
    }

    /* ==========================================================
       EMPTY STATE
    ========================================================== */
    .empty-state {
        padding: 56px 24px;
        background: var(--gf-surface);
        border: 2px dashed var(--gf-border-strong);
        border-radius: var(--gf-radius);
        text-align: center;
        color: var(--gf-text-secondary);
    }
    .empty-state .empty-icon {
        display: inline-grid;
        place-items: center;
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        color: #22c55e;
        margin-bottom: 14px;
    }
    .empty-state .empty-icon i { width: 32px; height: 32px; }
    .empty-state p {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: var(--gf-text);
        letter-spacing: -0.01em;
    }
    .empty-state .sub {
        margin-top: 6px;
        font-size: 13px;
        color: var(--gf-text-muted);
    }
    .empty-state .btn { margin-top: 18px; }

    /* ==========================================================
       BACKDROP
    ========================================================== */
    .gf-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
        z-index: 1055;
        cursor: pointer;
    }
    body.gf-sidebar-open .gf-backdrop {
        display: block;
        animation: gfFadeIn 0.2s var(--gf-ease);
    }

    /* ==========================================================
       RESPONSIVE
    ========================================================== */
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
        .gf-main { padding: calc(var(--gf-navbar-height) + 14px) 12px 100px; }
        .gf-page-header h1 { font-size: 20px; }
        .goat-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
        .goat-card-photo { height: 140px; }
        .goat-card-body { padding: 12px; }
        .goat-card-name { font-size: 14px; }
        .filter-group .filter-actions { width: 100%; }
        .filter-group .filter-actions .btn { flex: 1; }
    }

    @media (max-width: 430px) {
        .goat-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
        .goat-card-photo { height: 120px; }
        .goat-card-name { font-size: 13px; }
        .goat-card-actions .btn { font-size: 11px; padding: 6px 8px; }
        .meta-pill { font-size: 10px; padding: 3px 7px; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
        }
    }
</style>

<a href="#main-content" class="gf-skip-link">Skip to main content</a>

<!-- ============ NAVBAR ============ -->
<nav class="gf-navbar" aria-label="Main navigation">
    <a href="<?php echo BASE_URL; ?>/app/<?php echo htmlspecialchars($currentRole); ?>/dashboard.php" class="gf-navbar-brand">
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

    <button class="gf-navbar-toggle"
            type="button"
            aria-label="Open menu"
            aria-controls="gfSidebar"
            aria-expanded="false"
            onclick="toggleSidebar();">
        <i data-lucide="menu" aria-hidden="true" style="width:22px;height:22px;"></i>
    </button>
</nav>

<!-- ============ SIDEBAR ============ -->
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
                <div class="gf-brand-sub">Farmer</div>
            </div>
        </div>
        <button class="gf-sidebar-close"
                type="button"
                aria-label="Close menu"
                onclick="closeSidebar();">
            <i data-lucide="x" aria-hidden="true" style="width:18px;height:18px;"></i>
        </button>
    </div>

    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'inventory'); ?>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- ============ MAIN ============ -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="gf-main">

            <header class="gf-page-header">
                <div>
                    <h1>Goat Inventory</h1>
                    <p class="gf-subtitle">
                        <span class="live-dot" aria-hidden="true"></span>
                        <?php
                            $goatCount = count($goats);
                            echo $goatCount . ' goat' . ($goatCount === 1 ? '' : 's') . ' found';
                        ?>
                    </p>
                </div>
                <?php if (in_array($currentRole, ['user', 'admin'], true)): ?>
                    <a href="<?php echo BASE_URL; ?>/app/goats/goat-create.php" class="btn btn-primary">
                        <i data-lucide="plus" aria-hidden="true"></i>
                        Add Goat
                    </a>
                <?php endif; ?>
            </header>

            <!-- ===== SUMMARY BAR ===== -->
            <div class="summary-bar" role="status" aria-label="Inventory summary">
                <span class="summary-item">
                    <span class="summary-dot dot-green"></span>
                    Active <strong><?php echo $activeCount; ?></strong>
                </span>
                <span class="summary-item">
                    <span class="summary-dot dot-blue"></span>
                    Male <strong><?php echo $sexCounts['male']; ?></strong>
                </span>
                <span class="summary-item">
                    <span class="summary-dot dot-pink"></span>
                    Female <strong><?php echo $sexCounts['female']; ?></strong>
                </span>
                <span class="summary-item">
                    <span class="summary-dot dot-green"></span>
                    Healthy <strong><?php echo $healthCounts['healthy']; ?></strong>
                </span>
                <?php if ($healthCounts['sick'] > 0): ?>
                    <span class="summary-item">
                        <span class="summary-dot dot-red"></span>
                        Sick <strong style="color:#dc2626;"><?php echo $healthCounts['sick']; ?></strong>
                    </span>
                <?php endif; ?>
                <?php if ($healthCounts['unknown'] > 0): ?>
                    <span class="summary-item">
                        <span class="summary-dot dot-gray"></span>
                        Unknown <strong><?php echo $healthCounts['unknown']; ?></strong>
                    </span>
                <?php endif; ?>
                <?php if ($soldCount > 0): ?>
                    <span class="summary-item">
                        <span class="summary-dot dot-amber"></span>
                        Sold <strong><?php echo $soldCount; ?></strong>
                    </span>
                <?php endif; ?>
                <?php if ($deceasedCount > 0): ?>
                    <span class="summary-item">
                        <span class="summary-dot dot-gray"></span>
                        Deceased <strong><?php echo $deceasedCount; ?></strong>
                    </span>
                <?php endif; ?>
            </div>

            <!-- ===== FILTER FORM ===== -->
            <form method="GET" class="filter-form" role="search">
                <div class="filter-group">
                    <div>
                        <label for="q">Search</label>
                        <input type="text" id="q" name="q"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="ID, name, tag..."
                               autocomplete="off">
                    </div>

                    <?php if ($currentRole !== 'user'): ?>
                        <div>
                            <label for="farmer_id">Farmer</label>
                            <select id="farmer_id" name="farmer_id">
                                <option value="">All Farmers</option>
                                <?php foreach ($farmers as $f): ?>
                                    <option value="<?php echo (int)$f['id']; ?>"
                                            <?php echo $farmerFilter === (int)$f['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label for="sex">Sex</label>
                        <select id="sex" name="sex">
                            <option value="">All</option>
                            <option value="male"   <?php echo $sexFilter === 'male'   ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $sexFilter === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>

                    <div>
                        <label for="health_status">Health</label>
                        <select id="health_status" name="health_status">
                            <option value="">All</option>
                            <option value="healthy" <?php echo $healthFilter === 'healthy' ? 'selected' : ''; ?>>Healthy</option>
                            <option value="sick"    <?php echo $healthFilter === 'sick'    ? 'selected' : ''; ?>>Sick</option>
                            <option value="unknown" <?php echo $healthFilter === 'unknown' ? 'selected' : ''; ?>>Unknown</option>
                        </select>
                    </div>

                    <div>
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All</option>
                            <option value="active"   <?php echo $statusFilter === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="sold"     <?php echo $statusFilter === 'sold'     ? 'selected' : ''; ?>>Sold</option>
                            <option value="deceased" <?php echo $statusFilter === 'deceased' ? 'selected' : ''; ?>>Deceased</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="search" aria-hidden="true"></i>
                            Filter
                        </button>
                        <?php if ($hasActiveFilters): ?>
                            <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php" class="btn btn-outline">
                                <i data-lucide="x" aria-hidden="true"></i>
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- ===== GOAT LIST ===== -->
            <?php if (empty($goats)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i data-lucide="paw-print" aria-hidden="true"></i>
                    </div>
                    <p>No goat records found.</p>
                    <div class="sub">Try adjusting your filters or add a new goat.</div>
                    <?php if (in_array($currentRole, ['user', 'admin'], true)): ?>
                        <a href="<?php echo BASE_URL; ?>/app/goats/goat-create.php" class="btn btn-primary">
                            <i data-lucide="plus" aria-hidden="true"></i>
                            Add Your First Goat
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="goat-grid">
                    <?php foreach ($goats as $goat):
                        $healthColor = $goat['health_status'] === 'healthy'
                            ? '#22c55e'
                            : ($goat['health_status'] === 'sick' ? '#ef4444' : '#94a3b8');
                        $sexColor = $goat['sex'] === 'male' ? '#3b82f6' : '#ec4899';
                        $canEdit  = canEditGoat($currentRole, $currentUserId, $goat['user_id']);

                        $photoPath = trim((string)($goat['photo_path'] ?? ''));
                        $photoUrl  = '';

                        if ($photoPath !== '') {
                            if (preg_match('#^(https?:)?//#i', $photoPath) || str_starts_with($photoPath, '/')) {
                                $photoUrl = $photoPath;
                            } else {
                                $photoUrl = rtrim(BASE_URL, '/') . '/app/goats/' . ltrim($photoPath, '/');
                            }
                        }

                        $updatedTs = strtotime((string)($goat['updated_at'] ?? '')) ?: null;
                    ?>
                        <article class="goat-card">

                            <div class="goat-card-photo">
                                <?php if ($photoUrl !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                         alt="Photo of <?php echo htmlspecialchars($goat['goat_name']); ?>"
                                         loading="lazy"
                                         decoding="async"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="no-photo" style="display:none;" aria-hidden="true">
                                        <i data-lucide="image"></i>
                                        <span>No photo</span>
                                    </div>
                                <?php else: ?>
                                    <div class="no-photo" aria-hidden="true">
                                        <i data-lucide="image"></i>
                                        <span>No photo</span>
                                    </div>
                                <?php endif; ?>

                                <span class="health-strip" aria-label="Health status">
                                    <span class="dot" style="background: <?php echo $healthColor; ?>;"></span>
                                    <?php echo htmlspecialchars(healthStatusLabel($goat['health_status'])); ?>
                                </span>
                            </div>

                            <div class="goat-card-body">
                                <a href="goat-view.php?id=<?php echo urlencode($goat['id']); ?>" class="goat-card-id">
                                    <i data-lucide="tag" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($goat['goat_id']); ?>
                                </a>

                                <div class="goat-card-name">
                                    <?php echo htmlspecialchars($goat['goat_name']); ?>
                                    <?php if (!empty($goat['tag_number'])): ?>
                                        <span class="tag-num">(<?php echo htmlspecialchars($goat['tag_number']); ?>)</span>
                                    <?php endif; ?>
                                </div>

                                <div class="goat-card-meta">
                                    <span class="meta-pill">
                                        <span class="dot" style="background: <?php echo $sexColor; ?>;"></span>
                                        <?php echo ucfirst(htmlspecialchars($goat['sex'])); ?>
                                    </span>

                                    <?php if ($currentRole !== 'user' && !empty($goat['owner_name'])): ?>
                                        <span class="meta-pill owner">
                                            <i data-lucide="user" aria-hidden="true" style="width:11px;height:11px;"></i>
                                            <?php echo htmlspecialchars($goat['owner_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="goat-card-actions">
                                    <a href="goat-view.php?id=<?php echo urlencode($goat['id']); ?>" class="btn btn-outline">
                                        <i data-lucide="eye" aria-hidden="true"></i>
                                        View
                                    </a>
                                    <?php if ($canEdit): ?>
                                        <a href="goat-update.php?id=<?php echo urlencode($goat['id']); ?>" class="btn btn-primary">
                                            <i data-lucide="pencil" aria-hidden="true"></i>
                                            Edit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script>
    lucide.createIcons();

    function toggleSidebar() {
        document.body.classList.toggle('gf-sidebar-open');
        var isOpen = document.body.classList.contains('gf-sidebar-open');
        var toggleBtn = document.querySelector('.gf-navbar-toggle');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    function closeSidebar() {
        document.body.classList.remove('gf-sidebar-open');
        var toggleBtn = document.querySelector('.gf-navbar-toggle');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });
</script>

<?php renderFooter(); ?>    