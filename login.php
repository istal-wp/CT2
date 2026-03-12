<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('SUPABASE_URL',      'https://kyhndwabhbkduoaxeunq.supabase.co');
define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imt5aG5kd2FiaGJrZHVvYXhldW5xIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzA2MzA1OTIsImV4cCI6MjA4NjIwNjU5Mn0.znUK7XAns21A6Pswb-tsMW4FO0q4ACt7B6eZrTvNTnA');

function supabase_get(string $table, array $params = []): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . SUPABASE_ANON_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }
    error_log("Supabase GET Error ({$table}): HTTP {$httpCode} - {$resp}");
    return [];
}

function verify_admin_password(string $email, string $password): ?array {
    // Step 1: Sign in via Supabase Auth
    $url = SUPABASE_URL . '/auth/v1/token?grant_type=password';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['email' => strtolower($email), 'password' => $password]),
        CURLOPT_HTTPHEADER     => [
            'apikey: '           . SUPABASE_ANON_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);


    if ($httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $auth         = json_decode($resp, true);
    $auth_user_id = $auth['user']['id']   ?? null;
    $access_token = $auth['access_token'] ?? null;


    if (!$auth_user_id || !$access_token) return null;

    // Step 2: Fetch admin_accounts — id IS the Auth UUID
    $url2 = SUPABASE_URL . '/rest/v1/admin_accounts?id=eq.' . urlencode($auth_user_id)
          . '&select=id,email,display_name,role';
    $ch2  = curl_init($url2);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: '               . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
    ]);
    $resp2     = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);


    if ($httpCode2 >= 200 && $httpCode2 < 300) {
        $rows = json_decode($resp2, true);
        if (!empty($rows) && isset($rows[0]['id'])) {
            // Only allow role=admin
            if (($rows[0]['role'] ?? '') === 'admin') {
                return $rows[0];
            }
                }
    }
    return null;
}

function redirectToDashboard(string $role): void {
    switch (strtolower($role)) {
        case 'admin':     header('Location: ../modules/dashboard.php');   break;
        case 'passenger': header('Location: ../passenger/dashboard.php'); break;
        default:          header('Location: ../modules/dashboard.php');
    }
    exit;
}

if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    redirectToDashboard($_SESSION['role'] ?? 'admin');
}

$error_message    = '';
$success_message  = '';
$registered_phone = '';

