<?php
// login.php: Enhanced Login Script

session_start();
require_once 'db_config.php';
require_once 'functions.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic validation
    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        // Check user credentials from database
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check password - support both hashed and plain text (for legacy admin)
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                
                // Log the login activity
                logActivity($conn, $user['id'], 'login', null, null, 'Successful login');
                
                // Check for redirect parameter
                $redirect = $_GET['redirect'] ?? 'dashboard.php';
                header("Location: " . $redirect);
                exit();
            } else {
                $error = "Invalid username or password. Please try again.";
            }
        } else {
            $error = "Invalid username or password. Please try again.";
        }
        $stmt->close();
    }
}

// Check for logout message
if (isset($_GET['message']) && $_GET['message'] === 'logged_out') {
    $success = "You have been successfully logged out.";
}

// Check for registration success message
if (isset($_GET['registered']) && $_GET['registered'] === 'success') {
    $success = "Registration successful! Please login with your new account.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - File Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 30px 60px rgba(0,0,0,0.12);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            position: relative;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 50%, #667eea 100%);
        }

        .login-header {
            padding: 40px 40px 20px;
            text-align: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .logo {
            font-size: 4em;
            margin-bottom: 20px;
        }

        .login-header h1 {
            color: #495057;
            font-size: 2em;
            margin-bottom: 10px;
            font-weight: 300;
        }

        .login-header p {
            color: #6c757d;
            font-size: 14px;
        }

        .login-form {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-label {
            display: block;
            color: #495057;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 15px 50px 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.15);
        }

        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 18px;
            pointer-events: none;
        }

        .form-group.has-label .input-icon {
            top: calc(50% + 12px);
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
            font-size: 14px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .demo-credentials {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            font-size: 14px;
        }

        .demo-credentials h4 {
            color: #1976d2;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .demo-credentials p {
            color: #424242;
            margin-bottom: 8px;
        }

        .demo-credentials strong {
            color: #1976d2;
        }

        .footer-text {
            text-align: center;
            color: #6c757d;
            font-size: 12px;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }
            
            .login-header,
            .login-form {
                padding: 30px 25px;
            }
            
            .logo {
                font-size: 3em;
            }
            
            .login-header h1 {
                font-size: 1.8em;
            }
        }

        /* Form validation styles */
        .form-input.error {
            border-color: #dc3545;
            background: #fff5f5;
        }

        .form-input.success {
            border-color: #28a745;
            background: #f0fff4;
        }

        /* Floating label effect */
        .form-group.floating {
            position: relative;
        }

        .floating .form-label {
            position: absolute;
            left: 20px;
            top: 15px;
            background: white;
            padding: 0 5px;
            color: #6c757d;
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .floating .form-input:focus + .form-label,
        .floating .form-input:not(:placeholder-shown) + .form-label {
            top: -8px;
            font-size: 12px;
            color: #667eea;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .register-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🔐</div>
            <h1>Welcome Back</h1>
            <p>Sign in to your File Management System</p>
        </div>

        <div class="login-form">
            <!-- Messages -->
            <?php if ($error): ?>
                <div class="message error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="message success">
                    ✅ <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" id="loginForm" novalidate>
                <div class="form-group has-label">
                    <label for="username" class="form-label">👤 Username</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-input" 
                        placeholder="Enter your username"
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                        required
                        autocomplete="username"
                    >
                    <span class="input-icon">👤</span>
                </div>

                <div class="form-group has-label">
                    <label for="password" class="form-label">🔑 Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input" 
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                    <span class="input-icon" id="togglePassword" style="cursor: pointer;">🔑</span>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    🚀 Sign In
                </button>

                <div style="text-align: center; margin-top: 15px;">
                    <a href="forgot_password.php" style="color: #667eea; text-decoration: none; font-size: 14px;">Forgot your password?</a>
                </div>

                <div class="register-link">
                    Don't have an account? <a href="register.php">Create one</a>
                </div>
            </form>

            <!-- Loading State -->
            <div class="loading" id="loadingState">
                <div class="spinner"></div>
                <p>Signing you in...</p>
            </div>
        </div>

        <div class="footer-text">
            <p>🔒 Secure File Management System</p>
            <p>© <?php echo date('Y'); ?> - Built with PHP & MySQL</p>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const loadingState = document.getElementById('loadingState');
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const usernameInput = document.getElementById('username');

        // Password toggle functionality
        togglePassword.addEventListener('click', function() {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                togglePassword.textContent = '👁️';
            } else {
                passwordInput.type = 'password';
                togglePassword.textContent = '🔑';
            }
        });

        // Form validation
        function validateForm() {
            let isValid = true;
            
            // Username validation
            if (usernameInput.value.trim() === '') {
                usernameInput.classList.add('error');
                usernameInput.classList.remove('success');
                isValid = false;
            } else {
                usernameInput.classList.add('success');
                usernameInput.classList.remove('error');
            }
            
            // Password validation
            if (passwordInput.value === '') {
                passwordInput.classList.add('error');
                passwordInput.classList.remove('success');
                isValid = false;
            } else {
                passwordInput.classList.add('success');
                passwordInput.classList.remove('error');
            }
            
            return isValid;
        }

        // Real-time validation
        usernameInput.addEventListener('blur', validateForm);
        passwordInput.addEventListener('blur', validateForm);

        // Form submission
        loginForm.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return;
            }
            
            // Show loading state
            loginForm.style.display = 'none';
            loadingState.style.display = 'block';
            
            // Let form submit naturally after showing loading
        });

        // Auto-fill demo credentials (for demonstration)
        document.addEventListener('DOMContentLoaded', function() {
            // Add subtle animation to form elements
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                setTimeout(() => {
                    group.style.opacity = '0';
                    group.style.transform = 'translateY(20px)';
                    group.style.transition = 'all 0.5s ease';
                    
                    setTimeout(() => {
                        group.style.opacity = '1';
                        group.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 100);
            });
        });

        // Quick fill demo credentials button (for admin testing only)
        function fillDemoCredentials() {
            usernameInput.value = 'admin';
            passwordInput.value = 'password';
            usernameInput.classList.add('success');
            passwordInput.classList.add('success');
        }
    </script>
</body>
</html>