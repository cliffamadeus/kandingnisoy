<?php
require_once '../config/config.php';
require_once '../config/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// ============================================================
// AUTH CHECK — Allow admin, manager, user
// ============================================================
if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Please log in.', 'data' => []]);
    exit;
}

$currentRole = strtolower($_SESSION['role'] ?? '');
$userId      = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($currentRole, ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Invalid role.', 'data' => []]);
    exit;
}

// ============================================================
// FETCH ACTIVITY LOGS WITH ROLE-BASED FILTERING
// ============================================================
try {
    // Base query
    $sql = "
        SELECT
            al.id,
            al.email,
            al.user_id,
            u.full_name,
            u.role AS user_role,
            al.action,
            al.status,
            al.created_at
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
    ";

    $where  = [];
    $params = [];

    // Role-based filter
    if ($currentRole === 'admin') {
        // Admin: see everything (no filter)
    } elseif ($currentRole === 'manager') {
        // Manager: see manager + user activities only (exclude admin)
        $where[] = "(u.role IN ('manager', 'user') OR u.role IS NULL)";
    } elseif ($currentRole === 'user') {
        // User: see own activities only
        $where[] = "al.user_id = ?";
        $params[] = $userId;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT 1000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize — ensure consistent shape sa tanan
    $normalized = [];
    foreach ($logs as $log) {
        $normalized[] = [
            'id'         => (int)$log['id'],
            'user_id'    => $log['user_id'] ? (int)$log['user_id'] : null,
            'email'      => $log['email']     ?? 'system',
            'full_name'  => $log['full_name'] ?? null,
            'user_role'  => $log['user_role'] ?? 'system',
            'action'     => $log['action']    ?? 'unknown',
            'status'     => $log['status']    ?? 'success',
            'created_at' => $log['created_at'] ?? date('Y-m-d H:i:s'),
        ];
    }

    // DataTables (serverSide: false) expects {data: [...]}
    echo json_encode(['data' => $normalized]);

} catch (PDOException $e) {
    error_log('[Activity Logs API] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to load activity logs.',
        'data'  => [],
    ]);
}