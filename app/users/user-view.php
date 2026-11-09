<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/activity-logger.php';
requireLogin();

$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

$userId = $_GET['user_id'] ?? 0;
$message = '';
$success = false;

// Get user details up front so we can check visibility permissions
$stmt = $pdo->prepare("SELECT id, full_name, username, email, role, status, is_verified, verification_token, email_verification_expires, created_at, updated_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && !canViewTarget($currentRole, $currentUserId, $user['role'], $user['id'])) {
    die("Access denied. You do not have permission to view this user.");
}

// Handle resend verification email (Admin/Manager only, for accounts they can manage)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verification'])) {
    if (!$user || !canManageTarget($currentRole, $currentUserId, $user['role'], $user['id'])) {
        die("Access denied.");
    }

    $verification_token = bin2hex(random_bytes(32));
    $verification_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    try {
        $stmt = $pdo->prepare("
            UPDATE users
            SET verification_token = :token,
                email_verification_expires = :expires
            WHERE id = :id
        ");
        $stmt->execute([
            ':token' => $verification_token,
            ':expires' => $verification_expires,
            ':id' => $userId
        ]);

        $userEmail = $user['email'];

        if ($userEmail) {
            $verificationLink = BASE_URL . "/app/auth/verify-email.php?token=" . $verification_token;

            $email_subject = "Verify Your Email Address";

            $email_body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #1976d2; color: white; padding: 20px; text-align: center; }
                    .content { background: #f9f9f9; padding: 30px; }
                    .button { display: inline-block; padding: 12px 30px; background: #4caf50; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                    .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Email Verification</h2>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        <p>You requested a new verification link. Please verify your email address.</p>
                        <p style='text-align: center;'>
                            <a href='{$verificationLink}' class='button'>Verify Email</a>
                        </p>
                        <p>If the button doesn't work, copy and paste this link:</p>
                        <p style='word-break: break-all; color: #1976d2;'>{$verificationLink}</p>
                        <p>This link expires in 24 hours.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " Precision Livestock Farming - User Management</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: " . MAIL_FROM . "\r\n";

            if (mail($userEmail, $email_subject, $email_body, $headers)) {
                $message = "Verification email has been resent successfully! Link: <a href='$verificationLink' target='_blank'>$verificationLink</a>";
                $success = true;

                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'verification_email_resent', 'success');
                logActivity($pdo, $userId, $userEmail, 'verification_email_received', 'success');
            } else {
                $message = "Verification email failed to send. Please try again later.";
                $success = false;

                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'verification_email_resent', 'failed');
            }
        }

        $stmt = $pdo->prepare("SELECT id, full_name, username, email, role, status, is_verified, verification_token, email_verification_expires, created_at, updated_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch(PDOException $e) {
        $message = "Error resending verification: " . $e->getMessage();
        $success = false;

        logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'verification_email_resent', 'failed');
    }
}

// Log that this user profile was viewed
if ($user) {
    logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'user_viewed', 'success');
}

// Check if verification has expired
$verificationExpired = false;
if ($user && !$user['is_verified'] && $user['email_verification_expires']) {
    $verificationExpired = strtotime($user['email_verification_expires']) < time();
}

$canManage = $user ? canManageTarget($currentRole, $currentUserId, $user['role'], $user['id']) : false;

