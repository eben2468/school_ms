<?php
session_start();
if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
require_once __DIR__ . '/includes/csrf.php';

// Clear dynamic DB context if we are on the landing login page and not authenticated
unset($_SESSION['school_db_name']);
unset($_SESSION['school_id']);
unset($_SESSION['school_name']);

// Include settings helper for dynamic theming
require_once 'includes/settings_helper.php';
$school_name = getSchoolSetting('school_name', 'School Management System');
$theme_gradient = getThemeGradient();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/dynamic-theme.php" rel="stylesheet">
    <link href="assets/css/responsive.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        /* 40% size decrease: 16px default * 0.60 = ~10px */
        html {
            font-size: 13px !important;
        }

        .login-gradient {
            background: <?php echo $theme_gradient; ?>;
        }

        .login-card {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .theme-button {
            background: <?php echo $theme_gradient; ?>;
        }

        .theme-focus:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        .shape:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .shape:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 60%;
            right: 10%;
            animation-delay: 2s;
        }

        .shape:nth-child(3) {
            width: 60px;
            height: 60px;
            bottom: 20%;
            left: 20%;
            animation-delay: 4s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
    </style>
</head>
<body class="login-gradient min-h-screen flex items-center justify-center relative">
    <!-- Floating Background Shapes -->
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <div class="login-card p-8 rounded-2xl shadow-2xl w-full max-w-md relative z-10">
        <div class="text-center mb-8">
            <?php 
            $logo_url = getSchoolLogo();
            $is_default = (strpos($logo_url, 'logo.svg') !== false);
            ?>
            <div class="w-20 h-20 mx-auto mb-4 rounded-2xl <?php echo $is_default ? 'theme-button' : 'bg-white'; ?> flex items-center justify-center shadow-lg overflow-hidden">
                <?php if ($logo_url): ?>
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="w-full h-full object-contain <?php echo $is_default ? 'p-2' : 'p-1'; ?>">
                <?php else: ?>
                    <i class="fas fa-graduation-cap text-3xl text-white"></i>
                <?php endif; ?>
            </div>
            <h1 class="text-3xl font-bold text-gray-800 mb-2"><?php echo htmlspecialchars($school_name); ?></h1>
            <p class="text-gray-600">Welcome back! Please sign in to your account</p>
        </div>

        <?php if(isset($_GET['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
        <?php endif; ?>

        <?php if(isset($_GET['timeout'])): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-clock mr-1"></i> You were signed out due to inactivity. Please log in again.
        </div>
        <?php endif; ?>

        <form action="auth/login.php" method="POST" class="space-y-6">
            <?php echo csrf_field(); ?>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-user mr-2 text-gray-500"></i>Email or ID
                </label>
                <input type="text" id="email" name="email" required autocomplete="username"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500 transition-colors duration-200"
                    placeholder="Email, Student ID or Staff ID">
                <p class="mt-1 text-xs text-gray-500">Students and staff can sign in with their ID instead of email.</p>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-lock mr-2 text-gray-500"></i>Password
                </label>
                <div class="relative">
                    <input type="password" id="password" name="password" required
                        class="w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500 transition-colors duration-200"
                        placeholder="Enter your password">
                    <button type="button" id="togglePassword" aria-label="Show password" aria-pressed="false"
                        class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-400 hover:text-gray-600 focus:outline-none transition-colors duration-200">
                        <i class="fas fa-eye" id="togglePasswordIcon"></i>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input type="checkbox" id="remember" name="remember"
                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <label for="remember" class="ml-2 block text-sm text-gray-700">
                        <i class="fas fa-clock mr-1 text-gray-400"></i>Remember me
                    </label>
                </div>

                <a href="forgot-password.php" class="text-sm text-blue-600 hover:text-blue-800 transition-colors duration-200">
                    <i class="fas fa-question-circle mr-1"></i>Forgot password?
                </a>
            </div>

            <button type="submit"
                class="w-full flex justify-center items-center py-3 px-4 border border-transparent rounded-lg shadow-lg text-sm font-medium text-white theme-button hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 transform hover:scale-105">
                <i class="fas fa-sign-in-alt mr-2"></i>Sign in to Dashboard
            </button>
        </form>
    </div>

    <script>
        (function () {
            const toggle = document.getElementById('togglePassword');
            const input = document.getElementById('password');
            const icon = document.getElementById('togglePasswordIcon');
            if (toggle && input && icon) {
                toggle.addEventListener('click', function () {
                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    icon.classList.toggle('fa-eye', !show);
                    icon.classList.toggle('fa-eye-slash', show);
                    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    input.focus();
                });
            }
        })();
    </script>
</body>
</html>