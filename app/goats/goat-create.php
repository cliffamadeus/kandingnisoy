<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-logger.php';
require_once '../../includes/goat-functions.php';
requireLogin();

$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

if (!canRegisterGoat($currentRole)) {
    die("Access denied. Only Farmers can register goats.");
}

define('GOAT_UPLOAD_DIR', __DIR__ . '/uploads/goats/');
define('GOAT_UPLOAD_URL', BASE_URL . '/app/goats/uploads/goats/');

function handleGoatPhotoUpload(array $file): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Photo upload failed (error code {$file['error']}).");

    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) throw new Exception("Photo is too large. Max size is 5MB.");

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) throw new Exception("Uploaded file is not a valid image.");

    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = $imageInfo['mime'];
    if (!isset($allowedMimes[$mime])) throw new Exception("Only JPG, PNG, or WEBP images are allowed.");
    $ext = $allowedMimes[$mime];

    if (!is_dir(GOAT_UPLOAD_DIR)) {
        if (!mkdir(GOAT_UPLOAD_DIR, 0755, true) && !is_dir(GOAT_UPLOAD_DIR)) {
            throw new Exception("Upload directory could not be created.");
        }
    }
    if (!is_writable(GOAT_UPLOAD_DIR)) throw new Exception("Upload directory is not writable.");

    $filename = 'goat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = GOAT_UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("Could not save the uploaded photo.");
    }

    @chmod($destination, 0644);
    return 'uploads/goats/' . $filename;
}

function generateNextGoatId(PDO $pdo, int $ownerId, string $prefix = 'G-', int $pad = 4): string {
    $stmt = $pdo->prepare("SELECT goat_id FROM goats WHERE user_id = :owner_id AND goat_id LIKE :pattern");
    $stmt->execute([':owner_id' => $ownerId, ':pattern' => $prefix . '%']);

    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$existingId, $m)) {
            $num = (int)$m[1];
            if ($num > $max) $max = $num;
        }
    }
    return $prefix . str_pad((string)($max + 1), $pad, '0', STR_PAD_LEFT);
}

$farmers = [];
if ($currentRole === 'admin') {
    try {
        $farmers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'user' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Goat Create] Farmer list failed: ' . $e->getMessage());
    }
}

$message = '';
$success = false;

if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $success = true;
    $message = "Goat registered successfully! Goat ID: " . htmlspecialchars($_GET['goat_id'] ?? '');
}

