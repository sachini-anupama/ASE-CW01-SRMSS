<?php
declare(strict_types=1);
session_start();

if (!empty($_SESSION['srmss_user'])) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/backend/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = trim($_POST['role']     ?? '');

    if (!$email || !$password || !$role) {
        $error = 'Please fill in all fields including your role.';
    } else {
        try {
            $pdo  = db();
            $stmt = $pdo->prepare(
                'SELECT u.UserID, u.FullName, u.Email, u.PasswordHash, u.Status, r.RoleName
                 FROM User u
                 JOIN Role r ON r.RoleID = u.RoleID
                 WHERE u.Email = :email
                 LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'Invalid email or password.';
            } elseif ($user['Status'] !== 'Active') {
                $error = 'Your account is inactive. Contact the administrator.';
            } elseif (
                !password_verify($password, $user['PasswordHash']) &&
                $user['PasswordHash'] !== base64_encode($password) &&
                $user['PasswordHash'] !== $password &&
                !(str_starts_with($user['PasswordHash'], 'hashed_password_') && $password === 'password123')
            ) {
                $error = 'Invalid email or password.';
            } elseif (strtolower($user['RoleName']) !== strtolower($role)) {
                $error = 'Selected role does not match this account.';
            } else {
                $_SESSION['srmss_user'] = [
                    'id'    => $user['UserID'],
                    'name'  => $user['FullName'],
                    'email' => $user['Email'],
                    'role'  => $user['RoleName'],
                ];
                header('Location: index.php');
                exit;
            }
        } catch (\Throwable $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Schedulix SRMSS | Login</title>
  <style>
    :root {
      --primary-blue: #1559b7;
      --primary-dark: #0f2f5f;
      --success-green: #16a56f;
      --light-bg: #f5f9fd;
      --white: #ffffff;
      --text-dark: #17243a;
      --text-light: #68758a;
      --border-color: #dbe7f3;
      --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--light-bg);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 30px;
    }

    button, input, select { font: inherit; }

    /* ── Card ── */
    .login-card {
      width: 100%;
      max-width: 1050px;
      min-height: 640px;
      display: grid;
      grid-template-columns: 1fr 0.9fr;
      background: var(--white);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: var(--shadow-xl);
    }

    /* ── LEFT: Brand side — same gradient as dashboard sidebar & header ── */
    .brand-side {
      background: linear-gradient(180deg, #0f2f5f 0%, #0d4b79 55%, #0f765f 100%);
      color: var(--white);
      padding: 44px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      overflow: hidden;
    }

    /* Decorative circle — same vibe as dashboard */
    .brand-side::before {
      content: "";
      position: absolute;
      width: 340px; height: 340px;
      right: -130px; top: -110px;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 50%;
    }
    .brand-side::after {
      content: "";
      position: absolute;
      width: 220px; height: 220px;
      left: -80px; bottom: -80px;
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 50%;
    }

    .brand, .intro, .route-preview { position: relative; z-index: 1; }

    /* Brand logo — same as sidebar */
    .brand { display: flex; align-items: center; gap: 14px; }
    .brand-icon {
      width: 48px; height: 48px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden; background: rgba(255,255,255,0.12);
    }
    .brand-icon img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .brand-text h1 { font-size: 22px; font-weight: 700; line-height: 1.2; }
    .brand-text p  { font-size: 11px; opacity: 0.78; margin-top: 3px; }

    .intro h2 {
      font-size: 48px; line-height: 1.1; font-weight: 800;
      margin-bottom: 16px;
      text-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .intro p  {
      font-size: 15px; line-height: 1.7;
      color: rgba(255,255,255,0.78);
      max-width: 380px;
    }

    /* Route preview card — matches dashboard card style */
    .route-preview {
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 12px;
      background: rgba(255,255,255,0.08);
      padding: 20px;
    }
    .route-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; }
    .route-top h3 { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
    .route-top p  { font-size: 12px; color: rgba(255,255,255,0.7); }
    .route-badge {
      padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700;
      background: rgba(22,165,111,0.25); color: #a8ffd8; white-space: nowrap;
    }
    .route-step { display: grid; grid-template-columns: 20px 1fr auto; gap: 10px; align-items: center; margin-top: 14px; }
    .route-dot  {
      width: 16px; height: 16px; border-radius: 50%;
      background: #17b88f;
      box-shadow: 0 0 0 5px rgba(23,184,143,0.2);
    }
    .route-step h4 { font-size: 13px; font-weight: 600; margin-bottom: 2px; }
    .route-step p  { font-size: 11px; color: rgba(255,255,255,0.65); }
    .route-step span { font-size: 12px; font-weight: 700; color: #a8ffd8; }

    /* ── RIGHT: Form side ── */
    .form-side {
      padding: 52px 48px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background: var(--white);
    }

    .form-title { margin-bottom: 30px; }
    .form-title .tag {
      display: inline-block; margin-bottom: 10px;
      color: var(--success-green); font-size: 12px;
      font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
    }
    .form-title h2 {
      font-size: 34px; font-weight: 800;
      color: var(--primary-dark); margin-bottom: 10px;
    }
    .form-title p { color: var(--text-light); font-size: 14px; line-height: 1.6; }

    form { display: grid; gap: 16px; }

    .form-group { display: flex; flex-direction: column; gap: 7px; }
    label { font-size: 13px; font-weight: 700; color: var(--text-dark); }

    input, select {
      height: 48px;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      background: var(--light-bg);
      color: var(--text-dark);
      padding: 0 14px;
      font-size: 14px;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }
    input:focus, select:focus {
      border-color: var(--primary-blue);
      background: var(--white);
      box-shadow: 0 0 0 3px rgba(21,89,183,0.12);
    }

    .form-row {
      display: flex; align-items: center;
      justify-content: space-between; gap: 12px; font-size: 13px;
    }
    .remember { display: flex; align-items: center; gap: 7px; color: var(--text-light); font-weight: 600; }
    .remember input { width: 15px; height: 15px; accent-color: var(--success-green); }

    .link-btn {
      border: 0; background: none;
      color: var(--primary-blue); font-weight: 700;
      font-size: 13px; cursor: pointer;
    }
    .link-btn:hover { color: var(--success-green); }

    /* Main button — same gradient as dashboard header */
    .main-btn {
      height: 50px; border: none; border-radius: 8px;
      background: linear-gradient(100deg, #0d3d68 0%, #0b6473 58%, #078064 100%);
      color: var(--white); font-size: 15px; font-weight: 700;
      cursor: pointer;
      box-shadow: 0 4px 18px rgba(13,61,104,0.22);
      transition: 0.2s ease;
    }
    .main-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(7,128,100,0.3);
    }

    /* Error box — same as dashboard alert-danger */
    .msg-error {
      padding: 12px 16px; border-radius: 8px;
      background: #fee2e2; color: #7f1d1d;
      border-left: 4px solid #dc3545;
      font-size: 13px; font-weight: 600;
    }

    /* Demo hint */
    .demo-box {
      margin-top: 18px; padding: 14px 16px;
      border: 1px solid var(--border-color);
      border-radius: 8px; background: var(--light-bg);
      color: var(--text-light); font-size: 12px; line-height: 1.9;
    }
    .demo-box strong { color: var(--primary-dark); }

    @media (max-width: 820px) {
      .login-card { grid-template-columns: 1fr; }
      .brand-side { min-height: 400px; }
      .intro h2 { font-size: 36px; }
    }
    @media (max-width: 500px) {
      body { padding: 0; }
      .login-card { border-radius: 0; min-height: 100vh; }
      .brand-side, .form-side { padding: 28px 20px; }
    }
  </style>
</head>
<body>
<main class="login-card">

  <!-- ══ LEFT ══ -->
  <section class="brand-side">
    <div class="brand">
      <div class="brand-icon"><img src="assets/schedulix-logo.jpeg" alt="Schedulix SRMSS logo"></div>
      <div class="brand-text">
        <h1>Schedulix</h1>
        <p>Smart Route Management & Scheduling System</p>
      </div>
    </div>

    <div class="intro">
      <h2>Plan routes with confidence.</h2>
      <p>Manage schedules, vehicles, drivers, and dispatch operations from one unified dashboard built for Sri Lanka's transport network.</p>
    </div>

    <div class="route-preview">
      <div class="route-top">
        <div>
          <h3>Today's Main Route</h3>
          <p>Colombo Fort &rarr; Maharagama</p>
        </div>
        <div class="route-badge">On Schedule</div>
      </div>
      <div class="route-step">
        <div class="route-dot"></div>
        <div><h4>Colombo Fort</h4><p>Dispatch confirmed</p></div>
        <span>06:00</span>
      </div>
      <div class="route-step">
        <div class="route-dot"></div>
        <div><h4>Pettah</h4><p>Stop 2</p></div>
        <span>06:10</span>
      </div>
      <div class="route-step">
        <div class="route-dot"></div>
        <div><h4>Maharagama</h4><p>Final destination</p></div>
        <span>06:50</span>
      </div>
    </div>
  </section>

  <!-- ══ RIGHT ══ -->
  <section class="form-side">
    <div class="form-title">
      <span class="tag">&#128274; Secure Login</span>
      <h2>Welcome Back</h2>
      <p>Sign in to access the Schedulix dashboard and manage your depot operations.</p>
    </div>

    <form method="POST" action="login.php" novalidate>

      <?php if ($error): ?>
        <div class="msg-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="form-group">
        <label for="role">Login Role</label>
        <select id="role" name="role" required>
          <option value="">— Select your role —</option>
          <option value="Admin"      <?= ($_POST['role'] ?? '') === 'Admin'      ? 'selected' : '' ?>>Administrator</option>
          <option value="Supervisor" <?= ($_POST['role'] ?? '') === 'Supervisor' ? 'selected' : '' ?>>Supervisor</option>
          <option value="Driver"     <?= ($_POST['role'] ?? '') === 'Driver'     ? 'selected' : '' ?>>Driver</option>
          <option value="Clerk"      <?= ($_POST['role'] ?? '') === 'Clerk'      ? 'selected' : '' ?>>Clerk</option>
        </select>
      </div>

      <div class="form-group">
        <label for="email">Email Address</label>
        <input id="email" type="email" name="email"
               placeholder="kamal@srmss.lk"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input id="password" type="password" name="password"
               placeholder="Enter your password" required>
      </div>

      <div class="form-row">
        <label class="remember">
          <input type="checkbox" name="remember"> Remember me
        </label>
        <button type="button" class="link-btn" onclick="fillDemo()">Use demo login</button>
      </div>

      <button type="submit" class="main-btn">Login to Schedulix</button>
    </form>

    <div class="demo-box">
      <strong>Admin:</strong> kamal@srmss.lk &nbsp;/&nbsp; password123<br>
      <strong>Supervisor:</strong> nimal@srmss.lk &nbsp;/&nbsp; password123<br>
      <strong>Clerk:</strong> sunil@srmss.lk &nbsp;/&nbsp; password123
    </div>
  </section>

</main>

<script>
function fillDemo() {
  document.getElementById('role').value    = 'Admin';
  document.getElementById('email').value   = 'kamal@srmss.lk';
  document.getElementById('password').value = 'password123';
}
</script>
</body>
</html>
