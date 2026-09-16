<?php
require_once __DIR__ . '/inc/settings_loader.php';

require_once __DIR__ . '/inc/session.php';
kgv_start_existing_session();
require_once __DIR__ . '/inc/privacy_cleanup.php';
try {
    kgv_run_privacy_cleanup(__DIR__ . '/data');
} catch (Throwable $e) {
    error_log('[privacy-cleanup] ' . $e->getMessage());
}
// ── Wartungsmodus ─────────────────────────────────────────────────────────
$_sm = load_settings();
$_elevatedRoles = ['vorstand','buchung','schriftfuehrer','web'];
$_memberBypasses = !empty($_SESSION['kgv_member']['roles']) && array_intersect($_SESSION['kgv_member']['roles'], $_elevatedRoles);
if (!empty($_sm['maintenance']) && empty($_SESSION['kgv_admin']) && !$_memberBypasses) {
    http_response_code(503); header('Retry-After: 3600');
    $mMsg  = htmlspecialchars($_sm['maintenance_message'] ?? 'Die Website wird gerade aktualisiert. Wir sind gleich zurück.');
    $_cf2  = __DIR__ . '/data/content.json';
    $_cc2  = file_exists($_cf2) ? (json_decode((string)file_get_contents($_cf2), true) ?: []) : [];
    $_mCfg = $_cc2['settings'] ?? [];
    $mEmail = htmlspecialchars($_mCfg['email']    ?? 'vorstand@example.org');
    $mTel   = htmlspecialchars($_mCfg['telefon']  ?? '');
    echo '<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>unser Verein – Wartungsarbeiten</title>
<link rel="stylesheet" href="/assets/fonts/fonts.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Poppins",sans-serif;min-height:100vh;background:linear-gradient(135deg,#2d5234 0%,#3d6b41 50%,#4a7c4e 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;position:relative;overflow:hidden}
body::before{content:"🌿";position:fixed;font-size:28rem;opacity:0.04;top:-6rem;right:-8rem;line-height:1;pointer-events:none;user-select:none}
body::after{content:"🌱";position:fixed;font-size:16rem;opacity:0.05;bottom:-3rem;left:-3rem;line-height:1;pointer-events:none;user-select:none}
.card{background:rgba(255,255,255,0.10);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.20);border-radius:24px;padding:52px 44px;max-width:520px;width:100%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,0.25)}
.logo-wrap{margin-bottom:28px}
.logo-wrap img{width:80px;height:80px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.9}
.badge{display:inline-block;background:rgba(255,255,255,0.18);color:rgba(255,255,255,0.9);font-size:0.72rem;font-weight:500;letter-spacing:.12em;text-transform:uppercase;padding:5px 14px;border-radius:20px;border:1px solid rgba(255,255,255,0.25);margin-bottom:20px}
h1{font-family:"Playfair Display",serif;color:#fff;font-size:2rem;line-height:1.25;margin-bottom:8px}
.sub{color:rgba(255,255,255,0.65);font-size:0.82rem;letter-spacing:.04em;margin-bottom:28px}
.divider{width:48px;height:2px;background:rgba(255,255,255,0.25);border-radius:2px;margin:0 auto 28px}
.msg{color:rgba(255,255,255,0.88);font-size:0.97rem;line-height:1.75;font-weight:300}
.contact{margin-top:36px;padding-top:28px;border-top:1px solid rgba(255,255,255,0.15)}
.contact p{color:rgba(255,255,255,0.55);font-size:0.78rem;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px}
.contact a{color:rgba(255,255,255,0.85);text-decoration:none;font-size:0.88rem;font-weight:500;display:inline-block;margin:3px 10px}
.contact a:hover{color:#fff;text-decoration:underline}
.admin-link{display:block;margin-top:44px;color:rgba(255,255,255,0.25);font-size:0.72rem;text-decoration:none;letter-spacing:.06em}
.admin-link:hover{color:rgba(255,255,255,0.5)}
@media(max-width:480px){.card{padding:40px 28px}h1{font-size:1.6rem}}
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap"><img src="/logo.png" alt="unser Verein"></div>
  <div class="badge">Wartungsarbeiten</div>
  <h1>Wir sind gleich zurück</h1>
  <p class="sub">unser Verein</p>
  <div class="divider"></div>
  <p class="msg">' . $mMsg . '</p>'
  . ($mEmail !== '' || $mTel !== '' ? '
  <div class="contact">
    <p>Dringende Anfragen</p>'
    . ($mTel   !== '' ? '<a href="tel:' . $mTel   . '">📞 ' . $mTel   . '</a>' : '')
    . ($mEmail !== '' ? '<a href="mailto:' . $mEmail . '">✉️ ' . $mEmail . '</a>' : '')
    . '</div>' : '')
  . '
</div>
<a href="/intern/" class="admin-link">→ Intern</a>
</body></html>';
    exit;
}

$_cf = __DIR__ . '/data/content.json';
$_c  = file_exists($_cf) ? (json_decode(file_get_contents($_cf), true) ?: []) : [];
$_settings = $_c['settings'] ?? [];
$_vereinName = trim((string)($_c['impressum']['verein'] ?? ($_c['verein']['name'] ?? 'Unser Verein')));
$_ftTel    = htmlspecialchars($_settings['telefon'] ?? '+49 000 000 00 00');
$_ftEmail  = htmlspecialchars($_settings['email']   ?? 'vorstand@example.org');
$_ftStr    = htmlspecialchars($_settings['strasse'] ?? 'Musterstraße 1');
$_ftPlz    = htmlspecialchars($_settings['plz_ort'] ?? 'PLZ Ort');
$_hero    = $_c['hero']         ?? [];
$_ticker  = $_c['ticker']       ?? [];
$_notif   = $_c['notification'] ?? ['active' => true, 'text' => ''];
$_gallery      = $_c['gallery']       ?? [];
$_galleryTitle = (string)($_c['gallery_title'] ?? 'Eindrücke aus dem Verein');
$_prices  = $_c['prices']       ?? ['miete' => 300, 'kaution' => 200, 'strom_kwh' => 0.50];
$_bgImg      = htmlspecialchars($_hero['bg_image'] ?? 'front.jpg');
$_sec        = array_merge(
    ['ticker'=>true,'about'=>true,'booking'=>true,'vorstand'=>true,'gallery'=>true,'termine'=>true,'links'=>true,'member'=>true,'kontakt'=>true,'newsletter'=>true],
    $_c['sections_visible'] ?? []
);
$_vsDefault = [
    ['role'=>'1. Vorsitzender','name'=>'Max Mustermann','email'=>'vorstand@example.org','phone'=>'+49 000 000 00 00','photo'=>'','full'=>true],
    ['role'=>'Kassiererin','name'=>'Erika Musterfrau','email'=>'kasse@example.org','phone'=>'+49 000 000 00 00','photo'=>'','full'=>false],
    ['role'=>'Schriftführerin','name'=>'Maria Beispiel','email'=>'schriftfuehrer@example.org','phone'=>'+49 000 000 00 00','photo'=>'','full'=>false],
];
$_lkDefault = [
    ['emoji'=>'🌿','title'=>'Landesverband der Gartenfreunde','desc'=>'Dachverband der regionalen Kleingartenvereine','url'=>'https://www.example.org'],
    ['emoji'=>'🦋','title'=>'Naturschutzverband (Beispiel)','desc'=>'Für Mensch und Natur','url'=>'https://example.org'],
    ['emoji'=>'📚','title'=>'Garten Wissen','desc'=>'Tipps und Tricks für Ihren Garten','url'=>'https://www.mein-schoener-garten.de'],
];
$_vorstand     = $_c['vorstand']             ?? $_vsDefault;
$_vsGroupPhoto = $_c['vorstand_group_photo'] ?? '6e6627d09a782d6b95bd89ba29c242b2.jpg';
$_links        = $_c['links']                ?? $_lkDefault;
$_termineAll   = array_filter($_c['termine'] ?? [], fn($t) => !empty($t['public']) && ($t['date'] ?? '') >= date('Y-m-d'));
usort($_termineAll, fn($a, $b) => strcmp($a['date'], $b['date']));
$_blockedDates  = $_c['blocked_dates']  ?? [];
$_blockedRanges = $_c['blocked_ranges'] ?? [];
$_vh         = $_c['vereinshaus'] ?? ['description' => '', 'main_image' => '', 'gallery' => []];
$_vhImgArr   = [];
if (!empty($_vh['main_image'])) {
    $_vhImgArr[] = ['src' => '/images/' . htmlspecialchars($_vh['main_image']), 'caption' => 'Außenansicht'];
}
foreach ($_vh['gallery'] as $_vg) {
    if (!empty($_vg['image'])) {
        $_vhImgArr[] = ['src' => '/images/' . htmlspecialchars($_vg['image']), 'caption' => htmlspecialchars($_vg['caption'] ?? '')];
    }
}
$_vhJson = json_encode($_vhImgArr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($_vereinName) ?> – Beschreibung deines Vereins. Parzellen, Vereinshaus, lebendige Gemeinschaft.">
    <meta name="keywords" content="Kleingartenverein, Garten, Parzelle, Vereinshaus mieten">
    <meta name="author" content="<?= htmlspecialchars($_vereinName) ?>">
    <meta name="google-site-verification" content="GOOGLE_VERIFICATION_TOKEN" />

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= site_url() ?>/">
    <meta property="og:title" content="<?= htmlspecialchars($_vereinName) ?>">
    <meta property="og:description" content="Grüne Oase – werden Sie Teil unserer Gemeinschaft!">
    <meta property="og:image" content="<?= site_url() ?>/front.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?= site_url() ?>/">
    <meta property="twitter:title" content="<?= htmlspecialchars($_vereinName) ?>">
    <meta property="twitter:description" content="Grüne Oase – werden Sie Teil unserer Gemeinschaft!">
    <meta property="twitter:image" content="<?= site_url() ?>/front.jpg">

    <!-- Canonical -->
    <link rel="canonical" href="<?= site_url() ?>/" />

    <!-- Favicon + PWA -->
    <link rel="icon" type="image/png" href="logo.png">
    <meta name="theme-color" content="#3d6b41">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-180.png">

    <title><?= htmlspecialchars($_vereinName) ?></title>

    <!-- Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Muster-Kleingartenverein e.V.",
        "alternateName": "unser Verein",
        "url": "<?= site_url() ?>",
        "logo": "/logo.png",
        "foundingDate": "1975",
        "description": "Kleingartenverein in Musterstadt mit 63 Parzellen und Vereinshaus",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "Musterstraße 1",
            "addressLocality": "DEINE STADT",
            "postalCode": "22419",
            "addressCountry": "DE"
        },
        "contactPoint": {
            "@type": "ContactPoint",
            "telephone": "+49-163-5140490",
            "contactType": "customer service",
            "email": "vorstand@example.org"
        }
    }
    </script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        "name": "unser Verein – Vereinshaus unserem Verein",
        "url": "<?= site_url() ?>/",
        "telephone": "+49-163-5140490",
        "email": "vorstand@example.org",
        "image": "/logo.png",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "Musterstraße 1",
            "addressLocality": "DEINE STADT",
            "postalCode": "22419",
            "addressCountry": "DE"
        },
        "geo": {
            "@type": "GeoCoordinates",
            "latitude": "53.6580",
            "longitude": "10.0140"
        },
        "priceRange": "€",
        "description": "Vermietung des Vereinshauses des unser Verein in Musterstadt für Feiern, Veranstaltungen und Vereinstreffen."
    }
    </script>

    <!-- LCP Preload: Hero-Hintergrundbild -->
    <link rel="preload" as="image" href="/<?= htmlspecialchars($_bgImg) ?>">

    <!-- Self-hosted fonts -->
    <link rel="stylesheet" href="/assets/fonts/fonts.css">
    <!-- Stylesheet -->
    <link rel="stylesheet" href="/portal.css?v=3">
