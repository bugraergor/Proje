<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = db();
$message = '';
$type = 'info';
$next = $_GET['next'] ?? '';

if (isset($_GET['logout'])) {
    $u = currentUser();
    if ($u) clearRememberMe($pdo, (int)$u['id']);
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $email === '' || $password === '') {
            $message = 'Tüm alanları doldurmak zorundasın.';
            $type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Geçerli bir e-posta adresi gir.';
            $type = 'error';
        } elseif (strlen($password) < 5) {
            $message = 'Şifre en az 5 karakter olmalı.';
            $type = 'error';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (:full_name, :email, :password_hash, :role, :created_at)');
                $stmt->execute([
                    'full_name'     => $fullName,
                    'email'         => mb_strtolower($email),
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role'          => 'user',
                    'created_at'    => date('Y-m-d H:i:s')
                ]);
                $message = '✅ Hesap oluşturuldu! Şimdi giriş yapabilirsin.';
                $type = 'success';
            } catch (Throwable $e) {
                $message = 'Bu e-posta zaten kayıtlı.';
                $type = 'error';
            }
        }
    }

    if ($action === 'login') {
        $email    = mb_strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            // Remember me — her zaman 30 gün sakla
            setRememberMe($pdo, (int)$user['id']);
            if ($next === 'admin' || $user['role'] === 'admin') {
                header('Location: admin.php');
                exit;
            }
            header('Location: index.php');
            exit;
        }
        $message = '❌ E-posta veya şifre hatalı.';
        $type = 'error';
    }
}

