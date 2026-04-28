<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

requireAdmin();
$pdo  = db();
$user = currentUser();

$message = '';
$type    = 'success';
$tab     = $_GET['tab'] ?? 'dashboard';

// POST işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Kullanıcı sil
    if ($action === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare('DELETE FROM users WHERE id=:id AND role != "admin"')->execute(['id' => $id]);
        $message = '✅ Kullanıcı silindi.';
        $tab = 'users';
    }

    // Kullanıcı rolünü değiştir
    if ($action === 'toggle_role') {
        $id   = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['new_role'] ?? 'user';
        if (in_array($role, ['user','admin']) && $id !== (int)$user['id']) {
            $pdo->prepare('UPDATE users SET role=:role WHERE id=:id')->execute(['role' => $role, 'id' => $id]);
            $message = '✅ Rol güncellendi.';
        }
        $tab = 'users';
    }

    // Blog yazısı ekle
    if ($action === 'add_post') {
        $title    = trim($_POST['title'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'genel');
        $imageUrl = trim($_POST['image_url'] ?? '');
        if ($title && $content) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $title)));
            $slug = trim($slug, '-') . '-' . time();
            try {
                $pdo->prepare('INSERT INTO blog_posts (title,slug,content,image_url,category,author_id,created_at,updated_at) VALUES (:title,:slug,:content,:image_url,:category,:author_id,:now,:now)')
                    ->execute(['title'=>$title,'slug'=>$slug,'content'=>$content,'image_url'=>$imageUrl,'category'=>$category,'author_id'=>$user['id'],'now'=>date('Y-m-d H:i:s')]);
                $message = '✅ Blog yazısı eklendi.';
            } catch (Throwable $e) {
                $message = '❌ Hata: ' . $e->getMessage();
                $type = 'error';
            }
        } else {
            $message = '❌ Başlık ve içerik zorunlu.';
            $type = 'error';
        }
        $tab = 'posts';
    }

    // Blog yazısı sil
    if ($action === 'delete_post') {
        $id = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare('DELETE FROM blog_posts WHERE id=:id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM comments WHERE post_id=:id')->execute(['id' => $id]);
        $message = '✅ Yazı ve yorumları silindi.';
        $tab = 'posts';
    }

    // Yorum sil
    if ($action === 'delete_comment') {
        $id = (int)($_POST['comment_id'] ?? 0);
        $pdo->prepare('DELETE FROM comments WHERE id=:id')->execute(['id' => $id]);
        $message = '✅ Yorum silindi.';
        $tab = 'comments';
    }

    // Yorum onayla / reddet
    if ($action === 'toggle_comment') {
        $id      = (int)($_POST['comment_id'] ?? 0);
        $current = (int)($_POST['current_approved'] ?? 0);
        $pdo->prepare('UPDATE comments SET approved=:a WHERE id=:id')->execute(['a' => $current ? 0 : 1, 'id' => $id]);
        $message = '✅ Yorum durumu güncellendi.';
        $tab = 'comments';
    }
    // Onayla / Reddet pending post
    if ($action === 'approve_post') {
        $id = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare('UPDATE blog_posts SET status="approved", updated_at=:now WHERE id=:id')
            ->execute(['id'=>$id,'now'=>date('Y-m-d H:i:s')]);
        $message = '✅ Paylaşım yayınlandı.';
        $tab = 'pending';
    }
    if ($action === 'reject_post') {
        $id = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare('UPDATE blog_posts SET status="rejected", updated_at=:now WHERE id=:id')
            ->execute(['id'=>$id,'now'=>date('Y-m-d H:i:s')]);
        $message = '🗑 Paylaşım reddedildi.';
        $tab = 'pending';
    }
}

// Veriler
$users    = $pdo->query('SELECT id, full_name, email, role, created_at FROM users ORDER BY id DESC')->fetchAll();
$posts    = $pdo->query('SELECT p.*, u.full_name as author FROM blog_posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.status="approved" ORDER BY p.created_at DESC')->fetchAll();
$pendingPosts = $pdo->query('SELECT p.*, u.full_name as author FROM blog_posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.status="pending" ORDER BY p.created_at DESC')->fetchAll();
$comments = $pdo->query('SELECT c.*, b.title as post_title, u.full_name as user_name FROM comments c LEFT JOIN blog_posts b ON b.id=c.post_id LEFT JOIN users u ON u.id=c.user_id ORDER BY c.created_at DESC')->fetchAll();