</head>
<body>
    <a href="#main" class="skip-link">Zum Hauptinhalt springen</a>

    <!-- Notification Banner -->
    <div class="notification-banner" id="notificationBanner" role="region" aria-label="Hinweis"<?php if (empty($_notif['active'])): ?> style="display:none"<?php endif; ?>>
        <p style="margin: 0; font-weight: 500;"><?php echo $_notif['text'] ?? ''; ?></p>
        <button class="notification-close" type="button" aria-label="Hinweis schließen" onclick="closeNotification()">×</button>
    </div>

    <header>
        <nav aria-label="Hauptnavigation">
            <div class="logo">
                <div class="logo-img">
                    <img src="logo.png" alt="Muster-Kleingartenverein e.V. Logo" title="unser Verein – Startseite" width="180" height="60">
                </div>
            </div>
            <button class="mobile-menu-btn" type="button" aria-label="Menü öffnen" aria-expanded="false" aria-controls="navMenu" onclick="toggleMobileMenu(this)">☰</button>
            <ul id="navMenu">
                <li><a href="#home" title="Zur Startseite">Startseite</a></li>
                <li><a href="#about" title="Über unseren Verein">Über Uns</a></li>
                <?php if ($_sec['vorstand']): ?><li><a href="#vorstand" title="Der Vorstand des unser Verein">Der Vorstand</a></li><?php endif; ?>
                <?php if ($_sec['gallery']): ?><li><a href="#gallery" title="Bildergalerie">Galerie</a></li><?php endif; ?>
                <?php if ($_sec['booking']): ?><li><a href="/vereinshaus" title="Vereinshaus des unser Verein mieten">Vereinshaus mieten</a></li><?php endif; ?>
                <?php if ($_sec['termine'] && !empty($_termineAll)): ?><li><a href="#termine" title="Termine und Veranstaltungen">Termine</a></li><?php endif; ?>
                <?php if ($_sec['links']): ?><li><a href="#links" title="Nützliche Links">Links</a></li><?php endif; ?>
                <?php if ($_sec['member']): ?><li><a href="#member" title="Mitglied im unser Verein werden">Mitglied werden</a></li><?php endif; ?>
                <li><a href="/mitglieder.php" title="Zum Mitgliederbereich" style="font-weight:600;color:var(--primary-green)"><img src="/images/logo.png" alt="unser Verein Mitgliederbereich" title="Mitgliederbereich" style="height:18px;width:auto;vertical-align:middle;margin-right:5px" width="18" height="18"> Mitgliederbereich</a></li>
            </ul>
        </nav>
    </header>

    <main id="main">

    <section class="hero" id="home">
        <?php if (!empty($_hero['bg_image'])): ?>
        <div class="hero-bg" style="background-image: url('<?php echo $_bgImg; ?>')"></div>
        <?php endif; ?>
        <div class="hero-overlay"></div>
        <div class="hero-inner">
            <span class="hero-eyebrow">🌿 DEINE STADT · seit GRÜNDUNGSJAHR</span>
            <h1><?php echo htmlspecialchars($_hero['headline'] ?? 'Muster-Kleingartenverein e.V.'); ?></h1>
            <p class="hero-subtitle"><?php echo htmlspecialchars($_hero['subtitle'] ?? ''); ?></p>
            <p class="hero-description"><?php echo $_hero['description'] ?? ''; ?></p>
            <div class="hero-buttons">
                <a href="/vereinshaus" title="Vereinshaus des unser Verein für Ihre Veranstaltung mieten" class="hero-btn-primary">🏡 Vereinshaus mieten</a>
                <a href="#about" title="Mehr über den unser Verein erfahren" class="hero-btn-outline">Mehr erfahren</a>
            </div>
            <div class="hero-stats">
                <div class="hero-stat"><strong>63</strong><span>Parzellen</span></div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat"><strong>50+</strong><span>Jahre Tradition</span></div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat"><strong>100+</strong><span>Mitglieder</span></div>
            </div>
        </div>
    </section>



    <?php if ($_sec['about']): ?>
    <section class="section" id="about">
        <div class="container">
            <h2 class="section-title">Über unseren Verein</h2>
            <p class="section-subtitle">Eine lebendige Gemeinschaft</p>
            <div style="max-width: 800px; margin: 0 auto; text-align: center;">
                <p style="font-size: 1.15rem; line-height: 1.8; color: var(--text-gray);">
                    Unsere Gartengemeinschaft in Musterstadt bietet Stadtbewohnern die Möglichkeit,
                    ihr eigenes Stück Natur zu bewirtschaften und Teil einer lebendigen Gemeinschaft zu werden.
                </p>
                <div style="margin: 3rem 0; padding: 2rem; background: rgba(139,195,74,0.05); border-radius: 15px;">
                    <p style="font-weight: 600; color: var(--primary-green); font-size: 1.2rem;">
                        Gegründet im Jahr 1975<br>
                        Vereinshaus errichtet 1976
                    </p>
                </div>
                <p style="font-style: italic; font-family: 'Playfair Display', serif; color: var(--light-green); font-size: 1.3rem; margin-top: 2rem;">
                    „In unserer Gartengemeinschaft säen wir mehr als Pflanzen –<br>
                    wir säen Vertrauen, Freude und Zusammenhalt."
                </p>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- News Ticker -->
    <?php if ($_sec['ticker']): ?>
    <div class="news-ticker" role="region" aria-label="Aktuelle Hinweise">
        <div class="news-content" id="newsTickerContent">
            <?php foreach ($_ticker as $_ti): ?>
            <span class="news-item"><?php echo htmlspecialchars($_ti); ?></span>
            <?php endforeach; ?>
            <?php foreach ($_ticker as $_ti): // zweite Runde für nahtlosen Loop ?>
            <span class="news-item"><?php echo htmlspecialchars($_ti); ?></span>
            <?php endforeach; ?>
        </div>
        <button type="button" class="news-ticker-toggle" id="newsTickerToggle"
                aria-label="Laufschrift pausieren" aria-pressed="false"
                onclick="toggleNewsTicker(this)">⏸</button>
    </div>
    <?php endif; ?>

    <?php if ($_sec['booking']): ?>
    <section class="booking-section" id="booking">
        <div class="container">
            <h2 class="section-title">Vereinshaus mieten</h2>
            <p class="section-subtitle">Feiern Sie Ihre besonderen Momente in unserem Vereinshaus</p>

            <div class="booking-grid">
                <div class="booking-info">
                    <h3 style="color: var(--primary-green); font-size: 1.8rem; margin-bottom: 1.5rem;">Unser Vereinshaus</h3>
                    <p style="line-height: 1.8; margin-bottom: 1rem;">
                        Unser Vereinshaus bietet Platz für bis zu <strong>50 Personen</strong> und kann auf Anfrage angemietet werden.
                    </p>
                    <p style="line-height: 1.8;">
                        Die Vermietung erfolgt in persönlicher Absprache mit unserer Ansprechpartnerin.
                    </p>
                    <div style="background: rgba(255,193,7,0.1); padding: 1rem; border-radius: 10px; margin: 1.5rem 0; border-left: 4px solid #ffc107;">
                        <p style="margin: 0; font-weight: 500;">
                            <strong>Wichtig:</strong> Bitte beachte dabei die Ruhezeiten unserer Gartengemeinschaft –
                            der respektvolle Umgang miteinander liegt uns am Herzen.
                        </p>
                    </div>

                    <div class="price-table">
                        <h4 style="color: var(--primary-green); margin-bottom: 1rem; font-size: 1.3rem;">Kosten der Vermietung</h4>
                        <div class="price-row">
                            <span>Nutzungsgebühr pro Vermietung</span>
                            <strong><?php echo number_format((float)($_prices['miete'] ?? 300), 2, ',', '.'); ?> €</strong>
                        </div>
                        <div class="price-row">
                            <span>Kaution</span>
                            <strong><?php echo number_format((float)($_prices['kaution'] ?? 200), 2, ',', '.'); ?> €</strong>
                        </div>
                        <div class="price-row">
                            <span>Strom je kWh</span>
                            <strong><?php echo number_format((float)($_prices['strom_kwh'] ?? 0.5), 2, ',', '.'); ?> €</strong>
                        </div>
                    </div>

                    <div class="contact-person">
                        <h4 style="color: var(--primary-green); margin-bottom: 1rem;">Terminvergabe und Vermietung:</h4>
                        <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem;">Erika Musterfrau</p>
                        <p style="margin-bottom: 0.3rem;">📱 Mobil: +49 000 000 00 00</p>
                        <p>✉️ E-Mail: kasse@example.org</p>
                    </div>
                </div>

                <div class="calendar-container">
                    <h3 style="color: var(--primary-green); margin-bottom: 0.75rem; font-size: 1.5rem;">Verfügbarkeit prüfen</h3>
                    <p style="font-size: 0.9rem; color: #666; margin-bottom: 1.25rem;">💡 Klicken Sie auf einen Tag für eine einzelne Nacht – oder wählen Sie Start- und Enddatum für mehrere Tage.</p>
                    <div class="calendar-header">
                        <p id="currentMonth" style="font-size: 1.2rem; color: var(--text-dark); font-weight: 600; margin: 0;"></p>
                        <div class="calendar-nav">
                            <button class="calendar-btn" onclick="previousMonth()">←</button>
                            <button class="calendar-btn" onclick="nextMonth()">→</button>
                        </div>
                    </div>
                    <div class="calendar-grid" id="calendar">
                        <div class="calendar-day-header">Mo</div>
                        <div class="calendar-day-header">Di</div>
                        <div class="calendar-day-header">Mi</div>
                        <div class="calendar-day-header">Do</div>
                        <div class="calendar-day-header">Fr</div>
                        <div class="calendar-day-header">Sa</div>
                        <div class="calendar-day-header">So</div>
                    </div>

                    <!-- Calendar Legend -->
                    <div style="display:flex;gap:1.5rem;margin-top:1rem;font-size:0.85rem;color:var(--text-gray);flex-wrap:wrap;">
                        <span><span style="display:inline-block;width:14px;height:14px;background:#ffebee;border:2px solid #c62828;border-radius:4px;vertical-align:middle;margin-right:4px;"></span>Gebucht</span>
                        <span><span style="display:inline-block;width:14px;height:14px;background:#fff8e1;border:2px solid #f9a825;border-radius:4px;vertical-align:middle;margin-right:4px;"></span>Reserviert (Anfrage läuft)</span>
                        <span><span style="display:inline-block;width:14px;height:14px;background:var(--white);border:2px solid var(--light-green);border-radius:4px;vertical-align:middle;margin-right:4px;"></span>Verfügbar</span>
                        <span><span style="display:inline-block;width:14px;height:14px;background:rgba(61,107,65,0.18);border:2px solid var(--primary-green);border-radius:4px;vertical-align:middle;margin-right:4px;"></span>Ausgewählt</span>
                    </div>
                    <p style="font-size:0.82rem;color:var(--text-gray);margin-top:0.6rem;">Einzelner Tag: einmal klicken · Mehrere Tage: Start- und Endtag klicken</p>

                    <div class="booking-form" id="bookingForm">
                        <h4 style="color: var(--primary-green); margin-bottom: 0.5rem;">Buchungsanfrage für: <span id="selectedDate"></span></h4>
                        <p style="margin-bottom:1.2rem;"><button type="button" onclick="resetCalendarSelection()" style="background:none;border:none;color:var(--text-gray);font-size:0.82rem;cursor:pointer;text-decoration:underline;padding:0;">✕ Auswahl zurücksetzen</button></p>
                        <form onsubmit="submitBooking(event)">
                            <div class="form-group">
                                <label for="bk-name">Name *</label>
                                <input id="bk-name" type="text" name="name" required placeholder="Ihr vollständiger Name" autocomplete="name">
                            </div>
                            <div class="form-group">
                                <label for="bk-email">E-Mail *</label>
                                <input id="bk-email" type="email" name="email" required placeholder="ihre.email@beispiel.de" autocomplete="email">
                            </div>
                            <div class="form-group">
                                <label for="bk-phone">Telefon *</label>
                                <input id="bk-phone" type="tel" name="phone" required placeholder="Ihre Telefonnummer" autocomplete="tel">
                            </div>
                            <div class="form-group">
                                <label for="bk-guests">Anzahl Personen *</label>
                                <input id="bk-guests" type="number" name="guests" min="1" max="50" required placeholder="z.B. 20">
                            </div>
                            <div class="form-group">
                                <label for="bk-purpose">Anlass der Vermietung</label>
                                <textarea id="bk-purpose" name="purpose" rows="3" placeholder="Beschreiben Sie kurz Ihren Anlass..."></textarea>
                            </div>
                            <button type="submit" class="submit-btn">Anfrage senden</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Vereinshaus Galerie -->

            <div style="margin-top:1rem">
                <h3 style="text-align:center;color:var(--primary-green);margin-bottom:0.5rem;font-size:1.8rem;">
                    Eindrücke aus unserem Vereinshaus
                </h3>

                <?php if (!empty($_vhImgArr)): ?>

                <div class="gvi-wrap">
                    <div class="gvi-main" id="gvi-main" onclick="vhOpen(gviActive)">
                        <img id="gvi-main-img" src="<?php echo $_vhImgArr[0]['src']; ?>" alt="<?php echo htmlspecialchars($_vhImgArr[0]['caption']); ?>">
                        <div class="gvi-main-overlay">
                            <span class="gvi-main-caption" id="gvi-main-caption"><?php echo htmlspecialchars($_vhImgArr[0]['caption']); ?></span>
                            <span class="gvi-lb-hint">🔍 Vergrößern</span>
                        </div>
                    </div>
                    <div class="gvi-thumbs" id="gvi-thumbs"></div>
                </div>

                <!-- Lightbox -->
                <div id="vh-lb" onclick="if(event.target===this)vhClose()">
                    <button id="vh-lb-close" onclick="vhClose()">✕</button>
                    <button id="vh-lb-prev" onclick="vhPrev()">&#8249;</button>
                    <div style="display:flex;flex-direction:column;align-items:center">
                        <img id="vh-lb-img" src="" alt="">
                        <div id="vh-lb-caption"></div>
                    </div>
                    <button id="vh-lb-next" onclick="vhNext()">&#8250;</button>
                </div>
                <script>
                const vhImages=<?php echo $_vhJson; ?>;
                let gviActive=0;
                function gviRender(){
                    if(!vhImages.length)return;
                    const m=vhImages[gviActive];
                    document.getElementById('gvi-main-img').src=m.src;
                    document.getElementById('gvi-main-img').alt=m.caption;
                    document.getElementById('gvi-main-caption').textContent=m.caption;
                    const row=document.getElementById('gvi-thumbs');
                    row.innerHTML='';
                    vhImages.forEach(function(img,i){
                        const div=document.createElement('div');
                        div.className='gvi-thumb'+(i===gviActive?' active':'');
                        div.innerHTML='<img src="'+img.src+'" alt="'+img.caption+'" loading="lazy"><span class="gvi-thumb-caption">'+img.caption+'</span>';
                        if(i!==gviActive)div.onclick=function(){gviActive=i;gviRender();};
                        row.appendChild(div);
                    });
                }
                gviRender();
                let vhIdx=0;
                function vhOpen(i){vhIdx=(i!==undefined)?i:gviActive;vhShow();document.getElementById('vh-lb').style.display='flex';document.body.style.overflow='hidden';}
                function vhClose(){document.getElementById('vh-lb').style.display='none';document.body.style.overflow='';}
                function vhShow(){const d=vhImages[vhIdx];const i=document.getElementById('vh-lb-img');i.src=d.src;i.alt=d.caption||'Vereinshaus unser Verein';document.getElementById('vh-lb-caption').textContent=d.caption;document.getElementById('vh-lb-prev').style.display=vhImages.length>1?'':'none';document.getElementById('vh-lb-next').style.display=vhImages.length>1?'':'none';}
                function vhPrev(){vhIdx=(vhIdx-1+vhImages.length)%vhImages.length;vhShow();}
                function vhNext(){vhIdx=(vhIdx+1)%vhImages.length;vhShow();}
                document.addEventListener('keydown',function(e){if(document.getElementById('vh-lb').style.display==='flex'){if(e.key==='Escape')vhClose();if(e.key==='ArrowLeft')vhPrev();if(e.key==='ArrowRight')vhNext();}});
                </script>

                <?php else: ?>
                <!-- Platzhalter: noch keine Fotos hochgeladen -->
                <div class="vh-placeholder">
                    <?php
                    $placeholders=[['🏠','Außenansicht'],['🪑','Saal (bis 50 Personen)'],['🍽️','Küche'],['🍺','Bar & Theke'],['☀️','Terrasse'],['🎉','Festlich dekorierbar']];
                    foreach($placeholders as [$em,$lbl]):
                    ?>
                    <div class="vh-placeholder-card"><div><?php echo $em; ?></div><p><?php echo htmlspecialchars($lbl); ?></p></div>
                    <?php endforeach; ?>
                </div>
                <p style="text-align:center;margin-top:1.5rem;color:var(--text-gray);font-style:italic;">
                    Fotos folgen in Kürze – sprechen Sie uns gerne an für eine Besichtigung.
                </p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($_sec['vorstand']): ?>
    <section class="section" id="vorstand">
        <div class="container">
            <h2 class="section-title">Der Vorstand</h2>
            <p class="section-subtitle">Ihre Ansprechpartner im Verein</p>

            <?php
                // Erste Person = vertikal (Vorsitzender), Rest = horizontal gestapelt
                $__fullM  = array_slice($_vorstand, 0, 1);
                $__otherM = array_slice($_vorstand, 1);
            ?>
            <div class="vorstand-wrap">
                <div class="vorstand-row-main">
                    <!-- Linke Spalte: Vorsitzender vertikal -->
                    <?php foreach ($__fullM as $_vm): ?>
                    <div class="vorstand-card-vert fade-in">
                        <?php if (!empty($_vm['photo'])): ?>
                        <img class="vorstand-photo" src="/images/<?= htmlspecialchars($_vm['photo']) ?>" alt="<?= htmlspecialchars($_vm['name']) ?>" title="<?= htmlspecialchars($_vm['name']) ?> – <?= htmlspecialchars($_vm['role']) ?>" width="200" height="200">
                        <?php else: ?><div class="vorstand-photo" style="width:100px;height:100px;background:#e8f0e0;display:flex;align-items:center;justify-content:center;font-size:2rem;margin-bottom:1rem">👤</div><?php endif; ?>
                        <div class="vorstand-info">
                            <h3><?= htmlspecialchars($_vm['role']) ?></h3>
                            <p class="vorstand-name"><?= htmlspecialchars($_vm['name']) ?></p>
                            <?php if (!empty($_vm['email'])): ?><p>📧 <?= htmlspecialchars($_vm['email']) ?></p><?php endif; ?>
                            <?php if (!empty($_vm['phone'])): ?><p>📱 <?= htmlspecialchars($_vm['phone']) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <!-- Rechte Spalte: andere horizontal gestapelt -->
                    <div class="vorstand-col-right">
                        <?php foreach ($__otherM as $_vm): ?>
                        <div class="vorstand-card-horiz fade-in">
                            <?php if (!empty($_vm['photo'])): ?>
                            <img class="vorstand-photo" src="/images/<?= htmlspecialchars($_vm['photo']) ?>" alt="<?= htmlspecialchars($_vm['name']) ?>" title="<?= htmlspecialchars($_vm['name']) ?> – <?= htmlspecialchars($_vm['role']) ?>" width="120" height="120" loading="lazy">
                            <?php else: ?><div class="vorstand-photo" style="width:80px;height:80px;background:#e8f0e0;display:flex;align-items:center;justify-content:center;font-size:2rem">👤</div><?php endif; ?>
                            <div class="vorstand-info">
                                <h3><?= htmlspecialchars($_vm['role']) ?></h3>
                                <p class="vorstand-name"><?= htmlspecialchars($_vm['name']) ?></p>
                                <?php if (!empty($_vm['email'])): ?><p>📧 <?= htmlspecialchars($_vm['email']) ?></p><?php endif; ?>
                                <?php if (!empty($_vm['phone'])): ?><p>📱 <?= htmlspecialchars($_vm['phone']) ?></p><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!empty($_vsGroupPhoto)): ?>
                <img class="vorstand-group-photo fade-in" src="/images/<?= htmlspecialchars($_vsGroupPhoto) ?>" alt="Der Vorstand des unser Verein" title="Der Vorstand der Muster-Kleingartenverein e.V." width="800" height="400" loading="lazy">
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($_sec['gallery']): ?>
    <section class="section" id="gallery" style="background: var(--bg-light);">
        <div class="container">
            <h2 class="section-title"><?= htmlspecialchars($_galleryTitle) ?></h2>
            <?php
            // Themen-Galerie: Karten mit Cover, Klick öffnet Lightbox mit Sub-Bildern des Themas
            $_themedGallery = array_values(array_filter($_gallery, fn($g) => !empty($g['cover'] ?? $g['image'] ?? '')));
            ?>
            <?php if (!empty($_themedGallery)): ?>
            <div class="hg-themes">
                <?php foreach ($_themedGallery as $_i => $_g): ?>
                <?php $_cover = (string)($_g['cover'] ?? $_g['image'] ?? ''); $_imgCount = count($_g['images'] ?? []) + 1; ?>
                <a href="#" class="hg-theme" onclick="hgOpenTheme(<?= (int)$_i ?>);return false" aria-label="<?= htmlspecialchars(($_g['title'] ?? '') . ' – ' . $_imgCount . ' Bilder') ?>">
                    <div class="hg-theme-img">
                        <img src="/images/<?= htmlspecialchars($_cover) ?>" alt="<?= htmlspecialchars($_g['title'] ?? '') ?>" loading="lazy" width="800" height="500">
                        <?php if ($_imgCount > 1): ?>
                        <span class="hg-theme-count">📷 <?= $_imgCount ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="hg-theme-meta">
                        <?php if (!empty($_g['title'])): ?>
                        <span class="hg-theme-title"><?= htmlspecialchars($_g['title']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($_g['subtitle'])): ?>
                        <span class="hg-theme-sub"><?= htmlspecialchars($_g['subtitle']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <!-- Lightbox -->
            <div id="hglb" onclick="if(event.target===this)hgClose()">
                <button id="hglb-close" onclick="hgClose()" aria-label="Schließen">✕</button>
                <button id="hglb-prev" onclick="hgPrev()" aria-label="Vorheriges Bild">&#8249;</button>
                <div class="hglb-center">
                    <img id="hglb-img" src="" alt="">
                    <div id="hglb-cap"></div>
                    <div id="hglb-counter" style="color:#fff;opacity:0.65;font-size:0.85rem;margin-top:6px"></div>
                </div>
                <button id="hglb-next" onclick="hgNext()" aria-label="Nächstes Bild">&#8250;</button>
            </div>
            <?php else: ?>
            <p style="text-align:center;color:var(--text-gray)">Bilder folgen in Kürze.</p>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($_sec['termine'] && !empty($_termineAll)): ?>
    <section class="section" id="termine" style="background:var(--bg-light)">
        <div class="container">
            <h2 class="section-title">Termine & Veranstaltungen</h2>
            <p class="section-subtitle">Was uns in nächster Zeit erwartet</p>
            <?php
            $_deMon = ['01'=>'Januar','02'=>'Februar','03'=>'März','04'=>'April','05'=>'Mai',
                       '06'=>'Juni','07'=>'Juli','08'=>'August','09'=>'September',
                       '10'=>'Oktober','11'=>'November','12'=>'Dezember'];
            $_tByMonth = [];
            foreach ($_termineAll as $_te) {
                $_tByMonth[substr($_te['date'],0,7)][] = $_te;
            }
            ?>
            <div class="termine-wrap">
            <?php foreach ($_tByMonth as $_ym => $_tes):
                [$_yr,$_mo] = explode('-',$_ym);
            ?>
                <div class="fade-in">
                    <div class="termine-month-header">
                        <span>📅 <?= $_deMon[$_mo] ?? $_mo ?> <?= $_yr ?></span>
                    </div>
                    <div class="termine-grid">
                    <?php foreach ($_tes as $_te):
                        $teDate = DateTime::createFromFormat('Y-m-d',$_te['date']);
                        $teDow  = $teDate ? ['So','Mo','Di','Mi','Do','Fr','Sa'][(int)$teDate->format('w')] : '';
                    ?>
                        <div class="termine-item">
                            <div class="te-badge">
                                <div class="te-dow"><?= $teDow ?></div>
                                <div class="te-day"><?= $teDate ? $teDate->format('d') : '' ?></div>
                            </div>
                            <div>
                                <div class="te-title"><?= htmlspecialchars($_te['title']) ?></div>
                                <?php if (!empty($_te['desc'])): ?>
                                <div class="te-desc"><?= htmlspecialchars($_te['desc']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($_sec['links']): ?>
    <section class="section" id="links">
        <div class="container">
            <h2 class="section-title">Nützliche Links</h2>
            <p class="section-subtitle">Weiterführende Informationen rund um das Gärtnern</p>
            <div style="max-width:860px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:0.65rem">
                <?php foreach ($_links as $_lk): ?>
                <a href="<?= htmlspecialchars($_lk['url']) ?>" target="_blank" rel="noopener noreferrer"
                   style="display:flex;align-items:center;gap:0.75rem;background:#fff;border:1px solid #e4edd9;border-radius:10px;padding:0.8rem 1rem;text-decoration:none;color:inherit;transition:box-shadow .15s,border-color .15s;box-shadow:0 1px 3px rgba(0,0,0,0.05)"
                   onmouseover="this.style.boxShadow='0 4px 12px rgba(61,107,65,0.12)';this.style.borderColor='#b5d48a'"
                   onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,0.05)';this.style.borderColor='#e4edd9'">
                    <div style="font-size:1.5rem;flex-shrink:0;line-height:1"><?= htmlspecialchars($_lk['emoji'] ?? '🔗') ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;color:var(--primary-green);font-size:0.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($_lk['title']) ?></div>
                        <?php if (!empty($_lk['desc'])): ?><div style="color:var(--text-gray);font-size:0.77rem;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($_lk['desc']) ?></div><?php endif; ?>
                    </div>
                    <span style="color:#c8d8b8;font-size:0.85rem;flex-shrink:0">↗</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php endif; ?>

    <?php if ($_sec['member']): ?>
    <section class="section" id="member" style="background: linear-gradient(to bottom, var(--white), var(--bg-light));">
        <div class="container">
            <h2 class="section-title">Mitglied werden</h2>
            <p class="section-subtitle">Werden Sie Teil unserer grünen Gemeinschaft</p>
            <div style="max-width: 800px; margin: 0 auto; text-align: center;">
                <div style="background: white; padding: 3rem; border-radius: 20px; box-shadow: var(--shadow);">
                    <h3 style="color: var(--light-green); margin-bottom: 2rem; font-size: 1.8rem;">
                        Du möchtest Teil unserer Gemeinschaft werden?
                    </h3>
                    <p style="font-size: 1.1rem; line-height: 1.8; color: var(--text-gray); margin-bottom: 1.5rem;">
                        Dann freuen wir uns auf deine Nachricht mit einer kurzen Vorstellung von dir
                        (und ggf. deiner Familie).
                    </p>
                    <p style="font-size: 1.1rem; line-height: 1.8; color: var(--text-gray); margin-bottom: 2.5rem;">
                        Anschließend laden wir dich / euch herzlich zu einem ersten, ganz entspannten
                        Kennenlerngespräch ein – damit wir uns gegenseitig einen ersten Eindruck
                        voneinander machen können.
                    </p>

                    <div style="background: linear-gradient(135deg, rgba(139,195,74,0.1), rgba(124,179,66,0.05)); padding: 2rem; border-radius: 15px; margin-bottom: 2.5rem;">
                        <p style="font-style: italic; font-family: 'Playfair Display', serif; color: var(--primary-green); font-size: 1.3rem; margin: 0; line-height: 1.6;">
                            „Die schönsten Begegnungen beginnen oft ganz natürlich –<br>
                            zwischen Blumen, Erde und einem Lächeln."
                        </p>
                    </div>

                    <!-- Schnell-Kontakt Themen -->
                    <p style="font-size:.9rem;color:var(--text-gray);margin-bottom:1.2rem;font-weight:500;">Wähle dein Thema und schreib uns direkt:</p>
                    <div style="display:flex;flex-wrap:wrap;gap:.75rem;justify-content:center;margin-bottom:2rem;">
                        <button onclick="scrollToContact('🌱 Mitgliedschaft / Neue Parzelle anfragen')" class="contact-topic-btn">🌱 Parzelle anfragen</button>
                        <button onclick="scrollToContact('❓ Allgemeine Anfrage')" class="contact-topic-btn">❓ Allgemeine Frage</button>
                        <button onclick="scrollToContact('📋 Gartenfragen / Regelungen')" class="contact-topic-btn">📋 Gartenfragen</button>
                        <button onclick="scrollToContact('🏡 Vereinshaus mieten')" class="contact-topic-btn">🏡 Vereinshaus mieten</button>
                        <button onclick="scrollToContact('📅 Veranstaltungen & Termine')" class="contact-topic-btn">📅 Veranstaltungen</button>
                        <button onclick="scrollToContact('💬 Sonstiges')" class="contact-topic-btn">💬 Sonstiges</button>
                    </div>
                    <button onclick="scrollToContact('🌱 Mitgliedschaft / Neue Parzelle anfragen')" class="btn" style="background:linear-gradient(135deg,var(--primary-green),var(--light-green));color:#fff;border:none;padding:14px 36px;border-radius:50px;font-size:1rem;font-weight:700;cursor:pointer;box-shadow:0 6px 20px rgba(74,124,78,0.3);transition:.25s">
                        Jetzt Kontakt aufnehmen ↓
                    </button>
                </div>
            </div>
        </div>
    </section>

    <?php endif; ?>

    <!-- Kontakt & Anfahrt Section -->
    <?php if ($_sec['kontakt']): ?>
    <section class="section" id="kontakt" style="background: var(--white);">
        <div class="container">
            <h2 class="section-title">Kontakt & Anfahrt</h2>
            <p class="section-subtitle">So finden Sie zu uns</p>

            <div class="booking-grid">
                <div style="background: var(--bg-light); padding: 3rem; border-radius: 20px;">
                    <h3 style="color: var(--primary-green); margin-bottom: 2rem; font-size: 1.5rem;">Anfahrt</h3>

                    <!-- Local map preview; OpenStreetMap is contacted only after a deliberate click. -->
                    <div style="margin-bottom: 2rem;">
                        <a href="https://www.openstreetmap.org/?mlat=53.6637&amp;mlon=10.0048#map=16/53.6637/10.0048"
                           target="_blank" rel="noopener noreferrer"
                           aria-label="Standort des Vereinshauses auf OpenStreetMap öffnen">
                            <img src="/assets/anfahrt-karte-osm.png"
                                 width="1280" height="720" loading="lazy"
                                 alt="Karte mit dem Standort des Vereinshauses unser Verein in Musterstadt"
                                 style="display:block;width:100%;height:400px;object-fit:cover;border:0;border-radius:15px;">
                        </a>
                        <p style="font-size:.75rem;color:var(--text-gray);margin:.5rem 0 0;">
                            Kartendaten © OpenStreetMap-Mitwirkende · Klick öffnet die interaktive Karte.
                        </p>
                    </div>

                    <div style="display: grid; gap: 1.5rem;">
                        <div>
                            <h4 style="color: var(--primary-green); margin-bottom: 0.5rem;">📍 Adresse</h4>
                            <p>Musterstraße 1</p>
                            <p>PLZ Ort</p>
                        </div>

                        <div>
                            <h4 style="color: var(--primary-green); margin-bottom: 0.5rem;">📮 Postanschrift</h4>
                            <p>Postfach 620 162</p>
                            <p>DEINE PLZ · DEINE STADT</p>
                        </div>

                        <div>
                            <h4 style="color: var(--primary-green); margin-bottom: 0.5rem;">🚌 Anfahrt mit öffentlichen Verkehrsmitteln</h4>
                            <p>Bus Linie 292 – Haltestelle Oehleckerring</p>
                            <p>U-Bahn U1 – Musterstadt Nord (ca. 10 Min. Fußweg)</p>
                        </div>
                    </div>
                </div>

                <div style="background: var(--bg-light); padding: 3rem; border-radius: 20px;">
                    <h3 style="color: var(--primary-green); margin-bottom: 2rem; font-size: 1.5rem;">Kontakt</h3>

                    <form onsubmit="submitContact(event)" style="display: grid; gap: 1.5rem;">
                        <div class="form-group">
                            <label for="ct-name">Ihr Name *</label>
                            <input id="ct-name" type="text" name="contact_name" required placeholder="Max Mustermann" autocomplete="name">
                        </div>

                        <div class="form-group">
                            <label for="ct-email">E-Mail *</label>
                            <input id="ct-email" type="email" name="contact_email" required placeholder="ihre.email@beispiel.de" autocomplete="email">
                        </div>

                        <div class="form-group">
                            <label for="contactSubject">Thema *</label>
                            <select name="subject" id="contactSubject" required>
                                <option value="" disabled selected>Bitte wählen…</option>
                                <option value="🌱 Mitgliedschaft / Neue Parzelle anfragen">🌱 Mitgliedschaft / Neue Parzelle anfragen</option>
                                <option value="🏡 Vereinshaus mieten">🏡 Vereinshaus mieten</option>
                                <option value="❓ Allgemeine Anfrage">❓ Allgemeine Anfrage</option>
                                <option value="📋 Gartenfragen / Regelungen">📋 Gartenfragen / Regelungen</option>
                                <option value="📅 Veranstaltungen & Termine">📅 Veranstaltungen & Termine</option>
                                <option value="💬 Sonstiges">💬 Sonstiges</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="ct-message">Ihre Nachricht *</label>
                            <textarea id="ct-message" name="message" rows="5" required placeholder="Ihre Nachricht an uns..."></textarea>
                        </div>

                        <button type="submit" class="submit-btn">Nachricht senden</button>
                    </form>

                    <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(124,179,66,0.1); border-radius: 10px;">
                        <p style="text-align: center; margin: 0; color: var(--primary-green);">
                            📱 Telefon: <?= $_ftTel ?><br>
                            ✉️ E-Mail: <?= $_ftEmail ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php endif; ?>

    <!-- Newsletter Section -->
    <?php if ($_sec['newsletter']): ?>
    <section class="section" id="newsletter" style="background: linear-gradient(135deg, var(--primary-green), var(--light-green)); color: white; padding: 3rem 0;">
        <div class="container">
            <div style="max-width: 600px; margin: 0 auto; text-align: center;">
                <h2 style="color: white; font-size: 2rem; margin-bottom: 1rem;">Bleiben Sie informiert!</h2>
                <p style="font-size: 1.1rem; margin-bottom: 2rem; opacity: 0.95;">
                    Erhalten Sie aktuelle Neuigkeiten, Termine und Tipps rund um unseren Gartenverein.
                </p>
                <form onsubmit="submitNewsletter(event)" style="display: flex; gap: 1rem; max-width: 500px; margin: 0 auto;">
                    <label for="nl-email" class="visually-hidden">E-Mail-Adresse für Newsletter</label>
                    <input id="nl-email" type="email" name="newsletter_email" placeholder="Ihre E-Mail-Adresse" required
                           autocomplete="email"
                           style="flex: 1; padding: 1rem; border: none; border-radius: 50px; font-size: 1rem;">
                    <button type="submit"
                            style="background: white; color: var(--primary-green); border: none; padding: 1rem 2rem; border-radius: 50px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        Anmelden
                    </button>
                </form>
                <p style="font-size: 0.85rem; margin-top: 1rem; opacity: 0.8;">
                    Mit der Anmeldung akzeptieren Sie unsere <a href="/datenschutz.php" style="color: white; text-decoration: underline;">Datenschutzbestimmungen</a>.
                </p>
            </div>
        </div>
    </section>
    <?php endif; ?>

    </main>

    <footer>
        <div class="footer-content">

            <!-- Spalte 1: Branding + Kontakt -->
            <div class="footer-section">
                <div class="footer-logo-row">
                    <img src="logo.png" alt="unser Verein Logo" title="Muster-Kleingartenverein e.V." width="60" height="60" loading="lazy">
                    <span>Gartengemeinschaft<br>Musterstadt e.V.</span>
                </div>
                <p class="footer-tagline">Grüne Oase –<br>Natur, Gemeinschaft und Nachbarschaft.</p>
                <p class="footer-col-title">Kontakt</p>
                <div class="footer-contact-list">
                    <span class="footer-contact-item">
                        <span class="fci">📍</span>
                        <span><?= $_ftStr ?>, <?= $_ftPlz ?></span>
                    </span>
                    <a href="tel:<?= preg_replace('/[^0-9+]/', '', $_ftTel) ?>" title="unser Verein anrufen" class="footer-contact-item">
                        <span class="fci">📱</span>
                        <span><?= $_ftTel ?></span>
                    </a>
                    <a href="mailto:<?= $_ftEmail ?>" title="E-Mail an unser Verein senden" class="footer-contact-item">
                        <span class="fci">✉️</span>
                        <span><?= $_ftEmail ?></span>
                    </a>
                </div>
            </div>

            <!-- Spalte 2: Navigation -->
            <div class="footer-section">
                <p class="footer-col-title">Navigation</p>
                <nav class="footer-nav">
                    <a href="#home" title="Zur Startseite scrollen">Zur Startseite</a>
                    <a href="#about" title="Über die Gartengemeinschaft">Über den Verein</a>
                    <?php if ($_sec['vorstand']): ?><a href="#vorstand" title="Vorstand der Gartengemeinschaft">Unser Vorstand</a><?php endif; ?>
                    <?php if ($_sec['gallery']): ?><a href="#gallery" title="Bildergalerie des Vereins">Bildergalerie</a><?php endif; ?>
                    <?php if ($_sec['booking']): ?><a href="/vereinshaus" title="Vereinshaus in Musterstadt mieten">Vereinshaus buchen</a><?php endif; ?>
                    <?php if ($_sec['termine'] && !empty($_termineAll)): ?><a href="#termine" title="Aktuelle Veranstaltungen">Veranstaltungen</a><?php endif; ?>
                    <?php if ($_sec['links']): ?><a href="#links" title="Nützliche Links rund um den Garten">Gartenlinks</a><?php endif; ?>
                    <?php if ($_sec['member']): ?><a href="#member" title="Mitglied in der Gartengemeinschaft werden">Mitglied werden</a><?php endif; ?>
                    <?php if ($_sec['kontakt']): ?><a href="#kontakt" title="Kontakt und Anfahrt">Kontakt & Anfahrt</a><?php endif; ?>
                </nav>
            </div>

            <!-- Spalte 3: Vereinsinfos -->
            <div class="footer-section">
                <p class="footer-col-title">Verein</p>
                <div class="footer-register">
                    Muster-Kleingartenverein e.V.<br>
                    Eingetragener Verein<br>
                    Registergericht: Amtsgericht Musterstadt<br>
                    Registernummer: 12345
                </div>
            </div>

        </div>

        <div class="footer-divider" role="separator" aria-hidden="true"></div>

        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> Muster-Kleingartenverein e.V.</span>
            <span class="footer-credit">Realisierung: Andreas Schuchard · <a href="https://horizontlabor.de" target="_blank" rel="noopener" title="Horizontlabor – Webentwicklung">Horizontlabor</a></span>
            <div class="footer-bottom-links">
                <a href="/impressum.php" title="Impressum des unser Verein">Impressum</a>
                <a href="/datenschutz.php" title="Datenschutzerklärung des unser Verein">Datenschutz</a>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <button class="back-to-top" id="backToTop" type="button" aria-label="Nach oben scrollen" onclick="scrollToTop()">
        ↑
    </button>

    <script>
        // Mobile Menu Toggle
        function toggleMobileMenu(btn) {
            const navMenu = document.getElementById('navMenu');
            const isOpen = navMenu.classList.toggle('active');
            const trigger = btn || document.querySelector('.mobile-menu-btn');
            if (trigger) {
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                trigger.setAttribute('aria-label', isOpen ? 'Menü schließen' : 'Menü öffnen');
            }
        }

        // News-Ticker Pause-Toggle (WCAG 2.2.2)
        function toggleNewsTicker(btn) {
            const content = document.getElementById('newsTickerContent');
            if (!content) return;
            const paused = content.style.animationPlayState !== 'paused';
            content.style.animationPlayState = paused ? 'paused' : 'running';
            btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
            btn.setAttribute('aria-label', paused ? 'Laufschrift fortsetzen' : 'Laufschrift pausieren');
            btn.textContent = paused ? '▶' : '⏸';
        }

        // Close mobile menu when clicking on a link
        document.querySelectorAll('nav a').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('navMenu').classList.remove('active');
                const trigger = document.querySelector('.mobile-menu-btn');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.setAttribute('aria-label', 'Menü öffnen');
                }
            });
        });

        // Kalender-Funktionalität
        let currentDate = new Date();
        let selectedStart = null;
        let selectedEnd = null;
        let bookedDates = [];     // confirmed dates
        let pendingDates = [];    // pending/reserved dates
        let apiBlockedDates  = <?= json_encode(array_values(array_column($_blockedDates, 'date')), JSON_UNESCAPED_UNICODE) ?>;
        const adminBlockedRanges = <?= json_encode(array_values($_blockedRanges), JSON_UNESCAPED_UNICODE) ?>;
        function isAdminBlocked(dateStr) {
            if (apiBlockedDates.includes(dateStr)) return true;
            const d = new Date(dateStr + 'T00:00:00');
            for (const r of adminBlockedRanges) {
                if (r.from && r.to && d >= new Date(r.from + 'T00:00:00') && d <= new Date(r.to + 'T00:00:00')) return true;
            }
            return false;
        }

        async function loadBookedDates() {
            try {
                const res = await fetch('/calendar-data.php');
                const data = await res.json();
                if (data && typeof data === 'object' && !Array.isArray(data)) {
                    bookedDates     = data.confirmed || [];
                    pendingDates    = data.pending   || [];
                    if (Array.isArray(data.blocked)) apiBlockedDates = data.blocked;
                } else if (Array.isArray(data)) {
                    bookedDates = data; // fallback für altes Format
                }
            } catch(e) { /* use empty array as fallback */ }
            renderCalendar();
        }

        const monthNames = ["Januar", "Februar", "März", "April", "Mai", "Juni",
            "Juli", "August", "September", "Oktober", "November", "Dezember"
        ];

        function getDatesInRange(start, end) {
            const dates = [];
            if (!start) return dates;
            let cur = new Date(start + 'T00:00:00');
            const endD = new Date((end || start) + 'T00:00:00');
            while (cur <= endD) {
                const y = cur.getFullYear();
                const m = String(cur.getMonth() + 1).padStart(2, '0');
                const d = String(cur.getDate()).padStart(2, '0');
                dates.push(`${y}-${m}-${d}`);
                cur.setDate(cur.getDate() + 1);
            }
            return dates;
        }

        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            document.getElementById('currentMonth').textContent =
                `${monthNames[month]} ${year}`;

            const calendar = document.getElementById('calendar');
            // Entferne alte Tage
            while (calendar.children.length > 7) {
                calendar.removeChild(calendar.lastChild);
            }

            // Ausgewählter Bereich
            const selRange = getDatesInRange(selectedStart, selectedEnd);

            // Leere Felder vor dem ersten Tag
            const startDay = firstDay === 0 ? 6 : firstDay - 1; // Montag als erster Tag
            for (let i = 0; i < startDay; i++) {
                const emptyDay = document.createElement('div');
                calendar.appendChild(emptyDay);
            }

            // Tage des Monats
            for (let day = 1; day <= daysInMonth; day++) {
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day';
                dayElement.textContent = day;

                const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

                const today = new Date();
                today.setHours(0,0,0,0);
                const thisDate = new Date(dateString + 'T00:00:00');

                if (thisDate < today) {
                    dayElement.classList.add('past');
                    dayElement.style.opacity = '0.3';
                    dayElement.style.cursor = 'not-allowed';
                } else if (bookedDates.includes(dateString)) {
                    dayElement.classList.add('booked');
                    dayElement.title = 'Bereits verbindlich gebucht';
                } else if (pendingDates.includes(dateString)) {
                    dayElement.classList.add('pending');
                    dayElement.style.background = '#fff8e1';
                    dayElement.style.border = '2px solid #f9a825';
                    dayElement.style.color = '#e65100';
                    dayElement.style.cursor = 'not-allowed';
                    dayElement.title = 'Anfrage läuft – noch nicht bestätigt';
                } else if (isAdminBlocked(dateString)) {
                    dayElement.style.background = '#f3f3f3';
                    dayElement.style.color = '#bbb';
                    dayElement.style.cursor = 'not-allowed';
                    dayElement.style.textDecoration = 'line-through';
                    dayElement.title = 'Dieser Tag ist nicht verfügbar';
                } else {
                    dayElement.onclick = () => selectDate(dateString);
                    // Ausgewählten Bereich hervorheben
                    if (selRange.includes(dateString)) {
                        if (dateString === selectedStart || dateString === selectedEnd) {
                            dayElement.style.background = 'var(--primary-green)';
                            dayElement.style.color = '#fff';
                            dayElement.style.fontWeight = '700';
                        } else {
                            dayElement.style.background = 'rgba(61,107,65,0.18)';
                            dayElement.style.color = 'var(--primary-green)';
                            dayElement.style.fontWeight = '600';
                        }
                    }
                }

                // Heute hervorheben (nur wenn nicht ausgewählt)
                const todayCheck = new Date();
                if (year === todayCheck.getFullYear() && month === todayCheck.getMonth() && day === todayCheck.getDate()
                    && !selRange.includes(dateString)) {
                    dayElement.style.background = 'rgba(124,179,66,0.1)';
                    dayElement.style.borderColor = 'var(--light-green)';
                    dayElement.style.fontWeight = '700';
                }

                calendar.appendChild(dayElement);
            }
        }

        function previousMonth() {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar();
        }

        function nextMonth() {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar();
        }

        function selectDate(dateString) {
            if (!selectedStart || (selectedStart && selectedEnd)) {
                // Neue Auswahl starten
                selectedStart = dateString;
                selectedEnd = null;
            } else {
                // Enddatum setzen
                if (dateString < selectedStart) {
                    selectedEnd = selectedStart;
                    selectedStart = dateString;
                } else {
                    selectedEnd = dateString;
                }
                // Prüfen ob gebuchte oder gesperrte Tage im Bereich
                const range = getDatesInRange(selectedStart, selectedEnd);
                const blockedInRange = range.filter(d => bookedDates.includes(d));
                const adminBlockedInRange = range.filter(d => isAdminBlocked(d));
                if (blockedInRange.length > 0) {
                    const fmt = new Date(blockedInRange[0] + 'T00:00:00').toLocaleDateString('de-DE', {day:'numeric',month:'long'});
                    alert(`Der ${fmt} ist bereits gebucht und liegt in Ihrem gewählten Zeitraum. Bitte wählen Sie einen anderen Zeitraum.`);
                    selectedStart = dateString;
                    selectedEnd = null;
                } else if (adminBlockedInRange.length > 0) {
                    const fmt = new Date(adminBlockedInRange[0] + 'T00:00:00').toLocaleDateString('de-DE', {day:'numeric',month:'long'});
                    alert(`Der ${fmt} ist nicht verfügbar und liegt in Ihrem gewählten Zeitraum. Bitte wählen Sie einen anderen Zeitraum.`);
                    selectedStart = dateString;
                    selectedEnd = null;
                }
            }

            renderCalendar();
            updateBookingForm();
        }

        function resetCalendarSelection() {
            selectedStart = null;
            selectedEnd = null;
            renderCalendar();
            document.getElementById('bookingForm').classList.remove('active');
        }

        function updateBookingForm() {
            if (!selectedStart) {
                document.getElementById('bookingForm').classList.remove('active');
                return;
            }
            const dates = getDatesInRange(selectedStart, selectedEnd);
            let displayText;
            if (!selectedEnd || selectedEnd === selectedStart) {
                const opts = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
                displayText = new Date(selectedStart + 'T00:00:00').toLocaleDateString('de-DE', opts);
            } else {
                const fmtOpts = { day: 'numeric', month: 'long', year: 'numeric' };
                const s = new Date(selectedStart + 'T00:00:00').toLocaleDateString('de-DE', fmtOpts);
                const e = new Date(selectedEnd + 'T00:00:00').toLocaleDateString('de-DE', fmtOpts);
                displayText = `${s} – ${e} (${dates.length} Tage)`;
            }
            document.getElementById('selectedDate').textContent = displayText;
            document.getElementById('bookingForm').classList.add('active');
        }

        async function submitBooking(event) {
            event.preventDefault();
            const form = event.target;
            const btn = form.querySelector('button[type="submit"]');
            if (!selectedStart) { alert('Bitte wählen Sie zuerst einen Tag im Kalender.'); return; }
            btn.disabled = true;
            btn.textContent = 'Wird gesendet…';
            const data = {
                dates: getDatesInRange(selectedStart, selectedEnd),
                name: form.querySelector('[name="name"]').value,
                email: form.querySelector('[name="email"]').value,
                phone: form.querySelector('[name="phone"]').value,
                purpose: form.querySelector('[name="purpose"]')?.value || '',
                guests: parseInt(form.querySelector('[name="guests"]')?.value) || 0,
            };
            try {
                const res = await fetch('/booking.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data)
                });
                const json = await res.json();
                if (json.status === 'ok') {
                    document.getElementById('bookingForm').innerHTML = '<div style="text-align:center;padding:2rem;background:rgba(124,179,66,0.1);border-radius:15px;"><p style="font-size:1.3rem;color:var(--primary-green);font-weight:600;">✓ Anfrage gesendet!</p><p style="color:var(--text-gray);margin-top:0.5rem;">Wir melden uns innerhalb von 48 Stunden bei Ihnen.</p></div>';
                } else if (json.message === 'date_unavailable') {
                    btn.disabled = false;
                    btn.textContent = 'Anfrage senden';
                    alert('Einer der gewählten Tage ist leider bereits vergeben. Bitte wählen Sie einen anderen Zeitraum.');
                } else if (json.message === 'date_blocked') {
                    btn.disabled = false;
                    btn.textContent = 'Anfrage senden';
                    alert('Einer der gewählten Tage ist leider gesperrt und steht nicht zur Verfügung. Bitte wählen Sie einen anderen Zeitraum.');
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Anfrage senden';
                    alert('Fehler beim Senden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.');
                }
            } catch(e) {
                btn.disabled = false;
                btn.textContent = 'Anfrage senden';
                alert('Verbindungsfehler. Bitte kontaktieren Sie uns direkt unter <?= addslashes($_ftTel) ?>.');
            }
        }

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    const offset = 80; // Header height
                    const targetPosition = target.offsetTop - offset;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Scroll to contact form and pre-select topic
        function scrollToContact(topic) {
            const sel = document.getElementById('contactSubject');
            if (sel) {
                sel.value = topic;
                // Highlight the select briefly
                sel.style.borderColor = 'var(--light-green)';
                sel.style.boxShadow = '0 0 0 3px rgba(124,179,66,0.2)';
                setTimeout(() => { sel.style.borderColor = ''; sel.style.boxShadow = ''; }, 2000);
            }
            document.getElementById('kontakt').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Contact Form Submit
        async function submitContact(event) {
            event.preventDefault();
            const form = event.target;
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Wird gesendet…';
            const data = {
                name: form.querySelector('[name="contact_name"]').value,
                email: form.querySelector('[name="contact_email"]').value,
                subject: form.querySelector('[name="subject"]')?.value || '',
                message: form.querySelector('[name="message"]').value,
            };
            try {
                const res = await fetch('/contact.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data)
                });
                const json = await res.json();
                if (json.status === 'ok') {
                    form.innerHTML = '<div style="text-align:center;padding:2rem;background:rgba(124,179,66,0.1);border-radius:15px;"><p style="font-size:1.2rem;color:var(--primary-green);font-weight:600;">✓ Nachricht gesendet!</p><p style="color:var(--text-gray);margin-top:0.5rem;">Wir melden uns so schnell wie möglich.</p></div>';
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Nachricht senden';
                    alert('Fehler. Bitte versuchen Sie es erneut.');
                }
            } catch(e) {
                btn.disabled = false;
                btn.textContent = 'Nachricht senden';
                alert('Verbindungsfehler. Bitte schreiben Sie uns direkt an <?= addslashes($_ftEmail) ?>');
            }
        }

        // Newsletter Form Submit
        function submitNewsletter(event) {
            event.preventDefault();
            const form = event.target;
            form.innerHTML = '<p style="color:white;font-size:1.1rem;text-align:center;">✓ Danke! Sie erhalten in Kürze eine Bestätigungs-E-Mail.</p>';
        }

        // Gallery hover effect
        document.querySelectorAll('.gallery-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.querySelector('.gallery-card-img').style.transform = 'scale(1.1)';
            });
            card.addEventListener('mouseleave', function() {
                this.querySelector('.gallery-card-img').style.transform = 'scale(1)';
            });
        });

        // Fade in animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationDelay = '0.1s';
                    entry.target.style.animationPlayState = 'running';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in').forEach(el => {
            observer.observe(el);
        });

        // Initial load - fetch booked dates then render calendar
        loadBookedDates();

        // Close notification banner
        function closeNotification() {
            const banner = document.getElementById('notificationBanner');
            banner.style.animation = 'slideUp 0.5s ease-out forwards';
            setTimeout(() => {
                banner.style.display = 'none';
            }, 500);
        }

        // Add slide up animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideUp {
                to {
                    transform: translateY(-100%);
                }
            }
        `;
        document.head.appendChild(style);

        // Auto-close notification after 10 seconds
        setTimeout(() => {
            const banner = document.getElementById('notificationBanner');
            if (banner && banner.style.display !== 'none') {
                closeNotification();
            }
        }, 10000);

        // Dynamic year in footer
        document.querySelectorAll('.copy-year').forEach(el => {
            el.textContent = new Date().getFullYear();
        });

        // Add loading state for forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⏳</span> Wird gesendet...';
                    submitBtn.disabled = true;
                }
            });
        });

        // Back to Top Button
        const backToTopButton = document.getElementById('backToTop');

        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) {
                backToTopButton.classList.add('visible');
            } else {
                backToTopButton.classList.remove('visible');
            }
        });

        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Spin animation
        const spinStyle = document.createElement('style');
        spinStyle.textContent = `
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(spinStyle);
    </script>

    <!-- Homepage-Galerie Lightbox: Themen mit Sub-Bildern -->
    <script>
    (function(){
        // Pro Thema: Array aus Bildern [{src, caption}]. Erstes Element ist immer das Cover.
        const hgThemes = <?= json_encode(array_values(array_map(function($g) {
            $cover = (string)($g['cover'] ?? $g['image'] ?? '');
            $title = (string)($g['title'] ?? $g['caption'] ?? '');
            $subt  = (string)($g['subtitle'] ?? '');
            $items = [];
            if ($cover !== '') {
                $items[] = ['src' => '/images/' . $cover, 'caption' => trim($title . ($subt ? ' · ' . $subt : ''))];
            }
            foreach (($g['images'] ?? []) as $sub) {
                $file = (string)($sub['file'] ?? '');
                if ($file === '') continue;
                $items[] = ['src' => '/images/' . $file, 'caption' => (string)($sub['caption'] ?? '')];
            }
            return ['title' => $title, 'images' => $items];
        }, array_filter($_gallery, fn($g) => !empty($g['cover'] ?? $g['image'] ?? '')))), JSON_UNESCAPED_UNICODE) ?>;
        if (!hgThemes.length || !document.getElementById('hglb')) {
            window.hgOpen = window.hgOpenTheme = window.hgClose = window.hgPrev = window.hgNext = function(){};
            return;
        }
        let curImages = [], curIdx = 0, curTitle = '';
        const el = id => document.getElementById(id);
        function show() {
            if (!curImages.length) return;
            const d = curImages[curIdx];
            el('hglb-img').src = d.src;
            el('hglb-img').alt = d.caption;
            el('hglb-cap').textContent = d.caption;
            const counterEl = el('hglb-counter');
            if (counterEl) counterEl.textContent = curImages.length > 1
                ? (curTitle ? curTitle + ' · ' : '') + (curIdx + 1) + ' / ' + curImages.length
                : (curTitle || '');
            el('hglb-prev').style.display = curImages.length > 1 ? '' : 'none';
            el('hglb-next').style.display = curImages.length > 1 ? '' : 'none';
        }
        window.hgOpenTheme = function(themeIdx) {
            const t = hgThemes[themeIdx];
            if (!t || !t.images.length) return;
            curImages = t.images;
            curIdx    = 0;
            curTitle  = t.title;
            show();
            el('hglb').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        };
        window.hgOpen  = window.hgOpenTheme; // Abwärtskompatibilität
        window.hgClose = function(){ el('hglb').style.display = 'none'; document.body.style.overflow = ''; };
        window.hgPrev  = function(){ if (curImages.length) { curIdx = (curIdx - 1 + curImages.length) % curImages.length; show(); } };
        window.hgNext  = function(){ if (curImages.length) { curIdx = (curIdx + 1) % curImages.length; show(); } };
        document.addEventListener('keydown', e => {
            if (el('hglb').style.display === 'flex') {
                if (e.key === 'Escape')     hgClose();
                if (e.key === 'ArrowLeft')  hgPrev();
                if (e.key === 'ArrowRight') hgNext();
            }
        });
    })();
    </script>
</body>
</html>
