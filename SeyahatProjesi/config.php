<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Auto-login from remember-me cookie
if (!isset($_SESSION['user']) && isset($_COOKIE['remember_token'])) {
    $tokenDb = new PDO('sqlite:' . __DIR__ . '/travel.sqlite');
    $tokenDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        $st = $tokenDb->prepare('SELECT id, full_name, email, role FROM users WHERE remember_token = :t LIMIT 1');
        $st->execute(['t' => $_COOKIE['remember_token']]);
        $found = $st->fetch();
        if ($found) {
            $_SESSION['user'] = $found;
        }
    } catch (Throwable $e) { /* table may not exist yet */ }
}

const DB_PATH = __DIR__ . '/travel.sqlite';

global $placesData;
$placesData = [
    'istanbul' => [
        'name'=>'İstanbul, Türkiye','category'=>'sehir','cat_label'=>'Şehir',
        'image'=>'https://images.unsplash.com/photo-1524231757912-21f4fe3a7200?w=1200',
        'description'=>'İki kıtayı birbirine bağlayan eşsiz şehir İstanbul; tarihin, kültürün ve modernliğin muhteşem bir sentezini sunar. Boğaziçi\'nin her iki yakasında uzanan kadim yapılar, camileri ve paletonları ile ziyaretçilerini büyüler.',
        'highlights'=>['Ayasofya Camii','Topkapı Sarayı','Kapalıçarşı','Boğaz Turu','Sultanahmet'],
        'season'=>'İlkbahar & Sonbahar','budget'=>'₺ - ₺₺₺','duration'=>'3-5 Gün',
        'lat'=>41.0082, 'lng'=>28.9784
    ],
    'yosemite' => [
        'name'=>'Yosemite, ABD','category'=>'doga','cat_label'=>'Doğa',
        'image'=>'https://images.unsplash.com/photo-1506744038136-46273834b3fb?w=1200',
        'description'=>'Kayalık dağlar, yüksek şelaleler ve sonsuz ormanlarla çevrili Yosemite, dünyanın en muhteşem milli parklarından biridir. El Capitan\'ın devasa granit duvarları ve Half Dome\'un ikonik silueti burada sizi bekliyor.',
        'highlights'=>['El Capitan','Half Dome','Yosemite Şelalesi','Glacier Point','Mariposa Grove'],
        'season'=>'Yaz (Haziran-Ağustos)','budget'=>'$$','duration'=>'4-7 Gün',
        'lat'=>37.8651, 'lng'=>-119.5383
    ],
    'paris' => [
        'name'=>'Paris, Fransa','category'=>'ulke','cat_label'=>'Ülke Turu',
        'image'=>'https://images.unsplash.com/photo-1431274172761-fca41d930114?w=1200',
        'description'=>'Işık Şehri Paris; Eyfel Kulesi\'nin romantik silueti, dünya sınıfı müzeleri, geniş bulvarları ve eşsiz mutfağıyla her mevsim büyüleyici. Sanat, moda ve gastronomi tutkunları için vazgeçilmez bir destinasyon.',
        'highlights'=>['Eyfel Kulesi','Louvre Müzesi','Montmartre','Champs-Élysées','Notre-Dame'],
        'season'=>'İlkbahar & Sonbahar','budget'=>'€€€','duration'=>'4-6 Gün',
        'lat'=>48.8566, 'lng'=>2.3522
    ],
    'efes' => [
        'name'=>'Efes Antik Kenti, İzmir','category'=>'tarih','cat_label'=>'Tarihi Yer',
        'image'=>'https://images.unsplash.com/photo-1539650116574-8efeb43e2750?w=1200',
        'description'=>'M.Ö. 10. yüzyılda kurulan Efes, antik dünyanın en büyük şehirlerinden biri olarak tarihe damga vurmuştur. UNESCO Dünya Mirası listesindeki bu alan; kütüphanesi, tiyatrosu ve tapınağıyla büyüler.',
        'highlights'=>['Celsus Kütüphanesi','Büyük Tiyatro','Artemis Tapınağı','Yamaç Evler','Meryem Ana Evi'],
        'season'=>'İlkbahar & Sonbahar','budget'=>'₺₺','duration'=>'1-2 Gün',
        'lat'=>37.9404, 'lng'=>27.3415
    ],
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Users table
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "user",
        avatar TEXT DEFAULT NULL,
        bio TEXT DEFAULT NULL,
        remember_token TEXT DEFAULT NULL,
        created_at TEXT NOT NULL
    )');

    // Add remember_token column if upgrading existing DB
    try { $pdo->exec('ALTER TABLE users ADD COLUMN remember_token TEXT DEFAULT NULL'); } catch (Throwable $e) {}

    // Blog posts table
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        content TEXT NOT NULL,
        image_url TEXT DEFAULT NULL,
        category TEXT NOT NULL DEFAULT "genel",
        destination TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT "approved",
        author_id INTEGER NOT NULL,
        views INTEGER DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    try { $pdo->exec('ALTER TABLE blog_posts ADD COLUMN status TEXT NOT NULL DEFAULT "approved"'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE blog_posts ADD COLUMN destination TEXT DEFAULT NULL'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE blog_posts ADD COLUMN highlights TEXT DEFAULT NULL'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE blog_posts ADD COLUMN season TEXT DEFAULT NULL'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE blog_posts ADD COLUMN duration TEXT DEFAULT NULL'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE blog_posts ADD COLUMN budget TEXT DEFAULT NULL'); } catch (Throwable $e) {}

    // Comments table
    $pdo->exec('CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER DEFAULT NULL,
        place_key TEXT DEFAULT NULL,
        user_id INTEGER,
        author_name TEXT NOT NULL,
        content TEXT NOT NULL,
        approved INTEGER DEFAULT 1,
        created_at TEXT NOT NULL
    )');
    try { $pdo->exec('ALTER TABLE comments ADD COLUMN place_key TEXT DEFAULT NULL'); } catch (Throwable $e) {}

    // Auto-migration: post_id NULL kontrolü (eski DB şemasını düzelt)
    $cols = $pdo->query("PRAGMA table_info(comments)")->fetchAll();
    $postIdCol = array_filter($cols, fn($c) => $c['name'] === 'post_id');
    $postIdCol = reset($postIdCol);
    if ($postIdCol && (int)$postIdCol['notnull'] === 1) {
        // Eski şema: post_id NOT NULL — tabloyu yeniden yap
        $pdo->exec('ALTER TABLE comments RENAME TO _comments_old');
        $pdo->exec('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER DEFAULT NULL,
            place_key TEXT DEFAULT NULL,
            user_id INTEGER,
            author_name TEXT NOT NULL,
            content TEXT NOT NULL,
            approved INTEGER DEFAULT 1,
            created_at TEXT NOT NULL
        )');
        $pdo->exec('INSERT INTO comments SELECT * FROM _comments_old');
        $pdo->exec('DROP TABLE _comments_old');
    }


    // Place ratings table
    $pdo->exec('CREATE TABLE IF NOT EXISTS place_ratings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        place_key TEXT NOT NULL,
        user_id INTEGER,
        rating INTEGER NOT NULL,
        created_at TEXT NOT NULL
    )');

    // Blog ratings table
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_ratings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        user_id INTEGER,
        rating INTEGER NOT NULL,
        created_at TEXT NOT NULL
    )');

    // Favorites table
    $pdo->exec('CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        place_key TEXT NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE(user_id, place_key)
    )');

    // Seed admin user
    $adminEmail = 'admin@gezginder.com';
    $adminPassword = '12345';
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $adminEmail]);
    if (!$check->fetch()) {
        $insert = $pdo->prepare('
            INSERT INTO users (full_name, email, password_hash, role, created_at)
            VALUES (:full_name, :email, :password_hash, :role, :created_at)
        ');
        $insert->execute([
            'full_name'     => 'Sistem Admin',
            'email'         => $adminEmail,
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'role'          => 'admin',
            'created_at'    => date('Y-m-d H:i:s')
        ]);
    }

    // Seed sample blog posts
    $checkPost = $pdo->query('SELECT COUNT(*) as cnt FROM blog_posts')->fetch();
    if ((int)$checkPost['cnt'] === 0) {
        $adminId = $pdo->query('SELECT id FROM users WHERE role="admin" LIMIT 1')->fetch()['id'];
        $posts = [
            [
                'title'     => "Ege'nin Gizli Köyleri: Bir Hafta Sonu Kaçamağı",
                'slug'      => 'ege-gizli-koyleri',
                'content'   => "Ege kıyıları sadece deniz ve kumdan ibaret değil. Zeytin ağaçları arasında kaybolmuş, taş evleriyle büyüleyen köyler sizi bekliyor. Şirince, Birgi ve Tire gibi saklı cennetleri keşfedin.",
                'image_url' => 'https://images.unsplash.com/photo-1516483638261-f4dbaf036963?w=800',
                'category'  => 'doga',
            ],
            [
                'title'     => "İstanbul'da 48 Saat: Tarihi Yarımada Rotası",
                'slug'      => 'istanbul-48-saat',
                'content'   => "Ayasofya'dan Kapalıçarşı'ya, Boğaz vapurlarından Balık-ekmek rıhtımına kadar İstanbul'u 48 saatte keşfedin. Tarihi yarımadanın büyüsüne kapılmadan yapamazsınız.",
                'image_url' => 'https://images.unsplash.com/photo-1524231757912-21f4fe3a7200?w=800',
                'category'  => 'sehir',
            ],
            [
                'title'     => "Kapadokya: Balonların Diyarında Bir Gün",
                'slug'      => 'kapadokya-balon-turu',
                'content'   => "Peri bacaları, yeraltı şehirleri ve sonsuz vadileriyle Kapadokya, dünyada eşi olmayan bir coğrafya. Şafakta balon turu, Göreme'nin büyüsüne ortak olmanın en güzel yolu.",
                'image_url' => 'https://images.unsplash.com/photo-1641128324972-af3212f0f6bd?w=800',
                'category'  => 'tarih',
            ],
        ];
        $ins = $pdo->prepare('INSERT INTO blog_posts (title,slug,content,image_url,category,author_id,created_at,updated_at) VALUES (:title,:slug,:content,:image_url,:category,:author_id,:now,:now)');
        foreach ($posts as $p) {
            $ins->execute(array_merge($p, ['author_id' => $adminId, 'now' => date('Y-m-d H:i:s')]));
        }
    }

    return $pdo;
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void
{
    if (!currentUser()) {
        header('Location: auth.php');
        exit;
    }
}

function setRememberMe(PDO $pdo, int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET remember_token = :t WHERE id = :id')
        ->execute(['t' => $token, 'id' => $userId]);
    setcookie('remember_token', $token, [
        'expires'  => time() + 60 * 60 * 24 * 30, // 30 gün
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearRememberMe(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET remember_token = NULL WHERE id = :id')->execute(['id' => $userId]);
    setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/']);
}

function requireAdmin(): void
{
    $user = currentUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: auth.php?next=admin');
        exit;
    }
}