$totalUsers    = count($users);
$totalPosts    = count($posts);
$totalPending  = count($pendingPosts);
$totalComments = count($comments);
$totalViews    = (int)$pdo->query('SELECT COALESCE(SUM(views),0) FROM blog_posts')->fetchColumn();

$categories = ['sehir'=>'Şehir','ulke'=>'Ülke','doga'=>'Doğa','tarih'=>'Tarihi','genel'=>'Genel'];

$catStats = [];
foreach ($posts as $p) {
    $c = $categories[$p['category']] ?? $p['category'];
    $catStats[$c] = ($catStats[$c] ?? 0) + 1;
}
$chartLabels = json_encode(array_keys($catStats));
$chartData = json_encode(array_values($catStats));
?>
<!DOCTYPE html>
<html lang="tr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Paneli | GezginDer</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('gezginder-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="admin-body">
    <header class="site-header admin-header">
        <nav class="navbar">
            <a href="index.php" class="logo">GEZGÎN<span>DER</span></a>
            <div class="admin-header-right">
                <span class="admin-welcome"><i class="fas fa-shield-alt"></i> <?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?></span>
                <button class="theme-toggle" onclick="toggleTheme()" title="Temayı değiştir"><i class="fas fa-sun" id="theme-icon"></i></button>
                <a href="dashboard.php" class="btn-nav-sm"><i class="fas fa-user"></i> Panelim</a>
                <a href="index.php" class="btn-nav-sm"><i class="fas fa-home"></i> Site</a>
                <a href="#" class="btn-nav-sm btn-logout-sm" onclick="openLogoutModal(event)"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
            </div>
        </nav>
    </header>

    <div class="admin-layout">

        <aside class="admin-sidebar">
            <div class="admin-brand">
                <i class="fas fa-shield-alt"></i>
                <span>Admin Panel</span>
            </div>
            <nav class="admin-nav">
                <a href="?tab=dashboard" class="admin-nav-item <?= $tab === 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i> Gösterge Paneli
                </a>
                <a href="?tab=pending" class="admin-nav-item <?= $tab === 'pending' ? 'active' : '' ?>">
                    <i class="fas fa-hourglass-half"></i> Bekleyen Paylaşımlar
                    <?php if ($totalPending > 0): ?><span class="admin-nav-count" style="background:#f59e0b"><?= $totalPending ?></span><?php endif; ?>
                </a>
                <a href="?tab=users" class="admin-nav-item <?= $tab === 'users' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Kullanıcılar
                    <span class="admin-nav-count"><?= $totalUsers ?></span>
                </a>
                <a href="?tab=posts" class="admin-nav-item <?= $tab === 'posts' ? 'active' : '' ?>">
                    <i class="fas fa-newspaper"></i> Blog Yazıları
                    <span class="admin-nav-count"><?= $totalPosts ?></span>
                </a>
                <a href="?tab=add_post" class="admin-nav-item <?= $tab === 'add_post' ? 'active' : '' ?>">
                    <i class="fas fa-plus-circle"></i> Yeni Yazı
                </a>
                <a href="?tab=comments" class="admin-nav-item <?= $tab === 'comments' ? 'active' : '' ?>">
                    <i class="fas fa-comments"></i> Yorumlar
                    <span class="admin-nav-count"><?= $totalComments ?></span>
                </a>
            </nav>
            <div class="admin-sidebar-footer">
                <a href="index.php" class="admin-site-link"><i class="fas fa-external-link-alt"></i> Siteyi Gör</a>
            </div>
        </aside>

        <!-- Ana İçerik -->
        <main class="admin-main">
            <?php if ($message !== ''): ?>
                <div class="admin-alert admin-alert-<?= $type ?>">
                    <i class="fas fa-<?= $type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>

            <!-- ======================== DASHBOARD ======================== -->
            <?php if ($tab === 'dashboard'): ?>
                <div class="admin-page-header">
                    <h1><i class="fas fa-chart-bar"></i> Gösterge Paneli</h1>
                    <p>Site istatistiklerine genel bakış</p>
                </div>
                <div class="admin-stats-grid">
                    <div class="admin-stat-card stat-blue">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-body"><span class="stat-num"><?= $totalUsers ?></span><span class="stat-label">Toplam Kullanıcı</span></div>
                        <div class="stat-trend"><i class="fas fa-arrow-up"></i></div>
                    </div>
                    <div class="admin-stat-card stat-purple">
                        <div class="stat-icon"><i class="fas fa-newspaper"></i></div>
                        <div class="stat-body"><span class="stat-num"><?= $totalPosts ?></span><span class="stat-label">Blog Yazısı</span></div>
                        <div class="stat-trend"><i class="fas fa-arrow-up"></i></div>
                    </div>
                    <div class="admin-stat-card stat-orange" style="cursor:pointer" onclick="location.href='?tab=pending'">
                        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-body"><span class="stat-num"><?= $totalPending ?></span><span class="stat-label">Bekleyen Paylaşım</span></div>
                        <div class="stat-trend"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    <div class="admin-stat-card stat-green">
                        <div class="stat-icon"><i class="fas fa-comments"></i></div>
                        <div class="stat-body"><span class="stat-num"><?= $totalComments ?></span><span class="stat-label">Yorum</span></div>
                        <div class="stat-trend"><i class="fas fa-arrow-up"></i></div>
                    </div>
                </div>
                
                <!-- İstatistik Grafikleri -->
                <div class="admin-widget" style="margin-bottom: 30px;">
                    <div class="admin-widget-header">
                        <h3><i class="fas fa-chart-bar"></i> Kategori Dağılımı</h3>
                    </div>
                    <div style="height: 280px; display:flex; justify-content:center;">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>

                <!-- Son Kullanıcılar -->
                <div class="admin-widget">
                    <div class="admin-widget-header">
                        <h3><i class="fas fa-users"></i> Son Kayıt Olan Kullanıcılar</h3>
                        <a href="?tab=users" class="admin-widget-link">Tümünü Gör <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <table class="admin-table">
                        <thead><tr><th>Ad Soyad</th><th>E-posta</th><th>Rol</th><th>Kayıt</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($users, 0, 5) as $u): ?>
                                <tr>
                                    <td>
                                        <div class="admin-user-mini">
                                            <div class="admin-mini-avatar"><?= mb_strtoupper(mb_substr($u['full_name'], 0, 1)) ?></div>
                                            <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
                                    <td><span class="role-badge role-<?= $u['role'] ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Kullanıcı' ?></span></td>
                                    <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Son Yazılar -->
                <div class="admin-widget">
                    <div class="admin-widget-header">
                        <h3><i class="fas fa-newspaper"></i> Son Blog Yazıları</h3>
                        <a href="?tab=posts" class="admin-widget-link">Tümünü Gör <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <table class="admin-table">
                        <thead><tr><th>Başlık</th><th>Kategori</th><th>Yazar</th><th>Tarih</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($posts, 0, 5) as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></td>
                                    <td><span class="cat-tag cat-<?= $p['category'] ?>"><?= $categories[$p['category']] ?? $p['category'] ?></span></td>
                                    <td><?= htmlspecialchars($p['author'] ?? '-', ENT_QUOTES) ?></td>
                                    <td><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ======================== KULLANICILAR ======================== -->
            <?php elseif ($tab === 'users'): ?>
                <div class="admin-page-header">
                    <h1><i class="fas fa-users"></i> Kullanıcı Yönetimi</h1>
                    <p><?= $totalUsers ?> kayıtlı kullanıcı</p>
                </div>

                <div class="admin-card">
                    <div style="overflow-x:auto;">
                        <table class="admin-table">
                            <thead>
                                <tr><th>ID</th><th>Ad Soyad</th><th>E-posta</th><th>Rol</th><th>Kayıt Tarihi</th><th>İşlemler</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><strong>#<?= $u['id'] ?></strong></td>
                                        <td>
                                            <div class="admin-user-mini">
                                                <div class="admin-mini-avatar"><?= mb_strtoupper(mb_substr($u['full_name'], 0, 1)) ?></div>
                                                <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
                                        <td><span class="role-badge role-<?= $u['role'] ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Kullanıcı' ?></span></td>
                                        <td><?= date('d M Y H:i', strtotime($u['created_at'])) ?></td>
                                        <td class="admin-actions">
                                            <?php if ($u['role'] !== 'admin' || (int)$u['id'] !== (int)$user['id']): ?>
                                                <?php if ($u['role'] !== 'admin'): ?>
                                                    <!-- Role yükselt -->
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="action" value="toggle_role">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <input type="hidden" name="new_role" value="admin">
                                                        <button type="submit" class="admin-btn admin-btn-info" title="Admina Yükselt">
                                                            <i class="fas fa-shield-alt"></i>
                                                        </button>
                                                    </form>
                                                    <!-- Sil -->
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Kullanıcı silinsin mi?')">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="admin-btn admin-btn-danger" title="Sil">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <!-- Kullanıcıya düşür -->
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Admin yetkisi kaldırılsın mı?')">
                                                        <input type="hidden" name="action" value="toggle_role">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <input type="hidden" name="new_role" value="user">
                                                        <button type="submit" class="admin-btn admin-btn-warning" title="Kullanıcıya Düşür">
                                                            <i class="fas fa-user-minus"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="admin-protected"><i class="fas fa-lock"></i> Korunuyor</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- ======================== BLOG YAZILARI ======================== -->
            <?php elseif ($tab === 'posts'): ?>
                <div class="admin-page-header">
                    <h1><i class="fas fa-newspaper"></i> Blog Yazıları</h1>
                    <a href="?tab=add_post" class="admin-action-btn"><i class="fas fa-plus"></i> Yeni Yazı Ekle</a>
                </div>

                <div class="admin-card">
                    <div style="overflow-x:auto;">
                        <table class="admin-table">
                            <thead>
                                <tr><th>Başlık</th><th>Kategori</th><th>Yazar</th><th>Görüntülenme</th><th>Tarih</th><th>İşlem</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($posts as $p): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></strong></td>
                                        <td><span class="cat-tag cat-<?= $p['category'] ?>"><?= $categories[$p['category']] ?? $p['category'] ?></span></td>
                                        <td><?= htmlspecialchars($p['author'] ?? '-', ENT_QUOTES) ?></td>
                                        <td><i class="fas fa-eye"></i> <?= number_format($p['views']) ?></td>
                                        <td><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                                        <td class="admin-actions">
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Yazı ve tüm yorumları silinsin mi?')">
                                                <input type="hidden" name="action" value="delete_post">
                                                <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="admin-btn admin-btn-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- ======================== YENİ YAZI EKLE ======================== -->
            <?php elseif ($tab === 'add_post'): ?>
                <div class="admin-page-header">
                    <h1><i class="fas fa-plus-circle"></i> Yeni Blog Yazısı Ekle</h1>
                </div>

                <div class="admin-card" style="max-width:760px; padding: 28px;">
                    <form method="post" class="dash-form">
                        <input type="hidden" name="action" value="add_post">
                        <div class="dash-form-group">
                            <label><i class="fas fa-heading"></i> Başlık *</label>
                            <input type="text" name="title" placeholder="Yazı başlığı" required>
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-tag"></i> Kategori</label>
                            <select name="category" class="admin-select">
                                <?php foreach ($categories as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-image"></i> Görsel URL (isteğe bağlı)</label>
                            <input type="url" name="image_url" placeholder="https://...">
                        </div>
                        <div class="dash-form-group">
                            <label><i class="fas fa-align-left"></i> İçerik *</label>
                            <textarea name="content" rows="10" placeholder="Blog yazısının içeriğini buraya girin..." required></textarea>
                        </div>
                        <button type="submit" class="auth-btn auth-btn-primary">
                            <i class="fas fa-paper-plane"></i> Yayınla
                        </button>
                    </form>
                </div>

            <!-- ======================== BEKLEYENler ======================== -->
            <?php elseif ($tab === 'pending'): ?>
                <div class="admin-page-header">
                    <h1><i class="fas fa-hourglass-half"></i> Bekleyen Kullanıcı Paylaşımları</h1>
                    <p><?= $totalPending ?> paylaşım onay bekliyor</p>
                </div>
                <?php if (empty($pendingPosts)): ?>
                    <div class="admin-card admin-empty">
                        <i class="fas fa-check-circle" style="color:#10b981"></i>
                        <p>Harika! Bekleyen paylaşım yok.</p>
                    </div>
                <?php else: ?>
                <?php foreach ($pendingPosts as $pp): ?>
                    <div class="pending-post-card">
                        <div class="pending-post-header">
                            <?php if ($pp['image_url']): ?>
                                <img src="<?= htmlspecialchars($pp['image_url'],ENT_QUOTES) ?>" alt="" class="pending-post-thumb">
                            <?php endif; ?>
                            <div class="pending-post-info">
                                <h3 class="pending-post-title"><?= htmlspecialchars($pp['title'],ENT_QUOTES) ?></h3>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                                    <span class="cat-tag cat-<?= $pp['category'] ?>"><?= $categories[$pp['category']]??$pp['category'] ?></span>
                                    <span class="status-pending-badge"><i class="fas fa-clock"></i> Bekliyor</span>
                                </div>
                                <div class="pending-post-meta">
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($pp['author']??'-',ENT_QUOTES) ?></span>
                                    <?php if ($pp['destination']): ?>
                                    <span><i class="fas fa-map-pin"></i> <?= htmlspecialchars($pp['destination'],ENT_QUOTES) ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-calendar"></i> <?= date('d M Y H:i',strtotime($pp['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <p class="pending-post-preview"><?= htmlspecialchars(mb_substr($pp['content'],0,220),ENT_QUOTES) ?>...</p>
                        <div class="pending-post-actions">
                            <form method="post" style="display:contents;">
                                <input type="hidden" name="action" value="approve_post">
                                <input type="hidden" name="post_id" value="<?= $pp['id'] ?>">
                                <button type="submit" class="pending-approve-btn">
                                    <i class="fas fa-check"></i> Onayla & Yayınla
                                </button>
                            </form>
                            <form method="post" style="display:contents;" onsubmit="return confirm('Bu paylaşım reddedilsin mi?')">
                                <input type="hidden" name="action" value="reject_post">
                                <input type="hidden" name="post_id" value="<?= $pp['id'] ?>">
                                <button type="submit" class="pending-reject-btn">
                                    <i class="fas fa-times"></i> Reddet
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

            <!-- ======================== YORUMLAR ======================== -->
            <?php elseif ($tab === 'comments'): ?>
                <div class="admin-page-header">
                    <h1><i class="fas fa-comments"></i> Yorum Yönetimi</h1>
                    <p><?= $totalComments ?> yorum</p>
                </div>

                <div class="admin-card">
                    <?php if (empty($comments)): ?>
                        <div class="admin-empty">
                            <i class="fas fa-comment-slash"></i>
                            <p>Henüz yorum bulunmuyor.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="admin-table">
                                <thead>
                                    <tr><th>Yazar</th><th>Yazı</th><th>Yorum</th><th>Durum</th><th>Tarih</th><th>İşlem</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($comments as $c): ?>
                                        <tr class="<?= !$c['approved'] ? 'row-pending' : '' ?>">
                                            <td><strong><?= htmlspecialchars($c['author_name'], ENT_QUOTES) ?></strong><br><small><?= htmlspecialchars($c['user_name'] ?? 'Misafir', ENT_QUOTES) ?></small></td>
                                            <td><?= htmlspecialchars($c['post_title'] ?? (!empty($c['place_key']) ? 'Keşfet Yeri Modalı' : '-'), ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars(mb_substr($c['content'], 0, 80), ENT_QUOTES) ?>...</td>
                                            <td>
                                                <span class="status-badge <?= $c['approved'] ? 'status-approved' : 'status-pending' ?>">
                                                    <i class="fas fa-<?= $c['approved'] ? 'check' : 'clock' ?>"></i>
                                                    <?= $c['approved'] ? 'Onaylı' : 'Bekliyor' ?>
                                                </span>
                                            </td>
                                            <td><?= date('d M H:i', strtotime($c['created_at'])) ?></td>
                                            <td class="admin-actions">
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_comment">
                                                    <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                                                    <input type="hidden" name="current_approved" value="<?= $c['approved'] ?>">
                                                    <button type="submit" class="admin-btn admin-btn-info" title="<?= $c['approved'] ? 'Gizle' : 'Onayla' ?>">
                                                        <i class="fas fa-<?= $c['approved'] ? 'eye-slash' : 'check' ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Yorum silinsin mi?')">
                                                    <input type="hidden" name="action" value="delete_comment">
                                                    <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="admin-btn admin-btn-danger"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <footer class="credits-footer admin-footer">
        <p>© 2026 GezginDer Admin Paneli — Nurettin Yavuz • Buğrahan Ergör • Duygu Bebek • İrem Zülal</p>
    </footer>
    <!-- Çıkış Onay Modali -->
    <div class="logout-overlay" id="logout-modal">
        <div class="logout-modal">
            <div class="logout-icon-ring">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h3>Çıkış Yapmak İstiyor musun?</h3>
            <p>Admin panelinden çıkış yapılacak. Tekrar giriş yapman gerekecek.</p>
            <div class="logout-modal-btns">
                <button class="logout-cancel-btn" onclick="closeLogoutModal()"><i class="fas fa-times"></i> Vazgeç</button>
                <a href="auth.php?logout=1" class="logout-confirm-btn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fas fa-sign-out-alt"></i> Çıkış Yap</a>
            </div>
        </div>
    </div>

    <script>
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

    function openLogoutModal(e) {
        if (e) e.preventDefault();
        document.getElementById('logout-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeLogoutModal() {
        document.getElementById('logout-modal').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.getElementById('logout-modal').addEventListener('click', function(e){
        if (e.target === this) closeLogoutModal();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeLogoutModal();
    });
    </script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ctx = document.getElementById('categoryChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?= $chartLabels ?>,
                    datasets: [{
                        data: <?= $chartData ?>,
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { color: '#94a3b8', font: {family:'Inter', size:12} } }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
