<?php

function redirect($path) {
    header("Location: " . BASE_URL . $path);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/index.php');
    }
}

function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        die("Access denied. Required role: $role");
    }
}

function hasAnyRole($roles) {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
}

function requireAnyRole($roles) {
    requireLogin();
    if (!hasAnyRole($roles)) {
        die("Access denied. Required role: " . implode(' or ', $roles));
    }
}

// Role hierarchy used across the User Management module.
// Admin can manage everyone. Manager can only manage regular users
// (Farmers) and can never create, edit, or delete Admin or Manager accounts.
function canManageTarget($currentRole, $currentUserId, $targetRole, $targetUserId) {
    if ($currentRole === 'admin') {
        return true;
    }
    if ($currentRole === 'manager') {
        return $targetRole === 'user';
    }
    return $targetUserId == $currentUserId;
}

function canDeleteTarget($currentRole, $currentUserId, $targetRole, $targetUserId) {
    if ($targetUserId == $currentUserId) {
        return false;
    }
    if ($currentRole === 'admin') {
        return true;
    }
    if ($currentRole === 'manager') {
        return $targetRole === 'user';
    }
    return false;
}

function canAssignRole($currentRole) {
    return $currentRole === 'admin';
}

function canViewTarget($currentRole, $currentUserId, $targetRole, $targetUserId) {
    if ($targetUserId == $currentUserId) {
        return true;
    }
    if ($currentRole === 'admin') {
        return true;
    }
    if ($currentRole === 'manager') {
        return $targetRole === 'user';
    }
    return false;
}

/**
 * ============================================================
 * PHONE NUMBER HELPERS (for SMS advisory system)
 * ============================================================
 */

function normalizePhoneNumber(string $input): ?string
{
    $cleaned = preg_replace('/[^\d+]/', '', $input);
    
    if ($cleaned === '') return null;
    
    if (preg_match('/^0(\d{10})$/', $cleaned, $m)) {
        return '+63' . $m[1];
    }
    
    if (preg_match('/^\+639(\d{9})$/', $cleaned)) {
        return $cleaned;
    }
    
    if (preg_match('/^63(\d{10})$/', $cleaned, $m)) {
        return '+63' . $m[1];
    }
    
    if (preg_match('/^\+\d{10,15}$/', $cleaned)) {
        return $cleaned;
    }
    
    return null;
}

function formatPhoneDisplay(?string $phone): string
{
    if (empty($phone)) return '—';
    
    if (preg_match('/^\+63(\d{3})(\d{3})(\d{4})$/', $phone, $m)) {
        return '0' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    
    return $phone;
}

function isValidE164(?string $phone): bool
{
    if (empty($phone)) return false;
    return (bool) preg_match('/^\+\d{10,15}$/', $phone);
}

function maskPhone(?string $phone): string
{
    if (empty($phone) || strlen($phone) < 8) {
        return $phone ?? '—';
    }
    
    $len = strlen($phone);
    $visibleStart = substr($phone, 0, 6);
    $visibleEnd = substr($phone, -3);
    $masked = str_repeat('*', max(1, $len - 9));
    
    return $visibleStart . $masked . $visibleEnd;
}

/**
 * ============================================================
 * TEXTBEE SMS FUNCTIONS
 * ============================================================
 */

function sendSMSViaTextBee(array $recipients, string $message): array
{
    if (empty($recipients)) return ['success' => false, 'error' => 'No recipients provided'];
    if (empty($message)) return ['success' => false, 'error' => 'Message is empty'];
    
    foreach ($recipients as $phone) {
        if (!isValidE164($phone)) {
            return ['success' => false, 'error' => "Invalid phone number: $phone"];
        }
    }
    
    $url = TEXTBEE_API_URL . '/gateway/devices/' . TEXTBEE_DEVICE_ID . '/send-sms';
    
    $payload = [
        'recipients' => array_values($recipients),
        'message'    => $message,
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . TEXTBEE_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'response' => $decoded, 'http_code' => $httpCode];
    }
    
    return [
        'success'   => false,
        'error'     => 'API error (HTTP ' . $httpCode . '): ' . ($decoded['message'] ?? $response),
        'response'  => $decoded,
        'http_code' => $httpCode,
    ];
}

