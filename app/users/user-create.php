<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/activity-logger.php';
requireLogin();

$currentRole = $_SESSION['role'];

// Only admin and manager can create users
if ($currentRole !== 'admin' && $currentRole !== 'manager') {
    die("Access denied. Only administrators and managers can create users.");
}

$message = '';
$success = false;
$verificationLink = '';

// Preserve submitted values on validation failure so the form isn't wiped
$full_name = '';
$username = '';
$email = '';
$role = 'user';
$phone_number = '';                          // NEW
$phone_raw = '';                             // NEW
$barangay = '';                              // NEW
$municipality = 'Manolo Fortich';            // NEW
$sms_opt_in = 1;                             // NEW

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $send_email = isset($_POST['send_verification_email']) ? true : false;

    // NEW: SMS-related fields
    $phone_raw = trim($_POST['phone_number'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $municipality = trim($_POST['municipality'] ?? 'Manolo Fortich');
    $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;

    // NEW: Normalize phone number to E.164
    $phone_number = null;
    if ($phone_raw !== '') {
        $phone_number = normalizePhoneNumber($phone_raw);
    }

    // Role restrictions based on current user's role.
    if ($currentRole === 'manager') {
        $role = 'user';
    } elseif ($currentRole === 'admin') {
        if (!in_array($role, ['admin', 'manager', 'user'])) {
            $role = 'user';
        }
    }

    // Basic validation
    $errors = [];
    if ($full_name === '') $errors[] = "Full name is required.";
    if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
        $errors[] = "Username must be 3-50 characters (letters, numbers, dot, dash, underscore only).";
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    // NEW: Phone validation
    if ($role === 'user' && $phone_raw === '') {
        $errors[] = "Phone number is required for farmers (SMS advisory recipient).";
    } elseif ($phone_raw !== '' && $phone_number === null) {
        $errors[] = "Invalid phone number format. Use 09171234567 or +639171234567.";
    }

    // NEW: Municipality fallback
    if ($municipality === '') {
        $municipality = 'Manolo Fortich';
    }

    if (empty($errors)) {
        try {
            $email_verified = 1;
            $verification_token = null;
            $verification_expires = null;

            if ($send_email) {
                $email_verified = 0;
                $verification_token = bin2hex(random_bytes(32));
                $verification_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            }

            $stmt = $pdo->prepare("
                INSERT INTO users
                    (full_name, username, email, phone_number, barangay, municipality, sms_opt_in,
                     password, role, status, verification_token, is_verified, email_verification_expires, created_at)
                VALUES
                    (:full_name, :username, :email, :phone_number, :barangay, :municipality, :sms_opt_in,
                     :password, :role, :status, :token, :verified, :expires, NOW())
            ");

            $stmt->execute([
                ':full_name'    => $full_name,
                ':username'     => $username,
                ':email'        => $email,
                ':phone_number' => $phone_number,
                ':barangay'     => $barangay !== '' ? $barangay : null,
                ':municipality' => $municipality,
                ':sms_opt_in'   => $sms_opt_in,
                ':password'     => password_hash($password, PASSWORD_DEFAULT),
                ':role'         => $role,
                ':status'       => $status,
                ':token'        => $verification_token,
                ':verified'     => $email_verified,
                ':expires'      => $verification_expires
            ]);

            $new_user_id = $pdo->lastInsertId();

            logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'user_created', 'success');
            logActivity($pdo, $new_user_id, $email, 'account_created', 'success');

            if ($send_email) {
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
                        <div class='header'><h2>Email Verification</h2></div>
                        <div class='content'>
                            <p>Hello {$full_name},</p>
                            <p>An account was created for this email. Please verify your email address.</p>
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

                if (mail($email, $email_subject, $email_body, $headers)) {
                    $message = "User created successfully! Verification email sent.";
                    logActivity($pdo, $new_user_id, $email, 'verification_email_sent', 'success');
                } else {
                    $message = "User created successfully! Verification email failed to send.";
                    logActivity($pdo, $new_user_id, $email, 'verification_email_sent', 'failed');
                }
            } else {
                $message = "User created successfully! (Email verification skipped)";
            }

            $success = true;

            // Reset form
            $full_name = '';
            $username = '';
            $email = '';
            $role = 'user';
            $phone_number = '';
            $phone_raw = '';
            $barangay = '';
            $municipality = 'Manolo Fortich';
            $sms_opt_in = 1;

        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "Error creating user: that email address or username is already taken.";
            } else {
                $message = "Error creating user: " . $e->getMessage();
            }
            logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'user_created', 'failed');
        }
    } else {
        $message = implode(' ', $errors);
    }
}

