<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && $_SESSION['role'] === 'passenger') {
    header('Location: ../passenger/dashboard.php');
    exit;
}

define('SUPABASE_URL', 'https://kyhndwabhbkduoaxeunq.supabase.co');
define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imt5aG5kd2FiaGJrZHVvYXhldW5xIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzA2MzA1OTIsImV4cCI6MjA4NjIwNjU5Mn0.znUK7XAns21A6Pswb-tsMW4FO0q4ACt7B6eZrTvNTnA');

function supabase_insert($table, $data) {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . SUPABASE_ANON_KEY,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) return json_decode($response, true);
    error_log("Supabase INSERT Error ({$table}) HTTP {$httpCode}: " . $response);
    return false;
}

function supabase_get($table, $params = []) {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) return json_decode($response, true) ?: [];
    return [];
}

$error_message   = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name']        ?? '');
    $email            = trim(strtolower($_POST['email'] ?? ''));
    $phone            = trim($_POST['phone']            ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';

    if (empty($full_name) || empty($email) || empty($phone) || empty($password) || empty($confirm_password)) {
        $error_message = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
        $error_message = 'Please enter a valid phone number.';
    } elseif (strlen($password) < 6) {
        $error_message = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } else {
        $existing_email = supabase_get('core2_customers', ['email' => 'eq.' . $email, 'select' => 'customer_id']);
        if (!empty($existing_email)) {
            $error_message = 'This email address is already registered.';
        } else {
            $existing_phone = supabase_get('core2_customers', ['phone' => 'eq.' . $phone, 'select' => 'customer_id']);
            if (!empty($existing_phone)) {
                $error_message = 'This phone number is already registered.';
            } else {
                $customer_code = 'PASS-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $result = supabase_insert('core2_customers', [
                    'customer_code' => $customer_code,
                    'name'          => $full_name,
                    'email'         => $email,
                    'phone'         => $phone,
                    'password'      => password_hash($password, PASSWORD_DEFAULT),
                    'customer_type' => 'Passenger',
                    'status'        => 'Active',
                ]);

                if ($result && isset($result[0]['customer_id'])) {
                    $customer_id = $result[0]['customer_id'];
                    $_SESSION['logged_in']   = true;
                    $_SESSION['customer_id'] = $customer_id;
                    $_SESSION['name']        = $full_name;
                    $_SESSION['email']       = $email;
                    $_SESSION['phone']       = $phone;
                    $_SESSION['role']        = 'passenger';
                    header('Location: ../passenger/dashboard.php');
                    exit;
                } else {
                    $sb_err = '';
                    // Re-run insert to capture actual error
                    $url = SUPABASE_URL . '/rest/v1/core2_customers';
                    $ch  = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode([
                            'customer_code' => $customer_code,
                            'name'          => $full_name,
                            'email'         => $email,
                            'phone'         => $phone,
                            'password'      => password_hash($password, PASSWORD_DEFAULT),
                            'customer_type' => 'Passenger',
                            'status'        => 'Active',
                        ]),
                        CURLOPT_HTTPHEADER => [
                            'apikey: '               . SUPABASE_ANON_KEY,
                            'Authorization: Bearer ' . SUPABASE_ANON_KEY,
                            'Content-Type: application/json',
                            'Prefer: return=representation',
                        ],
                    ]);
                    $sb_resp = curl_exec($ch);
                    $sb_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $decoded = json_decode($sb_resp, true);
                    $sb_err  = $decoded['message'] ?? $decoded['hint'] ?? $decoded['details'] ?? $sb_resp;
                    $error_message = 'Registration failed: ' . htmlspecialchars($sb_err);
                    error_log("Customer insert failed HTTP {$sb_code}: {$sb_resp}");
                }
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
    <title>EnviroCab – Passenger Registration</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        .register-container {
            width: 100%;
            max-width: 420px;
            animation: slideUp 0.5s ease;
        }

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

        .register-card {
            background: white;
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border);
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 208, 132, 0.12);
            color: var(--dark-green);
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            margin-bottom: 18px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .register-title { font-size: 24px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .register-subtitle { font-size: 14px; color: var(--text-light); margin-bottom: 28px; }

        .form-group { margin-bottom: 20px; }

        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }

        .form-input {
            width: 100%;
            height: 52px;
            padding: 0 16px;
            font-size: 15px;
            border: 2px solid var(--border);
            border-radius: 14px;
            background: white;
            color: var(--text-dark);
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(0, 208, 132, 0.1);
        }

        .form-input::placeholder { color: var(--text-light); }

        .form-input.input-error {
            border-color: var(--error-red);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .input-hint {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 6px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error   { background: rgba(239,68,68,0.1);   color: var(--error-red);    border: 1px solid rgba(239,68,68,0.2); }
        .alert-success { background: rgba(16,185,129,0.1);  color: var(--success-green); border: 1px solid rgba(16,185,129,0.2); }

        
        .strength-bar-wrap {
            height: 4px;
            background: var(--border);
            border-radius: 4px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.3s, background 0.3s;
        }

        .strength-label {
            font-size: 11px;
            margin-top: 4px;
            font-weight: 600;
        }

        .register-btn {
            width: 100%;
            height: 56px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            font-size: 16px;
            font-weight: 700;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 16px rgba(0, 208, 132, 0.3);
            margin-top: 8px;
            font-family: 'Inter', sans-serif;
        }

        .register-btn:hover   { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,208,132,0.4); }
        .register-btn:active  { transform: translateY(0); }
        .register-btn:disabled{ opacity: 0.6; cursor: not-allowed; }

        .register-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 13px;
            color: var(--text-light);
        }

        .link { 
            color: var(--primary-green); 
            text-decoration: none; 
            font-weight: 600;
            transition: all 0.2s;
        }

        .link:hover {
            color: var(--dark-green);
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .register-card { padding: 32px 24px; }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo-section">
            <img src="../images/envirocab_logo.png" alt="EnviroCab" class="logo-img" onerror="this.style.display='none'">
        </div>

        <div class="register-card">
            <div class="role-badge">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                New Passenger
            </div>
            <div class="register-title">Create Account</div>
            <div class="register-subtitle">Register to start booking your rides</div>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register_passenger.php" id="register-form">

                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        class="form-input"
                        placeholder="Enter your full name"
                        value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        placeholder="Enter your email address"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        class="form-input"
                        placeholder="e.g. 09171234567"
                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                        required
                    >
                    <div class="input-hint">You can log in with email or phone number.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Create a password (min. 6 characters)"
                        required
                    >
                    <div class="strength-bar-wrap">
                        <div class="strength-bar" id="strength-bar"></div>
                    </div>
                    <div class="strength-label" id="strength-label" style="color: var(--text-light);"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        class="form-input"
                        placeholder="Re-enter your password"
                        required
                    >
                    <div class="input-hint" id="match-hint"></div>
                </div>

                <button type="submit" class="register-btn" id="submit-btn">Create Account</button>
            </form>
        </div>

        <div class="register-footer">
            Already have an account? <a href="login.php" class="link">Sign in</a>
        </div>
    </div>

    <script>

        const passwordInput  = document.getElementById('password');
        const strengthBar    = document.getElementById('strength-bar');
        const strengthLabel  = document.getElementById('strength-label');

        passwordInput.addEventListener('input', function () {
            const val = this.value;
            let score = 0;
            if (val.length >= 6)  score++;
            if (val.length >= 10) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const levels = [
                { width: '0%',   color: '#E2E8F0', label: '',          labelColor: '#718096' },
                { width: '25%',  color: '#EF4444', label: 'Weak',       labelColor: '#EF4444' },
                { width: '50%',  color: '#F59E0B', label: 'Fair',       labelColor: '#F59E0B' },
                { width: '75%',  color: '#3B82F6', label: 'Good',       labelColor: '#3B82F6' },
                { width: '90%',  color: '#10B981', label: 'Strong',     labelColor: '#10B981' },
                { width: '100%', color: '#00D084', label: 'Very Strong', labelColor: '#00D084' },
            ];

            const level = val.length === 0 ? 0 : Math.min(score, 5);
            strengthBar.style.width      = levels[level].width;
            strengthBar.style.background = levels[level].color;
            strengthLabel.textContent    = levels[level].label;
            strengthLabel.style.color    = levels[level].labelColor;
        });

        const confirmInput = document.getElementById('confirm_password');
        const matchHint    = document.getElementById('match-hint');

        confirmInput.addEventListener('input', function () {
            if (this.value === '') {
                matchHint.textContent = '';
                this.classList.remove('input-error');
                return;
            }
            if (this.value === passwordInput.value) {
                matchHint.textContent  = '✓ Passwords match';
                matchHint.style.color  = '#10B981';
                this.classList.remove('input-error');
            } else {
                matchHint.textContent  = '✗ Passwords do not match';
                matchHint.style.color  = '#EF4444';
                this.classList.add('input-error');
            }
        });

        document.getElementById('register-form').addEventListener('submit', function (e) {
            const fullName = document.getElementById('full_name').value.trim();
            const phone    = document.getElementById('phone').value.trim();
            const password = passwordInput.value;
            const confirm  = confirmInput.value;

            const email = document.getElementById('email').value.trim();
            if (!fullName || !email || !phone || !password || !confirm) {
                e.preventDefault();
                alert('Please fill in all fields.');
                return;
            }
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return;
            }

            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters.');
                return;
            }

            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match.');
                return;
            }
        });
    </script>
</body>
</html>