$goat_name = '';
$tag_number = '';
$sex = '';
$breed = '';
$date_of_birth = '';
$color = '';
$identifying_characteristics = '';
$date_acquired = '';
$health_status = 'unknown';
$owner_id_input = ($currentRole === 'admin') ? 0 : $currentUserId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $goat_name = trim($_POST['goat_name'] ?? '');
    $tag_number = trim($_POST['tag_number'] ?? '');
    $sex = normalizeGoatSex($_POST['sex'] ?? '');
    $breed = trim($_POST['breed'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $identifying_characteristics = trim($_POST['identifying_characteristics'] ?? '');
    $date_acquired = trim($_POST['date_acquired'] ?? '');
    $health_status = normalizeHealthStatus($_POST['health_status'] ?? 'unknown') ?? 'unknown';

    if ($currentRole === 'admin') {
        $owner_id_input = (int)($_POST['owner_id'] ?? 0);
        $ownerIsValidFarmer = false;
        foreach ($farmers as $f) {
            if ((int)$f['id'] === $owner_id_input) { $ownerIsValidFarmer = true; break; }
        }
        $ownerId = $ownerIsValidFarmer ? $owner_id_input : 0;
    } else {
        $ownerId = $currentUserId;
    }

    $errors = [];
    if ($goat_name === '') $errors[] = "Goat Name is required.";
    if ($sex === null) $errors[] = "Sex must be Male or Female.";
    if ($breed === '') $errors[] = "Breed is required.";
    if ($date_of_birth !== '' && !DateTime::createFromFormat('Y-m-d', $date_of_birth)) $errors[] = "Date of Birth must be a valid date.";
    if ($date_acquired !== '' && !DateTime::createFromFormat('Y-m-d', $date_acquired)) $errors[] = "Date Acquired must be a valid date.";
    if ($ownerId <= 0) $errors[] = ($currentRole === 'admin') ? "Please select a valid Farmer." : "Unable to determine owner.";

    $photo_path = null;
    if (empty($errors)) {
        try {
            if (!empty($_FILES['goat_photo']) && $_FILES['goat_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $photo_path = handleGoatPhotoUpload($_FILES['goat_photo']);
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $maxAttempts = 5;
        $inserted = false;

        for ($attempt = 1; $attempt <= $maxAttempts && !$inserted; $attempt++) {
            $goat_id_input = generateNextGoatId($pdo, $ownerId);
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO goats
                        (goat_id, user_id, goat_name, tag_number, sex, breed, date_of_birth, color,
                         identifying_characteristics, photo_path, date_acquired, health_status, status, date_registered)
                    VALUES
                        (:goat_id, :user_id, :goat_name, :tag_number, :sex, :breed, :dob, :color,
                         :characteristics, :photo_path, :date_acquired, :health_status, 'active', NOW())
                ");
                $stmt->execute([
                    ':goat_id' => $goat_id_input,
                    ':user_id' => $ownerId,
                    ':goat_name' => $goat_name,
                    ':tag_number' => $tag_number !== '' ? $tag_number : null,
                    ':sex' => $sex,
                    ':breed' => $breed,
                    ':dob' => $date_of_birth !== '' ? $date_of_birth : null,
                    ':color' => $color !== '' ? $color : null,
                    ':characteristics' => $identifying_characteristics !== '' ? $identifying_characteristics : null,
                    ':photo_path' => $photo_path,
                    ':date_acquired' => $date_acquired !== '' ? $date_acquired : null,
                    ':health_status' => $health_status,
                ]);
                $inserted = true;

                logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_registered', 'success', $goat_id_input, [
                    'owner_id' => $ownerId,
                    'health_status' => $health_status,
                    'status' => 'active',
                    'photo' => $photo_path ? 'uploaded' : 'none',
                ]);

                header('Location: ' . BASE_URL . '/app/goats/goat-create.php?registered=1&goat_id=' . urlencode($goat_id_input));
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) continue;
                error_log('[Goat Create] Insert failed: ' . $e->getMessage());
                $message = "Error registering goat. Please try again.";
                logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_registered', 'failed', $goat_id_input);
                $success = false;
                break;
            }
        }

        if (!$inserted) {
            if ($photo_path && file_exists(__DIR__ . '/' . $photo_path)) @unlink(__DIR__ . '/' . $photo_path);
            if ($message === '') {
                $message = "Could not generate a unique Goat ID right now. Please try again.";
                $success = false;
            }
        }
    } else {
        if ($photo_path && file_exists(__DIR__ . '/' . $photo_path)) @unlink(__DIR__ . '/' . $photo_path);
        $message = implode(' ', $errors);
    }
}