if (!empty($_SESSION['registration_success'])) {
    $success_message  = 'Registration successful! Please login with your credentials.';
    $registered_phone = $_SESSION['registered_phone'] ?? '';
    unset($_SESSION['registration_success'], $_SESSION['registered_phone']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both email/phone and password.';
    } else {
        $is_email          = (bool) filter_var($username, FILTER_VALIDATE_EMAIL);
        $login_as_passenger = false;

        if ($is_email) {
            // Always try Supabase Auth first for email logins.
            // verify_admin_password() signs in via Auth then checks admin_accounts FK.
            $admin = verify_admin_password($username, $password);
            if ($admin) {
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id']   = $admin['id'];
                $_SESSION['username']  = $admin['email'];
                $_SESSION['full_name'] = $admin['display_name'];
                $_SESSION['email']     = $admin['email'];
                $_SESSION['role']      = 'admin';
                redirectToDashboard('admin');
            }
            // Auth succeeded but user is NOT in admin_accounts — try passenger login below.
            // Auth failed entirely — also try passenger login (passenger passwords are hashed in DB).
            $login_as_passenger = true;
        } else {
            $login_as_passenger = true;
        }

        if ($login_as_passenger && empty($error_message)) {
            $passenger = null;

            if ($is_email) {
                $rows = supabase_get('core2_customers', [
                    'email'         => 'eq.' . strtolower($username),
                    'customer_type' => 'eq.Passenger',
                    'status'        => 'eq.Active',
                    'select'        => 'customer_id,name,email,phone,password,status',
                ]);
                $passenger = $rows[0] ?? null;
            } else {
                $rows = supabase_get('core2_customers', [
                    'phone'         => 'eq.' . $username,
                    'customer_type' => 'eq.Passenger',
                    'status'        => 'eq.Active',
                    'select'        => 'customer_id,name,email,phone,password,status',
                ]);
                $passenger = $rows[0] ?? null;
            }

            if ($passenger) {
                $stored_hash = $passenger['password'] ?? '';
                $valid = $stored_hash && (
                    password_verify($password, $stored_hash) ||
                    $stored_hash === $password
                );

                if ($valid) {
                    $_SESSION['logged_in']   = true;
                    $_SESSION['customer_id'] = $passenger['customer_id'];
                    $_SESSION['user_id']     = $passenger['customer_id'];
                    $_SESSION['username']    = $passenger['email'] ?? $passenger['phone'];
                    $_SESSION['name']        = $passenger['name'];
                    $_SESSION['full_name']   = $passenger['name'];
                    $_SESSION['email']       = $passenger['email'];
                    $_SESSION['phone']       = $passenger['phone'];
                    $_SESSION['role']        = 'passenger';
                    redirectToDashboard('passenger');
                } else {
                    $error_message = 'Invalid email/phone or password.';
                }
            } else {
                $error_message = 'No active passenger account found with those credentials.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>EnviroCab Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --primary-green: #00D084;
            --dark-green: #00A86B;
            --bg-gradient: linear-gradient(135deg, #E8F5F1 0%, #F0F9FF 100%);
            --text-dark: #1A202C;
            --text-medium: #4A5568;
            --text-light: #718096;
            --border: #E2E8F0;
            --error-red: #EF4444;
            --success-green: #10B981;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-gradient);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        .login-container { width: 100%; max-width: 420px; animation: slideUp 0.5s ease; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .logo-section { text-align: center; margin-bottom: 40px; }
        .logo-img { height: 48px; width: auto; margin-bottom: 16px; }
        .logo-text {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-card {
            background: white;
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
        }

        .login-title { font-size: 24px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .login-subtitle { font-size: 14px; color: var(--text-light); margin-bottom: 32px; }
        .form-group { margin-bottom: 20px; }

        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }

        .form-input {
            width: 100%; height: 52px; padding: 0 16px; font-size: 15px;
            border: 2px solid var(--border); border-radius: 14px;
            background: white; color: var(--text-dark);
            transition: all 0.2s; font-family: 'Inter', sans-serif;
        }

        .form-input:focus {
            outline: none; border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(0,208,132,0.1);
        }

        .form-input::placeholder { color: var(--text-light); }

        .alert {
            padding: 14px 16px; border-radius: 12px; margin-bottom: 20px;
            font-size: 14px; font-weight: 500; display: flex;
            align-items: center; gap: 10px; animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .alert-error  { background: rgba(239,68,68,0.1);  color: var(--error-red);   border: 1px solid rgba(239,68,68,0.2); }
        .alert-success{ background: rgba(16,185,129,0.1); color: var(--success-green);border: 1px solid rgba(16,185,129,0.2); }

        .login-btn {
            width: 100%; height: 56px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white; font-size: 16px; font-weight: 700;
            border: none; border-radius: 14px; cursor: pointer;
            transition: all 0.3s; box-shadow: 0 4px 16px rgba(0,208,132,0.3);
            margin-top: 8px;
        }

        .login-btn:hover   { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,208,132,0.4); }
        .login-btn:active  { transform: translateY(0); }
        .login-btn:disabled{ opacity: 0.6; cursor: not-allowed; transform: none; }

        .remember-section { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }

        .remember-checkbox { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .remember-checkbox input { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary-green); }
        .remember-checkbox label { font-size: 13px; color: var(--text-medium); cursor: pointer; }

        .forgot-link { font-size: 13px; color: var(--primary-green); text-decoration: none; font-weight: 600; }

        .login-footer { text-align: center; margin-top: 24px; font-size: 13px; color: var(--text-light); }

        .signup-link { color: var(--primary-green); text-decoration: none; font-weight: 600; transition: all 0.2s; }
        .signup-link:hover { color: var(--dark-green); text-decoration: underline; }

        .features {
            display: grid; grid-template-columns: repeat(3,1fr);
            gap: 12px; margin-top: 32px; padding-top: 32px;
            border-top: 1px solid var(--border);
        }

        .feature-item { text-align: center; }

        .feature-icon {
            width: 44px; height: 44px; margin: 0 auto 8px;
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
        }

        .feature-icon.green  { background: rgba(0,208,132,0.15); }
        .feature-icon.blue   { background: rgba(96,165,250,0.15); }
        .feature-icon.purple { background: rgba(192,132,252,0.15); }

        .feature-label { font-size: 11px; color: var(--text-light); font-weight: 500; }

        @media (max-width: 480px) { .login-card { padding: 32px 24px; } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <img src="../images/envirocab_logo.png" alt="EnviroCab" class="logo-img" onerror="this.style.display='none'">
        </div>

        <div class="login-card">
            <div class="login-title">Welcome Back!</div>
            <div class="login-subtitle">Sign in to access your dashboard</div>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="form-group">
                    <label class="form-label" for="username">Email / Phone</label>
                    <input
                        type="text" id="username" name="username" class="form-input"
                        placeholder="Enter your email or phone number"
                        value="<?php echo htmlspecialchars($registered_phone ?: ($_POST['username'] ?? '')); ?>"
                        required autofocus autocomplete="username"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input
                        type="password" id="password" name="password" class="form-input"
                        placeholder="Enter your password"
                        required autocomplete="current-password"
                    >
                </div>

                <div class="remember-section">
                    <div class="remember-checkbox">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>
                    <a href="#" class="forgot-link" onclick="alert('Please contact administrator'); return false;">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">Sign In</button>
            </form>

            <div class="features">
                <div class="feature-item">
                    <div class="feature-icon green">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#00D084" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                    <div class="feature-label">Secure Login</div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon blue">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#60A5FA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
                            <line x1="12" y1="18" x2="12.01" y2="18"></line>
                        </svg>
                    </div>
                    <div class="feature-label">Mobile Ready</div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon purple">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#C084FC" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div class="feature-label">24/7 Access</div>
                </div>
            </div>
        </div>

        <div class="login-footer">
            Don't have an account? <a href="register_passenger.php" class="signup-link">Sign up as Passenger</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const f = document.getElementById('username');
            if (f && !f.value) f.focus();
        });

        document.querySelector('form').addEventListener('submit', function (e) {
            const u = document.getElementById('username').value.trim();
            const p = document.getElementById('password').value;
            if (!u || !p) { e.preventDefault(); alert('Please fill in all fields'); return; }
            const btn = document.getElementById('loginBtn');
            btn.disabled    = true;
            btn.textContent = 'Signing In…';
        });
    </script>
</body>
</html>