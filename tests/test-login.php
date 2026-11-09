<?php
require_once '../config/config.php';
require_once '../config/functions.php';

// ADMIN ONLY (test page - do not deploy to production)
requireRole('admin');

// Generate fresh hash for password123
$freshHash = password_hash('password123', PASSWORD_DEFAULT);

// Get all users for testing
$stmt = $pdo->prepare("SELECT id, full_name, username, email, role, status, is_verified, password FROM users ORDER BY role, email");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$testResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_all'])) {
    // Test all users with default password
    foreach ($users as $user) {
        $testPassword = 'password123';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$user['email']]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $passwordMatch = password_verify($testPassword, $dbUser['password']);
        
        $testResults[$user['email']] = [
            'password_match' => $passwordMatch,
            'is_verified' => $user['is_verified'],
            'status' => $user['status'],
            'can_login' => $passwordMatch && $user['is_verified'] && $user['status'] === 'active',
            'stored_hash' => substr($dbUser['password'], 0, 30) . '...'
        ];
    }
}

// Auto-fix passwords
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_passwords'])) {
    $newHash = password_hash('password123', PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET password = ?, is_verified = 1, status = 'active'");
        $stmt->execute([$newHash]);
        
        $fixMessage = "✓ All user passwords have been reset to 'password123', verified, and activated!";
        
        // Refresh users list
        $stmt = $pdo->prepare("SELECT id, full_name, username, email, role, status, is_verified, password FROM users ORDER BY role, email");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $fixMessage = "Error: " . $e->getMessage();
    }
}

// Quick login functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_login'])) {
    $email = $_POST['email'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        
        redirect('/app/' . $user['role'] . '/dashboard.php');
    }
}

renderHeader('Login Test Page');
?>

<h1>🧪 Login Test Page</h1>

<div class="info-box">
    <strong>⚠️ This is a testing page - Remove in production!</strong><br>
    Use this page to test all user credentials and verify database setup.
</div>

<?php if (isset($fixMessage)): ?>
    <div class="success"><?php echo $fixMessage; ?></div>
<?php endif; ?>

<h2>Quick Actions</h2>

<form method="POST" style="display: inline-block; margin-right: 10px;">
    <button type="submit" name="test_all" style="background: #28a745;">Test All Credentials</button>
</form>

<form method="POST" style="display: inline-block;">
    <button type="submit" name="fix_passwords" style="background: #dc3545;" 
            onclick="return confirm('This will reset ALL user passwords to \'password123\' and verify all accounts. Continue?')">
        🔧 Auto-Fix All Passwords
    </button>
</form>

<div class="info-box" style="background: #e7f3ff; margin-top: 15px;">
    <strong>Fresh Hash Generated:</strong><br>
    <code style="font-size: 11px; word-break: break-all;"><?php echo $freshHash; ?></code><br>
    <small>This is what 'password123' should look like when hashed.</small>
</div>

<?php if (!empty($testResults)): ?>
    <h2>Test Results</h2>
    <table>
        <thead>
            <tr>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Verified</th>
                <th>Password Match</th>
                <th>Stored Hash</th>
                <th>Can Login</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): 
                $result = $testResults[$user['email']];
            ?>
            <tr style="background: <?php echo $result['can_login'] ? '#d4edda' : '#f8d7da'; ?>">
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                <td><span class="badge badge-<?php echo $result['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($result['status']); ?></span></td>
                <td>
                    <?php if ($user['is_verified']): ?>
                        <span style="color: green;">✓ Yes</span>
                    <?php else: ?>
                        <span style="color: red;">✗ No</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($result['password_match']): ?>
                        <span style="color: green;">✓ Correct</span>
                    <?php else: ?>
                        <span style="color: red;">✗ Wrong</span>
                    <?php endif; ?>
                </td>
                <td><code style="font-size: 10px;"><?php echo $result['stored_hash']; ?></code></td>
                <td>
                    <?php if ($result['can_login']): ?>
                        <span style="color: green; font-weight: bold;">✓ YES</span>
                    <?php else: ?>
                        <span style="color: red; font-weight: bold;">✗ NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>All Users in Database</h2>

<?php if (empty($users)): ?>
    <div class="error">
        <strong>⚠️ No users found in database!</strong><br>
        Please run the database setup SQL to create test users.
    </div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Verified</th>
                <th>Quick Login</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?php echo $user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                <td><span class="badge badge-<?php echo $user['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                <td>
                    <span class="badge badge-<?php echo $user['is_verified'] ? 'verified' : 'unverified'; ?>">
                        <?php echo $user['is_verified'] ? 'Yes' : 'No'; ?>
                    </span>
                </td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                        <button type="submit" name="quick_login" style="padding: 5px 10px; font-size: 12px;">
                            Login as this user
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Database Fix Scripts</h2>

<div class="info-box">
    <strong>Option 1: Use the Auto-Fix Button Above (Easiest!)</strong><br>
    Click the "🔧 Auto-Fix All Passwords" button to automatically reset everything.
    
    <h3 style="margin-top: 15px;">Option 2: Manual SQL Fix</h3>
    
    <strong>Copy this FRESH hash and use it in SQL:</strong>
    <pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 11px;"><?php echo $freshHash; ?></pre>
    
    <strong>Then run this SQL:</strong>
    <pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto;">UPDATE users SET 
    password = '<?php echo $freshHash; ?>', 
    is_verified = 1,
    status = 'active';</pre>
    
    <h3 style="margin-top: 15px;">Option 3: Re-create test users with fresh hash:</h3>
    <pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto;">DELETE FROM users;

INSERT INTO users (full_name, username, email, password, role, status, is_verified) VALUES
('System Administrator', 'admin', 'admin@example.com', '<?php echo $freshHash; ?>', 'admin', 'active', 1),
('Farm Manager', 'manager', 'manager@example.com', '<?php echo $freshHash; ?>', 'manager', 'active', 1),
('Juan Dela Cruz', 'jdelacruz', 'user@example.com', '<?php echo $freshHash; ?>', 'user', 'active', 1);</pre>
</div>

<div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
    <strong>Test Credentials:</strong><br>
    All test accounts use password: <code style="background: #f4f4f4; padding: 2px 6px; border-radius: 3px;">password123</code>
</div>

<p style="margin-top: 20px;">
    <a href="<?php echo BASE_URL; ?>/index.php">
        <button>Go to Normal Login Page</button>
    </a>
</p>

<?php renderFooter(); ?>
