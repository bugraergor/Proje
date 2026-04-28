// =====================================================================
// GezginDer — Main JS  (theme + place modal + real AJAX)
// =====================================================================



// ── Theme Toggle ──────────────────────────────────────────────────────
function toggleTheme() {
    const html  = document.documentElement;
    const cur   = html.getAttribute('data-theme') || 'dark';
    const next  = cur === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('gezginder-theme', next);
    _syncThemeIcon(next);
    
    // Harita katmanını dinamik olarak değiştir
    if (window.leafletMap && window.mapTileLayer) {
        const url = next === 'dark' 
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
        window.leafletMap.removeLayer(window.mapTileLayer);
        window.mapTileLayer = L.tileLayer(url, {
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
            maxZoom: 19
        }).addTo(window.leafletMap);
    }
}

function _syncThemeIcon(theme) {
    const icon = document.getElementById('theme-icon');
    if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}

// Sayfa yüklenince simgeyi senkronize et
document.addEventListener('DOMContentLoaded', () => {
    _syncThemeIcon(localStorage.getItem('gezginder-theme') || 'dark');
});

// ── Galeri Filtre + Sıralama ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const filterButtons = document.querySelectorAll('#filter-container .btn-filter');
    const cards         = Array.from(document.querySelectorAll('.gallery .card'));
    const gallery       = document.querySelector('.gallery');
    const placeSearch   = document.querySelector('#place-search');
    const sortPlaces    = document.querySelector('#sort-places');
    let selectedCat     = 'hepsi';

    function getScore(card) {
        const t = card.querySelector('.star-rating span')?.textContent || '(0)';
        return Number(t.replace(/[^\d.]/g,'')) || 0;
    }

    function renderCards() {
        const q = (placeSearch?.value || '').trim().toLowerCase();
        const sorted = [...cards];
        if (sortPlaces?.value === 'asc')  sorted.sort((a,b) => getScore(a)-getScore(b));
        if (sortPlaces?.value === 'desc') sorted.sort((a,b) => getScore(b)-getScore(a));
        sorted.forEach(c => gallery.appendChild(c));
        sorted.forEach(c => {
            const cat = c.getAttribute('data-category');
            const ttl = (c.querySelector('h3')?.textContent||'').toLowerCase();
            const ok  = (selectedCat==='hepsi'||selectedCat===cat) && ttl.includes(q);
            c.style.display   = ok ? '' : 'none';
            c.style.animation = ok ? 'fadeIn 0.4s ease forwards' : '';
        });
    }

    filterButtons.forEach(b => b.addEventListener('click', () => {
        document.querySelector('#filter-container .btn-filter.active')?.classList.remove('active');
        b.classList.add('active');
        selectedCat = b.getAttribute('data-target') || 'hepsi';
        renderCards();
    }));
    placeSearch?.addEventListener('input', renderCards);
    sortPlaces?.addEventListener('change', renderCards);
    renderCards();

    // Okuma süresi
    const blogText = document.querySelector('.blog-content p');
    const rtEl     = document.querySelector('#reading-time');
    if (blogText && rtEl) {
        const words = blogText.textContent.trim().split(/\s+/).length;
        rtEl.textContent = `${Math.max(1,Math.ceil(words/180))} dk okuma`;
    }

    // Yer puanlarını yükle
    loadAllPlaceRatings();
});