$title = 'Create User';
renderHeader($title);
$isDashboard = false;
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
            'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'user-cog'      => '<circle cx="18" cy="15" r="3"/><circle cx="9" cy="7" r="4"/><path d="M10 15H6a4 4 0 0 0-4 4v2"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'list-check'    => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m15 9 2 2 4-4"/>',
            'tractor'       => '<path d="m10 11 11 .9a1 1 0 0 1 .9 1v1.6a1 1 0 0 1-1 1H15"/><path d="M3 4h9l1 7"/><path d="M4 11V4"/><circle cx="7" cy="17" r="3"/><circle cx="18" cy="17" r="3"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'alert-circle'  => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'arrow-left'    => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
            'mail'          => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'save'          => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
            'eye'           => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'map-pin'       => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
            'message-square'=> '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
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
       DESIGN TOKENS (same as admin dashboard)
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
        margin: 0 0 18px;
        padding-bottom: 14px;
        border-bottom: 1px solid #eef2f6;
        flex-wrap: wrap;
    }
    .gf-card-title svg { color: var(--gf-primary); }
    .gf-card-title .gf-card-chip {
        margin-left: auto;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 999px;
        background: var(--gf-primary-light);
        color: var(--gf-primary-dark);
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }

    /* ---------- MESSAGE BOXES ---------- */
    .gf-message {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 13px 16px;
        border-radius: var(--gf-radius-sm);
        margin-bottom: 18px;
        font-size: 13.5px;
        font-weight: 500;
        line-height: 1.5;
    }
    .gf-message svg { flex-shrink: 0; margin-top: 2px; }
    .gf-message.success { background: #dcfce7; color: #166534; border-left: 4px solid var(--gf-success); }
    .gf-message.success svg { color: #16a34a; }
    .gf-message.error { background: #fef3c7; color: #92400e; border-left: 4px solid var(--gf-warning); }
    .gf-message.error svg { color: #d97706; }
    .gf-message.info { background: #dbeafe; color: #1e40af; border-left: 4px solid var(--gf-info); }
    .gf-message.info svg { color: #2563eb; }
    .gf-message.info a {
        color: #1d4ed8;
        font-weight: 700;
        word-break: break-all;
    }

    /* ---------- SECTION HEADER ---------- */
    .gf-section-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 8px 0 4px;
        padding-bottom: 0;
        font-size: 12px;
        font-weight: 800;
        color: var(--gf-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        grid-column: 1 / -1;
    }
    .gf-section-header::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--gf-border);
    }

    /* ---------- FORM ---------- */
    .gf-form-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 18px;
    }
    @media (min-width: 768px) {
        .gf-form-grid {
            grid-template-columns: 1fr 1fr;
            gap: 18px 24px;
        }
        .gf-form-grid .gf-field-full { grid-column: 1 / -1; }
    }

    .gf-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .gf-field label {
        font-size: 13px;
        font-weight: 700;
        color: var(--gf-text);
        letter-spacing: 0.01em;
    }
    .gf-field label .required { color: var(--gf-danger); margin-left: 2px; }

    .gf-field input[type="text"],
    .gf-field input[type="email"],
    .gf-field input[type="password"],
    .gf-field input[type="tel"],
    .gf-field select {
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
        -moz-appearance: none;
    }
    .gf-field select:disabled {
        background-color: #f8fafc;
        color: var(--gf-text-muted);
        cursor: not-allowed;
    }

    .gf-field small {
        font-size: 12px;
        color: var(--gf-text-muted);
        line-height: 1.45;
    }

    .gf-checkbox-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px solid var(--gf-border);
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s var(--gf-ease);
    }
    .gf-checkbox-row:hover { background: #f1f5f9; }
    .gf-checkbox-row input[type="checkbox"] {
        width: 18px; height: 18px;
        margin: 1px 0 0;
        accent-color: var(--gf-primary);
        cursor: pointer;
        flex-shrink: 0;
    }
    .gf-checkbox-row .checkbox-text {
        flex: 1;
        min-width: 0;
    }
    .gf-checkbox-row .checkbox-text strong {
        display: block;
        font-size: 13.5px;
        color: var(--gf-text);
        font-weight: 700;
        margin-bottom: 2px;
    }
    .gf-checkbox-row .checkbox-text span {
        font-size: 12px;
        color: var(--gf-text-muted);
        line-height: 1.4;
    }

    /* ---------- INFO NOTE ---------- */
    .gf-note {
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
    .gf-note svg { color: var(--gf-primary); flex-shrink: 0; margin-top: 2px; }
    .gf-note strong { color: var(--gf-primary-dark); }

    .gf-note.gf-note-info {
        background: #eff6ff;
        border-left-color: var(--gf-info);
    }
    .gf-note.gf-note-info svg { color: var(--gf-info); }
    .gf-note.gf-note-info strong { color: #1e40af; }

    /* ---------- FORM ACTIONS ---------- */
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
        min-height: 44px;
        padding: 11px 22px;
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
    }
    .gf-btn-primary:active { transform: translateY(0); }

    .gf-btn-secondary {
        background: #f1f5f9;
        color: var(--gf-text-secondary);
        border: 1px solid var(--gf-border);
    }
    .gf-btn-secondary:hover { background: #e2e8f0; color: var(--gf-text); }

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
        .gf-card { padding: 18px; }
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
                        <h1>Create New User</h1>
                        <p class="gf-welcome">
                            Add a new <?php echo $currentRole === 'manager' ? 'farmer' : 'user'; ?> to the system
                        </p>
                    </div>
                </div>
                <span class="gf-date-badge">
                    <?php echo icon('calendar', 14); ?>
                    <?php echo date('M d, Y'); ?>
                </span>
            </header>

            <!-- ===== FORM CARD ===== -->
            <div class="gf-card">

                <h2 class="gf-card-title">
                    <?php echo icon('user-plus', 18); ?>
                    User Details
                    <span class="gf-card-chip"><?php echo ucfirst($currentRole); ?> access</span>
                </h2>

                <?php if ($currentRole === 'manager'): ?>
                    <div class="gf-note">
                        <?php echo icon('info', 16); ?>
                        <div>
                            <strong>Manager Access:</strong>
                            You can only create regular <strong>User (Farmer)</strong> accounts. The role selection is disabled.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="gf-message <?php echo $success ? 'success' : 'error'; ?>">
                        <?php echo $success ? icon('check-circle', 16) : icon('alert-circle', 16); ?>
                        <div><?php echo htmlspecialchars($message); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($verificationLink): ?>
                    <div class="gf-message info">
                        <?php echo icon('mail', 16); ?>
                        <div>
                            <strong>Email Verification Link (for testing):</strong><br>
                            <a href="<?php echo $verificationLink; ?>" target="_blank"><?php echo $verificationLink; ?></a>
                            <p style="margin: 8px 0 0; font-size: 12.5px; color: #4b5563;">
                                In production, this link is sent via email. The email has been queued.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="gf-form-grid">

                        <!-- ============================================
                             SECTION: ACCOUNT INFORMATION
                        ============================================ -->
                        <div class="gf-section-header">
                            <?php echo icon('user', 14); ?>
                            Account Information
                        </div>

                        <!-- Full Name -->
                        <div class="gf-field">
                            <label for="full_name">Full Name<span class="required">*</span></label>
                            <input type="text" id="full_name" name="full_name" required
                                   value="<?php echo htmlspecialchars($full_name); ?>"
                                   placeholder="Juan Dela Cruz">
                        </div>

                        <!-- Username -->
                        <div class="gf-field">
                            <label for="username">Username<span class="required">*</span></label>
                            <input type="text" id="username" name="username" required
                                   value="<?php echo htmlspecialchars($username); ?>"
                                   placeholder="jdelacruz">
                            <small>3–50 chars · letters, numbers, dot, dash, underscore</small>
                        </div>

                        <!-- Email -->
                        <div class="gf-field">
                            <label for="email">Email Address<span class="required">*</span></label>
                            <input type="email" id="email" name="email" required
                                   value="<?php echo htmlspecialchars($email); ?>"
                                   placeholder="user@example.com">
                        </div>

                        <!-- Password -->
                        <div class="gf-field">
                            <label for="password">Password<span class="required">*</span></label>
                            <input type="password" id="password" name="password" required
                                   placeholder="At least 6 characters">
                            <small>Minimum 6 characters</small>
                        </div>

                        <!-- ============================================
                             SECTION: SMS ADVISORY CONTACT
                        ============================================ -->
                        <div class="gf-section-header">
                            <?php echo icon('message-square', 14); ?>
                            SMS Advisory Contact
                        </div>

                        <div class="gf-field gf-field-full">
                            <div class="gf-note gf-note-info" style="margin-bottom: 0;">
                                <?php echo icon('info', 16); ?>
                                <div>
                                    <strong>Weather Advisory SMS:</strong>
                                    Kini nga impormasyon gamiton sa sistema aron makapadala og automated
                                    weather advisory (rain, heat, wind) ngadto sa farmer pinaagi sa SMS.
                                </div>
                            </div>
                        </div>

                        <!-- Phone Number -->
                        <div class="gf-field">
                            <label for="phone_number">
                                Phone Number
                                <?php if ($role === 'user' || $currentRole === 'manager'): ?>
                                    <span class="required">*</span>
                                <?php endif; ?>
                            </label>
                            <input type="tel" 
                                   id="phone_number" 
                                   name="phone_number" 
                                   inputmode="tel"
                                   value="<?php echo htmlspecialchars($phone_raw); ?>"
                                   placeholder="09171234567 o +639171234567"
                                   pattern="[0-9+\s\-()]{10,20}"
                                   maxlength="20">
                            <small>Format: <code>09171234567</code> o <code>+639171234567</code>. Automatic ma-convert sa E.164 format.</small>
                        </div>

                        <!-- Barangay -->
                        <div class="gf-field">
                            <label for="barangay">Barangay</label>
                            <input type="text" 
                                   id="barangay" 
                                   name="barangay" 
                                   value="<?php echo htmlspecialchars($barangay); ?>"
                                   placeholder="e.g., Barangay 1"
                                   maxlength="100">
                            <small>Optional — para ma-filter ang advisory by area.</small>
                        </div>

                        <!-- Municipality -->
                        <div class="gf-field">
                            <label for="municipality">Municipality</label>
                            <input type="text" 
                                   id="municipality" 
                                   name="municipality" 
                                   value="<?php echo htmlspecialchars($municipality); ?>"
                                   maxlength="100">
                            <small>Default: Manolo Fortich</small>
                        </div>

                        <!-- SMS Opt-in -->
                        <div class="gf-field gf-field-full">
                            <label class="gf-checkbox-row">
                                <input type="checkbox" name="sms_opt_in" value="1" 
                                       <?php echo $sms_opt_in ? 'checked' : ''; ?>>
                                <span class="checkbox-text">
                                    <strong>📱 Gusto makadawat og weather advisory via SMS</strong>
                                    <span>Kung i-check, makadawat ang user og automated SMS advisory kung naay rain, heat, o wind warning.</span>
                                </span>
                            </label>
                        </div>

                        <!-- ============================================
                             SECTION: ROLE & ACCESS
                        ============================================ -->
                        <div class="gf-section-header">
                            <?php echo icon('shield', 14); ?>
                            Role & Access
                        </div>

                        <!-- Role -->
                        <div class="gf-field">
                            <label for="role">Role</label>
                            <select id="role" name="role" <?php echo $currentRole === 'manager' ? 'disabled' : ''; ?>>
                                <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>User (Farmer)</option>
                                <?php if ($currentRole === 'admin'): ?>
                                    <option value="manager" <?php echo $role === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <?php endif; ?>
                            </select>
                            <?php if ($currentRole === 'manager'): ?>
                                <input type="hidden" name="role" value="user">
                                <small>Managers can only create regular User (Farmer) accounts.</small>
                            <?php else: ?>
                                <small>Select the role for this user.</small>
                            <?php endif; ?>
                        </div>

                        <!-- Status -->
                        <div class="gf-field">
                            <label for="status">Account Status</label>
                            <select id="status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <small>If inactive, the user cannot log in.</small>
                        </div>

                        <!-- Email Verification Checkbox -->
                        <div class="gf-field gf-field-full">
                            <label class="gf-checkbox-row">
                                <input type="checkbox" name="send_verification_email" value="1" checked>
                                <span class="checkbox-text">
                                    <strong>Send email verification</strong>
                                    <span>If unchecked, the user will be verified immediately without email confirmation.</span>
                                </span>
                            </label>
                        </div>

                    </div>

                    <div class="gf-form-actions">
                        <button type="submit" class="gf-btn gf-btn-primary">
                            <?php echo icon('save', 16); ?>
                            Create User
                        </button>
                        <a href="<?php echo BASE_URL; ?>/app/admin/dashboard.php" class="gf-btn gf-btn-secondary">
                            <?php echo icon('arrow-left', 16); ?>
                            Cancel
                        </a>
                    </div>
                </form>
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