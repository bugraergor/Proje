<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';
$pdo    = db();

function jsonOut(bool $ok, string $message, array $extra = []): never {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

// ── Giriş Yap ─────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = mb_strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    if (!$email || !$password) jsonOut(false, 'E-posta ve şifre zorunludur.');
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) jsonOut(false, '❌ E-posta veya şifre hatalı.');
    unset($user['password_hash']);
    $_SESSION['user'] = $user;
    setRememberMe($pdo, (int)$user['id']);
    jsonOut(true, '✅ Hoş geldin, ' . $user['full_name'] . '!', ['role' => $user['role']]);
}

// ── Kayıt Ol ──────────────────────────────────────────────────────────
if ($action === 'register') {
    $fullName = trim($body['full_name'] ?? '');
    $email    = mb_strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    if (!$fullName || !$email || !$password) jsonOut(false, 'Tüm alanlar zorunludur.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(false, 'Geçerli bir e-posta adresi gir.');
    if (strlen($password) < 5) jsonOut(false, 'Şifre en az 5 karakter olmalı.');
    try {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (:fn, :em, :ph, "user", :now)');
        $stmt->execute(['fn'=>$fullName,'em'=>$email,'ph'=>password_hash($password,PASSWORD_DEFAULT),'now'=>date('Y-m-d H:i:s')]);
        $newId = (int)$pdo->lastInsertId();
        $_SESSION['user'] = ['id'=>$newId,'full_name'=>$fullName,'email'=>$email,'role'=>'user'];
        setRememberMe($pdo, $newId);
        jsonOut(true, '✅ Hesabın oluşturuldu ve giriş yapıldı!');
    } catch (Throwable $e) { jsonOut(false, 'Bu e-posta zaten kayıtlı.'); }
}

// ── Çıkış ─────────────────────────────────────────────────────────────
if ($action === 'logout') {
    $u = currentUser();
    if ($u) clearRememberMe($pdo, (int)$u['id']);
    session_destroy();
    jsonOut(true, 'Çıkış yapıldı.');
}

// ── Yer Verisi (yorumlar + kullanıcı puanı) ──────────────────────────
if ($action === 'get_place_data') {
    $placeKey = trim($body['place_key'] ?? '');
    if (!$placeKey) jsonOut(false, 'place_key gerekli.');

    // Yorumlar
    $comments = $pdo->prepare('SELECT c.id, c.author_name, c.content, c.created_at, u.full_name
        FROM comments c LEFT JOIN users u ON u.id = c.user_id
        WHERE c.place_key = :k AND c.approved = 1
        ORDER BY c.created_at DESC LIMIT 20');
    $comments->execute(['k' => $placeKey]);
    $commentList = $comments->fetchAll();

    // Ortalama puan
    $avgStmt = $pdo->prepare('SELECT AVG(rating) as avg, COUNT(*) as cnt FROM place_ratings WHERE place_key = :k');
    $avgStmt->execute(['k' => $placeKey]);
    $avgData = $avgStmt->fetch();

    // Kullanıcının kendi puanı
    $userRating = null;
    $cu = currentUser();
    if ($cu) {
        $ur = $pdo->prepare('SELECT rating FROM place_ratings WHERE place_key = :k AND user_id = :u LIMIT 1');
        $ur->execute(['k' => $placeKey, 'u' => $cu['id']]);
        $userRating = $ur->fetchColumn();
    }

    jsonOut(true, 'OK', [
        'comments'   => $commentList,
        'avg_rating' => $avgData['avg'] ? round((float)$avgData['avg'], 1) : null,
        'rating_cnt' => (int)$avgData['cnt'],
        'user_rating'=> $userRating ? (int)$userRating : null,
    ]);
}

// ── Yer Yıldız Puanla ─────────────────────────────────────────────────
if ($action === 'rate_place') {
    $cu = currentUser();
    if (!$cu) jsonOut(false, '❌ Giriş yapmalısın.');
    $placeKey = trim($body['place_key'] ?? '');
    $rating   = (int)($body['rating'] ?? 0);
    if (!$placeKey || $rating < 1 || $rating > 5) jsonOut(false, 'Geçersiz veri.');

    // Upsert
    $existing = $pdo->prepare('SELECT id FROM place_ratings WHERE place_key=:k AND user_id=:u LIMIT 1');
    $existing->execute(['k'=>$placeKey,'u'=>$cu['id']]);
    if ($existing->fetch()) {
        $pdo->prepare('UPDATE place_ratings SET rating=:r WHERE place_key=:k AND user_id=:u')
            ->execute(['r'=>$rating,'k'=>$placeKey,'u'=>$cu['id']]);
    } else {
        $pdo->prepare('INSERT INTO place_ratings (place_key,user_id,rating,created_at) VALUES (:k,:u,:r,:now)')
            ->execute(['k'=>$placeKey,'u'=>$cu['id'],'r'=>$rating,'now'=>date('Y-m-d H:i:s')]);
    }

    $avgStmt = $pdo->prepare('SELECT AVG(rating) as avg, COUNT(*) as cnt FROM place_ratings WHERE place_key=:k');
    $avgStmt->execute(['k'=>$placeKey]);
    $avgData = $avgStmt->fetch();
    jsonOut(true, '✅ Puanın kaydedildi!', [
        'avg_rating' => round((float)$avgData['avg'], 1),
        'rating_cnt' => (int)$avgData['cnt'],
    ]);
}

// ── Yer Yorum Ekle ────────────────────────────────────────────────────
if ($action === 'add_place_comment') {
    $cu = currentUser();
    if (!$cu) jsonOut(false, '❌ Giriş yapmalısın.');
    $placeKey = trim($body['place_key'] ?? '');
    $content  = trim($body['content'] ?? '');
    if (!$placeKey || !$content) jsonOut(false, 'Yer ve içerik zorunlu.');
    if (mb_strlen($content) < 5) jsonOut(false, 'Yorum çok kısa.');

    $pdo->prepare('INSERT INTO comments (place_key,user_id,author_name,content,approved,created_at) VALUES (:pk,:uid,:name,:content,1,:now)')
        ->execute(['pk'=>$placeKey,'uid'=>$cu['id'],'name'=>$cu['full_name'],'content'=>$content,'now'=>date('Y-m-d H:i:s')]);

    jsonOut(true, '✅ Yorumun eklendi!', [
        'comment' => [
            'author_name' => $cu['full_name'],
            'content'     => $content,
            'created_at'  => date('Y-m-d H:i:s'),
        ]
    ]);
}

// ── Blog Yıldız Puanla ────────────────────────────────────────────────
if ($action === 'rate_blog') {
    $cu = currentUser();
    if (!$cu) jsonOut(false, '❌ Giriş yapmalısın.');
    $postId = (int)($body['post_id'] ?? 0);
    $rating = (int)($body['rating'] ?? 0);
    if (!$postId || $rating < 1 || $rating > 5) jsonOut(false, 'Geçersiz veri.');
    $existing = $pdo->prepare('SELECT id FROM blog_ratings WHERE post_id=:p AND user_id=:u LIMIT 1');
    $existing->execute(['p'=>$postId,'u'=>$cu['id']]);
    if ($existing->fetch()) {
        $pdo->prepare('UPDATE blog_ratings SET rating=:r WHERE post_id=:p AND user_id=:u')
            ->execute(['r'=>$rating,'p'=>$postId,'u'=>$cu['id']]);
    } else {
        $pdo->prepare('INSERT INTO blog_ratings (post_id,user_id,rating,created_at) VALUES (:p,:u,:r,:now)')
            ->execute(['p'=>$postId,'u'=>$cu['id'],'r'=>$rating,'now'=>date('Y-m-d H:i:s')]);
    }
    jsonOut(true, '✅ Puanın kaydedildi!');
}

// ── Blog Yorum Ekle ───────────────────────────────────────────────────
if ($action === 'add_blog_comment') {
    $cu = currentUser();
    if (!$cu) jsonOut(false, '❌ Giriş yapmalısın.');
    $postId  = (int)($body['post_id'] ?? 0);
    $content = trim($body['content'] ?? '');
    if (!$postId || !$content) jsonOut(false, 'Veri eksik.');
    if (mb_strlen($content) < 5) jsonOut(false, 'Yorum çok kısa.');
    $pdo->prepare('INSERT INTO comments (post_id,user_id,author_name,content,approved,created_at) VALUES (:pid,:uid,:name,:content,1,:now)')
        ->execute(['pid'=>$postId,'uid'=>$cu['id'],'name'=>$cu['full_name'],'content'=>$content,'now'=>date('Y-m-d H:i:s')]);
    jsonOut(true, '✅ Yorumun eklendi!', [
        'comment' => ['author_name'=>$cu['full_name'],'content'=>$content,'created_at'=>date('Y-m-d H:i:s')]
    ]);
}

// ── Kullanıcı Paylaşım Gönder (Onay Bekler) ──────────────────────────
if ($action === 'submit_post') {
    $cu = currentUser();
    if (!$cu) jsonOut(false, '❌ Giriş yapmalısın.');
    $title       = trim($body['title'] ?? '');
    $destination = trim($body['destination'] ?? '');
    $category    = trim($body['category'] ?? 'genel');
    $content     = trim($body['content'] ?? '');
    $image_url   = trim($body['image_url'] ?? '');
    $highlights  = trim($body['highlights'] ?? '');   // virgülle ayrılmış
    $season      = trim($body['season'] ?? '');
    $duration    = trim($body['duration'] ?? '');
    $budget      = trim($body['budget'] ?? '');

    if (!$title || !$destination || !$content) jsonOut(false, 'Başlık, destinasyon ve içerik zorunludur.');
    if (mb_strlen($content) < 30) jsonOut(false, 'İçerik en az 30 karakter olmalı.');
    if (!in_array($category, ['sehir','ulke','doga','tarih','genel'])) $category = 'genel';

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8','ASCII//TRANSLIT',$title)));
    $slug = trim($slug,'-') . '-' . time();

    try {
        $pdo->prepare('INSERT INTO blog_posts (title,slug,content,image_url,category,destination,highlights,season,duration,budget,status,author_id,created_at,updated_at) VALUES (:t,:s,:c,:i,:cat,:dest,:hi,:sea,:dur,:bud,"pending",:aid,:now,:now)')
            ->execute(['t'=>$title,'s'=>$slug,'c'=>$content,'i'=>$image_url,'cat'=>$category,'dest'=>$destination,'hi'=>$highlights,'sea'=>$season,'dur'=>$duration,'bud'=>$budget,'aid'=>$cu['id'],'now'=>date('Y-m-d H:i:s')]);
        jsonOut(true, '✅ Paylaşımın admin onayına gönderildi!');
    } catch (Throwable $e) {
        jsonOut(false, 'Hata oluştu: ' . $e->getMessage());
    }
}

// ── Admin: Bekleyen Paylaşımlar ───────────────────────────────────────
if ($action === 'get_pending_posts') {
    $cu = currentUser();
    if (!$cu || $cu['role'] !== 'admin') jsonOut(false, 'Yetkisiz.');
    $posts = $pdo->query('SELECT p.*, u.full_name as author FROM blog_posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.status="pending" ORDER BY p.created_at DESC')->fetchAll();
    jsonOut(true, 'OK', ['posts' => $posts]);
}

// ── Admin: Paylaşım Onayla/Reddet ─────────────────────────────────────
if ($action === 'review_post') {
    $cu = currentUser();
    if (!$cu || $cu['role'] !== 'admin') jsonOut(false, 'Yetkisiz.');
    $postId = (int)($body['post_id'] ?? 0);
    $status = $body['status'] ?? ''; // 'approved' veya 'rejected'
    if (!$postId || !in_array($status, ['approved','rejected'])) jsonOut(false, 'Geçersiz veri.');
    $pdo->prepare('UPDATE blog_posts SET status=:s, updated_at=:now WHERE id=:id')
        ->execute(['s'=>$status,'id'=>$postId,'now'=>date('Y-m-d H:i:s')]);
jsonOut(true, $status === 'approved' ? '✅ Paylaşım yayınlandı!' : '🗑 Paylaşım reddedildi.');
}

// ── Favori Ekle/Kaldır ────────────────────────────────────────────────
if ($action === 'toggle_favorite') {
    $cu = currentUser();
    if (!$cu) jsonOut(false, 'Lütfen giriş yapın.');
    $key = trim($body['place_key'] ?? '');
    if (!$key) jsonOut(false, 'Gezilecek yer belirtilmedi.');
    
    // Zaten favorilerde var mı?
    $chk = $pdo->prepare('SELECT id FROM favorites WHERE user_id=:u AND place_key=:p');
    $chk->execute(['u'=>$cu['id'],'p'=>$key]);
    
    if ($chk->fetch()) {
        $pdo->prepare('DELETE FROM favorites WHERE user_id=:u AND place_key=:p')->execute(['u'=>$cu['id'],'p'=>$key]);
        jsonOut(true,'Favorilerden çıkarıldı.',['state'=>'removed']);
    } else {
        $pdo->prepare('INSERT INTO favorites (user_id,place_key,created_at) VALUES (:u,:p,:now)')->execute(['u'=>$cu['id'],'p'=>$key,'now'=>date('Y-m-d H:i:s')]);
        jsonOut(true,'Favorilere eklendi!',['state'=>'added']);
    }
}

jsonOut(false, 'Geçersiz işlem.');
