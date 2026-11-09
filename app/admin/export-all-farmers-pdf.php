<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/activity-logger.php';
require_once '../../includes/goat-functions.php';
requireRole('admin');

// Get ALL farmers
$stmt = $pdo->query("
    SELECT 
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.is_verified,
        u.created_at,
        COUNT(g.id) AS total_goats,
        SUM(CASE WHEN g.status = 'active'   THEN 1 ELSE 0 END) AS active_goats,
        SUM(CASE WHEN g.status = 'sold'     THEN 1 ELSE 0 END) AS sold_goats,
        SUM(CASE WHEN g.status = 'deceased' THEN 1 ELSE 0 END) AS deceased_goats
    FROM users u
    LEFT JOIN goats g ON g.user_id = u.id
    GROUP BY u.id
    ORDER BY total_goats DESC, u.full_name ASC
");
$farmersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall totals
$grandTotal    = 0;
$grandActive   = 0;
$grandSold     = 0;
$grandDeceased = 0;
foreach ($farmersList as $f) {
    $grandTotal    += (int)$f['total_goats'];
    $grandActive   += (int)$f['active_goats'];
    $grandSold     += (int)$f['sold_goats'];
    $grandDeceased += (int)$f['deceased_goats'];
}

// Log activity
logActivity($pdo, $_SESSION['user_id'] ?? null, 'all_farmers_printed', 'success', 'Printed ALL farmers report');

$adminEmail = $_SESSION['email'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Farmers Report — <?php echo date('Y-m-d'); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            font-size: 12px;
            color: #1f2937;
            background: #f3f4f6;
            padding: 20px;
        }

        /* ===== Toolbar (dili ma-print) ===== */
        .toolbar {
            max-width: 1100px;
            margin: 0 auto 16px auto;
            padding: 12px 18px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .toolbar-info {
            display: flex; align-items: center; gap: 10px;
        }
        .toolbar-info i {
            color: #22C55E; font-size: 20px;
        }
        .toolbar-info .title {
            font-weight: 700; font-size: 14px; color: #1f2937;
        }
        .toolbar-info .sub {
            font-size: 12px; color: #6b7280;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 10px 20px; border-radius: 999px;
            font-weight: 600; font-size: 13px;
            border: none; cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-print {
            background: linear-gradient(145deg, #22C55E, #15803D);
            color: white;
            box-shadow: 0 4px 10px rgba(34,197,94,0.25);
        }
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(34,197,94,0.35);
        }
        .btn-back {
            background: #f3f4f6;
            color: #374151;
        }
        .btn-back:hover { background: #e5e7eb; }

        /* ===== Report (ma-print) ===== */
        .report {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            padding: 32px 36px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .report-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 3px solid #22C55E;
            padding-bottom: 14px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .report-header .left { display: flex; align-items: center; gap: 12px; }
        .report-header .logo {
            font-size: 38px; line-height: 1;
        }
        .report-header h1 {
            font-size: 22px; color: #15803D;
            margin: 0; font-weight: 800;
        }
        .report-header .subtitle {
            font-size: 12px; color: #6b7280; margin-top: 2px;
        }
        .report-header .meta {
            text-align: right;
            font-size: 11px;
            color: #6b7280;
        }
        .report-header .meta strong {
            color: #374151;
            display: block;
            font-size: 12px;
        }

        /* ===== Summary Boxes ===== */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        .summary-box {
            background: #f0fdf4;
            border-left: 4px solid #22C55E;
            padding: 12px 14px;
            border-radius: 8px;
        }
        .summary-box .num {
            font-size: 22px;
            font-weight: 800;
            color: #15803D;
            line-height: 1.1;
        }
        .summary-box .lbl {
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .summary-box.green .num { color: #15803D; }
        .summary-box.yellow { border-left-color: #f59e0b; background: #fffbeb; }
        .summary-box.yellow .num { color: #b45309; }
        .summary-box.red { border-left-color: #ef4444; background: #fef2f2; }
        .summary-box.red .num { color: #b91c1c; }
        .summary-box.blue { border-left-color: #3b82f6; background: #eff6ff; }
        .summary-box.blue .num { color: #1d4ed8; }

        /* ===== Table ===== */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        thead th {
            background: #f0fdf4;
            color: #166534;
            text-align: left;
            padding: 10px 12px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border-bottom: 2px solid #dcfce7;
            white-space: nowrap;
        }
        thead th.center { text-align: center; }

        tbody td {
            padding: 9px 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        tbody tr:nth-child(even) td { background: #fafff7; }
        tbody td.center { text-align: center; }

        tbody .farmer-name {
            font-weight: 700;
            color: #1f2937;
        }
        tbody .farmer-email {
            color: #6b7280;
        }

        .role-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .role-user    { background: #e0e7ff; color: #3730a3; }
        .role-admin   { background: #fce7f3; color: #9d174d; }
        .role-manager { background: #dbeafe; color: #1e40af; }

        .count-total {
            font-weight: 800;
            color: #1f2937;
            font-size: 13px;
        }
        .count-active   { color: #15803D; font-weight: 700; }
        .count-sold     { color: #b45309; font-weight: 700; }
        .count-deceased { color: #b91c1c; font-weight: 700; }

        tfoot td {
            padding: 12px;
            background: #f0fdf4;
            font-weight: 800;
            color: #15803D;
            border-top: 2px solid #22C55E;
            font-size: 13px;
        }
        tfoot td.center { text-align: center; }

        .footer-note {
            margin-top: 30px;
            padding-top: 14px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
            line-height: 1.6;
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            @page {
                size: A4 landscape;
                margin: 12mm;
            }
            body {
                background: white;
                padding: 0;
                font-size: 10px;
            }
            .toolbar { display: none !important; }
            .report {
                box-shadow: none;
                border-radius: 0;
                padding: 0;
                max-width: 100%;
            }
            .report-header h1 { font-size: 18px; }
            .summary-box .num { font-size: 18px; }
            table { font-size: 10px; }
            thead th { font-size: 9px; padding: 6px 8px; }
            tbody td { padding: 6px 8px; }
            tfoot td { padding: 8px; font-size: 11px; }
            tbody tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
        }

        @media (max-width: 768px) {
            .report { padding: 20px; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .report-header { flex-direction: column; align-items: flex-start; }
            .report-header .meta { text-align: left; }
        }
    </style>
</head>
<body>

    <!-- Toolbar (dili ma-print) -->
    <div class="toolbar">
        <div class="toolbar-info">
            <i class="fas fa-print"></i>
            <div>
                <div class="title">All Farmers Report</div>
                <div class="sub">Pilia ang <strong>"Save as PDF"</strong> sa print dialog aron ma-download.</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="dashboard.php" class="btn btn-back">
                ← Back to Dashboard
            </a>
            <button type="button" class="btn btn-print" onclick="window.print()">
                🖨️ Print / Save as PDF
            </button>
        </div>
    </div>

    <!-- Report content -->
    <div class="report">

        <!-- Header -->
        <div class="report-header">
            <div class="left">
                <span class="logo">🐐</span>
                <div>
                    <h1>All Farmers Report</h1>
                    <div class="subtitle">Complete list of farmers with goat inventory summary</div>
                </div>
            </div>
            <div class="meta">
                <strong>Goat Management System</strong>
                Generated: <?php echo date('F d, Y h:i A'); ?><br>
                By: <?php echo htmlspecialchars($adminEmail); ?>
            </div>
        </div>

        <!-- Summary -->
        <div class="summary-grid">
            <div class="summary-box blue">
                <div class="num"><?php echo count($farmersList); ?></div>
                <div class="lbl">Total Farmers</div>
            </div>
            <div class="summary-box">
                <div class="num"><?php echo $grandTotal; ?></div>
                <div class="lbl">Total Goats</div>
            </div>
            <div class="summary-box">
                <div class="num"><?php echo $grandActive; ?></div>
                <div class="lbl">Active Goats</div>
            </div>
            <div class="summary-box yellow">
                <div class="num"><?php echo $grandSold; ?></div>
                <div class="lbl">Sold Goats</div>
            </div>
        </div>

        <!-- Table -->
        <table>
            <thead>
                <tr>
                    <th style="width:5%;" class="center">#</th>
                    <th style="width:22%;">Farmer Name</th>
                    <th style="width:28%;">Email</th>
                    <th style="width:10%;" class="center">Role</th>
                    <th style="width:9%;" class="center">Total</th>
                    <th style="width:9%;" class="center">Active</th>
                    <th style="width:9%;" class="center">Sold</th>
                    <th style="width:9%;" class="center">Deceased</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($farmersList)): ?>
                    <tr>
                        <td colspan="8" class="center" style="padding:24px;color:#9ca3af;">
                            No farmers found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($farmersList as $i => $farmer): ?>
                        <tr>
                            <td class="center"><?php echo $i + 1; ?></td>
                            <td class="farmer-name"><?php echo htmlspecialchars($farmer['full_name']); ?></td>
                            <td class="farmer-email"><?php echo htmlspecialchars($farmer['email']); ?></td>
                            <td class="center">
                                <?php
                                    $roleClass = 'role-user';
                                    if ($farmer['role'] === 'admin')   $roleClass = 'role-admin';
                                    if ($farmer['role'] === 'manager') $roleClass = 'role-manager';
                                ?>
                                <span class="role-badge <?php echo $roleClass; ?>">
                                    <?php echo htmlspecialchars($farmer['role']); ?>
                                </span>
                            </td>
                            <td class="center count-total"><?php echo (int)$farmer['total_goats']; ?></td>
                            <td class="center count-active"><?php echo (int)$farmer['active_goats']; ?></td>
                            <td class="center count-sold"><?php echo (int)$farmer['sold_goats']; ?></td>
                            <td class="center count-deceased"><?php echo (int)$farmer['deceased_goats']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;">GRAND TOTAL:</td>
                    <td class="center"><?php echo $grandTotal; ?></td>
                    <td class="center"><?php echo $grandActive; ?></td>
                    <td class="center"><?php echo $grandSold; ?></td>
                    <td class="center"><?php echo $grandDeceased; ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Footer -->
        <div class="footer-note">
            <strong>Goat Management System</strong> — Confidential Report<br>
            This document contains <?php echo count($farmersList); ?> farmer records as of <?php echo date('F d, Y h:i A'); ?>.
        </div>

    </div>

    <!-- Auto-trigger print dialog -->
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 400);
        });
    </script>

</body>
</html>