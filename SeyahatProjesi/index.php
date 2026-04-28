<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$pdo        = db();
$activeUser = currentUser();
$isLoggedIn = $activeUser !== null;
$isAdmin    = $isLoggedIn && ($activeUser['role'] ?? '') === 'admin';
$userJson   = $isLoggedIn
    ? json_encode(['name'=>$activeUser['full_name'],'id'=>(int)$activeUser['id'],'role'=>$activeUser['role']])
    : 'null';

// Gerçek puanları veritabanından çek
$realRatings = [];
foreach (array_keys($placesData) as $key) {
    $rStmt = $pdo->prepare('SELECT AVG(rating) as avg, COUNT(*) as cnt FROM place_ratings WHERE place_key = :k');
    $rStmt->execute(['k' => $key]);
    $row = $rStmt->fetch();
    $realRatings[$key] = [
        'avg' => $row['avg'] ? round((float)$row['avg'], 1) : null,
        'cnt' => (int)$row['cnt'],
    ];
    // JS'e gönderilecek veriyi de ekle
    $placesData[$key]['avg_rating'] = $realRatings[$key]['avg'];
    $placesData[$key]['rating_cnt'] = $realRatings[$key]['cnt'];
}

// Onaylanmış kullanıcı paylaşımlarını da çek (keşfet bölümü için)
$approvedUserPosts = $pdo->prepare(
    'SELECT p.*, u.full_name as author_name FROM blog_posts p
     LEFT JOIN users u ON u.id = p.author_id
     WHERE p.status = "approved"
     ORDER BY p.created_at DESC'
);
$approvedUserPosts->execute();
$approvedUserPostsList = $approvedUserPosts->fetchAll();

$catLabels = ['sehir'=>'Şehir','ulke'=>'Ülke Turu','doga'=>'Doğa','tarih'=>'Tarihi Yer','genel'=>'Genel'];

