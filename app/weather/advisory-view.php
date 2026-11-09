<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';

// ADMIN / MANAGER ONLY
$currentRole = $_SESSION['role'] ?? null;
if ($currentRole !== 'admin' && $currentRole !== 'manager') {
    http_response_code(403);
    exit('Access denied. Required role: admin or manager.');
}

$advisoryId = (int)($_GET['id'] ?? 0);
if ($advisoryId <= 0) {
    header('Location: advisory-logs.php');
    exit;
}

// ============================================================
// FETCH ADVISORY
// ============================================================
$stmt = $pdo->prepare("
    SELECT al.*, 
           u.full_name AS sender_name, 
           u.email AS sender_email,
           u.role AS sender_role
    FROM advisory_logs al
    LEFT JOIN users u ON al.sent_by = u.id
    WHERE al.id = :id
");
$stmt->execute([':id' => $advisoryId]);
$advisory = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$advisory) {
    header('Location: advisory-logs.php');
    exit;
}

// ============================================================
// FETCH RECIPIENTS
// ============================================================
$stmt = $pdo->prepare("
    SELECT ar.*, 
           u.full_name, 
           u.email, 
           u.barangay, 
           u.municipality,
           u.phone_number AS current_phone
    FROM advisory_recipients ar
    LEFT JOIN users u ON ar.user_id = u.id
    WHERE ar.advisory_id = :id
    ORDER BY 
        CASE ar.status 
            WHEN 'sent' THEN 1 
            WHEN 'pending' THEN 2 
            WHEN 'failed' THEN 3 
            ELSE 4 
        END,
        u.full_name ASC
");
$stmt->execute([':id' => $advisoryId]);
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// DECODE TRIGGER DATA
// ============================================================
$triggerData = json_decode($advisory['trigger_data'] ?? '{}', true);
if (!is_array($triggerData)) $triggerData = [];

// ============================================================
// STATS
// ============================================================
$stats = [
    'total' => count($recipients),
    'sent' => 0,
    'failed' => 0,
    'pending' => 0,
];
foreach ($recipients as $r) {
    if ($r['status'] === 'sent') $stats['sent']++;
    elseif ($r['status'] === 'failed') $stats['failed']++;
    else $stats['pending']++;
}

$deliveryRate = $stats['total'] > 0 
    ? round(($stats['sent'] / $stats['total']) * 100) 
    : 0;

$title = 'Advisory #' . $advisoryId;
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
        max-width: 1200px; margin: 0 auto; width: 100%; min-height: 100vh;
    }

    /* PAGE HEADER */
    .page-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
    }
    .page-head-left h1 {
        font-size: 22px; font-weight: 800; margin: 0 0 3px;
        letter-spacing: -0.02em; display: flex; align-items: center; gap: 10px;
    }
    .page-head-left h1 .id-badge {
        font-family: monospace; font-size: 14px; font-weight: 700;
        padding: 4px 10px; border-radius: 6px;
        background: var(--primary-light); color: var(--primary-dark);
    }
    .page-head-left p { font-size: 12.5px; color: var(--text-3); margin: 0; font-weight: 600; }
    .back-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 14px; background: var(--surface); color: var(--text-2);
        border: 1px solid var(--border); border-radius: 8px;
        font-size: 12.5px; font-weight: 700; transition: all 0.15s var(--ease);
    }
    .back-btn:hover { background: #f1f5f9; color: var(--text); }

    /* CARD */
    .card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 20px 22px; margin-bottom: 18px;
    }
    .card-title {
        font-size: 14px; font-weight: 800; color: var(--text);
        margin: 0 0 16px; padding-bottom: 12px;
        border-bottom: 1px solid var(--border-2);
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; flex-wrap: wrap;
    }

    /* ADVISORY MAIN INFO */
    .advisory-header {
        display: flex; align-items: flex-start;
        gap: 16px; margin-bottom: 18px; flex-wrap: wrap;
    }
    .advisory-icon {
        width: 56px; height: 56px; border-radius: 14px;
        display: grid; place-items: center;
        font-size: 26px; flex-shrink: 0;
    }
    .advisory-icon.rain    { background: var(--primary-light); }
    .advisory-icon.heat    { background: var(--red-light); }
    .advisory-icon.wind    { background: #e0e7ff; }
    .advisory-icon.general { background: #f1f5f9; }
    .advisory-meta { flex: 1; min-width: 0; }
    .advisory-meta h2 {
        font-size: 18px; font-weight: 800; margin: 0 0 8px;
        letter-spacing: -0.02em; color: var(--text);
    }
    .badge-row {
        display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px;
    }

    /* BADGES */
    .badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 10px; border-radius: 6px;
        font-size: 10.5px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.04em;
        white-space: nowrap;
    }
    .badge.rain    { background: #dbeafe; color: #1e40af; }
    .badge.heat    { background: #fee2e2; color: #b91c1c; }
    .badge.wind    { background: #e0e7ff; color: #3730a3; }
    .badge.general { background: #f1f5f9; color: #475569; }

    .badge.high   { background: #fee2e2; color: #b91c1c; }
    .badge.medium { background: #fef3c7; color: #92400e; }
    .badge.low    { background: #f1f5f9; color: #64748b; }

    .badge.sent    { background: var(--green-light); color: #166534; }
    .badge.failed  { background: var(--red-light); color: #b91c1c; }
    .badge.pending { background: #fef3c7; color: #92400e; }
    .badge.partial { background: #fef3c7; color: #92400e; }

    /* MESSAGE BOX */
    .message-box {
        background: #f8fafc; border: 1px solid var(--border-2);
        border-radius: var(--radius-sm); padding: 16px 18px;
        margin-bottom: 18px;
    }
    .message-label {
        font-size: 10.5px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.08em;
        margin-bottom: 8px;
    }
    .message-text {
        font-size: 14px; color: var(--text); line-height: 1.6;
        font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
        white-space: pre-wrap; word-break: break-word;
    }
    .char-count {
        font-size: 11px; color: var(--text-3);
        margin-top: 8px; font-weight: 600;
    }

    /* STATS GRID */
    .stats-grid {
        display: grid; grid-template-columns: repeat(4, 1fr);
        gap: 12px; margin-bottom: 18px;
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
    .stat-value.red   { color: var(--red); }
    .stat-value.amber { color: var(--amber); }

    /* PROGRESS BAR */
    .progress-wrap {
        margin-top: 10px;
    }
    .progress-bar {
        height: 8px; background: var(--border-2);
        border-radius: 999px; overflow: hidden;
    }
    .progress-fill {
        height: 100%; border-radius: 999px;
        background: linear-gradient(90deg, #22c55e, #16a34a);
        transition: width 0.4s var(--ease);
    }
    .progress-label {
        font-size: 11px; color: var(--text-3);
        margin-top: 4px; font-weight: 600;
    }

    /* DETAIL GRID */
    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    @media (max-width: 640px) { .detail-grid { grid-template-columns: 1fr; } }
    .detail-item {
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid var(--border-2);
        border-radius: var(--radius-sm);
    }
    .detail-label {
        font-size: 10.5px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.06em;
        margin-bottom: 5px;
    }
    .detail-value {
        font-size: 13.5px; font-weight: 700; color: var(--text);
    }
    .detail-value.mono { font-family: monospace; }

    /* RECIPIENTS TABLE */
    .table-wrapper { overflow-x: auto; margin: 0 -22px; padding: 0 22px; }
    @media (max-width: 640px) {
        .table-wrapper { margin: 0 -16px; padding: 0 16px; }
    }
    .recipients-table {
        width: 100%; border-collapse: collapse; font-size: 13px;
        min-width: 700px;
    }
    .recipients-table thead th {
        text-align: left; padding: 11px 14px;
        background: #fafbfc; color: var(--text-3);
        font-size: 10.5px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }
    .recipients-table tbody td {
        padding: 12px 14px; border-bottom: 1px solid var(--border-2);
        vertical-align: middle; color: var(--text-2);
    }
    .recipients-table tbody tr:last-child td { border-bottom: none; }
    .recipients-table tbody tr:hover { background: #fafbfc; }

    .recipient-info { display: flex; align-items: center; gap: 10px; }
    .recipient-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        display: grid; place-items: center;
        font-size: 11px; font-weight: 800; color: #fff;
        flex-shrink: 0; text-transform: uppercase;
        background: linear-gradient(135deg, #22c55e, #15803d);
    }
    .recipient-name {
        font-weight: 700; color: var(--text); font-size: 13px;
        line-height: 1.2;
    }
    .recipient-sub {
        font-size: 11px; color: var(--text-3);
        line-height: 1.3; margin-top: 1px;
    }
    .phone-code {
        font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
        font-size: 12.5px; font-weight: 600; color: var(--text);
        white-space: nowrap;
    }

    /* EMPTY STATE */
    .empty {
        padding: 40px 20px; text-align: center;
    }
    .empty-icon { font-size: 42px; margin-bottom: 10px; opacity: 0.4; }
    .empty p { margin: 0; font-size: 13.5px; color: var(--text-3); font-weight: 600; }

    /* TRIGGER DATA */
    .trigger-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 10px;
    }
    .trigger-item {
        padding: 10px 12px;
        background: #f8fafc; border: 1px solid var(--border-2);
        border-radius: 8px;
    }
    .trigger-key {
        font-size: 10px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.05em;
        margin-bottom: 3px;
        word-break: break-word;
    }
    .trigger-val {
        font-size: 14px; font-weight: 800; color: var(--text);
        letter-spacing: -0.02em;
    }

    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
        .main { padding: calc(var(--navbar-height) + 16px) 12px 60px; }
        .page-head-left h1 { font-size: 18px; }
        .page-head-left h1 .id-badge { font-size: 12px; padding: 3px 8px; }
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
            <span class="gf-navbar-sub"><?php echo ucfirst($currentRole); ?></span>
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
                    <h1>
                        Advisory
                        <span class="id-badge">#<?php echo (int)$advisory['id']; ?></span>
                    </h1>
                    <p>Detalye sa advisory nga gipadala sa farmers</p>
                </div>
                <a href="advisory-logs.php" class="back-btn">
                    ← Back to Logs
                </a>
            </div>

            <!-- ADVISORY MAIN INFO -->
            <div class="card">
                <?php
                $iconMap = [
                    'rain' => '🌧️',
                    'heat' => '🔥',
                    'wind' => '💨',
                    'general' => '📢',
                ];
                $icon = $iconMap[$advisory['advisory_type']] ?? '📢';
                ?>
                <div class="advisory-header">
                    <div class="advisory-icon <?php echo htmlspecialchars($advisory['advisory_type']); ?>">
                        <?php echo $icon; ?>
                    </div>
                    <div class="advisory-meta">
                        <h2><?php echo ucfirst($advisory['advisory_type']); ?> Advisory</h2>
                        <div class="badge-row">
                            <span class="badge <?php echo htmlspecialchars($advisory['advisory_type']); ?>">
                                <?php echo ucfirst($advisory['advisory_type']); ?>
                            </span>
                            <span class="badge <?php echo htmlspecialchars($advisory['priority']); ?>">
                                <?php echo ucfirst($advisory['priority']); ?> Priority
                            </span>
                            <span class="badge <?php echo htmlspecialchars($advisory['status']); ?>">
                                <?php echo ucfirst($advisory['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- MESSAGE -->
                <div class="message-box">
                    <div class="message-label">Message Content</div>
                    <div class="message-text"><?php echo htmlspecialchars($advisory['message']); ?></div>
                    <div class="char-count">
                        <?php echo mb_strlen($advisory['message']); ?> characters · 
                        Approx. <?php echo max(1, ceil(mb_strlen($advisory['message']) / 160)); ?> SMS
                    </div>
                </div>

                <!-- DELIVERY STATS -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Recipients</div>
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Successfully Sent</div>
                        <div class="stat-value green"><?php echo $stats['sent']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Failed</div>
                        <div class="stat-value red"><?php echo $stats['failed']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Delivery Rate</div>
                        <div class="stat-value <?php 
                            echo $deliveryRate >= 90 ? 'green' : ($deliveryRate >= 50 ? 'amber' : 'red'); 
                        ?>"><?php echo $deliveryRate; ?>%</div>
                    </div>
                </div>

                <!-- PROGRESS BAR -->
                <div class="progress-wrap">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $deliveryRate; ?>%;"></div>
                    </div>
                    <div class="progress-label">
                        <?php echo $stats['sent']; ?> of <?php echo $stats['total']; ?> recipients received the message
                    </div>
                </div>
            </div>

            <!-- METADATA -->
            <div class="card">
                <h3 class="card-title">📋 Advisory Details</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Sent By</div>
                        <div class="detail-value">
                            <?php if ($advisory['sender_name']): ?>
                                <?php echo htmlspecialchars($advisory['sender_name']); ?>
                                <div style="font-size: 11.5px; color: var(--text-3); font-weight: 600; margin-top: 2px;">
                                    <?php echo htmlspecialchars($advisory['sender_email']); ?>
                                    · <?php echo ucfirst($advisory['sender_role'] ?? 'system'); ?>
                                </div>
                            <?php else: ?>
                                <em style="color: var(--text-3);">Auto / System Generated</em>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created At</div>
                        <div class="detail-value">
                            <?php echo date('F j, Y', strtotime($advisory['created_at'])); ?>
                            <div style="font-size: 11.5px; color: var(--text-3); font-weight: 600; margin-top: 2px;">
                                <?php echo date('g:i A', strtotime($advisory['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TRIGGER DATA -->
            <?php if (!empty($triggerData)): ?>
                <div class="card">
                    <h3 class="card-title">⚡ Weather Trigger Data</h3>
                    <p style="font-size: 12px; color: var(--text-3); margin: 0 0 14px; font-weight: 600;">
                        Kini nga data gikan sa weather API sa diha nga gi-trigger ang advisory.
                    </p>
                    <div class="trigger-list">
                        <?php foreach ($triggerData as $key => $value): ?>
                            <div class="trigger-item">
                                <div class="trigger-key">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $key)); ?>
                                </div>
                                <div class="trigger-val">
                                    <?php 
                                    if (is_array($value)) {
                                        echo htmlspecialchars(json_encode($value));
                                    } elseif (is_numeric($value)) {
                                        echo htmlspecialchars((string)$value);
                                    } else {
                                        echo htmlspecialchars((string)$value);
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RECIPIENTS -->
            <div class="card">
                <h3 class="card-title">
                    <span>👥 Recipients (<?php echo count($recipients); ?>)</span>
                </h3>

                <?php if (empty($recipients)): ?>
                    <div class="empty">
                        <div class="empty-icon">📭</div>
                        <p>Walay recipients nga naka-record.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="recipients-table">
                            <thead>
                                <tr>
                                    <th>Farmer</th>
                                    <th>Phone</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Sent At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipients as $r): 
                                    $initials = strtoupper(substr($r['full_name'] ?: 'U', 0, 1));
                                    if (!empty($r['full_name'])) {
                                        $parts = preg_split('/\s+/', trim($r['full_name']));
                                        if (count($parts) >= 2) {
                                            $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                                        }
                                    }
                                    
                                    $location = [];
                                    if (!empty($r['barangay'])) $location[] = $r['barangay'];
                                    if (!empty($r['municipality'])) $location[] = $r['municipality'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="recipient-info">
                                                <div class="recipient-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                                <div>
                                                    <div class="recipient-name">
                                                        <?php echo htmlspecialchars($r['full_name'] ?? 'Unknown User'); ?>
                                                    </div>
                                                    <div class="recipient-sub">
                                                        <?php echo htmlspecialchars($r['email'] ?? '—'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="phone-code">
                                                <?php echo htmlspecialchars(formatPhoneDisplay($r['phone_number'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (empty($location)): ?>
                                                <span style="color: var(--text-3); font-style: italic;">—</span>
                                            <?php else: ?>
                                                <span style="font-size: 12.5px;">
                                                    <?php echo htmlspecialchars(implode(', ', $location)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo htmlspecialchars($r['status']); ?>">
                                                <?php echo ucfirst($r['status']); ?>
                                            </span>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <?php if ($r['sent_at']): ?>
                                                <div style="font-size: 12.5px; font-weight: 700; color: var(--text);">
                                                    <?php echo date('M j, Y', strtotime($r['sent_at'])); ?>
                                                </div>
                                                <div style="font-size: 11px; color: var(--text-3);">
                                                    <?php echo date('g:i A', strtotime($r['sent_at'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size: 12px; color: var(--text-3); font-style: italic;">—</span>
                                            <?php endif; ?>
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

<?php renderFooter(); ?>