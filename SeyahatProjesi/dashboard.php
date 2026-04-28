<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

requireLogin();
$pdo  = db();
$user = currentUser();

// Profil güncelleme
$message = '';
$type    = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $bio      = trim($_POST['bio'] ?? '');
        if ($fullName !== '') {
            $stmt = $pdo->prepare('UPDATE users SET full_name=:fn, bio=:bio WHERE id=:id');
            $stmt->execute(['fn' => $fullName, 'bio' => $bio, 'id' => $user['id']]);
            $_SESSION['user']['full_name'] = $fullName;
            $user = currentUser();
            $message = '✅ Profil güncellendi.';
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $row     = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
        $row->execute(['id' => $user['id']]);
        $hash = $row->fetchColumn();
        if (!password_verify($current, $hash)) {
            $message = '❌ Mevcut şifre yanlış.';
            $type = 'error';
        } elseif (strlen($new) < 5) {
            $message = '❌ Yeni şifre en az 5 karakter olmalı.';
            $type = 'error';
        } else {
            $upd = $pdo->prepare('UPDATE users SET password_hash=:ph WHERE id=:id');
            $upd->execute(['ph' => password_hash($new, PASSWORD_DEFAULT), 'id' => $user['id']]);
            $message = '✅ Şifre başarıyla değiştirildi.';
        }
    }

    if ($action === 'delete_comment') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        $pdo->prepare('DELETE FROM comments WHERE id=:id AND user_id=:uid')->execute(['id' => $cid, 'uid' => $user['id']]);
        $message = '✅ Yorum silindi.';
    }
}

