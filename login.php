<?php
require_once 'config/config.php';
require_once 'config/functions.php';
require_once 'includes/activity-logger.php'; 

// uncomment on deployment
/*
require_once $_SERVER['DOCUMENT_ROOT'] . '/test/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/test/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/test/includes/activity-logger.php';
*/

// Fix: Check if session exists and user is logged in properly
if (isLoggedIn()) {
    // Check if role is set in session before using it
    if (isset($_SESSION['role'])) {
        switch($_SESSION['role']) {
            case 'admin':
                redirect('/app/admin/dashboard.php');
                break;
            case 'manager':
                redirect('/app/manager/dashboard.php');
                break;
            case 'user':
                redirect('/app/user/dashboard.php');
                break;
            default:
                redirect('/app/user/dashboard.php');
                break;
        }
    } else {
        redirect('/app/user/dashboard.php');
    }
}

$error = '';
$success = '';
$rememberMe = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']) ? true : false;

    // Allow login using either the email address or the username
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = :identifier OR username = :identifier) AND is_verified = 1");
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        if ($user['status'] !== 'active') {
            $error = "⚠️ This account has been deactivated. Please contact an administrator.";
            logActivity($pdo, $user['id'], $user['email'], 'login', 'failed');
        } else {
            // Successful login - set all session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();

            // Remember me functionality
            if ($rememberMe) {
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                // Store token in database
                $stmt = $pdo->prepare("UPDATE users SET remember_token = :token, token_expiry = :expiry WHERE id = :id");
                $stmt->execute([
                    'token' => $token,
                    'expiry' => $expiry,
                    'id' => $user['id']
                ]);
                
                // Set cookie
                setcookie('remember_token', $token, time() + (86400 * 30), '/', '', false, true);
            }

            // Log successful login
            logActivity($pdo, $user['id'], $user['email'], 'login', 'success');

            // Determine redirect path based on role
            $redirectPath = '';
            switch($user['role']) {
                case 'admin':
                    $redirectPath = '/app/admin/dashboard.php';
                    break;
                case 'manager':
                    $redirectPath = '/app/manager/dashboard.php';
                    break;
                case 'user':
                    $possiblePaths = [
                        '/app/user/dashboard.php',
                        '/app/users/dashboard.php',
                        '/app/dashboard.php'
                    ];
                    
                    $foundPath = false;
                    foreach ($possiblePaths as $path) {
                        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $path;
                        if (file_exists($fullPath)) {
                            $redirectPath = $path;
                            $foundPath = true;
                            break;
                        }
                    }
                    
                    if (!$foundPath) {
                        $redirectPath = '/app/user/dashboard.php';
                    }
                    break;
                default:
                    $redirectPath = '/app/user/dashboard.php';
                    break;
            }
            
            redirect($redirectPath);
        }
    } else {
        $error = "❌ Invalid credentials or email not verified";
        logActivity($pdo, null, $identifier, 'login', 'failed');
    }
}

