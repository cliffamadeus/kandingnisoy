<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-logger.php';
require_once '../../includes/goat-functions.php';
requireLogin();

$currentRole   = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// Look up by primary key `id`
$goatPk = (int) trim($_GET['id'] ?? '');

$goat = null;
if ($goatPk > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM goats WHERE id = ?");
        $stmt->execute([$goatPk]);
        $goat = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('[Goat Update] Goat lookup failed: ' . $e->getMessage());
        $goat = null;
    }
}

if (!$goat) {
    renderHeader('Edit Goat');
    ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            min-height: 100%;
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            color: #0f172a;
            font-family: system-ui, -apple-system, BlinkMacSystemFont,
                "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .fallback-wrap {
            width: min(100%, 520px);
            margin: 0 auto;
            padding: 80px clamp(16px, 3vw, 36px);
        }
        .fallback-card {
            padding: 40px 32px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(15,23,42,.08);
            text-align: center;
        }
        .fallback-card svg { margin: 0 auto 20px; color: #fca5a5; }
        .fallback-card h2 {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 20px;
            font-weight: 800;
        }
        .fallback-card p {
            margin: 0 0 24px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }
        .fallback-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 44px;
            padding: 0 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            background: linear-gradient(145deg, #22C55E, #15803D);
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(34,197,94,.25);
            transition: all 0.15s ease;
        }
        .fallback-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(34,197,94,.3);
            color: #fff;
        }
    </style>
    <main class="fallback-wrap">
        <div class="fallback-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
            </svg>
            <h2>Goat record not found</h2>
            <p>The requested goat does not exist or may have been removed.</p>
            <a href="dashboard.php" class="fallback-btn">
                ← Back to Inventory
            </a>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

// Permissions
$canFullEdit     = canEditGoat($currentRole, $currentUserId, $goat['user_id']);
$canHealthOnly   = canUpdateHealthStatus($currentRole, $currentUserId, $goat['user_id']);
$canCorrectOwner = canCorrectOwnership($currentRole);

if (!$canFullEdit && !$canHealthOnly) {
    renderHeader('Edit Goat');
    ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            min-height: 100%;
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            color: #0f172a;
            font-family: system-ui, -apple-system, BlinkMacSystemFont,
                "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .fallback-wrap {
            width: min(100%, 520px);
            margin: 0 auto;
            padding: 80px clamp(16px, 3vw, 36px);
        }
        .fallback-card {
            padding: 40px 32px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(15,23,42,.08);
            text-align: center;
        }
        .fallback-card svg { margin: 0 auto 20px; color: #fca5a5; }
        .fallback-card h2 {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 20px;
            font-weight: 800;
        }
        .fallback-card p {
            margin: 0 0 24px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }
        .fallback-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 44px;
            padding: 0 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            background: linear-gradient(145deg, #22C55E, #15803D);
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(34,197,94,.25);
            transition: all 0.15s ease;
        }
        .fallback-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(34,197,94,.3);
            color: #fff;
        }
    </style>
    <main class="fallback-wrap">
        <div class="fallback-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <h2>Access denied</h2>
            <p>You are not authorized to access this goat record.</p>
            <a href="dashboard.php" class="fallback-btn">
                ← Back to Inventory
            </a>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

$farmers = [];
if ($canCorrectOwner) {
    try {
        $farmers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'user' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Goat Update] Farmer list failed: ' . $e->getMessage());
        $farmers = [];
    }
}