function sendAdvisoryToFarmers(
    PDO $pdo,
    string $message,
    string $advisoryType = 'general',
    string $priority = 'medium',
    array $triggerData = [],
    ?int $sentBy = null
): array {
    $stmt = $pdo->prepare("
        SELECT id, full_name, phone_number, barangay, municipality 
        FROM users 
        WHERE role = 'user' 
          AND status = 'active'
          AND phone_number IS NOT NULL 
          AND phone_number != ''
          AND sms_opt_in = 1
    ");
    $stmt->execute();
    $farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($farmers)) {
        return ['success' => false, 'error' => 'No farmers with valid phone numbers', 'count' => 0];
    }
    
    $logStmt = $pdo->prepare("
        INSERT INTO advisory_logs 
            (advisory_type, priority, message, trigger_data, recipient_count, sent_by, status)
        VALUES 
            (:type, :priority, :message, :trigger_data, :count, :sent_by, 'pending')
    ");
    $logStmt->execute([
        ':type'         => $advisoryType,
        ':priority'     => $priority,
        ':message'      => $message,
        ':trigger_data' => json_encode($triggerData),
        ':count'        => count($farmers),
        ':sent_by'      => $sentBy,
    ]);
    $advisoryId = $pdo->lastInsertId();
    
    $recipients = array_column($farmers, 'phone_number');
    $result = sendSMSViaTextBee($recipients, $message);
    
    $recipientStmt = $pdo->prepare("
        INSERT INTO advisory_recipients 
            (advisory_id, user_id, phone_number, status, provider_response, sent_at)
        VALUES 
            (:advisory_id, :user_id, :phone, :status, :response, :sent_at)
    ");
    
    $successCount = 0;
    $failedCount = 0;
    
    foreach ($farmers as $farmer) {
        $status = $result['success'] ? 'sent' : 'failed';
        if ($result['success']) $successCount++;
        else $failedCount++;
        
        $recipientStmt->execute([
            ':advisory_id' => $advisoryId,
            ':user_id'     => $farmer['id'],
            ':phone'       => $farmer['phone_number'],
            ':status'      => $status,
            ':response'    => json_encode($result['response'] ?? $result['error'] ?? null),
            ':sent_at'     => $result['success'] ? date('Y-m-d H:i:s') : null,
        ]);
    }
    
    $finalStatus = $result['success'] ? 'sent' : 'failed';
    $updateStmt = $pdo->prepare("
        UPDATE advisory_logs 
        SET status = :status, 
            success_count = :success_count, 
            failed_count = :failed_count
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':status'        => $finalStatus,
        ':success_count' => $successCount,
        ':failed_count'  => $failedCount,
        ':id'            => $advisoryId,
    ]);
    
    return [
        'success'        => $result['success'],
        'advisory_id'    => $advisoryId,
        'recipient_count' => count($farmers),
        'success_count'  => $successCount,
        'failed_count'   => $failedCount,
        'error'          => $result['error'] ?? null,
        'response'       => $result['response'] ?? null,
    ];
}

/**
 * ============================================================
 * LAYOUT FUNCTIONS (FIXED FOOTER ALIGNMENT)
 * ============================================================
 */

function renderHeader($title) {
    $currentRole = $_SESSION['role'] ?? 'guest';
    $currentEmail = $_SESSION['email'] ?? '';
    $isLoggedIn = isset($_SESSION['user_id']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?></title>
        <!-- Material Icons -->
        <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
        <!-- Google Fonts -->
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
        <style>
            /* Force body to be a flex container so footer sticks to bottom */
            body { 
                display: flex; 
                flex-direction: column; 
                min-height: 100vh; 
                margin: 0; 
            }
        </style>
    </head>
    <body>
        <?php if ($isLoggedIn): ?>
            <!-- flex: 1 ensures this takes up all available space, pushing footer down -->
            <div class="main-content" style="flex: 1 0 auto;">
                <div class="container">
        <?php else: ?>
            <div class="container" style="flex: 1 0 auto; margin: 0 auto; max-width: 500px; padding-top: 100px;">
        <?php endif; ?>
    <?php
}

function renderFooter() {
    $isLoggedIn = isset($_SESSION['user_id']);
    ?>
        <?php if ($isLoggedIn): ?>
                </div> <!-- /.container -->
            </div> <!-- /.main-content -->
        <?php else: ?>
            </div> <!-- /.container -->
        <?php endif; ?>

        <!-- GLOBAL FOOTER -->
        <footer style="flex-shrink: 0; width: 100%; padding: 24px; text-align: center; font-size: 12.5px; color: #64748b; border-top: 1px solid #e2e8f0; background: #ffffff; box-sizing: border-box;">
            <p style="margin: 0 0 4px;">&copy; <?php echo date('Y'); ?> <strong>GoatPro</strong> - Precision Livestock Farming. All rights reserved.</p>
            <p style="margin: 0;">Developed for Manolo Fortich, Bukidnon Farmers</p>
        </footer>
    </body>
    </html>
    <?php
}

/* ============================================================
 * NAVIGATION (single source of truth for navbar + sidebar)
 * Based on app/weather/dashboard.php (admin) and
 * app/user/weather.php (user).
 * ============================================================ */

function gfNavItems($role): array {
    $b = BASE_URL . '/app/';
    switch ($role) {
        case 'admin':
            return [
                ['key' => 'dashboard', 'label' => 'Dashboard',       'url' => $b . 'admin/dashboard.php'],
                ['key' => 'weather',   'label' => 'Weather',         'url' => $b . 'weather/dashboard.php'],
                ['key' => 'advisory',  'label' => 'Advisory Logs',   'url' => $b . 'weather/advisory-logs.php'],
                ['key' => 'inventory', 'label' => 'Inventory',       'url' => $b . 'goats/dashboard.php'],
                ['key' => 'users',     'label' => 'User Management', 'url' => $b . 'users/dashboard.php'],
                ['key' => 'farmers',   'label' => 'Farmers',         'url' => $b . 'goats/farmer-overview.php'],
                ['key' => 'logout',    'label' => 'Logout',          'url' => $b . 'auth/signout.php'],
            ];
        case 'manager':
            return [
                ['key' => 'dashboard', 'label' => 'Dashboard',       'url' => $b . 'manager/dashboard.php'],
                ['key' => 'users',     'label' => 'User Management', 'url' => $b . 'users/dashboard.php'],
                ['key' => 'inventory', 'label' => 'Inventory',       'url' => $b . 'goats/dashboard.php'],
                ['key' => 'farmers',   'label' => 'Farmers',         'url' => $b . 'goats/farmer-overview.php'],
                ['key' => 'logout',    'label' => 'Logout',          'url' => $b . 'auth/signout.php'],
            ];
        default: // 'user' (farmer)
            return [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => $b . 'user/dashboard.php'],
                ['key' => 'weather',   'label' => 'Weather',   'url' => $b . 'user/weather.php'],
                ['key' => 'inventory', 'label' => 'Inventory', 'url' => $b . 'goats/dashboard.php'],
                ['key' => 'add_goat',  'label' => 'Add Goat',  'url' => $b . 'goats/goat-create.php'],
                ['key' => 'logout',    'label' => 'Logout',    'url' => $b . 'auth/signout.php'],
            ];
    }
}

function gfRenderNavbarLinks($role, string $active = ''): void {
    foreach (gfNavItems($role) as $item) {
        $cls = [];
        if ($item['key'] === $active) $cls[] = 'active';
        if ($item['key'] === 'logout') $cls[] = 'logout';
        echo '        <li><a href="' . htmlspecialchars($item['url']) . '"'
           . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . '>'
           . htmlspecialchars($item['label']) . '</a></li>' . "\n";
    }
}

function gfRenderSidebarLinks($role, string $active = ''): void {
    foreach (gfNavItems($role) as $item) {
        $cls = ['gf-nav-link'];
        if ($item['key'] === $active) $cls[] = 'active';
        if ($item['key'] === 'logout') $cls[] = 'logout';
        echo '        <a href="' . htmlspecialchars($item['url']) . '" class="' . implode(' ', $cls) . '"'
           . ($item['key'] === $active ? ' aria-current="page"' : '') . '><span>'
           . htmlspecialchars($item['label']) . '</span></a>' . "\n";
    }
}

?>