// Kullanıcı yorumlarını çek
$myComments = $pdo->prepare('
    SELECT c.id, c.content, c.created_at, b.title as post_title, b.slug
    FROM comments c
    LEFT JOIN blog_posts b ON b.id = c.post_id
    WHERE c.user_id = :uid
    ORDER BY c.created_at DESC LIMIT 20
');
$myComments->execute(['uid' => $user['id']]);
$myComments = $myComments->fetchAll();

// İstatistikler
$totalComments = count($myComments);
$joinDate      = $pdo->prepare('SELECT created_at FROM users WHERE id=:id');
$joinDate->execute(['id' => $user['id']]);
$joinDate      = $joinDate->fetchColumn();
$userBio       = $pdo->prepare('SELECT bio FROM users WHERE id=:id');
$userBio->execute(['id' => $user['id']]);
$userBio = $userBio->fetchColumn() ?: '';

// Son blog yazıları
$latestPosts = $pdo->query('SELECT id, title, slug, category, created_at FROM blog_posts ORDER BY created_at DESC LIMIT 4')->fetchAll();

// Favorileri Çek
$favStmt = $pdo->prepare('SELECT place_key FROM favorites WHERE user_id = :u ORDER BY id DESC');
$favStmt->execute(['u' => $user['id']]);
$myFavorites = $favStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="tr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanıcı Paneli | GezginDer</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('gezginder-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="dashboard-body">
    <header class="site-header dash-header">
        <nav class="navbar">
            <a href="index.php" class="logo">GEZGÎN<span>DER</span></a>
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-home"></i> Ana Sayfa</a></li>
                <li><a href="dashboard.php" class="active-link"><i class="fas fa-tachometer-alt"></i> Panelim</a></li>
                <?php if ($user['role'] === 'admin'): ?>
                    <li><a href="admin.php"><i class="fas fa-shield-alt"></i> Admin</a></li>
                <?php endif; ?>
                <li><button class="theme-toggle" onclick="toggleTheme()" title="Temayı değiştir"><i class="fas fa-sun" id="theme-icon"></i></button></li>
                <li><a href="#" class="btn-nav-logout" onclick="openLogoutModal(event)"><i class="fas fa-sign-out-alt"></i> Çıkış</a></li>
            </ul>
        </nav>
    </header>

    <div class="dash-layout">

        <!-- Sol Sidebar -->
        <aside class="dash-sidebar">
            <div class="dash-user-card">
                <div class="dash-avatar">
                    <?= strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
                </div>
                <h3><?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?></h3>
                <span class="dash-role-badge <?= $user['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                    <i class="fas fa-<?= $user['role'] === 'admin' ? 'shield-alt' : 'star' ?>"></i>
                    <?= $user['role'] === 'admin' ? 'Yönetici' : 'Gezgin' ?>
                </span>
                <p class="dash-user-email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email'], ENT_QUOTES) ?></p>
                <?php if ($userBio): ?>
                    <p class="dash-user-bio"><?= htmlspecialchars($userBio, ENT_QUOTES) ?></p>
                <?php endif; ?>
            </div>

            <nav class="dash-nav">
                <a href="#overview" class="dash-nav-item active" onclick="showSection('overview', this)">
                    <i class="fas fa-chart-pie"></i> Genel Bakış
                </a>
                <a href="#submit-post" class="dash-nav-item" onclick="showSection('submit-post', this)">
                    <i class="fas fa-paper-plane"></i> Paylaşım Gönder
                </a>
                <a href="#my-posts" class="dash-nav-item" onclick="showSection('my-posts', this)">
                    <i class="fas fa-newspaper"></i> Paylaşımlarım
                </a>
                <a href="#profile" class="dash-nav-item" onclick="showSection('profile', this)">
                    <i class="fas fa-user-edit"></i> Profilim
                </a>
                <a href="#comments" class="dash-nav-item" onclick="showSection('comments', this)">
                    <i class="fas fa-comments"></i> Yorumlarım
                    <?php if ($totalComments > 0): ?>
                        <span class="dash-badge"><?= $totalComments ?></span>
                    <?php endif; ?>
                </a>
                <a href="#favorites" class="dash-nav-item" onclick="showSection('favorites', this)">
                    <i class="fas fa-heart"></i> Favori Rotalarım
                </a>
                <a href="#password" class="dash-nav-item" onclick="showSection('password', this)">
                    <i class="fas fa-key"></i> Şifre Değiştir
                </a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="admin.php" class="dash-nav-item dash-nav-admin">
                        <i class="fas fa-cog"></i> Admin Paneli
                    </a>
                <?php endif; ?>
                <a href="#" class="dash-nav-item dash-nav-logout" onclick="openLogoutModal(event)">
                    <i class="fas fa-sign-out-alt"></i> Çıkış Yap
                </a>
            </nav>
        </aside>

        <!-- Ana İçerik -->
        <main class="dash-main">
            <?php if ($message !== ''): ?>
                <div class="dash-alert dash-alert-<?= $type ?>">
                    <?= htmlspecialchars($message, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>

            <!-- Genel Bakış -->
            <section class="dash-section" id="overview">
                <h2 class="dash-section-title"><i class="fas fa-chart-pie"></i> Genel Bakış</h2>

                <div class="dash-stats-grid">
                    <div class="dash-stat-card">
                        <div class="dash-stat-icon" style="background: linear-gradient(135deg,#667eea,#764ba2)">
                            <i class="fas fa-comments"></i>
                        </div>
                        <div class="dash-stat-info">
                            <span class="dash-stat-num"><?= $totalComments ?></span>
                            <span class="dash-stat-label">Yorum</span>
                        </div>
                    </div>
                    <div class="dash-stat-card">
                        <div class="dash-stat-icon" style="background: linear-gradient(135deg,#f093fb,#f5576c)">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="dash-stat-info">
                            <span class="dash-stat-num">
                                <?= $pdo->prepare('SELECT COUNT(*) FROM place_ratings WHERE user_id=:id') ? ($pdo->prepare('SELECT COUNT(*) FROM place_ratings WHERE user_id=:id') ? (function($pdo,$id){ $s=$pdo->prepare('SELECT COUNT(*) FROM place_ratings WHERE user_id=:id'); $s->execute(['id'=>$id]); return (int)$s->fetchColumn(); })($pdo,$user['id']) : 0) : 0 ?>
                            </span>
                            <span class="dash-stat-label">Yer Puanı</span>
                        </div>
                    </div>
                    <div class="dash-stat-card">
                        <div class="dash-stat-icon" style="background: linear-gradient(135deg,#4facfe,#00f2fe)">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="dash-stat-info">
                            <span class="dash-stat-num"><?= date('d M', strtotime($joinDate)) ?></span>
                            <span class="dash-stat-label">Katılım</span>
                        </div>
                    </div>
                    <div class="dash-stat-card">
                        <div class="dash-stat-icon" style="background: linear-gradient(135deg,#43e97b,#38f9d7)">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <div class="dash-stat-info">
                            <span class="dash-stat-num"><?= count($latestPosts) ?></span>
                            <span class="dash-stat-label">Blog Yazısı</span>
                        </div>
                    </div>
                </div>

                <!-- Son Blog Yazıları -->
                <h3 class="dash-sub-title"><i class="fas fa-fire"></i> Keşfedilecek Yazılar</h3>
                <div class="dash-posts-grid">
                    <?php foreach ($latestPosts as $post): ?>
                        <article class="dash-post-card">
                            <div class="dash-post-cat cat-<?= htmlspecialchars($post['category'], ENT_QUOTES) ?>">
                                <?= mb_strtoupper($post['category']) ?>
                            </div>
                            <h4><?= htmlspecialchars($post['title'], ENT_QUOTES) ?></h4>
                            <span class="dash-post-date">
                                <i class="fas fa-clock"></i>
                                <?= date('d M Y', strtotime($post['created_at'])) ?>
                            </span>
                            <a href="blog.php?slug=<?= htmlspecialchars($post['slug'], ENT_QUOTES) ?>" class="dash-post-link">
                                Oku <i class="fas fa-arrow-right"></i>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Profil -->
            <section class="dash-section hidden" id="profile">
                <h2 class="dash-section-title"><i class="fas fa-user-edit"></i> Profilimi Düzenle</h2>
                <div class="dash-card">
                    <form method="post" class="dash-form">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="dash-form-group">
                            <label><i class="fas fa-user"></i> Ad Soyad</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?>" required>
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-envelope"></i> E-posta</label>
                            <input type="email" value="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>" disabled>
                            <small>E-posta değiştirilemez.</small>
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-pen"></i> Biyografi</label>
                            <textarea name="bio" rows="3" placeholder="Kendin hakkında bir şeyler yaz..."><?= htmlspecialchars($userBio, ENT_QUOTES) ?></textarea>
                        </div>
                        <button type="submit" class="auth-btn auth-btn-primary">
                            <i class="fas fa-save"></i> Kaydet
                        </button>
                    </form>
                </div>
            </section>

            <!-- Favoriler -->
            <section class="dash-section hidden" id="favorites">
                <h2 class="dash-section-title"><i class="fas fa-heart" style="color:#ef4444;"></i> Favori Rotalarım</h2>
                <?php if (empty($myFavorites)): ?>
                    <div class="dash-card" style="text-align:center; color:#94a3b8; padding: 40px;">
                        <i class="far fa-heart" style="font-size:3rem; margin-bottom:15px; opacity:0.5;"></i>
                        <p>Henüz keşfet sayfasından favoriye eklediğiniz bir konum bulunmuyor.</p>
                        <a href="index.php#kesfet" class="auth-btn auth-btn-primary" style="display:inline-block; margin-top:15px;">Keşfetmeye Başla</a>
                    </div>
                <?php else: ?>
                    <div class="dash-posts-grid">
                        <?php foreach ($myFavorites as $favKey): 
                            if (!isset($placesData[$favKey])) continue;
                            $favPlace = $placesData[$favKey];
                        ?>
                            <article class="dash-post-card" style="padding:0; overflow:hidden; border:none; background:transparent;">
                                <div style="position:relative;">
                                    <img src="<?= htmlspecialchars($favPlace['image'], ENT_QUOTES) ?>" alt="" style="width:100%; height:160px; object-fit:cover; display:block;">
                                    <span class="cat-tag cat-<?= htmlspecialchars($favPlace['category'],ENT_QUOTES) ?>" style="position:absolute; top:10px; left:10px; z-index:2;"><?= $favPlace['cat_label'] ?></span>
                                </div>
                                <div class="dash-card" style="border-radius:0 0 16px 16px; border-top:none; padding:16px;">
                                    <h4 style="margin:0 0 6px; font-size:1.1rem; color:var(--ana-renk,#e2e8f0);"><?= htmlspecialchars($favPlace['name'],ENT_QUOTES) ?></h4>
                                    <p style="font-size:0.85rem; color:#94a3b8; margin:0 0 12px; line-height:1.4;">
                                        <?= htmlspecialchars(mb_substr($favPlace['description'],0,80),ENT_QUOTES) ?>...
                                    </p>
                                    <a href="index.php" style="color:#3b82f6; text-decoration:none; font-size:0.9rem; font-weight:600;"><i class="fas fa-arrow-right"></i> İncele</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Yorumlarım -->
            <section class="dash-section hidden" id="comments">
                <h2 class="dash-section-title"><i class="fas fa-comments"></i> Yorumlarım</h2>
                <?php if (empty($myComments)): ?>
                    <div class="dash-empty">
                        <i class="fas fa-comment-slash"></i>
                        <p>Henüz hiç yorum yapmadın.</p>
                        <a href="index.html#blog" class="auth-btn auth-btn-primary">Blog'a Git</a>
                    </div>
                <?php else: ?>
                    <div class="dash-comment-list">
                        <?php foreach ($myComments as $c): ?>
                            <div class="dash-comment-item">
                                <div class="dash-comment-meta">
                                    <span class="dash-comment-post"><i class="fas fa-newspaper"></i> <?= htmlspecialchars($c['post_title'] ?? 'Silinmiş yazı', ENT_QUOTES) ?></span>
                                    <span class="dash-comment-date"><i class="fas fa-clock"></i> <?= date('d M Y H:i', strtotime($c['created_at'])) ?></span>
                                </div>
                                <p class="dash-comment-text"><?= htmlspecialchars($c['content'], ENT_QUOTES) ?></p>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Yorumu sil?')">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="dash-delete-btn"><i class="fas fa-trash"></i> Sil</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Şifre Değiştir -->
            <section class="dash-section hidden" id="password">
                <h2 class="dash-section-title"><i class="fas fa-key"></i> Şifre Değiştir</h2>
                <div class="dash-card" style="max-width:480px;">
                    <form method="post" class="dash-form">
                        <input type="hidden" name="action" value="change_password">
                        <div class="dash-form-group">
                            <label><i class="fas fa-lock"></i> Mevcut Şifre</label>
                            <input type="password" name="current_password" placeholder="••••••••" required>
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-key"></i> Yeni Şifre</label>
                            <input type="password" name="new_password" placeholder="Min. 5 karakter" required>
                        </div>
                        <button type="submit" class="auth-btn auth-btn-primary">
                            <i class="fas fa-check"></i> Şifreyi Güncelle
                        </button>
                    </form>
                </div>
            </section>

            <!-- Paylaşım Gönder -->
            <section class="dash-section hidden" id="submit-post">
                <h2 class="dash-section-title"><i class="fas fa-paper-plane"></i> Yeni Paylaşım Gönder</h2>
                <div class="dash-card" style="max-width:680px;">
                    <p style="color:var(--ikincil);margin-bottom:20px;font-size:0.9rem;">
                        <i class="fas fa-info-circle" style="color:#3b82f6;"></i>
                        Paylaşımını gönderdikten sonra admin onayıyla birlikte siteye eklenecek.
                    </p>
                    <form class="submit-post-form" id="user-post-form">
                        <div class="dash-form-group">
                            <label><i class="fas fa-heading"></i> Başlık *</label>
                            <input type="text" id="sp-title" placeholder="Paylaşımın başlığı" required maxlength="120">
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Destinasyon / Yer Adı *</label>
                            <input type="text" id="sp-dest" placeholder="örn. Kapadokya, Türkiye" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="dash-form-group">
                                <label><i class="fas fa-tag"></i> Kategori</label>
                                <select id="sp-cat">
                                    <option value="sehir">Şehir</option>
                                    <option value="ulke">Ülke Turu</option>
                                    <option value="doga">Doğa</option>
                                    <option value="tarih">Tarihi Yer</option>
                                    <option value="genel">Genel</option>
                                </select>
                            </div>
                            <div class="dash-form-group">
                                <label><i class="fas fa-image"></i> Kapak Görseli URL (isteğe bağlı)</label>
                                <input type="url" id="sp-img" placeholder="https://...">
                            </div>
                        </div>

                        <div class="dash-form-group">
                            <label><i class="fas fa-map-pin"></i> Gezilecek Yerler <small style="font-weight:400">(virgülle ayır, örn: Ayasofya, Topkapı Sarayı)</small></label>
                            <input type="text" id="sp-highlights" placeholder="Yer1, Yer2, Yer3...">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                            <div class="dash-form-group">
                                <label><i class="fas fa-sun"></i> En İyi Sezon</label>
                                <input type="text" id="sp-season" placeholder="örn. İlkbahar & Yaz">
                            </div>
                            <div class="dash-form-group">
                                <label><i class="fas fa-clock"></i> Önerilen Süre</label>
                                <input type="text" id="sp-duration" placeholder="örn. 3-5 Gün">
                            </div>
                            <div class="dash-form-group">
                                <label><i class="fas fa-wallet"></i> Bütçe Tahmini</label>
                                <input type="text" id="sp-budget" placeholder="örn. ₺₺ veya $$">
                            </div>
                        </div>

                        <div class="dash-form-group">
                            <label><i class="fas fa-align-left"></i> Yazı / Anlatı * <small style="font-weight:400">(en az 30 karakter)</small></label>
                            <textarea id="sp-content" rows="8" placeholder="Bu yer hakkında deneyimlerini, ipuçlarını ve önerilerini paylaş..." required></textarea>
                        </div>
                        <button type="submit" class="auth-btn auth-btn-primary" style="margin-top:4px;">
                            <i class="fas fa-paper-plane"></i> Gönder (Admin Onayına Sun)
                        </button>
                    </form>
                    <div id="sp-result"></div>
                </div>
            </section>

            <!-- Paylaşımlarım -->
            <section class="dash-section hidden" id="my-posts">
                <h2 class="dash-section-title"><i class="fas fa-newspaper"></i> Paylaşımlarım</h2>
                <?php
                $myPosts = $pdo->prepare('SELECT id,title,category,status,created_at FROM blog_posts WHERE author_id=:uid ORDER BY created_at DESC');
                $myPosts->execute(['uid'=>$user['id']]);
                $myPostsList = $myPosts->fetchAll();
                $postStatusLabels = ['approved'=>'Yayında','pending'=>'Bekliyor','rejected'=>'Reddedildi'];
                $postStatusColors = ['approved'=>'#10b981','pending'=>'#f59e0b','rejected'=>'#ef4444'];
                ?>
                <?php if (empty($myPostsList)): ?>
                    <div class="dash-empty">
                        <i class="fas fa-newspaper"></i>
                        <p>Henüz paylaşım göndermedin.</p>
                        <button onclick="showSection('submit-post', document.querySelector('[href=\'#submit-post\']'))" class="auth-btn auth-btn-primary">Paylaşım Gönder</button>
                    </div>
                <?php else: ?>
                    <div style="display:grid;gap:14px;">
                        <?php foreach ($myPostsList as $mp): ?>
                        <div class="dash-card" style="display:flex;align-items:center;gap:14px;justify-content:space-between;padding:16px 20px;">
                            <div>
                                <strong style="display:block;margin-bottom:4px;"><?= htmlspecialchars($mp['title'],ENT_QUOTES) ?></strong>
                                <small style="color:var(--ikincil);"><i class="fas fa-clock"></i> <?= date('d M Y', strtotime($mp['created_at'])) ?></small>
                            </div>
                            <span style="background:<?= $postStatusColors[$mp['status']] ?? '#6b7280' ?>;color:#fff;padding:4px 12px;border-radius:20px;font-size:0.78rem;white-space:nowrap;">
                                <?= $postStatusLabels[$mp['status']] ?? $mp['status'] ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <footer class="credits-footer">
        <p>© 2026 GezginDer — Nurettin Yavuz • Buğrahan Ergör • Duygu Bebek • İrem Zülal</p>
    </footer>

    <script>
    function showSection(id, el) {
        if (event) event.preventDefault();
        document.querySelectorAll('.dash-section').forEach(s => s.classList.add('hidden'));
        document.querySelectorAll('.dash-nav-item').forEach(n => n.classList.remove('active'));
        document.getElementById(id).classList.remove('hidden');
        if (el) el.classList.add('active');
    }
    const hash = window.location.hash.replace('#', '');
    if (hash) {
        const target = document.getElementById(hash);
        const navItem = document.querySelector('.dash-nav-item[href="#' + hash + '"]');
        if (target && navItem) showSection(hash, navItem);
    }

    // Tema
    function toggleTheme() {
        var html = document.documentElement;
        var cur  = html.getAttribute('data-theme') || 'dark';
        var next = cur === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('gezginder-theme', next);
        var icon = document.getElementById('theme-icon');
        if (icon) icon.className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    (function(){
        var t = localStorage.getItem('gezginder-theme') || 'dark';
        var icon = document.getElementById('theme-icon');
        if (icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    })();

    // Kullanıcı paylaşım formu
    document.getElementById('user-post-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        btn.disabled = true; btn.textContent = 'Gönderiliyor...';
        const body = {
            action: 'submit_post',
            title:       document.getElementById('sp-title').value.trim(),
            destination: document.getElementById('sp-dest').value.trim(),
            category:    document.getElementById('sp-cat').value,
            image_url:   document.getElementById('sp-img').value.trim(),
            highlights:  document.getElementById('sp-highlights').value.trim(),
            season:      document.getElementById('sp-season').value.trim(),
            duration:    document.getElementById('sp-duration').value.trim(),
            budget:      document.getElementById('sp-budget').value.trim(),
            content:     document.getElementById('sp-content').value.trim(),
        };
        try {
            const res  = await fetch('api.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
            const data = await res.json();
            const result = document.getElementById('sp-result');
            result.className = 'submit-post-result ' + (data.ok ? 'ok' : 'err');
            result.textContent = data.message;
            if (data.ok) document.getElementById('user-post-form').reset();
        } catch(err) { console.error(err); }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder (Admin Onayına Sun)';
    });
    </script>

    <!-- Çıkış Onay Modali -->
    <div class="logout-overlay" id="logout-modal">
        <div class="logout-modal">
            <div class="logout-icon-ring">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h3>Çıkış Yapmak İstiyor musun?</h3>
            <p>Oturumunuz kapatılacak. Tekrar giriş yapman gerekecek.</p>
            <div class="logout-modal-btns">
                <button class="logout-cancel-btn" onclick="closeLogoutModal()"><i class="fas fa-times"></i> Vazgeç</button>
                <a href="auth.php?logout=1" class="logout-confirm-btn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fas fa-sign-out-alt"></i> Çıkış Yap</a>
            </div>
        </div>
    </div>

    <script>
    function openLogoutModal(e) {
        if (e) e.preventDefault();
        document.getElementById('logout-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeLogoutModal() {
        document.getElementById('logout-modal').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.getElementById('logout-modal')?.addEventListener('click', function(e){
        if (e.target === this) closeLogoutModal();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeLogoutModal();
    });
    </script>
</body>
</html>