// Favorileri Çek
$userFavs = [];
if ($isLoggedIn) {
    try {
        $fst = $pdo->prepare('SELECT place_key FROM favorites WHERE user_id = :u');
        $fst->execute(['u' => $activeUser['id']]);
        $userFavs = $fst->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="tr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="En popüler gezi rotaları ve seyahat rehberi — GezginDer.">
    <title>Gezgin Rotaları | Dünyayı Keşfet</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Tema flaşı önle -->
    <script>(function(){var t=localStorage.getItem('gezginder-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
<!-- ======================== NAVBAR ======================== -->
<header class="site-header">
    <nav class="navbar">
        <a href="index.php" class="logo" style="text-decoration:none;">GEZGÎN<span>DER</span></a>
        <ul class="nav-links">
            <li><a href="#hero">Ana Sayfa</a></li>
            <li><a href="#kesfet">Keşfet</a></li>
            <li><a href="#blog">Blog</a></li>
            <li><a href="#harita">Harita</a></li>
            <!-- Tema Geçişi -->
            <li>
                <button class="theme-toggle" id="theme-toggle-btn" title="Temayı değiştir" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
            </li>
            <?php if ($isLoggedIn): ?>
                <li>
                    <a href="dashboard.php" style="display:flex;align-items:center;gap:6px;">
                        <span class="nav-avatar"><?= mb_strtoupper(mb_substr($activeUser['full_name'],0,1)) ?></span>
                        <?= htmlspecialchars($activeUser['full_name'],ENT_QUOTES) ?>
                    </a>
                </li>
                <li><a href="#" class="nav-logout-btn" onclick="openLogoutModal(event)"><i class="fas fa-sign-out-alt"></i> Çıkış</a></li>
            <?php else: ?>
                <li>
                    <button class="nav-login-btn" onclick="openLoginModal()">
                        <i class="fas fa-user"></i> Giriş Yap
                    </button>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<!-- ======================== LOGIN MODAL ======================== -->
<?php if (!$isLoggedIn): ?>
<div id="login-modal" class="modal-overlay" onclick="if(event.target===this)closeLoginModal()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeLoginModal()"><i class="fas fa-times"></i></button>
        <div class="modal-hero">
            <i class="fas fa-map-marked-alt"></i>
            <h2>GezginDer'e Hoş Geldin</h2>
            <p>Yorum yapmak ve puan vermek için giriş yap.</p>
        </div>
        <div class="modal-tabs">
            <button class="modal-tab active" id="mtab-login" onclick="switchModalTab('login',this)"><i class="fas fa-sign-in-alt"></i> Giriş</button>
            <button class="modal-tab" id="mtab-register" onclick="switchModalTab('register',this)"><i class="fas fa-user-plus"></i> Kayıt Ol</button>
        </div>
        <div id="modal-message" class="modal-msg hidden"></div>
        <div id="mpanel-login" class="modal-panel">
            <form id="modal-login-form" class="modal-form">
                <div class="modal-input-group"><i class="fas fa-envelope"></i><input type="email" id="ml-email" placeholder="E-posta" required></div>
                <div class="modal-input-group"><i class="fas fa-lock"></i><input type="password" id="ml-pass" placeholder="Şifre" required><button type="button" onclick="toggleModalPass(this)"><i class="fas fa-eye"></i></button></div>
                <button type="submit" class="modal-submit-btn"><i class="fas fa-arrow-right"></i> Giriş Yap</button>
            </form>
        </div>
        <div id="mpanel-register" class="modal-panel hidden">
            <form id="modal-register-form" class="modal-form">
                <div class="modal-input-group"><i class="fas fa-user"></i><input type="text" id="mr-name" placeholder="Ad Soyad" required></div>
                <div class="modal-input-group"><i class="fas fa-envelope"></i><input type="email" id="mr-email" placeholder="E-posta" required></div>
                <div class="modal-input-group"><i class="fas fa-lock"></i><input type="password" id="mr-pass" placeholder="Şifre (min. 5)" required><button type="button" onclick="toggleModalPass(this)"><i class="fas fa-eye"></i></button></div>
                <button type="submit" class="modal-submit-btn"><i class="fas fa-user-plus"></i> Hesap Oluştur</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================== YER DETAY MODALI ======================== -->
<div id="place-modal" class="place-modal-overlay" onclick="if(event.target===this)closePlaceModal()">
    <div class="place-modal-box">
        <button class="modal-close pm-close" onclick="closePlaceModal()"><i class="fas fa-times"></i></button>
        <div class="pm-image-wrap">
            <img id="pm-img" src="" alt="">
            <div class="pm-img-overlay">
                <span class="pm-badge" id="pm-badge"></span>
            </div>
        </div>
        <div class="pm-body">
            <h2 id="pm-name"></h2>
            <div class="pm-stats-row">
                <div class="pm-stat"><i class="fas fa-star"></i> <span id="pm-avg-rating">-</span> <small id="pm-rating-cnt"></small></div>
                <div class="pm-stat"><i class="fas fa-calendar-alt"></i> <span id="pm-season"></span></div>
                <div class="pm-stat"><i class="fas fa-clock"></i> <span id="pm-duration"></span></div>
                <div class="pm-stat"><i class="fas fa-wallet"></i> <span id="pm-budget"></span></div>
            </div>
            <p id="pm-description" class="pm-desc"></p>
            <div class="pm-highlights-box">
                <h4><i class="fas fa-map-pin"></i> Gezilecek Yerler</h4>
                <div id="pm-highlights" class="pm-tags"></div>
            </div>
            <!-- Kullanıcı Puanı -->
            <div class="pm-rate-box">
                <h4><i class="fas fa-star"></i> Puanını Ver</h4>
                <div id="pm-star-input" class="pm-stars-wrap"></div>
                <small id="pm-rate-msg"></small>
            </div>
            <!-- Yorumlar -->
            <div class="pm-comments-box">
                <h4><i class="fas fa-comments"></i> Yorumlar <span id="pm-comment-count" class="pm-cnt-badge"></span></h4>
                <div id="pm-comment-list"></div>
                <div id="pm-comment-form-area"></div>
            </div>
        </div>
    </div>
</div>

<!-- ======================== ANA İÇERİK ======================== -->
<main>
    <section id="hero">
        <h1>Hayalindeki Rotayı Bul</h1>
        <p>Şehirler, doğa harikaları ve tarihi mekanlar seni bekliyor.</p>
        <?php if (!$isLoggedIn): ?>
            <button class="hero-login-btn" onclick="openLoginModal()"><i class="fas fa-user"></i> Ücretsiz Üye Ol</button>
        <?php else: ?>
            <a href="dashboard.php" class="hero-login-btn"><i class="fas fa-tachometer-alt"></i> Panelime Git</a>
        <?php endif; ?>
    </section>

    <section id="kesfet">
        <div id="filter-container">
            <div class="filter-buttons">
                <button class="btn-filter active" data-target="hepsi">Hepsi</button>
                <button class="btn-filter" data-target="sehir">Şehir</button>
                <button class="btn-filter" data-target="ulke">Ülke</button>
                <button class="btn-filter" data-target="doga">Doğa</button>
                <button class="btn-filter" data-target="tarih">Tarihi Yerler</button>
            </div>
            <div class="explore-tools">
                <input type="search" id="place-search" placeholder="Yer ara... (örn. Paris)">
                <select id="sort-places" aria-label="Yerleri sırala">
                    <option value="default">Sırala: Varsayılan</option>
                    <option value="asc">Puan: Düşükten yükseğe</option>
                    <option value="desc">Puan: Yüksekten düşüğe</option>
                </select>
            </div>
        </div>

        <section class="gallery">
            <?php foreach ($placesData as $key => $p): ?>
            <div class="card reveal" data-category="<?= $p['category'] ?>" data-place-key="<?= $key ?>">
                <div class="card-img" onclick="openPlaceModal('<?= $key ?>')">
                    <?php if ($isLoggedIn): ?>
                        <button class="btn-fav <?= in_array($key, $userFavs) ? 'favorited' : '' ?>" onclick="event.stopPropagation(); toggleFav('<?= $key ?>', this)" title="Favorilere Ekle/Çıkar">
                            <i class="<?= in_array($key, $userFavs) ? 'fas' : 'far' ?> fa-heart"></i>
                        </button>
                    <?php endif; ?>
                    <img src="<?= str_replace('w=1200','w=500',$p['image']) ?>" alt="<?= htmlspecialchars($p['name'],ENT_QUOTES) ?>" loading="lazy">
                    <div class="overlay"><i class="fas fa-search-plus"></i> İncele</div>
                </div>
                <div class="card-info">
                    <h3><?= htmlspecialchars($p['name'],ENT_QUOTES) ?></h3>
                    <div class="star-rating" id="stars-<?= $key ?>">
                        <?php
                        $r   = $realRatings[$key]['avg'];  // null if no votes
                        $cnt = $realRatings[$key]['cnt'];
                        if ($r === null) {
                            // Hiç oy yok: 5 boş yıldız
                            for ($i=1;$i<=5;$i++) echo '<i class="far fa-star"></i>';
                            echo '<span>Henüz puan yok</span>';
                        } else {
                            for ($i=1;$i<=5;$i++) {
                                if ($r >= $i) echo '<i class="fas fa-star"></i>';
                                elseif ($r >= $i-0.5) echo '<i class="fas fa-star-half-alt"></i>';
                                else echo '<i class="far fa-star"></i>';
                            }
                            echo '<span>(' . $r . ' / ' . $cnt . ' oy)</span>';
                        }
                        ?>
                    </div>
                    <div class="place-rating" data-place="<?= $key ?>">
                        <span>Senin puanın:</span>
                        <div class="place-stars">
                            <?php for ($v=5;$v>=1;$v--): ?>
                                <input type="radio" name="place-<?= $key ?>" id="place-<?= $key ?>-<?= $v ?>" value="<?= $v ?>">
                                <label for="place-<?= $key ?>-<?= $v ?>">★</label>
                            <?php endfor; ?>
                        </div>
                        <small class="place-rating-result">Yükleniyor...</small>
                    </div>
                    <button class="btn-incele" onclick="openPlaceModal('<?= $key ?>')">
                        <i class="fas fa-info-circle"></i> Detayları Gör
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

            <?php foreach ($approvedUserPostsList as $up):
                $upKey  = 'db_post_' . $up['id'];
                $upCat  = $up['category'] ?? 'genel';
                $upImg  = $up['image_url'] ?: 'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=500';
                $upHls  = $up['highlights'] ? array_map('trim', explode(',', $up['highlights'])) : [];
                $upAvg  = null; $upCnt = 0;
                $uRs = $pdo->prepare('SELECT AVG(rating) as avg, COUNT(*) as cnt FROM place_ratings WHERE place_key=:k');
                $uRs->execute(['k' => $upKey]);
                $uRRow = $uRs->fetch();
                if ($uRRow['avg']) { $upAvg = round((float)$uRRow['avg'],1); $upCnt = (int)$uRRow['cnt']; }
                // JS'e gönderilecek veri
                $placesData[$upKey] = [
                    'name'        => htmlspecialchars($up['title'],ENT_QUOTES),
                    'category'    => $upCat,
                    'cat_label'   => $catLabels[$upCat] ?? 'Genel',
                    'image'       => $upImg,
                    'description' => $up['content'],
                    'highlights'  => $upHls,
                    'season'      => $up['season'] ?? '',
                    'duration'    => $up['duration'] ?? '',
                    'budget'      => $up['budget'] ?? '',
                    'avg_rating'  => $upAvg,
                    'rating_cnt'  => $upCnt,
                    'author'      => $up['author_name'],
                    'destination' => $up['destination'],
                ];
            ?>
            <div class="card db-place" data-category="<?= $upCat ?>" data-place-key="<?= $upKey ?>">
                <div class="card-img" onclick="openPlaceModal('<?= $upKey ?>')">
                    <img src="<?= htmlspecialchars($upImg,ENT_QUOTES) ?>" alt="<?= htmlspecialchars($up['title'],ENT_QUOTES) ?>" loading="lazy">
                    <div class="overlay"><i class="fas fa-search-plus"></i> İncele</div>
                </div>
                <div class="card-info" style="position:relative;">
                    <span class="user-post-badge"><i class="fas fa-user"></i> Kullanıcı</span>
                    <h3><?= htmlspecialchars($up['title'],ENT_QUOTES) ?></h3>
                    <?php if ($up['destination']): ?>
                        <small style="color:var(--ikincil);display:block;margin-bottom:6px;"><i class="fas fa-map-pin"></i> <?= htmlspecialchars($up['destination'],ENT_QUOTES) ?></small>
                    <?php endif; ?>
                    <div class="star-rating" id="stars-<?= $upKey ?>">
                        <?php if ($upAvg === null): ?>
                            <?php for ($i=1;$i<=5;$i++) echo '<i class="far fa-star"></i>'; ?>
                            <span>Henüz puan yok</span>
                        <?php else: ?>
                            <?php for ($i=1;$i<=5;$i++) {
                                if ($upAvg >= $i) echo '<i class="fas fa-star"></i>';
                                elseif ($upAvg >= $i-0.5) echo '<i class="fas fa-star-half-alt"></i>';
                                else echo '<i class="far fa-star"></i>';
                            } ?>
                            <span>(<?= $upAvg ?> / <?= $upCnt ?> oy)</span>
                        <?php endif; ?>
                    </div>
                    <div class="place-rating" data-place="<?= $upKey ?>">
                        <span>Senin puanın:</span>
                        <div class="place-stars">
                            <?php for ($v=5;$v>=1;$v--): ?>
                                <input type="radio" name="place-<?= $upKey ?>" id="place-<?= $upKey ?>-<?= $v ?>" value="<?= $v ?>">
                                <label for="place-<?= $upKey ?>-<?= $v ?>">★</label>
                            <?php endfor; ?>
                        </div>
                        <small class="place-rating-result">Yükleniyor...</small>
                    </div>
                    <button class="btn-incele" onclick="openPlaceModal('<?= $upKey ?>')">
                        <i class="fas fa-info-circle"></i> Detayları Gör
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
    </section>

    <section id="harita" class="map-section">
        <h2>Harita Üzerinde Keşfet</h2>
        <p>Öne çıkan rotaları harita üzerinden inceleyebilirsin.</p>
        <div id="interactive-map" style="height: 450px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 1; margin: 20px auto; width: 90%; max-width: 1200px;"></div>
    </section>

    <section id="blog" class="blog-container">
        <hr>
        <article class="blog-post">
            <header class="blog-header">
                <span class="blog-date">27 Mart 2026</span>
                <h2>Ege'nin Gizli Köyleri: Bir Hafta Sonu Kaçamağı</h2>
                <p class="blog-meta">
                    <span id="reading-time">Okunuyor...</span> •
                    <span id="comment-count">0 yorum</span>
                </p>
            </header>
            <div class="blog-content">
                <p>Ege kıyıları sadece deniz ve kumdan ibaret değil. Zeytin ağaçları arasında kaybolmuş, taş evleriyle büyüleyen köyler sizi bekliyor. Şirince, Birgi ve Tire gibi saklı cennetleri keşfedin; her sokaklarında tarihin sessiz fısıltısını duyacaksınız.</p>
                <img src="https://images.unsplash.com/photo-1516483638261-f4dbaf036963?w=800" alt="Ege Köyleri Gezi Rehberi" class="blog-img">
            </div>

            <div class="post-rating">
                <p>Bu yazıyı puanla:</p>
                <div class="stars">
                    <input type="radio" name="star" id="star5"><label for="star5">★</label>
                    <input type="radio" name="star" id="star4"><label for="star4">★</label>
                    <input type="radio" name="star" id="star3"><label for="star3">★</label>
                    <input type="radio" name="star" id="star2"><label for="star2">★</label>
                    <input type="radio" name="star" id="star1"><label for="star1">★</label>
                </div>
                <p id="blog-rating-result">Henüz puanlanmadı</p>
            </div>

            <div class="comment-section">
                <h3>Yorum Yap</h3>
                <?php if ($isLoggedIn): ?>
                    <div class="commenter-info">
                        <span class="commenter-avatar"><?= mb_strtoupper(mb_substr($activeUser['full_name'],0,1)) ?></span>
                        <strong><?= htmlspecialchars($activeUser['full_name'],ENT_QUOTES) ?></strong> olarak yorum yapıyorsun
                    </div>
                <?php endif; ?>
                <form id="comment-form">
                    <?php if (!$isLoggedIn): ?>
                        <input type="text" id="comment-name-input" placeholder="Adınız" required>
                    <?php endif; ?>
                    <textarea id="comment-text" placeholder="Yorumunuzu buraya yazın..." rows="4" required></textarea>
                    <button type="submit" class="btn-filter">Gönder</button>
                </form>
                <div id="comment-list" class="comment-list" aria-live="polite"></div>
            </div>
        </article>
    </section>
</main>

<footer class="credits-footer">
    <h3>Yapımcılar</h3>
    <p>Nurettin Yavuz • Buğrahan Ergör • Duygu Bebek • İrem Zülal</p>
</footer>

<!-- Scroll To Top Button -->
<button class="scroll-to-top" id="scrollToTopBtn" aria-label="Başa Dön" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

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
const CURRENT_USER    = <?= $userJson ?>;
const PLACES_DATA     = <?= json_encode($placesData, JSON_UNESCAPED_UNICODE) ?>;
const BLOG_POST_ID    = 1; // Ana sayfadaki Ege yazısının DB id'si

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

// Scroll to Top Button Logic
const scrollToTopBtn = document.getElementById('scrollToTopBtn');
if (scrollToTopBtn) {
    window.addEventListener('scroll', () => {
        if (window.scrollY > 400) {
            scrollToTopBtn.classList.add('show');
        } else {
            scrollToTopBtn.classList.remove('show');
        }
    });
}
</script>
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="script.js"></script>
</body>
</html>