$message = '';
$success = false;
$errors  = [];

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates = [];
    $params = [];
    $logEntries = [];

    // ---- Farmer-editable fields ----
    if ($canFullEdit) {
        $goat_name = trim($_POST['goat_name'] ?? $goat['goat_name']);
        $tag_number = trim($_POST['tag_number'] ?? '');
        $breed = trim($_POST['breed'] ?? $goat['breed']);
        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $identifying_characteristics = trim($_POST['identifying_characteristics'] ?? '');
        $date_acquired = trim($_POST['date_acquired'] ?? '');

        if ($goat_name === '') $errors[] = "Goat Name is required.";
        if ($breed === '') $errors[] = "Breed is required.";
        if ($date_of_birth !== '' && !DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
            $errors[] = "Date of Birth must be a valid date.";
        }
        if ($date_acquired !== '' && !DateTime::createFromFormat('Y-m-d', $date_acquired)) {
            $errors[] = "Date Acquired must be a valid date.";
        }

        if (empty($errors)) {
            if ($goat_name !== $goat['goat_name']) { $updates[] = "goat_name = :goat_name"; $params[':goat_name'] = $goat_name; }
            if ($tag_number !== (string)$goat['tag_number']) { $updates[] = "tag_number = :tag_number"; $params[':tag_number'] = $tag_number !== '' ? $tag_number : null; }
            if ($breed !== $goat['breed']) { $updates[] = "breed = :breed"; $params[':breed'] = $breed; }
            if ($date_of_birth !== (string)$goat['date_of_birth']) { $updates[] = "date_of_birth = :dob"; $params[':dob'] = $date_of_birth !== '' ? $date_of_birth : null; }
            if ($color !== (string)$goat['color']) { $updates[] = "color = :color"; $params[':color'] = $color !== '' ? $color : null; }
            if ($identifying_characteristics !== (string)$goat['identifying_characteristics']) { $updates[] = "identifying_characteristics = :characteristics"; $params[':characteristics'] = $identifying_characteristics !== '' ? $identifying_characteristics : null; }
            if ($date_acquired !== (string)$goat['date_acquired']) { $updates[] = "date_acquired = :date_acquired"; $params[':date_acquired'] = $date_acquired !== '' ? $date_acquired : null; }

            if (!empty($updates)) {
                $logEntries[] = ['goat_updated', ['field' => 'info']];
            }

            $newStatus = normalizeGoatStatus($_POST['status'] ?? $goat['status']);
            if ($newStatus === null) {
                $errors[] = "The selected goat status is not valid.";
            } elseif ($newStatus !== $goat['status']) {
                $updates[] = "status = :status";
                $params[':status'] = $newStatus;
                $statusAction = ($newStatus === 'sold') ? 'goat_marked_sold'
                              : (($newStatus === 'deceased') ? 'goat_marked_deceased' : 'goat_status_changed');
                $logEntries[] = [$statusAction, ['previous' => $goat['status'], 'new' => $newStatus]];
            }
        }
    }

    // ---- Health status ----
    if (($canFullEdit || $canHealthOnly) && empty($errors)) {
        $newHealth = normalizeHealthStatus($_POST['health_status'] ?? $goat['health_status']);
        if ($newHealth === null) {
            $errors[] = "The selected health status is not valid.";
        } elseif ($newHealth !== $goat['health_status']) {
            $updates[] = "health_status = :health_status";
            $params[':health_status'] = $newHealth;
            $logEntries[] = ['goat_health_status_changed', ['previous' => $goat['health_status'], 'new' => $newHealth]];
        }
    }

    // ---- Ownership correction (Admin only) ----
    if ($canCorrectOwner && empty($errors)) {
        $newOwnerId = (int)($_POST['owner_id'] ?? $goat['user_id']);
        if ($newOwnerId !== (int)$goat['user_id']) {
            $ownerIsValidFarmer = false;
            foreach ($farmers as $f) {
                if ((int)$f['id'] === $newOwnerId) { $ownerIsValidFarmer = true; break; }
            }
            if (!$ownerIsValidFarmer) {
                $errors[] = "Please select a valid Farmer for the ownership correction.";
            } else {
                $updates[] = "user_id = :owner_id";
                $params[':owner_id'] = $newOwnerId;
                $logEntries[] = ['goat_ownership_corrected', ['previous_owner' => (int)$goat['user_id'], 'new_owner' => $newOwnerId]];
            }
        }
    }

    // ---- Execute ----
    if (!empty($errors)) {
        $message = implode(' ', $errors);
        $success = false;
    } elseif (empty($updates)) {
        $message = "No changes were made.";
        $success = false;
    } else {
        try {
            $updates[] = "updated_at = NOW()";
            $params[':goat_id_pk'] = $goat['id'];
            $sql = "UPDATE goats SET " . implode(', ', $updates) . " WHERE id = :goat_id_pk";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            foreach ($logEntries as $entry) {
                try {
                    logGoatActivity($pdo, $currentUserId, $_SESSION['email'], $entry[0], 'success', $goat['goat_id'], $entry[1]);
                } catch (Exception $e) {
                    error_log('[Goat Update] Activity log failed: ' . $e->getMessage());
                }
            }

            $message = "Goat record updated successfully!";
            $success = true;

            // Refresh goat data
            $stmt = $pdo->prepare("SELECT * FROM goats WHERE id = ?");
            $stmt->execute([$goat['id']]);
            $refreshed = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($refreshed) {
                $goat = $refreshed;
            }

        } catch (PDOException $e) {
            error_log('[Goat Update] Update failed: ' . $e->getMessage());
            $message = "Error updating goat record. Please try again.";
            $success = false;
            try {
                logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_updated', 'failed', $goat['goat_id']);
            } catch (Exception $e2) {
                error_log('[Goat Update] Failure log failed: ' . $e2->getMessage());
            }
        }
    }
}