$title = 'View User';
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
            'pencil'        => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
            'fingerprint'   => '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
            'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-tag'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 13.5V17a2 2 0 0 1-2 2h-4"/><circle cx="19" cy="11" r="2"/>',
            'mail'          => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'circle'        => '<circle cx="12" cy="12" r="10"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'calendar-plus' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M12 14v4"/><path d="M10 16h4"/>',
            'send'          => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
            'alert-circle'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
            'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
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
        margin-bottom: 26px;
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
    .gf-page-header .gf-welcome { margin: 5px 0 0; font-size: 13px; color: var(--gf-text-muted); }

    .gf-username-badge {
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
        line-height: 1.55;
        box-shadow: var(--gf-shadow-xs);
    }
    .gf-message svg { flex-shrink: 0; margin-top: 2px; }
    .gf-message.success { background: #dcfce7; color: #166534; border-left: 4px solid var(--gf-success); }
    .gf-message.success svg { color: #16a34a; }
    .gf-message.error { background: #fef3c7; color: #92400e; border-left: 4px solid var(--gf-warning); }
    .gf-message.error svg { color: #d97706; }
    .gf-message a {
        color: #166534;
        font-weight: 700;
        word-break: break-all;
    }

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
        margin: 0 0 20px;
        padding-bottom: 14px;
        border-bottom: 1px solid #eef2f6;
        flex-wrap: wrap;
    }
    .gf-card-title svg { color: var(--gf-primary); }

    /* ---------- USER PROFILE HEADER ---------- */
    .gf-profile-header {
        display: flex;
        align-items: center;
        gap: 16px;
        padding-bottom: 20px;
        margin-bottom: 20px;
        border-bottom: 1px solid #eef2f6;
        flex-wrap: wrap;
    }
    .gf-profile-avatar {
        width: 60px; height: 60px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-size: 20px;
        font-weight: 800;
        color: #fff;
        flex-shrink: 0;
        text-transform: uppercase;
        background: linear-gradient(135deg, #22C55E, #15803D);
        box-shadow: 0 4px 12px rgba(34, 197, 94, 0.25);
    }
    .gf-profile-info { min-width: 0; flex: 1; }
    .gf-profile-name {
        font-size: 20px;
        font-weight: 800;
        color: var(--gf-text);
        line-height: 1.2;
        margin-bottom: 4px;
    }
    .gf-profile-email {
        font-size: 13px;
        color: var(--gf-text-muted);
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    /* ---------- DETAILS GRID ---------- */
    .gf-details-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 4px 24px;
    }
    @media (min-width: 640px) {
        .gf-details-grid { grid-template-columns: 1fr 1fr; }
        .gf-details-grid .full { grid-column: 1 / -1; }
    }

    .gf-detail-row {
        display: grid;
        grid-template-columns: 170px 1fr;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
    }
    @media (max-width: 639.98px) {
        .gf-detail-row {
            grid-template-columns: 1fr;
            gap: 4px;
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
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
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

    .gf-badge.expired      { background: #fee2e2; color: #b91c1c; }
    .gf-badge.expired .dot { background: #dc2626; }

    /* ---------- INFO BOXES ---------- */
    .gf-info-box {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
        border-radius: var(--gf-radius-sm);
        margin-top: 20px;
        font-size: 13.5px;
        line-height: 1.55;
        box-shadow: var(--gf-shadow-xs);
    }
    .gf-info-box svg { flex-shrink: 0; margin-top: 2px; }
    .gf-info-box strong { font-weight: 800; }

    .gf-info-box.warning {
        background: #fffbeb;
        border-left: 4px solid var(--gf-warning);
        color: #78350f;
    }
    .gf-info-box.warning svg { color: #d97706; }
    .gf-info-box.warning strong { color: #92400e; }

    .gf-info-box.danger {
        background: #fef2f2;
        border-left: 4px solid var(--gf-danger);
        color: #7f1d1d;
    }
    .gf-info-box.danger svg { color: var(--gf-danger); }
    .gf-info-box.danger strong { color: #991b1b; }

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
        padding: 0 24px;
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
    .gf-btn-secondary {
        background: #f1f5f9;
        color: var(--gf-text-secondary);
        border: 1px solid var(--gf-border);
    }
    .gf-btn-secondary:hover { background: #e2e8f0; color: var(--gf-text); }

    .gf-btn-warning {
        background: linear-gradient(145deg, #f59e0b, #d97706);
        color: #fff;
        box-shadow: 0 4px 10px rgba(245, 158, 11, 0.25);
    }
    .gf-btn-warning:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(245, 158, 11, 0.3);
        color: #fff;
    }

    .gf-btn-danger {
        background: linear-gradient(145deg, #ef4444, #b91c1c);
        color: #fff;
        box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25);
    }
    .gf-btn-danger:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(239, 68, 68, 0.35);
        color: #fff;
    }

    /* ---------- EMPTY STATE ---------- */
    .gf-empty {
        padding: 40px 20px;
        text-align: center;
        color: var(--gf-text-muted);
    }
    .gf-empty svg { margin: 0 auto 16px; color: #cbd5e1; }
    .gf-empty p {
        margin: 0;
        color: var(--gf-text);
        font-size: 15px;
        font-weight: 700;
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
                        <h1>
                            User Details
                            <?php if ($user): ?>
                                <span class="gf-username-badge">
                                    <?php echo icon('user-tag', 12); ?>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="gf-welcome">
                            View user profile and manage verification
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

            <!-- ===== MESSAGE ===== -->
            <?php if ($message): ?>
                <div role="alert" class="gf-message <?php echo $success ? 'success' : 'error'; ?>">
                    <?php echo $success ? icon('check-circle', 18) : icon('alert-circle', 18); ?>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <!-- ===== PROFILE CARD ===== -->
            <div class="gf-card">
                <h2 class="gf-card-title">
                    <?php echo icon('fingerprint', 18); ?>
                    Profile Information
                </h2>

                <?php if ($user):
                    $initials = strtoupper(substr($user['full_name'] ?: $user['email'], 0, 1));
                    if (!empty($user['full_name'])) {
                        $parts = preg_split('/\s+/', trim($user['full_name']));
                        if (count($parts) >= 2) {
                            $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                        }
                    }
                ?>

                    <!-- Profile Header -->
                    <div class="gf-profile-header">
                        <div class="gf-profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
                        <div class="gf-profile-info">
                            <div class="gf-profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="gf-profile-email">
                                <?php echo icon('mail', 13); ?>
                                <?php echo htmlspecialchars($user['email']); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Details Grid -->
                    <div class="gf-details-grid">

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('fingerprint', 14); ?>
                                User ID
                            </div>
                            <div class="gf-detail-value">#<?php echo (int)$user['id']; ?></div>
                        </div>

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('user-tag', 14); ?>
                                Username
                            </div>
                            <div class="gf-detail-value"><?php echo htmlspecialchars($user['username']); ?></div>
                        </div>

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('shield', 14); ?>
                                Role
                            </div>
                            <div class="gf-detail-value">
                                <span class="gf-badge <?php echo $user['role']; ?>">
                                    <span class="dot"></span>
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('circle', 14); ?>
                                Account Status
                            </div>
                            <div class="gf-detail-value">
                                <span class="gf-badge <?php echo $user['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                    <span class="dot"></span>
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('check-circle', 14); ?>
                                Verified
                            </div>
                            <div class="gf-detail-value">
                                <span class="gf-badge <?php echo $user['is_verified'] ? 'verified' : 'unverified'; ?>">
                                    <span class="dot"></span>
                                    <?php echo $user['is_verified'] ? 'Yes' : 'No'; ?>
                                </span>
                                <?php if (!$user['is_verified'] && $verificationExpired): ?>
                                    <span class="gf-badge expired">
                                        <span class="dot"></span>
                                        Expired
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$user['is_verified'] && $user['email_verification_expires']): ?>
                            <div class="gf-detail-row">
                                <div class="gf-detail-label">
                                    <?php echo icon('clock', 14); ?>
                                    Verification Expires
                                </div>
                                <div class="gf-detail-value">
                                    <?php
                                    $expiresTime = strtotime($user['email_verification_expires']);
                                    echo date('M j, Y · g:i a', $expiresTime);

                                    if ($verificationExpired) {
                                        echo ' <span style="color: #dc2626;font-weight:700;font-size:12px;">(Expired)</span>';
                                    } else {
                                        $timeLeft = $expiresTime - time();
                                        $hoursLeft = floor($timeLeft / 3600);
                                        echo ' <span style="color: #d97706;font-weight:700;font-size:12px;">(' . $hoursLeft . 'h left)</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('calendar-plus', 14); ?>
                                Created
                            </div>
                            <div class="gf-detail-value">
                                <?php echo date('M j, Y · g:i a', strtotime($user['created_at'])); ?>
                            </div>
                        </div>

                        <div class="gf-detail-row">
                            <div class="gf-detail-label">
                                <?php echo icon('calendar', 14); ?>
                                Last Updated
                            </div>
                            <div class="gf-detail-value">
                                <?php echo date('M j, Y · g:i a', strtotime($user['updated_at'])); ?>
                            </div>
                        </div>

                    </div>

                    <!-- Resend Verification Box -->
                    <?php if ($canManage && !$user['is_verified']): ?>
                        <div class="gf-info-box <?php echo $verificationExpired ? 'danger' : 'warning'; ?>">
                            <?php echo $verificationExpired ? icon('alert-triangle', 20) : icon('mail', 20); ?>
                            <div>
                                <strong>
                                    <?php echo $verificationExpired
                                        ? 'Verification Link Expired'
                                        : 'Email Not Verified'; ?>
                                </strong><br>
                                <?php echo $verificationExpired
                                    ? 'This user\'s verification link has expired. Click below to send a new verification email.'
                                    : 'This user has not verified their email address yet. You can resend the verification email if needed.'; ?>

                                <form method="POST" style="margin-top: 12px;">
                                    <button type="submit" name="resend_verification" class="gf-btn <?php echo $verificationExpired ? 'gf-btn-danger' : 'gf-btn-warning'; ?>">
                                        <?php echo icon('send', 16); ?>
                                        Resend Verification Email
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="gf-form-actions">
                        <?php if ($canManage): ?>
                            <a href="user-update.php?user_id=<?php echo $user['id']; ?>" class="gf-btn gf-btn-primary">
                                <?php echo icon('pencil', 16); ?>
                                Edit User
                            </a>
                        <?php endif; ?>
                        <a href="dashboard.php" class="gf-btn gf-btn-secondary">
                            <?php echo icon('arrow-left', 16); ?>
                            Back to List
                        </a>
                    </div>

                <?php else: ?>
                    <div class="gf-empty">
                        <?php echo icon('alert-triangle', 60, '', '1.5'); ?>
                        <p>User not found.</p>
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