$title = 'Add Goat';
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
        --gf-bg: #f7f8fa;
        --gf-surface: #ffffff;
        --gf-text: #0f172a;
        --gf-text-secondary: #64748b;
        --gf-text-muted: #94a3b8;
        --gf-border: #e5e7eb;
        --gf-border-strong: #d1d5db;
        --gf-danger: #dc2626;
        --gf-success: #16a34a;

        --gf-navbar-height: 68px;
        --gf-sidebar-width: 264px;
        --gf-radius: 10px;
        --gf-radius-sm: 8px;

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
        width: 100%;
        height: 100%;
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
        transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease);
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
    .gf-nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-nav-link:hover i { opacity: 1; }
    .gf-nav-link.active {
        background: #fff;
        color: var(--gf-primary-dark);
        font-weight: 700;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    }
    .gf-nav-link.active i { color: var(--gf-primary); opacity: 1; }

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
       MAIN LAYOUT
    ========================================================== */
    .gf-main {
        padding: calc(var(--gf-navbar-height) + 28px) clamp(16px, 3vw, 40px) 80px;
        min-height: 100vh;
        width: 100%;
        max-width: 720px;
        margin: 0 auto;
        animation: gfFadeIn 0.35s var(--gf-ease-out);
    }
    @keyframes gfFadeIn {
        from { opacity: 0; transform: translateY(4px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .gf-page-header {
        margin-bottom: 24px;
    }
    .gf-page-header h1 {
        margin: 0;
        font-size: 26px;
        font-weight: 700;
        letter-spacing: -0.02em;
        line-height: 1.2;
        color: var(--gf-text);
    }

    /* ==========================================================
       ALERTS
    ========================================================== */
    .gf-alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 20px;
        padding: 12px 16px;
        border: 1px solid;
        border-radius: var(--gf-radius);
        font-size: 14px;
        line-height: 1.5;
    }
    .gf-alert i { flex-shrink: 0; margin-top: 2px; width: 16px; height: 16px; }
    .gf-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #166534;
    }
    .gf-alert.success i { color: var(--gf-success); }
    .gf-alert.error {
        border-color: #fecaca;
        background: #fef2f2;
        color: #991b1b;
    }
    .gf-alert.error i { color: var(--gf-danger); }

    /* ==========================================================
       SIMPLE FORM
    ========================================================== */
    .gf-form {
        background: var(--gf-surface);
        border: 1px solid var(--gf-border);
        border-radius: var(--gf-radius);
        padding: 28px clamp(18px, 3vw, 32px);
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .gf-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .gf-field label {
        font-size: 13px;
        font-weight: 600;
        color: var(--gf-text);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .gf-field label .hint {
        font-weight: 400;
        font-size: 12px;
        color: var(--gf-text-muted);
    }

    .gf-field .req {
        color: var(--gf-danger);
    }

    .gf-field input[type="text"],
    .gf-field input[type="date"],
    .gf-field input[type="file"],
    .gf-field select,
    .gf-field textarea {
        width: 100%;
        padding: 11px 14px;
        border: 1px solid var(--gf-border-strong);
        border-radius: var(--gf-radius-sm);
        font-size: 15px;
        font-family: inherit;
        background: #fff;
        color: var(--gf-text);
        transition: border-color 0.15s var(--gf-ease), box-shadow 0.15s var(--gf-ease);
    }

    .gf-field textarea {
        resize: vertical;
        min-height: 80px;
    }

    .gf-field input:focus,
    .gf-field select:focus,
    .gf-field textarea:focus {
        border-color: var(--gf-primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.12);
    }

    .gf-field .note {
        font-size: 12px;
        color: var(--gf-text-muted);
    }

    /* Photo upload drop-zone feel */
    .gf-file-wrap {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 14px;
        border: 1.5px dashed var(--gf-border-strong);
        border-radius: var(--gf-radius-sm);
        background: #fafbfc;
        cursor: pointer;
        transition: border-color 0.15s var(--gf-ease), background 0.15s var(--gf-ease);
    }
    .gf-file-wrap:hover {
        border-color: var(--gf-primary);
        background: #f0fdf4;
    }
    .gf-file-wrap input[type="file"] {
        display: none;
    }
    .gf-file-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: #dcfce7;
        color: var(--gf-primary-dark);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .gf-file-icon i { width: 20px; height: 20px; }
    .gf-file-text {
        flex: 1;
        min-width: 0;
    }
    .gf-file-text strong {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: var(--gf-text);
    }
    .gf-file-text small {
        display: block;
        font-size: 12px;
        color: var(--gf-text-muted);
        margin-top: 2px;
    }

    .gf-photo-preview {
        margin-top: 12px;
        display: none;
        max-width: 180px;
        border-radius: var(--gf-radius-sm);
        overflow: hidden;
        border: 1px solid var(--gf-border);
    }
    .gf-photo-preview img {
        display: block;
        width: 100%;
        height: auto;
    }

    /* Two-column row */
    .gf-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    @media (max-width: 560px) {
        .gf-row { grid-template-columns: 1fr; }
    }

    /* Section divider (subtle) */
    .gf-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 4px 0;
        color: var(--gf-text-muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }
    .gf-divider::before,
    .gf-divider::after {
        content: "";
        flex: 1;
        height: 1px;
        background: var(--gf-border);
    }

    /* ==========================================================
       FORM ACTIONS
    ========================================================== */
    .gf-form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 8px;
        flex-wrap: wrap;
    }
    .gf-form-actions .btn {
        min-height: 44px;
        padding: 11px 24px;
        font-size: 15px;
    }

    /* ==========================================================
       BUTTONS
    ========================================================== */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 38px;
        padding: 9px 16px;
        border-radius: var(--gf-radius-sm);
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
        white-space: nowrap;
        transition: background 0.15s var(--gf-ease),
                    border-color 0.15s var(--gf-ease),
                    box-shadow 0.15s var(--gf-ease);
    }
    .btn i { width: 15px; height: 15px; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }

    .btn-primary {
        background: var(--gf-primary);
        color: #fff;
        border-color: var(--gf-primary);
        font-weight: 600;
    }
    .btn-primary:hover:not(:disabled) {
        background: #15803d;
        border-color: #15803d;
    }

    .btn-outline {
        background: #fff;
        color: var(--gf-text);
        border-color: var(--gf-border-strong);
    }
    .btn-outline:hover:not(:disabled) {
        background: #f9fafb;
        border-color: var(--gf-text-secondary);
    }

    /* ==========================================================
       BACKDROP
    ========================================================== */
    .gf-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        z-index: 1055;
        cursor: pointer;
    }
    body.gf-sidebar-open .gf-backdrop { display: block; }

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
        .gf-navbar-logo {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            font-size: 20px;
        }
        .gf-navbar-title { font-size: 17px; }
        .gf-navbar-sub { font-size: 9px; }
        .gf-main { padding: calc(var(--gf-navbar-height) + 14px) 12px 80px; }
        .gf-page-header h1 { font-size: 22px; }
        .gf-form { padding: 20px 16px; gap: 16px; }
    }

    @media (max-width: 430px) {
        .gf-form-actions { flex-direction: column-reverse; }
        .gf-form-actions .btn { width: 100%; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
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
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', (($_SESSION['role'] ?? '') === 'user' ? 'add_goat' : 'inventory')); ?>
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
               
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" aria-label="Close menu" onclick="closeSidebar();">
            <i data-lucide="x" aria-hidden="true" style="width:18px;height:18px;"></i>
        </button>
    </div>

    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', (($_SESSION['role'] ?? '') === 'user' ? 'add_goat' : 'inventory')); ?>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- ============ MAIN ============ -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="gf-main">

            <header class="gf-page-header">
                <h1>Add Goat</h1>
            </header>

            <?php if ($message): ?>
                <div role="alert" class="gf-alert <?php echo $success ? 'success' : 'error'; ?>">
                    <i data-lucide="<?php echo $success ? 'check-circle' : 'alert-circle'; ?>" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="gf-form" enctype="multipart/form-data" novalidate>

                <?php if ($currentRole === 'admin'): ?>
                    <div class="gf-field">
                        <label for="owner_id">Assign to Farmer <span class="req">*</span></label>
                        <select id="owner_id" name="owner_id" required>
                            <option value="">— Select Farmer —</option>
                            <?php foreach ($farmers as $f): ?>
                                <option value="<?php echo (int)$f['id']; ?>" <?php echo $owner_id_input === (int)$f['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($f['full_name'] . ' (' . $f['username'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="note">Registering on behalf of a Farmer will be logged.</span>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="owner_id" value="<?php echo (int)$currentUserId; ?>">
                <?php endif; ?>

                <!-- ============ IDENTIFICATION ============ -->
                <div class="gf-row">
                    <div class="gf-field">
                        <label for="goat_name">Goat Name <span class="req">*</span></label>
                        <input type="text" id="goat_name" name="goat_name" required
                               value="<?php echo htmlspecialchars($goat_name); ?>" placeholder="e.g. Bantay">
                    </div>
                    <div class="gf-field">
                        <label for="tag_number">Ear Tag <span class="hint">optional</span></label>
                        <input type="text" id="tag_number" name="tag_number"
                               value="<?php echo htmlspecialchars($tag_number); ?>" placeholder="e.g. 0245">
                    </div>
                </div>

                <div class="gf-field">
                    <label>Photo <span class="hint">optional</span></label>
                    <label class="gf-file-wrap" for="goat_photo">
                        <div class="gf-file-icon"><i data-lucide="image-plus"></i></div>
                        <div class="gf-file-text">
                            <strong id="photo-file-label">Choose a photo</strong>
                            <small>JPG, PNG, or WEBP · Max 5MB</small>
                        </div>
                    </label>
                    <input type="file" id="goat_photo" name="goat_photo" accept="image/jpeg,image/png,image/webp">
                    <div class="gf-photo-preview" id="goat_photo_preview">
                        <img id="goat_photo_preview_img" src="" alt="Photo preview">
                    </div>
                </div>

                <!-- ============ DETAILS ============ -->
                <div class="gf-divider">Details</div>

                <div class="gf-row">
                    <div class="gf-field">
                        <label for="sex">Sex <span class="req">*</span></label>
                        <select id="sex" name="sex" required>
                            <option value="">— Select —</option>
                            <option value="male" <?php echo $sex === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $sex === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="gf-field">
                        <label for="breed">Breed <span class="req">*</span></label>
                        <select id="breed" name="breed" required>
                            <option value="">— Select —</option>
                            <option value="Native" <?php echo $breed === 'Native' ? 'selected' : ''; ?>>Native</option>
                            <option value="Nubian" <?php echo $breed === 'Nubian' ? 'selected' : ''; ?>>Nubian</option>
                            <option value="Boer" <?php echo $breed === 'Boer' ? 'selected' : ''; ?>>Boer</option>
                        </select>
                    </div>
                </div>

                <div class="gf-row">
                    <div class="gf-field">
                        <label for="color">Color <span class="hint">optional</span></label>
                        <input type="text" id="color" name="color"
                               value="<?php echo htmlspecialchars($color); ?>" placeholder="e.g. Brown">
                    </div>
                    <div class="gf-field">
                        <label for="date_of_birth">Date of Birth <span class="hint">optional</span></label>
                        <input type="date" id="date_of_birth" name="date_of_birth"
                               value="<?php echo htmlspecialchars($date_of_birth); ?>">
                    </div>
                </div>

                <div class="gf-field">
                    <label for="identifying_characteristics">Characteristics <span class="hint">optional</span></label>
                    <textarea id="identifying_characteristics" name="identifying_characteristics" rows="3"
                              placeholder="Scars, markings, horn shape, etc."><?php echo htmlspecialchars($identifying_characteristics); ?></textarea>
                </div>

                <!-- ============ HEALTH ============ -->
                <div class="gf-divider">Health &amp; Acquisition</div>

                <div class="gf-row">
                    <div class="gf-field">
                        <label for="date_acquired">Date Acquired <span class="hint">optional</span></label>
                        <input type="date" id="date_acquired" name="date_acquired"
                               value="<?php echo htmlspecialchars($date_acquired); ?>">
                    </div>
                    <div class="gf-field">
                        <label for="health_status">Health Status</label>
                        <select id="health_status" name="health_status">
                            <option value="unknown" <?php echo $health_status === 'unknown' ? 'selected' : ''; ?>>Unknown</option>
                            <option value="healthy" <?php echo $health_status === 'healthy' ? 'selected' : ''; ?>>Healthy</option>
                            <option value="sick" <?php echo $health_status === 'sick' ? 'selected' : ''; ?>>Sick</option>
                        </select>
                    </div>
                </div>

                <div class="gf-form-actions">
                    <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php" class="btn btn-outline">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="plus-circle"></i>
                        Register Goat
                    </button>
                </div>
            </form>

        </main>
    </div>
</div>

<script>
    lucide.createIcons();

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

    document.addEventListener('DOMContentLoaded', function () {
        const photoInput = document.getElementById('goat_photo');
        const previewBox = document.getElementById('goat_photo_preview');
        const previewImg = document.getElementById('goat_photo_preview_img');
        const fileLabel  = document.getElementById('photo-file-label');
        if (!photoInput || !previewBox || !previewImg) return;

        photoInput.addEventListener('change', function () {
            const file = photoInput.files && photoInput.files[0];
            if (!file) {
                previewBox.style.display = 'none';
                previewImg.src = '';
                if (fileLabel) fileLabel.textContent = 'Choose a photo';
                return;
            }
            if (fileLabel) fileLabel.textContent = file.name;
            const reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewBox.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('form.gf-form');
        const submitBtn = form ? form.querySelector('.btn-primary[type="submit"]') : null;
        if (!form || !submitBtn) return;

        form.addEventListener('submit', function () {
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Registering...';
        });
    });
</script>

<?php renderFooter(); ?>