$title = 'Edit Goat - ' . htmlspecialchars($goat['goat_id']);
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
            'user-plus'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'list-check'    => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m15 9 2 2 4-4"/>',
            'tractor'       => '<path d="m10 11 11 .9a1 1 0 0 1 .9 1v1.6a1 1 0 0 1-1 1H15"/><path d="M3 4h9l1 7"/><path d="M4 11V4"/><circle cx="7" cy="17" r="3"/><circle cx="18" cy="17" r="3"/>',
            'arrow-left'    => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
            'eye'           => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'pencil'        => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
            'save'          => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'alert-circle'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'user-shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><circle cx="12" cy="10" r="2"/><path d="M8 16c.5-1.5 2-2.5 4-2.5s3.5 1 4 2.5"/>',
            'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'lock'          => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'shield-alert'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
            'goat'          => '<path d="M6 8c-1.5-1.5-2.5-3.5-2-5.5.8.2 2.2 1 3 2.2"/><path d="M18 8c1.5-1.5 2.5-3.5 2-5.5-.8.2-2.2 1-3 2.2"/><path d="M7 9a5 5 0 0 1 10 0v3a5 5 0 0 1-5 5 5 5 0 0 1-5-5V9Z"/><circle cx="10" cy="11" r=".8" fill="currentColor" stroke="none"/><circle cx="14" cy="11" r=".8" fill="currentColor" stroke="none"/><path d="M11 15h2"/>',
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
        max-width: 920px;
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
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .gf-page-header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .gf-page-header h1 {
        margin: 0;
        font-size: 25px;
        font-weight: 800;
        letter-spacing: -0.025em;
        line-height: 1.15;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .gf-page-header .gf-welcome {
        margin: 5px 0 0;
        font-size: 13px;
        color: var(--gf-text-muted);
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

    /* ---------- INFO BOXES ---------- */
    .gf-info {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 18px;
        background: var(--gf-primary-lighter);
        border-left: 4px solid var(--gf-primary);
        border-radius: var(--gf-radius-sm);
        margin-bottom: 16px;
        font-size: 13.5px;
        color: #1f2937;
        line-height: 1.55;
        box-shadow: var(--gf-shadow-xs);
    }
    .gf-info svg { color: var(--gf-primary); flex-shrink: 0; margin-top: 2px; }
    .gf-info strong { color: var(--gf-primary-dark); }

    .gf-info.warning {
        background: #fffbeb;
        border-left-color: var(--gf-warning);
        color: #78350f;
    }
    .gf-info.warning svg { color: #d97706; }
    .gf-info.warning strong { color: #92400e; }

    /* ---------- MESSAGE ---------- */
    .gf-message {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 14px 18px;
        border-radius: var(--gf-radius-sm);
        margin-bottom: 20px;
        font-size: 13.5px;
        font-weight: 500;
        line-height: 1.5;
        box-shadow: var(--gf-shadow-xs);
    }
    .gf-message svg { flex-shrink: 0; margin-top: 2px; }
    .gf-message.success { background: #dcfce7; color: #166534; border-left: 4px solid var(--gf-success); }
    .gf-message.success svg { color: #16a34a; }
    .gf-message.error { background: #fef3c7; color: #92400e; border-left: 4px solid var(--gf-warning); }
    .gf-message.error svg { color: #d97706; }

    /* ---------- CARD ---------- */
    .gf-card {
        background: #fff;
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius-sm);
        padding: 24px 26px;
        box-shadow: var(--gf-shadow-xs);
        margin-bottom: 20px;
    }
    @media (max-width: 640px) {
        .gf-card { padding: 18px 16px; }
    }

    .gf-card-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
        font-weight: 700;
        color: var(--gf-text);
        margin: 0 0 22px;
        padding-bottom: 14px;
        border-bottom: 1px solid #eef2f6;
        flex-wrap: wrap;
    }
    .gf-card-title svg { color: var(--gf-primary); }
    .gf-card-title .gf-card-chip {
        margin-left: auto;
        font-size: 11px;
        padding: 4px 11px;
        border-radius: 999px;
        background: var(--gf-primary-light);
        color: var(--gf-primary-dark);
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }

    /* ---------- FORM GRID ---------- */
    .gf-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 20px;
    }
    .gf-form-grid .span-2 { grid-column: 1 / -1; }

    @media (max-width: 640px) {
        .gf-form-grid { grid-template-columns: 1fr; }
    }

    .gf-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
    }
    .gf-field label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        color: var(--gf-text-muted);
    }
    .gf-field label .required { color: var(--gf-danger); margin-left: 2px; }
    .gf-field label .lock {
        font-size: 10px;
        color: var(--gf-text-muted);
        text-transform: none;
        letter-spacing: 0;
        font-weight: 500;
        margin-left: 4px;
    }

    .gf-field input,
    .gf-field select,
    .gf-field textarea {
        width: 100%;
        padding: 11px 14px;
        border: 1px solid var(--gf-border);
        border-radius: 10px;
        font-size: 14px;
        font-family: inherit;
        color: var(--gf-text);
        background: #fff;
        outline: none;
        transition: border-color 0.15s var(--gf-ease), box-shadow 0.15s var(--gf-ease);
    }
    .gf-field textarea {
        resize: vertical;
        min-height: 84px;
        line-height: 1.5;
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
    .gf-field input:focus,
    .gf-field select:focus,
    .gf-field textarea:focus {
        border-color: var(--gf-primary);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }
    .gf-field input:disabled,
    .gf-field select:disabled {
        background: #f8fafc;
        color: var(--gf-text-muted);
        cursor: not-allowed;
    }
    .gf-field .help-text {
        font-size: 11.5px;
        color: var(--gf-text-muted);
        line-height: 1.45;
        display: flex;
        align-items: flex-start;
        gap: 5px;
    }
    .gf-field .help-text svg { flex-shrink: 0; margin-top: 2px; }

    /* ---------- FORM SECTION DIVIDER ---------- */
    .gf-divider {
        grid-column: 1 / -1;
        padding-top: 20px;
        margin-top: 6px;
        border-top: 1px dashed #e2e8f0;
    }
    .gf-divider-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 11.5px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #92400e;
        margin-bottom: 16px;
    }
    .gf-divider-title svg { color: var(--gf-warning); }

    /* ---------- BUTTONS ---------- */
    .gf-form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 24px;
        padding-top: 22px;
        border-top: 1px solid #eef2f6;
    }

    .gf-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 46px;
        padding: 0 26px;
        border-radius: 10px;
        font-size: 14px;
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
    .gf-btn-outline {
        background: #fff;
        color: var(--gf-text-secondary);
        border: 1px solid var(--gf-border);
    }
    .gf-btn-outline:hover { background: #f8fafc; color: var(--gf-text); }

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
                            Edit Goat
                            <span class="gf-goat-id-badge">
                                <?php echo icon('goat', 12); ?>
                                <?php echo htmlspecialchars($goat['goat_id']); ?>
                            </span>
                        </h1>
                        <p class="gf-welcome">
                            Update the details of this goat
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

            <!-- ===== PERMISSION INFO BOXES ===== -->
            <?php if ($canHealthOnly && !$canFullEdit): ?>
                <div class="gf-info warning">
                    <?php echo icon('shield-alert', 18); ?>
                    <div>
                        <strong>Manager access:</strong>
                        You may only update the <strong>Health Status</strong> of this goat.
                        Other details can only be changed by the Farmer (owner) or an Administrator.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canCorrectOwner && $canFullEdit): ?>
                <div class="gf-info">
                    <?php echo icon('shield', 18); ?>
                    <div>
                        <strong>Administrator access:</strong>
                        You can edit all fields and also reassign ownership to another Farmer.
                    </div>
                </div>
            <?php endif; ?>

            <!-- ===== MESSAGE ===== -->
            <?php if ($message): ?>
                <div role="alert" class="gf-message <?php echo $success ? 'success' : 'error'; ?>">
                    <?php echo $success ? icon('check-circle', 18) : icon('alert-circle', 18); ?>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <!-- ===== FORM ===== -->
            <form method="POST" class="gf-card" novalidate>
                <h2 class="gf-card-title">
                    <?php echo icon('pencil', 18); ?>
                    Goat Information
                    <span class="gf-card-chip">
                        <?php echo $canFullEdit ? 'Full edit access' : 'Limited access'; ?>
                    </span>
                </h2>

                <div class="gf-form-grid">

                    <!-- GOAT NAME -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="goat_name">Goat Name<span class="required">*</span></label>
                            <input type="text" id="goat_name" name="goat_name" required
                                   value="<?php echo htmlspecialchars($goat['goat_name']); ?>"
                                   placeholder="e.g. Bella, Blackie">
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="goat_name" value="<?php echo htmlspecialchars($goat['goat_name']); ?>">
                    <?php endif; ?>

                    <!-- TAG NUMBER -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="tag_number">Ear Tag Number</label>
                            <input type="text" id="tag_number" name="tag_number"
                                   value="<?php echo htmlspecialchars((string)$goat['tag_number']); ?>"
                                   placeholder="e.g. T-001">
                            <div class="help-text">
                                <?php echo icon('info', 12); ?>
                                Optional unique identifier
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SEX (read-only) -->
                    <div class="gf-field">
                        <label for="sex_display">Sex<span class="lock">(locked)</span></label>
                        <input type="text" id="sex_display" value="<?php echo ucfirst(htmlspecialchars($goat['sex'])); ?>" disabled>
                        <div class="help-text">
                            <?php echo icon('lock', 12); ?>
                            Sex cannot be changed after registration
                        </div>
                    </div>

                    <!-- BREED -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="breed">Breed<span class="required">*</span></label>
                            <input type="text" id="breed" name="breed" required
                                   value="<?php echo htmlspecialchars($goat['breed']); ?>"
                                   placeholder="e.g. Saanen, Anglo-Nubian">
                        </div>
                    <?php endif; ?>

                    <!-- DATE OF BIRTH -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="date_of_birth">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth"
                                   value="<?php echo htmlspecialchars((string)$goat['date_of_birth']); ?>">
                        </div>
                    <?php endif; ?>

                    <!-- COLOR -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="color">Color</label>
                            <input type="text" id="color" name="color"
                                   value="<?php echo htmlspecialchars((string)$goat['color']); ?>"
                                   placeholder="e.g. White, Brown, Black">
                        </div>
                    <?php endif; ?>

                    <!-- DATE ACQUIRED -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="date_acquired">Date Acquired</label>
                            <input type="date" id="date_acquired" name="date_acquired"
                                   value="<?php echo htmlspecialchars((string)$goat['date_acquired']); ?>">
                        </div>
                    <?php endif; ?>

                    <!-- HEALTH STATUS -->
                    <div class="gf-field">
                        <label for="health_status">Health Status<span class="required">*</span></label>
                        <select id="health_status" name="health_status">
                            <option value="unknown" <?php echo $goat['health_status'] === 'unknown' ? 'selected' : ''; ?>>Unknown</option>
                            <option value="healthy" <?php echo $goat['health_status'] === 'healthy' ? 'selected' : ''; ?>>Healthy</option>
                            <option value="sick"    <?php echo $goat['health_status'] === 'sick'    ? 'selected' : ''; ?>>Sick</option>
                        </select>
                    </div>

                    <!-- STATUS -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field">
                            <label for="status">Goat Status<span class="required">*</span></label>
                            <select id="status" name="status">
                                <option value="active"   <?php echo $goat['status'] === 'active'   ? 'selected' : ''; ?>>Active / Currently Owned</option>
                                <option value="sold"     <?php echo $goat['status'] === 'sold'     ? 'selected' : ''; ?>>Sold</option>
                                <option value="deceased" <?php echo $goat['status'] === 'deceased' ? 'selected' : ''; ?>>Deceased</option>
                            </select>
                            <div class="help-text">
                                <?php echo icon('info', 12); ?>
                                Sold/Deceased goats are kept for historical records
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($goat['status']); ?>">
                    <?php endif; ?>

                    <!-- CHARACTERISTICS (full width) -->
                    <?php if ($canFullEdit): ?>
                        <div class="gf-field span-2">
                            <label for="identifying_characteristics">Identifying Characteristics</label>
                            <textarea id="identifying_characteristics" name="identifying_characteristics" rows="3"
                                      placeholder="e.g. White spot on forehead, missing left ear tag"><?php echo htmlspecialchars((string)$goat['identifying_characteristics']); ?></textarea>
                            <div class="help-text">
                                <?php echo icon('info', 12); ?>
                                Any unique markings or features
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- OWNER CORRECTION (Admin only) -->
                    <?php if ($canCorrectOwner): ?>
                        <div class="gf-divider">
                            <div class="gf-divider-title">
                                <?php echo icon('user-shield', 16); ?>
                                Administrative Action
                            </div>
                            <div class="gf-field">
                                <label for="owner_id">Owner (Farmer) — Ownership Correction</label>
                                <select id="owner_id" name="owner_id">
                                    <?php foreach ($farmers as $f): ?>
                                        <option value="<?php echo (int)$f['id']; ?>" <?php echo (int)$goat['user_id'] === (int)$f['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($f['full_name'] . ' (' . $f['username'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text" style="color: #b45309;">
                                    <?php echo icon('alert-triangle', 12); ?>
                                    Reassigning ownership is logged as an administrative correction.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- /gf-form-grid -->

                <div class="gf-form-actions">
                    <button type="submit" class="gf-btn gf-btn-primary">
                        <?php echo icon('save', 16); ?>
                        Save Changes
                    </button>
                    <a href="<?php echo BASE_URL; ?>/app/goats/goat-view.php?id=<?php echo urlencode($goat['id']); ?>" class="gf-btn gf-btn-outline">
                        <?php echo icon('x', 16); ?>
                        Cancel
                    </a>
                </div>
            </form>

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