// ── Yer Kartı Puanları (başlangıç yükleme) ─────────────────────────
async function loadAllPlaceRatings() {
    const boxes = document.querySelectorAll('.place-rating');
    for (const box of boxes) {
        const key = box.getAttribute('data-place');
        if (!key) continue;
        try {
            const res  = await fetch('api.php', {
                method:'POST',headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'get_place_data', place_key:key})
            });
            const data = await res.json();
            if (data.ok) {
                const msg = box.querySelector('.place-rating-result');
                if (data.user_rating) {
                    const inp = box.querySelector(`input[value="${data.user_rating}"]`);
                    if (inp) inp.checked = true;
                    if (msg) msg.textContent = `Puanın: ${data.user_rating}/5`;
                } else {
                    if (msg) msg.textContent = 'Henüz puanlamadın';
                }
                // Kart yıldızlarını gerçek ortalama ile güncelle
                if (data.avg_rating) updateCardStars(key, data.avg_rating, data.rating_cnt);
            }
        } catch(e) {/**/}
    }

    // Yıldız tıklama
    document.querySelectorAll('.place-rating').forEach(box => {
        const key = box.getAttribute('data-place');
        box.querySelectorAll('input[type="radio"]').forEach(inp => {
            inp.addEventListener('change', async () => {
                if (!CURRENT_USER) {
                    inp.checked = false;
                    openLoginModal('Yıldız vermek için giriş yap!');
                    return;
                }
                const res  = await fetch('api.php', {
                    method:'POST',headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({action:'rate_place', place_key:key, rating:parseInt(inp.value)})
                });
                const data = await res.json();
                const msg  = box.querySelector('.place-rating-result');
                if (msg) msg.textContent = data.ok ? `Puanın: ${inp.value}/5 ✓` : data.message;
                if (data.ok) updateCardStars(key, data.avg_rating, data.rating_cnt);
            });
        });
    });
}

// Kart üzerindeki yıldız gösterimini güncelle
function updateCardStars(key, avg, cnt) {
    const wrap = document.getElementById('stars-' + key);
    if (!wrap) return;
    wrap.innerHTML = '';
    const avgNum = parseFloat(avg) || 0;
    for (let i=1;i<=5;i++) {
        const star = document.createElement('i');
        if (avgNum >= i) star.className = 'fas fa-star';
        else if (avgNum >= i-0.5) star.className = 'fas fa-star-half-alt';
        else star.className = 'far fa-star';
        wrap.appendChild(star);
    }
    const sp = document.createElement('span');
    sp.textContent = '(' + avgNum + ' / ' + (cnt||0) + ' oy)';
    wrap.appendChild(sp);
}

// ── Blog Yorum Formu (gerçek DB) ────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const commentForm     = document.querySelector('#comment-form');
    const commentList     = document.querySelector('#comment-list');
    const commentCountEl  = document.querySelector('#comment-count');
    const commentTextarea = document.querySelector('#comment-text');

    // Yorum textarea focus — giriş yoksa modal aç
    commentTextarea?.addEventListener('focus', () => {
        if (!CURRENT_USER) {
            commentTextarea.blur();
            openLoginModal('Yorum yapmak için giriş yap!');
        }
    });

    commentForm?.addEventListener('submit', async e => {
        e.preventDefault();
        if (!CURRENT_USER) { openLoginModal('Yorum yapmak için giriş yap!'); return; }
        const content = commentTextarea?.value.trim() || '';
        if (!content) return;

        const res  = await fetch('api.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'add_blog_comment', post_id:BLOG_POST_ID, content})
        });
        const data = await res.json();
        if (data.ok && data.comment) {
            prependComment(commentList, data.comment);
            commentForm.reset();
            if (commentCountEl) {
                const cur = parseInt(commentCountEl.textContent)||0;
                commentCountEl.textContent = `${cur+1} yorum`;
            }
        }
    });

    // Blog yıldız
    const blogStars       = document.querySelectorAll('.post-rating .stars input[type="radio"]');
    const blogRatingResult = document.querySelector('#blog-rating-result');
    blogStars.forEach((inp, idx) => {
        inp.value = String(5-idx);
        inp.addEventListener('change', async () => {
            if (!CURRENT_USER) {
                inp.checked = false;
                openLoginModal('Blog yazısını puanlamak için giriş yap!');
                return;
            }
            const res  = await fetch('api.php', {
                method:'POST',headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'rate_blog', post_id:BLOG_POST_ID, rating:parseInt(inp.value)})
            });
            const data = await res.json();
            if (blogRatingResult) blogRatingResult.textContent = data.ok ? `Bu yazıya ${inp.value}/5 puan verdin ✓` : data.message;
        });
    });
});

function prependComment(list, comment) {
    if (!list) return;
    const div = document.createElement('div');
    div.className = 'comment-item';
    div.innerHTML = `<strong>${escHtml(comment.author_name || comment.full_name || 'Kullanıcı')}</strong>
                     <p>${escHtml(comment.content)}</p>`;
    list.prepend(div);
}

