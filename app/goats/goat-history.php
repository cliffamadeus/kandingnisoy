<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-functions.php';
requireLogin();

$currentRole   = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// Lookup by primary key `id`
$goatPk = (int) trim($_GET['id'] ?? '');

$goat = null;
if ($goatPk > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM goats WHERE id = ?");
        $stmt->execute([$goatPk]);
        $goat = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('[Goat History] Goat lookup failed: ' . $e->getMessage());
        $goat = null;
    }
}

$history = [];
$hasAccess = $goat && canAccessGoat($currentRole, $currentUserId, $goat['user_id']);

if ($hasAccess) {
    // IMPORTANT: activity_logs is joined on the goat_id STRING code
    // (e.g. "G-0002"), not the unique PK. Because goat_id is only unique
    // per-farmer (each farmer has their own G-0001, G-0002, ...), we ALSO
    // scope by owner_id when the column is available, so a farmer never
    // sees activity belonging to a different farmer's goat that happens
    // to share the same string code.
    //
    // TODO/VERIFY: confirm the exact column name (owner_id vs user_id)
    // and whether activity_logs stores a goat_pk column. If a goat_pk
    // column exists, switch the WHERE clause to filter by that instead.
    try {
        $stmt = $pdo->prepare("
            SELECT al.*, u.full_name AS actor_name, u.role AS actor_role
            FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.goat_id = ?
              AND (al.owner_id = ? OR al.owner_id IS NULL)
            ORDER BY al.created_at DESC
        ");
        $stmt->execute([$goat['goat_id'], $goat['user_id']]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If the owner_id column doesn't exist yet, fall back to the
        // original (looser) query rather than failing entirely.
        error_log('[Goat History] Scoped history query failed, falling back: ' . $e->getMessage());
        try {
            $stmt = $pdo->prepare("
                SELECT al.*, u.full_name AS actor_name, u.role AS actor_role
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.goat_id = ?
                ORDER BY al.created_at DESC
            ");
            $stmt->execute([$goat['goat_id']]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            error_log('[Goat History] Fallback history query failed: ' . $e2->getMessage());
            $history = [];
        }
    }
}

function friendlyGoatAction($action) {
    $map = [
        'goat_registered' => 'Goat Registered',
        'goat_registration_rejected_duplicate' => 'Registration Rejected (Duplicate Goat ID)',
        'goat_viewed' => 'Record Viewed',
        'goat_updated' => 'Information Updated',
        'goat_health_status_changed' => 'Health Status Changed',
        'goat_status_changed' => 'Goat Status Changed',
        'goat_marked_sold' => 'Marked as Sold',
        'goat_marked_deceased' => 'Marked as Deceased',
        'goat_ownership_corrected' => 'Ownership Corrected (Administrative Action)',
    ];
    return $map[$action] ?? ucwords(str_replace('_', ' ', (string)$action));
}

$title = 'Goat History';
renderHeader($title);
?>

<style>
    /* ==========================================================
       GOAT HISTORY STYLES
       Matches the design system used by dashboard.php,
       app/goats/dashboard.php, goat-create.php, and goat-view.php.
    ========================================================== */

    *, *::before, *::after { box-sizing: border-box; }

    html, body {
        min-height: 100%;
        margin: 0;
        padding: 0;
        background: #f8fafc;
        color: #172033;
        font-family: system-ui, -apple-system, BlinkMacSystemFont,
            "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        overflow-x: hidden;
    }

    img, svg { max-width: 100%; height: auto; }

    .main-content {
        width: 100%;
        max-width: 100%;
        min-height: 100vh;
        margin: 0 auto;
        background: #f8fafc;
        overflow-x: hidden;
    }
    .main-content .container {
        width: 100%;
        max-width: none;
        min-height: 100vh;
        margin: 0 auto;
        padding: 0;
    }

    /* ---------- Layout wrapper (matches dashboards) ---------- */
    .dashboard-wrap {
        width: min(100%, 1240px);
        max-width: 1240px;
        margin: 0 auto;
        padding: 22px clamp(16px, 3vw, 36px) 42px;
        display: flex;
        flex-direction: column;
        background: #f8fafc;
    }
    /* Slightly wider than view-narrow — history tables have 5 columns. */
    .dashboard-wrap.history-wide {
        max-width: 1080px;
    }

    /* ---------- Header (matches dashboards) ---------- */
    .header-grass {
        position: relative;
        overflow: hidden;
        margin-bottom: 16px;
        padding: 26px 28px;
        border-radius: 18px;
        background:
            radial-gradient(circle at 88% 18%, rgba(255,255,255,.14) 0 70px, transparent 71px),
            linear-gradient(135deg, #14532d 0%, #15803d 55%, #22c55e 100%);
        box-shadow: 0 10px 26px rgba(22,101,52,.16);
    }
    .header-grass::after {
        position: absolute;
        right: -45px;
        bottom: -70px;
        width: 190px;
        height: 190px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
        content: "";
        pointer-events: none;
    }
    .header-inner {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
    }
    .header-title-row {
        display: flex;
        align-items: center;
        min-width: 0;
        gap: 14px;
    }
    .header-goat {
        flex-shrink: 0;
        font-size: 40px;
        line-height: 1;
    }
    .header-grass h1 {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        margin: 0;
        color: #ffffff;
        font-size: clamp(25px, 2.4vw, 32px);
        font-weight: 800;
        line-height: 1.15;
        letter-spacing: -0.025em;
    }
    .header-grass p {
        margin: 8px 0 0;
        overflow-wrap: anywhere;
        color: rgba(255, 255, 255, 0.86);
        font-size: 14px;
    }
    .header-role-badge,
    .header-date-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        color: #ffffff;
        font-weight: 650;
    }
    .header-role-badge {
        padding: 4px 10px;
        background: rgba(255,255,255,.16);
        border: 1px solid rgba(255,255,255,.18);
        font-size: 12px;
        backdrop-filter: blur(4px);
    }
    .header-date-badge {
        padding: 9px 13px;
        background: rgba(0,0,0,.12);
        border: 1px solid rgba(255,255,255,.12);
        font-size: 13px;
        white-space: nowrap;
    }

    /* ---------- Top navigation (matches dashboards) ---------- */
    .dash-nav {
        display: flex;
        gap: 2px;
        overflow-x: auto;
        margin-bottom: 26px;
        padding: 0 0 10px;
        border-bottom: 1px solid #d7e2dc;
        scrollbar-width: thin;
    }
    .dash-nav::-webkit-scrollbar { height: 6px; }
    .dash-nav::-webkit-scrollbar-thumb { background: #bbf7d0; border-radius: 999px; }
    .dash-nav::-webkit-scrollbar-track { background: transparent; }

    .dash-nav-link {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        min-height: 40px;
        gap: 7px;
        padding: 8px 14px;
        border-radius: 9px;
        color: #64748b;
        font-size: 14px;
        font-weight: 650;
        text-decoration: none;
        white-space: nowrap;
        -webkit-tap-highlight-color: transparent;
        transition: color 0.2s ease, background 0.2s ease, transform 0.2s ease;
    }
    .dash-nav-link:hover,
    .dash-nav-link.active {
        background: #f0fdf4;
        color: #166534;
    }
    .dash-nav-link:hover { transform: translateY(-1px); }
    .dash-nav-link:active { transform: translateY(0); }
    .dash-nav-link i { color: #22c55e; }
    .dash-nav-link.logout { margin-left: auto; color: #dc2626; }
    .dash-nav-link.logout i { color: #dc2626; }

    /* ---------- Card primitive (matches dashboards) ---------- */
    .card {
        padding: 18px;
        border: 1px solid #e2e8f0;
        border-radius: 11px;
        background: #ffffff;
        box-shadow: 0 2px 8px rgba(15,23,42,.045);
        min-width: 0;
    }

    .card-title {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 16px;
        color: #172033;
        font-size: 18px;
        font-weight: 800;
        letter-spacing: -0.01em;
    }
    .card-title i { color: #22c55e; }

    /* ---------- Buttons (matches dashboards) ---------- */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        gap: 7px;
        padding: 9px 15px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        -webkit-tap-highlight-color: transparent;
        cursor: pointer;
        transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }
    .btn-primary {
        border: 1px solid #15803d;
        background: #15803d;
        color: #ffffff;
        box-shadow: 0 3px 8px rgba(21,128,61,.14);
    }
    .btn-primary:hover {
        background: #166534;
        box-shadow: 0 6px 14px rgba(21, 128, 61, 0.24);
        transform: translateY(-1px);
    }
    .btn-secondary, .btn-outline {
        border: 1px solid #d5e6d9;
        background: #ffffff;
        color: #15803d;
    }
    .btn-secondary:hover, .btn-outline:hover {
        background: #f0fdf4;
        border-color: #86efac;
        box-shadow: 0 4px 10px rgba(21, 128, 61, 0.12);
        transform: translateY(-1px);
    }
    .btn:active { box-shadow: none; transform: translateY(1px); }

    .btn:focus-visible,
    .dash-nav-link:focus-visible {
        outline: 3px solid rgba(34, 197, 94, 0.45);
        outline-offset: 3px;
    }

    .btn-group {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid #e2e8f0;
    }

    /* ---------- Badges (matches dashboard badge language) ---------- */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.25;
        white-space: nowrap;
    }
    .badge-success   { background: #dcfce7; color: #166534; }
    .badge-danger    { background: #fef3c7; color: #92400e; }
    .badge-warning   { background: #fef9c3; color: #854d0e; }
    .badge-secondary { background: #f3f4f6; color: #6b7280; }

    /* ---------- History table ---------- */
    .table-wrapper {
        overflow-x: auto;
        margin-top: 8px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        -webkit-overflow-scrolling: touch;
    }
    .table-wrapper table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        min-width: 720px;
    }
    .table-wrapper th {
        text-align: left;
        padding: 11px 14px;
        background: #f8fafc;
        color: #64748b;
        font-weight: 750;
        font-size: 11px;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .table-wrapper td {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: top;
        color: #1e293b;
        overflow-wrap: anywhere;
    }
    .table-wrapper tr:last-child td { border-bottom: none; }
    .table-wrapper tr:hover td { background: #fafff7; }

    /* Details cell: key/value mini list */
    .details-list {
        display: flex;
        flex-direction: column;
        gap: 3px;
        font-size: 12.5px;
    }
    .details-list .kv strong {
        color: #475569;
        font-weight: 700;
        margin-right: 4px;
    }
    .details-list .kv span { color: #1e293b; }

    /* ---------- Empty / error state (matches dashboard pattern) ---------- */
    .empty-state {
        padding: 40px 20px;
        color: #64748b;
        text-align: center;
    }
    .empty-state i {
        display: block;
        margin-bottom: 12px;
        color: #86efac;
        font-size: 56px;
    }
    .empty-state p {
        margin: 0;
        color: #1e293b;
        font-size: 17px;
        font-weight: 650;
    }
    .empty-state .sub {
        margin-top: 6px;
        color: #94a3b8;
        font-size: 14px;
    }
    .empty-state .btn { margin-top: 16px; }

    .error-state i { color: #fca5a5; }

    /* ---------- Responsive ---------- */
    @media (max-width: 700px) {
        .dashboard-wrap { padding: 16px 12px 36px; }
        .header-grass { padding: 20px 16px; border-radius: 14px; }
        .header-inner {
            align-items: flex-start;
            flex-direction: column;
        }
        .header-grass h1 { font-size: 24px; }
        .header-goat { font-size: 34px; }
        .card-title { font-size: 16px; }
    }
    @media (max-width: 600px) {
        .header-grass { padding: 20px 18px; }
        .header-date-badge { align-self: stretch; justify-content: center; }
        .table-wrapper th, .table-wrapper td { padding: 10px 12px; font-size: 13px; }
    }
    @media (max-width: 430px) {
        .dashboard-wrap { padding-right: 10px; padding-left: 10px; }
        .header-title-row { gap: 10px; }
        .header-grass h1 { font-size: 22px; }
        .header-role-badge { font-size: 11px; padding: 4px 8px; }
        .header-date-badge { width: 100%; justify-content: center; }
        .card { padding: 14px; }
        .btn-group { flex-direction: column; }
        .btn-group .btn { width: 100%; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            scroll-behavior: auto !important;
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
        }
    }
</style>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<main class="dashboard-wrap history-wide">

    <!-- ===== HEADER ===== -->
    <header class="header-grass">
        <div class="header-inner">
            <div class="header-title-row">
                <span class="header-goat" aria-hidden="true">🐐</span>
                <div style="min-width: 0;">
                    <h1>
                        Goat History
                        <?php if ($goat): ?>
                            <span class="header-role-badge"><?php echo htmlspecialchars($goat['goat_id']); ?></span>
                        <?php endif; ?>
                    </h1>
                    <p>
                        <i class="fas fa-history" aria-hidden="true"></i>
                        Audit trail for this goat
                    </p>
                </div>
            </div>

            <span class="header-date-badge">
                <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                <?php echo date('M d, Y'); ?>
            </span>
        </div>
    </header>

    <!-- ===== NAVIGATION ===== -->
    <nav class="dash-nav" aria-label="Goat history navigation">
        <a href="<?php echo BASE_URL; ?>/app/goats/goat-view.php?id=<?php echo urlencode($goatPk); ?>" class="dash-nav-link">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            Goat Details
        </a>
        <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php" class="dash-nav-link">
            <i class="fas fa-goat" aria-hidden="true"></i>
            Inventory
        </a>
        <a href="<?php echo BASE_URL; ?>/app/<?php echo htmlspecialchars($currentRole); ?>/dashboard.php" class="dash-nav-link">
            <i class="fas fa-tachometer-alt" aria-hidden="true"></i>
            Dashboard
        </a>
        <a href="<?php echo BASE_URL; ?>/app/auth/signout.php" class="dash-nav-link logout">
            <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
            Logout
        </a>
    </nav>

    <!-- ===== HISTORY CARD ===== -->
    <div class="card">
        <h2 class="card-title">
            <i class="fas fa-clock" aria-hidden="true"></i>
            Activity Log
            <?php if ($goat && $hasAccess): ?>
                <span class="badge badge-secondary">
                    <?php echo count($history); ?>
                    <?php echo count($history) === 1 ? 'entry' : 'entries'; ?>
                </span>
            <?php endif; ?>
        </h2>

        <?php if (!$goat): ?>
            <div class="empty-state error-state">
                <i class="fas fa-search" aria-hidden="true"></i>
                <p>Goat record not found.</p>
                <div class="sub">The requested goat does not exist or may have been removed.</div>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Back to Inventory
                </a>
            </div>
        <?php elseif (!$hasAccess): ?>
            <div class="empty-state error-state">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <p>You are not authorized to access this goat record.</p>
                <div class="sub">If you believe this is an error, please contact an administrator.</div>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Back to Inventory
                </a>
            </div>
        <?php elseif (empty($history)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <p>No history recorded for this goat yet.</p>
                <div class="sub">Activity will appear here as this goat's record is viewed or updated.</div>
                <a href="<?php echo BASE_URL; ?>/app/goats/goat-view.php?id=<?php echo urlencode($goatPk); ?>" class="btn btn-primary">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Back to Goat Details
                </a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Date / Time</th>
                            <th scope="col">Action</th>
                            <th scope="col">Details</th>
                            <th scope="col">Performed By</th>
                            <th scope="col">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <?php
                                $details = null;
                                if (!empty($entry['details'])) {
                                    $decoded = json_decode($entry['details'], true);
                                    if (is_array($decoded)) {
                                        $details = $decoded;
                                    }
                                }

                                // Null-safe timestamp formatting.
                                $entryTs = strtotime((string)($entry['created_at'] ?? '')) ?: null;

                                $actorLabel = $entry['actor_name']
                                    ?? $entry['email']
                                    ?? 'Unknown';

                                $statusLabel = ucfirst((string)($entry['status'] ?? 'unknown'));
                                $statusClass = ($entry['status'] ?? '') === 'success'
                                    ? 'badge-success'
                                    : 'badge-danger';
                            ?>
                            <tr>
                                <td>
                                    <?php echo $entryTs ? date('M j, Y · g:i a', $entryTs) : '—'; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars(friendlyGoatAction($entry['action'] ?? '')); ?>
                                </td>
                                <td>
                                    <?php if ($details): ?>
                                        <div class="details-list">
                                            <?php foreach ($details as $k => $v): ?>
                                                <?php
                                                    $keyLabel = ucwords(str_replace('_', ' ', (string)$k));
                                                    if (is_scalar($v) || $v === null) {
                                                        $valLabel = $v === null ? '—' : (string)$v;
                                                    } else {
                                                        $valLabel = json_encode($v);
                                                    }
                                                ?>
                                                <div class="kv">
                                                    <strong><?php echo htmlspecialchars($keyLabel); ?>:</strong>
                                                    <span><?php echo htmlspecialchars($valLabel); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($actorLabel); ?></td>
                                <td>
                                    <span class="badge <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

<?php renderFooter(); ?>