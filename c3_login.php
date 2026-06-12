<?php
// c3_login.php - Login Page
session_start();
require_once __DIR__ . '/assets/config/db_config.php';
require_once __DIR__ . '/assets/auth/auth.php';

$auth = new Auth();
$error = '';

if ($auth->isLoggedIn()) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin' || $role === 'admin') {
        header('Location: c3_admin_dashboard.php');
    } else {
        header('Location: c3_dashboard.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($username, $password)) {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'super_admin' || $role === 'admin') {
            header('Location: c3_admin_dashboard.php');
        } else {
            header('Location: c3_dashboard.php');
        }
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Session Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .login-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
        }
        .login-icon {
            font-size: 60px;
            color: #667eea;
            margin-bottom: 20px;
        }
        .login-title {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        .login-subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            margin-bottom: 15px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: none;
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px;
            border-radius: 10px;
            border: none;
            width: 100%;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        .error-message {
            background: #fee2e2;
            color: #ef4444;
            padding: 10px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .demo-credentials {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="login-title">Session Manager</div>
            <div class="login-subtitle">Login to access dashboard</div>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="text" name="username" class="form-control" placeholder="Username" required autofocus>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
                <button type="submit" class="btn-login">
                    <i class="fas fa-unlock-alt"></i> Login
                </button>
            </form>
            
            <div class="demo-credentials">
                <strong>Demo Credentials:</strong><br>
                Admin: super_admin / admin123<br>
                Domain Admin: domain_admin_10058322 / admin123
            </div>
        </div>
    </div>
</body>
</html>