function escHtml(str='') {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── YER DETAY MODALI ────────────────────────────────────────────────
let _currentPlaceKey = null;

async function openPlaceModal(key) {
    const overlay = document.getElementById('place-modal');
    if (!overlay) return;
    _currentPlaceKey = key;
    const p = PLACES_DATA[key];
    if (!p) return;

    // Statik bilgileri doldur
    document.getElementById('pm-img').src = p.image;
    document.getElementById('pm-img').alt = p.name;
    document.getElementById('pm-badge').textContent = p.cat_label;
    document.getElementById('pm-name').textContent  = p.name;
    document.getElementById('pm-season').textContent   = p.season;
    document.getElementById('pm-duration').textContent = p.duration;
    document.getElementById('pm-budget').textContent   = p.budget;
    document.getElementById('pm-description').textContent = p.description;
    document.getElementById('pm-avg-rating').textContent   = p.avg_rating;
    document.getElementById('pm-rate-msg').textContent     = '';

    // Highlights
    const hl = document.getElementById('pm-highlights');
    hl.innerHTML = p.highlights.map(h => `<span class="pm-tag"><i class="fas fa-map-pin"></i> ${escHtml(h)}</span>`).join('');

    // Yıldız girişi
    buildPmStars(key);

    // Morali aç, veri yükle
    overlay.classList.add('modal-active');
    document.body.style.overflow = 'hidden';

    // Yorum & puan AJAX
    loadPlaceData(key);
}

function closePlaceModal() {
    const overlay = document.getElementById('place-modal');
    if (overlay) overlay.classList.remove('modal-active');
    document.body.style.overflow = '';
    _currentPlaceKey = null;
}

function buildPmStars(key) {
    const wrap = document.getElementById('pm-star-input');
    if (!wrap) return;
    wrap.innerHTML = '';
    const names = ['place-pm'];
    for (let v=5;v>=1;v--) {
        const inp = document.createElement('input');
        inp.type = 'radio'; inp.name = 'pm-star'; inp.id = `pm-star-${v}`; inp.value = v;
        const lbl = document.createElement('label');
        lbl.htmlFor = `pm-star-${v}`; lbl.textContent = '★';
        inp.addEventListener('change', async () => {
            if (!CURRENT_USER) {
                inp.checked = false;
                closePlaceModal();
                openLoginModal('Yıldız vermek için giriş yap!');
                return;
            }
            const res  = await fetch('api.php', {
                method:'POST',headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'rate_place', place_key:key, rating:parseInt(inp.value)})
            });
            const data = await res.json();
            const msg  = document.getElementById('pm-rate-msg');
            if (msg) msg.textContent = data.ok ? `✓ ${inp.value}/5 puan verildi!` : data.message;
            if (data.ok) {
                document.getElementById('pm-avg-rating').textContent = data.avg_rating || '-';
                updateCardStars(key, data.avg_rating, data.rating_cnt);
            }
        });
        wrap.appendChild(inp); wrap.appendChild(lbl);
    }
}

