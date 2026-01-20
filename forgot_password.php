<?php
session_start();
require_once 'db_config.php';
require_once 'functions.php';

$message = '';
$messageType = '';
$emailSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';
    } else {
        $token = createPasswordReset($conn, $email);
        
        // Always show success message to prevent email enumeration
        $emailSent = true;
        $message = 'If an account with that email exists, a password reset link has been sent.';
        $messageType = 'success';
        
        if ($token) {
            // In production, send actual email. For local development, show the link.
            $resetLink = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            $emailBody = "
            <html>
            <head><title>Password Reset</title></head>
            <body>
                <h2>Password Reset Request</h2>
                <p>You have requested to reset your password. Click the link below to proceed:</p>
                <p><a href='$resetLink'>Reset Password</a></p>
                <p>Or copy and paste this URL into your browser:</p>
                <p>$resetLink</p>
                <p>This link will expire in 1 hour.</p>
                <p>If you did not request this, please ignore this email.</p>
            </body>
            </html>
            ";
            
            // Try to send email
            $emailResult = sendEmail($email, 'Password Reset Request', $emailBody);
            
            // For local development, display the link (remove in production)
            if (!$emailResult) {
                $message .= '<br><br><strong>Development Mode:</strong> Email not configured. <a href="' . htmlspecialchars($resetLink) . '">Click here to reset password</a>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - File Manager</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .forgot-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message a {
            color: #667eea;
            font-weight: 500;
        }
        .links {
            text-align: center;
            margin-top: 25px;
        }
        .links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .links a:hover {
            text-decoration: underline;
        }
        .icon {
            text-align: center;
            font-size: 60px;
            margin-bottom: 20px;
        }
        .success-icon {
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <?php if ($emailSent): ?>
            <div class="icon success-icon">✓</div>
            <h1>Check Your Email</h1>
            <div class="message success"><?php echo $message; ?></div>
        <?php else: ?>
            <div class="icon">🔐</div>
            <h1>Forgot Password?</h1>
            <p class="subtitle">Enter your email address and we'll send you a link to reset your password.</p>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email">
                </div>
                
                <button type="submit" class="btn">Send Reset Link</button>
            </form>
        <?php endif; ?>
        
        <div class="links">
            <a href="login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>