$activeUser = currentUser();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş / Kayıt | GezginDer</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-body">
    <header class="site-header">
        <nav class="navbar">
            <a href="index.html" class="logo">GEZGÎN<span>DER</span></a>
            <ul class="nav-links">
                <li><a href="index.html"><i class="fas fa-home"></i> Ana Sayfa</a></li>
                <?php if ($activeUser): ?>
                    <?php if ($activeUser['role'] === 'admin'): ?>
                        <li><a href="admin.php"><i class="fas fa-shield-alt"></i> Admin</a></li>
                    <?php endif; ?>
                    <li><a href="dashboard.php"><i class="fas fa-user"></i> Panelim</a></li>
                    <li><a href="auth.php?logout=1" class="btn-nav-logout"><i class="fas fa-sign-out-alt"></i> Çıkış</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="auth-main">
        <div class="auth-card-wrapper">
            
            <!-- Sol dekoratif alan -->
            <div class="auth-hero-side">
                <div class="auth-hero-content">
                    <div class="auth-hero-icon"><i class="fas fa-map-marked-alt"></i></div>
                    <h2>Dünyayı Keşfet</h2>
                    <p>Milyonlarca gezgine katıl, rotaları keşfet, deneyimlerini paylaş.</p>
                    <div class="auth-stats">
                        <div class="auth-stat"><span class="stat-num">1.2K+</span><span class="stat-label">Rota</span></div>
                        <div class="auth-stat"><span class="stat-num">48</span><span class="stat-label">Ülke</span></div>
                        <div class="auth-stat"><span class="stat-num">5K+</span><span class="stat-label">Gezgin</span></div>
                    </div>
                </div>
            </div>

            <!-- Sağ form alanı -->
            <div class="auth-form-side">
                <?php if ($activeUser): ?>
                    <div class="auth-logged">
                        <div class="auth-avatar-big">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <h3>Hoş geldin, <?= htmlspecialchars($activeUser['full_name'], ENT_QUOTES) ?>!</h3>
                        <p class="auth-email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($activeUser['email'], ENT_QUOTES) ?></p>
                        <p class="auth-role-badge <?= $activeUser['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                            <i class="fas fa-<?= $activeUser['role'] === 'admin' ? 'shield-alt' : 'user' ?>"></i>
                            <?= $activeUser['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı' ?>
                        </p>
                        <div class="auth-logged-actions">
                            <?php if ($activeUser['role'] === 'admin'): ?>
                                <a href="admin.php" class="auth-btn auth-btn-admin"><i class="fas fa-cog"></i> Admin Paneli</a>
                            <?php endif; ?>
                            <a href="dashboard.php" class="auth-btn auth-btn-primary"><i class="fas fa-tachometer-alt"></i> Panelime Git</a>
                            <a href="auth.php?logout=1" class="auth-btn auth-btn-outline"><i class="fas fa-sign-out-alt"></i> Çıkış Yap</a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Tab Sistemi -->
                    <div class="auth-tabs">
                        <button class="auth-tab active" onclick="switchTab('login', this)" id="tab-login">
                            <i class="fas fa-sign-in-alt"></i> Giriş Yap
                        </button>
                        <button class="auth-tab" onclick="switchTab('register', this)" id="tab-register">
                            <i class="fas fa-user-plus"></i> Kayıt Ol
                        </button>
                    </div>

                    <?php if ($message !== ''): ?>
                        <div class="auth-message auth-<?= $type ?>">
                            <?= htmlspecialchars($message, ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Giriş Formu -->
                    <div class="auth-panel" id="panel-login">
                        <h3 class="auth-form-title">Hesabına Giriş Yap</h3>
                        <form method="post" class="auth-form">
                            <input type="hidden" name="action" value="login">
                            <div class="auth-input-group">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" placeholder="E-posta adresin" required>
                            </div>
                            <div class="auth-input-group">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="password" placeholder="Şifren" required>
                                <button type="button" class="toggle-pass" onclick="togglePassword(this)"><i class="fas fa-eye"></i></button>
                            </div>
                            <button type="submit" class="auth-btn auth-btn-primary auth-btn-full">
                                <i class="fas fa-arrow-right-to-bracket"></i> Giriş Yap
                            </button>
                        </form>
                        <p class="auth-hint">
                            <i class="fas fa-info-circle"></i> 
                            Admin için: <code>admin@gezginder.com</code> / <code>12345</code>
                        </p>
                    </div>

                    <!-- Kayıt Formu -->
                    <div class="auth-panel hidden" id="panel-register">
                        <h3 class="auth-form-title">Yeni Hesap Oluştur</h3>
                        <form method="post" class="auth-form">
                            <input type="hidden" name="action" value="register">
                            <div class="auth-input-group">
                                <i class="fas fa-user"></i>
                                <input type="text" name="full_name" placeholder="Ad ve Soyadın" required>
                            </div>
                            <div class="auth-input-group">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" placeholder="E-posta adresin" required>
                            </div>
                            <div class="auth-input-group">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="password" placeholder="Şifre (min. 5 karakter)" required>
                                <button type="button" class="toggle-pass" onclick="togglePassword(this)"><i class="fas fa-eye"></i></button>
                            </div>
                            <button type="submit" class="auth-btn auth-btn-primary auth-btn-full">
                                <i class="fas fa-user-plus"></i> Hesap Oluştur
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="credits-footer">
        <p>© 2026 GezginDer — Nurettin Yavuz • Buğrahan Ergör • Duygu Bebek • İrem Zülal</p>
    </footer>

    <script>
    function switchTab(tab, btn) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.auth-panel').forEach(p => p.classList.add('hidden'));
        btn.classList.add('active');
        document.getElementById('panel-' + tab).classList.remove('hidden');
    }
    function togglePassword(btn) {
        const input = btn.parentElement.querySelector('input');
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    }
    <?php if ($type === 'success'): ?>
    document.addEventListener('DOMContentLoaded', () => switchTab('login', document.getElementById('tab-login')));
    <?php elseif (isset($_POST['action']) && $_POST['action'] === 'register'): ?>
    document.addEventListener('DOMContentLoaded', () => switchTab('register', document.getElementById('tab-register')));
    <?php endif; ?>
    </script>
</body>
</html>