async function loadPlaceData(key) {
    const commentList = document.getElementById('pm-comment-list');
    const formArea    = document.getElementById('pm-comment-form-area');
    const cntEl       = document.getElementById('pm-comment-count');
    const ratingEl    = document.getElementById('pm-avg-rating');
    const rateMsg     = document.getElementById('pm-rate-msg');

    if (commentList) commentList.innerHTML = '<div class="pm-loading"><i class="fas fa-spinner fa-spin"></i> Yükleniyor...</div>';

    try {
        const res  = await fetch('api.php', {
            method:'POST',headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'get_place_data', place_key:key})
        });
        const data = await res.json();
        if (!data.ok) { if (commentList) commentList.innerHTML = '<p class="pm-err">Yüklenemedi.</p>'; return; }

        // Puan güncelle
        if (data.avg_rating && ratingEl) ratingEl.textContent = data.avg_rating;
        if (data.rating_cnt > 0) {
            const cntParen = document.getElementById('pm-rating-cnt');
            if (cntParen) cntParen.textContent = `(${data.rating_cnt} oy)`;
        }

        // Kullanıcı puanını göster
        if (data.user_rating) {
            const inp = document.querySelector(`#pm-star-input input[value="${data.user_rating}"]`);
            if (inp) inp.checked = true;
            if (rateMsg) rateMsg.textContent = `Verdiğin puan: ${data.user_rating}/5`;
        }

        // Yorumlar
        if (cntEl) cntEl.textContent = data.comments.length;
        if (commentList) {
            if (data.comments.length === 0) {
                commentList.innerHTML = '<p class="pm-no-comments">Henüz yorum yok. İlk yorumu sen yap!</p>';
            } else {
                commentList.innerHTML = '';
                data.comments.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'pm-comment-item';
                    div.innerHTML = `<strong>${escHtml(c.full_name||c.author_name)}</strong>
                        <span class="pm-comment-date">${formatDate(c.created_at)}</span>
                        <p>${escHtml(c.content)}</p>`;
                    commentList.appendChild(div);
                });
            }
        }

        // Yorum formu
        if (formArea) {
            if (!CURRENT_USER) {
                formArea.innerHTML = `<div class="pm-login-prompt">
                    <i class="fas fa-lock"></i>
                    <span>Yorum yapmak için <button onclick="closePlaceModal();openLoginModal()">giriş yap</button></span>
                </div>`;
            } else {
                formArea.innerHTML = `<div class="pm-comment-form">
                    <textarea id="pm-comment-text" placeholder="Bu yer hakkında ne düşünüyorsun?" rows="3"></textarea>
                    <button class="pm-submit-comment-btn" data-place-key="${escHtml(key)}"><i class="fas fa-paper-plane"></i> Yorum Yap</button>
                </div>`;
                // Buton event listener'ını doğrudan ekle (onclick string yerine)
                formArea.querySelector('.pm-submit-comment-btn').addEventListener('click', () => submitPlaceComment(key));

            }
        }
    } catch(e) {
        if (commentList) commentList.innerHTML = '<p class="pm-err">Bağlantı hatası.</p>';
    }
}

async function submitPlaceComment(key) {
    const textarea = document.getElementById('pm-comment-text');
    const content  = textarea?.value.trim() || '';
    if (!content || content.length < 5) return;

    const res  = await fetch('api.php', {
        method:'POST',headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'add_place_comment', place_key:key, content})
    });
    const data = await res.json();
    if (data.ok && data.comment) {
        const list = document.getElementById('pm-comment-list');
        const noComments = list?.querySelector('.pm-no-comments');
        if (noComments) noComments.remove();
        const div = document.createElement('div');
        div.className = 'pm-comment-item new-comment';
        div.innerHTML = `<strong>${escHtml(CURRENT_USER.name)}</strong>
            <span class="pm-comment-date">Şimdi</span>
            <p>${escHtml(data.comment.content)}</p>`;
        list?.prepend(div);
        if (textarea) textarea.value = '';
        const cntEl = document.getElementById('pm-comment-count');
        if (cntEl) cntEl.textContent = parseInt(cntEl.textContent||'0')+1;
    }
}

function formatDate(str) {
    if (!str) return '';
    const d = new Date(str);
    return d.toLocaleDateString('tr-TR', {day:'numeric',month:'short',year:'numeric'});
}

// ── LOGIN MODAL ─────────────────────────────────────────────────────
function openLoginModal(hint) {
    const modal = document.getElementById('login-modal');
    if (!modal) return;
    modal.classList.add('modal-active');
    document.body.style.overflow = 'hidden';
    if (hint) {
        const msg = document.getElementById('modal-message');
        if (msg) { msg.textContent = hint; msg.className = 'modal-msg modal-msg-info'; }
    }
}
function closeLoginModal() {
    const modal = document.getElementById('login-modal');
    if (!modal) return;
    modal.classList.remove('modal-active');
    document.body.style.overflow = '';
}
function switchModalTab(tab, btn) {
    document.querySelectorAll('.modal-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.modal-panel').forEach(p=>p.classList.add('hidden'));
    btn.classList.add('active');
    document.getElementById('mpanel-'+tab)?.classList.remove('hidden');
}
function toggleModalPass(btn) {
    const inp  = btn.parentElement.querySelector('input');
    const icon = btn.querySelector('i');
    if (!inp) return;
    if (inp.type==='password') { inp.type='text'; icon.className='fas fa-eye-slash'; }
    else { inp.type='password'; icon.className='fas fa-eye'; }
}
document.addEventListener('keydown', e => {
    if (e.key==='Escape') { closeLoginModal(); closePlaceModal(); }
});