renderHeader('Login');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🐐 Goat Management - Login</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom Tailwind Configuration -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        grass: {
                            50: '#F0FDF4',
                            100: '#DCFCE7',
                            200: '#BBF7D0',
                            300: '#86EFAC',
                            400: '#4ADE80',
                            500: '#22C55E',
                            600: '#16A34A',
                            700: '#15803D',
                            800: '#166534',
                            900: '#14532D',
                            950: '#0A2E1A'
                        },
                        pasture: {
                            light: '#E8F5E9',
                            DEFAULT: '#C8E6C9',
                            dark: '#A5D6A7'
                        },
                        slate: {
                            dark: '#2D3748',
                            light: '#4A5568',
                            muted: '#718096'
                        }
                    }
                }
            }
        }
    </script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Enhanced animations */
        @keyframes sway {
            0%, 100% { transform: rotate(-3deg); }
            50% { transform: rotate(3deg); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(5deg); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }
        
        @keyframes glowPulse {
            0%, 100% { box-shadow: 0 0 20px rgba(34, 197, 94, 0.3); }
            50% { box-shadow: 0 0 40px rgba(34, 197, 94, 0.6); }
        }
        
        .grass-icon {
            display: inline-block;
            animation: sway 3s ease-in-out infinite;
        }
        
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        .float-animation-delay {
            animation-delay: 2s;
        }
        
        .pulse-animation {
            animation: pulse 2s ease-in-out infinite;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Enhanced input styles with more green */
        .input-focus {
            transition: all 0.3s ease;
        }
        
        .input-focus:focus {
            border-color: #22C55E;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.25);
            outline: none;
            transform: scale(1.01);
            background-color: #F0FDF4;
        }
        
        .input-focus:hover {
            border-color: #4ADE80;
            background-color: #F7FEF8;
        }
        
        /* Enhanced card styles with more green glow */
        .login-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(34, 197, 94, 0.1);
            background-color: rgba(255, 255, 255, 0.95) !important; /* Added so text is readable */
        }
        
        .login-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 25px 60px rgba(34, 197, 94, 0.25);
            border-color: rgba(34, 197, 94, 0.3);
        }
        
        /* Button hover effect - enhanced green */
        .btn-submit {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #22C55E 0%, #15803D 100%);
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.4);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.5);
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:active {
            transform: scale(0.95);
        }
        
        /* Enhanced green background pattern */
        .bg-pattern {
            background-image: 
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2322C55E' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        
        /* Message container */
        .message-container {
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Password toggle button */
        .password-toggle {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #22C55E;
        }
        
        /* Loading spinner */
        .spinner {
            border: 2px solid rgba(255,255,255,0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            animation: spin 0.8s linear infinite;
            display: none;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Interactive checkbox - enhanced green */
        .checkbox-custom {
            appearance: none;
            width: 16px;
            height: 16px;
            border: 2px solid #D1D5DB;
            border-radius: 4px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
        }
        
        .checkbox-custom:checked {
            background: linear-gradient(135deg, #22C55E, #15803D);
            border-color: #22C55E;
            box-shadow: 0 0 10px rgba(34, 197, 94, 0.3);
        }
        
        .checkbox-custom:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 11px;
            font-weight: bold;
        }
        
        .checkbox-custom:hover {
            border-color: #22C55E;
            box-shadow: 0 0 8px rgba(34, 197, 94, 0.2);
        }
        
        /* Tooltip */
        .tooltip {
            position: relative;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .tooltip-text {
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1F2937;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            margin-bottom: 5px;
        }
        
        .tooltip-text::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: #1F2937;
        }
        
        /* Enhanced link styles with green */
        .link-green {
            color: #16A34A;
            transition: all 0.3s ease;
        }
        
        .link-green:hover {
            color: #22C55E;
            text-decoration: underline;
        }
        
        /* Green glow for header */
        .green-glow {
            animation: glowPulse 2s ease-in-out infinite;
        }

        /* ===== COMPACT / NO-SCROLL SIZING ===== */
        html, body {
            height: 100%;
            overflow: hidden; /* no page scroll on normal viewports */
        }

        .login-viewport {
            height: 100vh;
            height: 100dvh; /* accounts for mobile browser chrome */
        }

        /* Safety net: if content genuinely can't fit (very short screens),
           allow the inner column to scroll instead of clipping content. */
        .login-scroll-safety {
            max-height: 100vh;
            max-height: 100dvh;
            overflow-y: auto;
        }

        /* Shrink further on short viewports (landscape phones, small laptops) */
        @media (max-height: 700px) {
            .compact-logo { width: 3rem !important; height: 3rem !important; }
            .compact-logo-wrap { padding: 0.4rem !important; }
            .compact-title { font-size: 1.1rem !important; }
            .compact-header { margin-bottom: 0.5rem !important; }
            .login-card { padding: 0.85rem !important; }
            .compact-form-gap { gap: 0.5rem !important; }
            .compact-field-label { margin-bottom: 0.25rem !important; }
            .compact-hint { display: none !important; }
            .compact-links { display: none !important; }
            .compact-footer { margin-top: 0.4rem !important; }
        }

        @media (max-height: 560px) {
            .compact-logo-wrap { display: none !important; }
            .compact-footer { display: none !important; }
        }
        
        /* Responsive adjustments */
        @media (max-width: 640px) {
            .login-card {
                padding: 1rem !important;
            }
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 0.85rem !important;
            }
        }

        /* ===== ADDED FOR GOAT BACKGROUND ===== */
        /* Overlay para mugaan ang background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4); /* 40% dark overlay */
            z-index: -1;
        }
    </style>
</head>

<!-- UPDATED BODY TAG WITH GOAT BACKGROUND -->
<body class="login-viewport flex items-center justify-center p-2 sm:p-3 relative overflow-hidden" style="background-image: url('<?php echo BASE_URL; ?>/assets/images/goat-bg.jpg'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;">
    
    <!-- Back to Landing Page Button -->
    <a href="<?php echo BASE_URL; ?>/index.php" class="absolute top-4 left-4 sm:top-6 sm:left-6 flex items-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/40 backdrop-blur-md text-white rounded-full text-sm font-semibold transition-all z-50 border border-white/30 shadow-lg">
        <i class="fas fa-arrow-left"></i> 
        <span class="hidden sm:inline">Back to Home</span>
    </a>

    <!-- Decorative Elements - Enhanced green -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden">
        <!-- Floating elements with more green -->
        <div class="absolute top-10 left-10 text-5xl opacity-30 grass-icon float-animation hidden lg:block" style="color: #86EFAC;"></div>
        <div class="absolute bottom-20 left-20 text-4xl opacity-25 grass-icon float-animation float-animation-delay hidden lg:block" style="color: #4ADE80;"></div>
        <div class="absolute top-1/4 right-10 text-5xl opacity-30 grass-icon float-animation float-animation-delay hidden lg:block" style="color: #86EFAC;"></div>
        <div class="absolute bottom-1/3 right-20 text-4xl opacity-25 grass-icon float-animation hidden lg:block" style="color: #BBF7D0;"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-9xl opacity-[0.04] hidden lg:block" style="color: #DCFCE7;"></div>
        
        <!-- Green gradient overlays -->
        <div class="absolute top-0 left-0 w-64 h-64 bg-white/10 rounded-full -translate-x-1/2 -translate-y-1/2"></div>
        <div class="absolute bottom-0 right-0 w-96 h-96 bg-white/10 rounded-full translate-x-1/2 translate-y-1/2"></div>
    </div>
    
    <!-- Login Container -->
    <div class="w-full max-w-md fade-in relative z-10 login-scroll-safety flex flex-col justify-center">
        
        <!-- Header - Compact -->
        <div class="text-center compact-header mb-3">
            <div class="inline-block relative compact-logo-wrap">
                <div class="absolute inset-0 bg-grass-500/30 rounded-full blur-2xl green-glow"></div>
                <div class="relative bg-gradient-to-br from-white to-green-50 p-2 rounded-2xl shadow-2xl mb-2 pulse-animation border-4 border-green-400/30">
                    <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png"
                         alt="Precision Livestock Farming"
                         class="compact-logo w-14 h-14 sm:w-16 sm:h-16 rounded-xl object-cover"
                         style="display:block;">
                </div>
            </div>
            <h1 class="compact-title text-xl sm:text-2xl font-bold flex items-center justify-center gap-2 text-white drop-shadow-lg">
                <span class="grass-icon text-lg sm:text-xl" style="color: #4ADE80;"></span>
                <span style="color: #FFFFFF;">GOAT PRO</span>
                <span class="grass-icon text-lg sm:text-xl" style="color: #4ADE80;"></span>
            </h1>
        </div>
        
        <!-- Login Card - Compact -->
        <div class="bg-white/95 backdrop-blur-sm rounded-2xl shadow-2xl p-4 sm:p-5 login-card relative overflow-hidden" style="border-color: rgba(34, 197, 94, 0.2);">
            
            <!-- Decorative elements - Green themed -->
            <div class="absolute top-0 right-0 text-8xl opacity-8 transform translate-x-6 -translate-y-6" style="color: #22C55E;">🌿</div>
            <div class="absolute bottom-0 left-0 text-7xl opacity-8 transform -translate-x-6 translate-y-6" style="color: #4ADE80;">🍃</div>
            
            <!-- Green corner accents -->
            <div class="absolute top-0 left-0 w-16 h-16 border-t-4 border-l-4 border-grass-400/20 rounded-tl-2xl"></div>
            <div class="absolute top-0 right-0 w-16 h-16 border-t-4 border-r-4 border-grass-400/20 rounded-tr-2xl"></div>
            <div class="absolute bottom-0 left-0 w-16 h-16 border-b-4 border-l-4 border-grass-400/20 rounded-bl-2xl"></div>
            <div class="absolute bottom-0 right-0 w-16 h-16 border-b-4 border-r-4 border-grass-400/20 rounded-br-2xl"></div>
            
            <!-- Brand watermark -->
            <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-8xl opacity-[0.02] font-bold whitespace-nowrap" style="color: #22C55E;">
                GOAT
            </div>
            
            <!-- Error/Success Messages -->
            <?php if ($error): ?>
                <div class="message-container mb-3 bg-red-50 border-l-4 border-red-500 text-red-700 px-3 py-2 rounded-lg flex items-start gap-2 shadow-sm">
                    <i class="fas fa-exclamation-circle text-red-500 mt-0.5 text-base"></i>
                    <div class="flex-1">
                        <p class="font-semibold text-xs">Login Failed</p>
                        <p class="text-xs"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                    <button onclick="this.parentElement.style.display='none'" class="text-red-400 hover:text-red-600 transition-colors">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="message-container mb-3 bg-green-50 border-l-4 border-grass-500 text-green-700 px-3 py-2 rounded-lg flex items-start gap-2 shadow-sm">
                    <i class="fas fa-check-circle text-grass-500 mt-0.5 text-base"></i>
                    <div class="flex-1">
                        <p class="font-semibold text-xs">Success</p>
                        <p class="text-xs"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                    <button onclick="this.parentElement.style.display='none'" class="text-green-400 hover:text-green-600 transition-colors">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Login Form -->
            <form method="POST" class="space-y-3 compact-form-gap" id="loginForm">
                <!-- Username/Email Field -->
                <div>
                    <label for="email" class="compact-field-label block text-sm font-semibold text-slate-dark mb-1">
                        <i class="fas fa-envelope text-grass-500 mr-1.5"></i>
                        Username or Email
                    </label>
                    <div class="relative group">
                        <input 
                            type="text" 
                            id="email" 
                            name="email" 
                            required 
                            autofocus
                            placeholder="Enter your username or email"
                            class="w-full pl-3 pr-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm input-focus transition-all bg-gray-50 hover:bg-white focus:bg-white"
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            autocomplete="username"
                        >
                    </div>
                    <p class="compact-hint text-xs text-slate-muted mt-1 flex items-center gap-1">
                        <i class="fas fa-info-circle text-grass-400 text-[10px]"></i>
                        Use your username or email address to sign in
                    </p>
                </div>
                
                <!-- Password Field -->
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label for="password" class="compact-field-label block text-sm font-semibold text-slate-dark">
                            <i class="fas fa-lock text-grass-500 mr-1.5"></i>
                            Password
                        </label>
                    </div>
                    <div class="relative group">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            placeholder="Enter your password"
                            class="w-full pl-3 pr-12 py-2.5 border-2 border-gray-200 rounded-lg text-sm input-focus transition-all bg-gray-50 hover:bg-white focus:bg-white"
                            autocomplete="current-password"
                        >
                        <button 
                            type="button" 
                            id="togglePassword"
                            class="absolute inset-y-0 right-0 pr-3 flex items-center password-toggle text-gray-400 hover:text-grass-600 transition-colors"
                            aria-label="Toggle password visibility"
                        >
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                </div>
                
                <!-- Remember Me & Options -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <label class="flex items-center gap-2 cursor-pointer group">
                        <input type="checkbox" name="remember_me" id="remember_me" class="checkbox-custom">
                        <span class="text-xs text-slate-light group-hover:text-slate-dark transition-colors">
                            Remember me
                        </span>
                        <div class="tooltip">
                            <i class="fas fa-question-circle text-xs text-gray-400 hover:text-grass-500 transition-colors"></i>
                            <span class="tooltip-text">Stay logged in for 30 days</span>
                        </div>
                    </label>
                    
                    <div class="flex items-center gap-3 text-xs text-slate-muted">
                        <span class="flex items-center gap-1">
                        <a href="#" class="text-xs link-green">
                            Forgot password?
                        </a>
                        </span>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <button 
                    type="submit" 
                    id="submitBtn"
                    class="btn-submit w-full text-white font-semibold py-2.5 px-4 rounded-lg transition-all flex items-center justify-center gap-2 text-sm relative"
                >
                    <span id="btnText">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In
                    </span>
                    <div class="spinner" id="spinner"></div>
                </button>
                
                              <!-- Quick Links -->
                <div class="compact-links mt-2 pt-2 border-t border-gray-200">
                    <div class="text-center">
                        <p class="text-xs text-slate-muted flex items-center justify-center gap-3 flex-wrap">
                            <span class="hidden sm:inline w-px h-3 bg-gray-300"></span>
                            <a href="<?php echo BASE_URL; ?>/privacy.php" class="link-green">Privacy Policy</a>
                            <span class="hidden sm:inline w-px h-3 bg-gray-300"></span>
                            <a href="<?php echo BASE_URL; ?>/terms.php" class="link-green">Terms of Service</a>
                        </p>
                    </div>
                </div>
        
        
    <!-- JavaScript for interactive features -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle visibility
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
            }
            
            // Form submission with loading state
            const loginForm = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('spinner');
            
            if (loginForm) {
                loginForm.addEventListener('submit', function(e) {
                    // Show loading state
                    btnText.textContent = 'Signing in...';
                    spinner.style.display = 'inline-block';
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.8';
                    
                    // Re-enable after 10 seconds (safety)
                    setTimeout(function() {
                        btnText.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In';
                        spinner.style.display = 'none';
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                    }, 10000);
                });
            }
            
            // Input field animations
            const inputs = document.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('ring-2', 'ring-grass-400/30', 'rounded-lg');
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('ring-2', 'ring-grass-400/30', 'rounded-lg');
                });
            });
            
            // Auto-dismiss error messages after 8 seconds
            const errorMessages = document.querySelectorAll('.message-container');
            errorMessages.forEach(msg => {
                setTimeout(() => {
                    msg.style.opacity = '0';
                    msg.style.transform = 'translateY(-10px)';
                    msg.style.transition = 'all 0.5s ease';
                    setTimeout(() => {
                        msg.style.display = 'none';
                    }, 500);
                }, 8000);
            });
            
            // Remember me checkbox enhancement
            const rememberCheckbox = document.getElementById('remember_me');
            if (rememberCheckbox) {
                rememberCheckbox.addEventListener('change', function() {
                    const label = this.nextElementSibling;
                    if (this.checked) {
                        label.innerHTML = 'Remember me <span class="text-grass-500">✓</span>';
                    } else {
                        label.innerHTML = 'Remember me';
                    }
                });
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + Shift + L to focus on login
                if (e.ctrlKey && e.shiftKey && e.key === 'L') {
                    e.preventDefault();
                    const emailInput = document.getElementById('email');
                    if (emailInput) {
                        emailInput.focus();
                        emailInput.select();
                    }
                }
            });
            
            // Click on card to focus email (user-friendly)
            const loginCard = document.querySelector('.login-card');
            if (loginCard) {
                loginCard.addEventListener('click', function(e) {
                    if (e.target === this || this.contains(e.target) && !e.target.closest('input') && !e.target.closest('button')) {
                        const emailInput = document.getElementById('email');
                        if (emailInput && document.activeElement !== emailInput) {
                            emailInput.focus();
                        }
                    }
                });
            }
        });
        
        // Smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
    
    <!-- Mobile detection -->
    <script>
        if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
            document.body.classList.add('touch-device');
            const style = document.createElement('style');
            style.textContent = `
                input, button, a, label {
                    min-height: 40px;
                }
                .checkbox-custom {
                    width: 20px;
                    height: 20px;
                }
            `;
            document.head.appendChild(style);
        }
    </script>
</body>
</html>

               

<?php renderFooter(); ?>