// --- FAVORİ EKLE/KALDIR ---
async function toggleFav(placeKey, btn) {
    const icon = btn.querySelector('i');
    btn.disabled = true;
    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'toggle_favorite', place_key: placeKey})
        });
        const data = await res.json();
        if (data.ok) {
            if (data.state === 'added') {
                btn.classList.add('favorited');
                icon.className = 'fas fa-heart';
            } else {
                btn.classList.remove('favorited');
                icon.className = 'far fa-heart';
            }
        } else {
            alert(data.message); // fallback for error
        }
    } catch (e) { console.error(e); }
    btn.disabled = false;
}

// AJAX login/register
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('modal-login-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const email    = document.getElementById('ml-email').value.trim();
        const password = document.getElementById('ml-pass').value;
        const res  = await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'login',email,password})});
        const data = await res.json();
        showModalMsg(data.message, data.ok?'success':'error');
        if (data.ok) setTimeout(()=>location.reload(), 800);
    });
    document.getElementById('modal-register-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const full_name = document.getElementById('mr-name').value.trim();
        const email     = document.getElementById('mr-email').value.trim();
        const password  = document.getElementById('mr-pass').value;
        const res  = await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'register',full_name,email,password})});
        const data = await res.json();
        showModalMsg(data.message, data.ok?'success':'error');
        if (data.ok) setTimeout(()=>location.reload(), 900);
    });
});

function showModalMsg(text, type) {
    const msg = document.getElementById('modal-message');
    if (!msg) return;
    msg.textContent = text;
    msg.className = 'modal-msg modal-msg-'+type;
}

// ============================================================
// 📍 INTERACTIVE LEAFLET MAP INITIALIZATION
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    const mapEl = document.getElementById('interactive-map');
    if (mapEl && typeof L !== 'undefined' && typeof PLACES_DATA !== 'undefined') {
        window.leafletMap = L.map('interactive-map').setView([41.0, 20.0], 4);
        
        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        const tileUrl = isDark 
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
            
        window.mapTileLayer = L.tileLayer(tileUrl, {
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
            maxZoom: 19
        }).addTo(window.leafletMap);

        Object.keys(PLACES_DATA).forEach(key => {
            const p = PLACES_DATA[key];
            if (p.lat && p.lng) {
                const marker = L.marker([p.lat, p.lng]).addTo(window.leafletMap);
                
                const popupContent = `
                    <div style="text-align:center; min-width:140px;">
                        <img src="${p.image}" style="width:100%; height:80px; object-fit:cover; border-radius:6px; margin-bottom:8px;">
                        <h4 style="margin:0; font-size:14px; color:#0f172a;">${p.name}</h4>
                        <p style="margin:4px 0 8px; font-size:12px; color:#64748b;">${p.cat_label}</p>
                        <button onclick="openPlaceModal('${key}')" style="background:#3b82f6; color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-size:12px; font-weight:600; width:100%; transition:0.2s;">
                            Haritada İncele
                        </button>
                    </div>
                `;
                marker.bindPopup(popupContent);
            }
        });
    }
});

// ============================================================
// 🎭 SCROLL REVEAL (İçerik Kaydırma Animasyonları)
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    const reveals = document.querySelectorAll('.reveal');
    if (!reveals.length) return;
    
    const obsOpts = { root: null, rootMargin: '0px', threshold: 0.15 };
    const revealObs = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('active');
                observer.unobserve(entry.target); // Sadece bir kere çalışsın
            }
        });
    }, obsOpts);
    
    reveals.forEach(el => revealObs.observe(el));
});
