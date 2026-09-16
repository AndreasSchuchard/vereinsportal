<?php
require_once __DIR__ . '/inc/settings_loader.php';

$_cf = __DIR__ . '/data/content.json';
$_c  = file_exists($_cf) ? (json_decode(file_get_contents($_cf), true) ?: []) : [];
$_vereinName = trim((string)($_c['impressum']['verein'] ?? ($_c['verein']['name'] ?? 'Unser Verein')));
$_vh            = $_c['vereinshaus']   ?? ['description' => '', 'main_image' => '', 'gallery' => []];
$_prices        = $_c['prices']        ?? ['miete' => 300, 'kaution' => 200, 'strom_kwh' => 0.50, 'endreinigung' => 50, 'endreinigung_mitglied' => 0];
// Member-Tarif dynamisch (Hälfte der Standard-Miete) für SEO + Hero-Stat
$_mieteMember   = round(((float)($_prices['miete'] ?? 300)) / 2, 0);
$_endrnExt      = (float)($_prices['endreinigung']          ?? 50);
$_endrnMember   = (float)($_prices['endreinigung_mitglied'] ?? 0);
// Zusätzlich buchbare Optionen (Preisliste) – Pflege in /intern/content.php?tab=prices
$_bookingOptions = (isset($_c['booking_options']) && is_array($_c['booking_options'])) ? $_c['booking_options'] : [
    ['label' => 'Bierzelt-Garnitur (1 Tisch, 2 Bänke)', 'price' => 5,  'unit' => '€ / Set'],
    ['label' => 'Fritteusennutzung',                    'price' => 10, 'unit' => '€'],
    ['label' => 'Grill-Nutzung',                        'price' => 5,  'unit' => '€'],
    ['label' => 'Musikbox',                             'price' => 10, 'unit' => '€'],
    ['label' => 'Eiswürfel-Maschine',                   'price' => 10, 'unit' => '€'],
];
$_blockedDates  = $_c['blocked_dates']  ?? [];
$_blockedRanges = $_c['blocked_ranges'] ?? [];

// Lightbox-Bilder aufbauen
$_vhImgArr = [];
if (!empty($_vh['main_image'])) {
    $_vhImgArr[] = ['src' => '/images/' . htmlspecialchars($_vh['main_image']), 'caption' => 'Außenansicht'];
}
foreach ($_vh['gallery'] as $_vg) {
    if (!empty($_vg['image'])) {
        $_vhImgArr[] = ['src' => '/images/' . htmlspecialchars($_vg['image']), 'caption' => htmlspecialchars($_vg['caption'] ?? '')];
    }
}
$_vhJson = json_encode($_vhImgArr, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$_hasPhoto = !empty($_vh['main_image']);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vereinshaus mieten – <?= htmlspecialchars($_vereinName) ?></title>
<meta name="description" content="Vereinshaus des <?= htmlspecialchars($_vereinName) ?> mieten. Platz für bis zu 50 Personen, voll ausgestattete Küche, Terrasse. Jetzt Verfügbarkeit prüfen & anfragen.">
<link rel="canonical" href="<?= site_url() ?>/vereinshaus" />
<link rel="icon" type="image/png" href="logo.png">
<meta name="theme-color" content="#3d6b41">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/icon-180.png">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= site_url() ?>/vereinshaus">
<meta property="og:title" content="Vereinshaus mieten – <?= htmlspecialchars($_vereinName) ?>">
<meta property="og:description" content="Vereinshaus in Musterstadt mieten. Bis zu 50 Personen, Küche, Terrasse, Parkplätze. Ab <?php echo (int)$_mieteMember; ?> € für Mitglieder.">
<meta property="og:image" content="<?= site_url() ?>/front.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "EventVenue",
  "name": "Vereinshaus unser Verein",
  "description": "Vereinshaus und Veranstaltungssaal in Musterstadt. Geeignet für Geburtstage, Feiern, Vereinstreffen und private Veranstaltungen. Bis zu 50 Personen, voll ausgestattete Küche, Terrasse und Parkplätze.",
  "url": "<?= site_url() ?>/vereinshaus",
  "telephone": "+49-163-5140490",
  "email": "vorstand@example.org",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Musterstraße 1",
    "addressLocality": "DEINE STADT",
    "addressRegion": "HH",
    "postalCode": "22419",
    "addressCountry": "DE"
  },
  "geo": {
    "@type": "GeoCoordinates",
    "latitude": 53.6580,
    "longitude": 10.0140
  },
  "image": "<?= site_url() ?>/front.jpg",
  "maximumAttendeeCapacity": 50,
  "amenityFeature": [
    {"@type": "LocationFeatureSpecification", "name": "Küche", "value": true},
    {"@type": "LocationFeatureSpecification", "name": "Terrasse", "value": true},
    {"@type": "LocationFeatureSpecification", "name": "Parkplätze", "value": true},
    {"@type": "LocationFeatureSpecification", "name": "Tische und Stühle", "value": true},
    {"@type": "LocationFeatureSpecification", "name": "WC", "value": true}
  ],
  "priceRange": "€€",
  "openingHoursSpecification": {
    "@type": "OpeningHoursSpecification",
    "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"],
    "opens": "08:00",
    "closes": "23:00"
  },
  "sameAs": "<?= site_url() ?>"
}
</script>
<link rel="stylesheet" href="/assets/fonts/fonts.css">
<link rel="stylesheet" href="/vereinshaus.css">
</head>
<body>

<!-- ── NAVIGATION ── -->
<nav class="topnav">
    <a href="/" class="topnav-logo" title="unser Verein – Startseite">
        <img src="logo.png" alt="unser Verein Logo">
        <span>Gartengemeinschaft<br>Musterstadt e.V.</span>
    </a>
    <div class="topnav-links">
        <a href="#ausstattung" class="topnav-back" title="Ausstattung und Merkmale des Vereinshauses">Ausstattung</a>
        <a href="#preise" class="topnav-back" title="Mietpreise und Konditionen">Preise</a>
        <a href="/" class="topnav-back" title="Zurück zur unser Verein Website">← Startseite</a>
        <a href="#buchung" class="topnav-cta" title="Vereinshaus unserem Verein anfragen">Jetzt anfragen</a>
    </div>
</nav>

<!-- ── HERO ── -->
<section class="hero">
    <?php if ($_hasPhoto): ?>
    <img class="hero-bg" src="/images/<?php echo htmlspecialchars($_vh['main_image']); ?>" alt="Vereinshaus unser Verein">
    <div class="hero-overlay"></div>
    <?php else: ?>
    <div class="hero-no-photo"></div>
    <?php endif; ?>
    <div class="hero-content">
        <span class="hero-badge">DEINE STADT · DEINE PLZ</span>
        <h1>Vereinshaus mieten in Musterstadt</h1>
        <p class="hero-sub">
            <?php echo !empty($_vh['description']) ? htmlspecialchars($_vh['description']) : 'Ihr besonderer Ort für Feiern, Feste und Vereinstreffen – mitten in der grünen Gartenanlage des unser Verein'; ?>
        </p>
        <div class="hero-stats">
            <div class="hero-stat"><strong>50</strong><span>Personen</span></div>
            <div class="hero-stat"><strong><?php echo number_format((float)$_prices['miete'], 0, ',', '.'); ?> €</strong><span>Raummiete</span></div>
            <div class="hero-stat"><strong>DEINE PLZ</strong><span>DEINE STADT</span></div>
        </div>
        <div class="hero-actions">
            <a href="#buchung" class="btn-primary" title="Verfügbarkeit prüfen und Vereinshaus anfragen">📅 Verfügbarkeit prüfen</a>
            <a href="#galerie" class="btn-outline" title="Fotos vom Vereinshaus und Saal ansehen">🖼️ Fotos ansehen</a>
        </div>
    </div>
    <div class="hero-scroll" onclick="document.getElementById('ausstattung').scrollIntoView({behavior:'smooth'})">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8l5 5 5-5"/></svg>
        <span>Mehr erfahren</span>
    </div>
</section>

<!-- ── AUSSTATTUNG ── -->
<section class="section" id="ausstattung">
    <div class="container">
        <p class="section-label">Ausstattung</p>
        <h2 class="section-title">Was Sie bei uns erwartet</h2>
        <p class="section-sub">Alles für eine gelungene Veranstaltung – in einer grünen Oase mitten in Hamburg.</p>

        <div class="features-box">
            <div class="features-box-img">
                <?php if (!empty($_vh['main_image'])): ?>
                <img src="/images/<?php echo htmlspecialchars($_vh['main_image']); ?>" alt="Vereinshaus unser Verein" loading="lazy">
                <?php else: ?>
                <div class="features-box-img-fallback">🏡</div>
                <?php endif; ?>
            </div>
            <div class="features-box-list">
                <div class="feat-item">
                    <span class="feat-icon">🏠</span>
                    <div class="feat-text"><strong>Großer Saal</strong><span>Bis zu 50 Personen, flexibel bestuhlt für Feiern, Meetings & Events</span></div>
                </div>
                <div class="feat-item">
                    <span class="feat-icon">🍽️</span>
                    <div class="feat-text"><strong>Voll ausgestattete Küche</strong><span>Mit Geschirrspüler, ideal für Catering oder eigenes Kochen</span></div>
                </div>
                <div class="feat-item">
                    <span class="feat-icon">☀️</span>
                    <div class="feat-text"><strong>Terrasse & Garten</strong><span>Schöner Außenbereich mit Sitzgelegenheiten, umgeben von Natur</span></div>
                </div>
                <div class="feat-item">
                    <span class="feat-icon">🍺</span>
                    <div class="feat-text"><strong>Bar & Theke</strong><span>Thekenbereich für Getränkeausgabe und geselliges Beisammensein</span></div>
                </div>
                <div class="feat-item">
                    <span class="feat-icon">🅿️</span>
                    <div class="feat-text"><strong>Kostenlos parken</strong><span>Ausreichend Parkplätze direkt am Vereinshaus</span></div>
                </div>
                <div class="feat-item">
                    <span class="feat-icon">🌿</span>
                    <div class="feat-text"><strong>Idyllische Lage</strong><span>Mitten in der Gartenanlage – Ruhe, Natur, einzigartige Atmosphäre</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── GALERIE ── -->
<section class="section section-alt" id="galerie">
    <div class="container">
        <p class="section-label">Eindrücke</p>
        <h2 class="section-title">Das Vereinshaus in Bildern</h2>
        <p class="section-sub">Machen Sie sich ein Bild – klicken Sie auf ein Foto für die große Ansicht.</p>

        <?php if (!empty($_vhImgArr)): ?>
        <div class="gv-wrap">
            <div class="gv-main" id="gv-main" onclick="vhlbOpen(gvActive)">
                <img id="gv-main-img" src="<?php echo $_vhImgArr[0]['src']; ?>" alt="<?php echo htmlspecialchars($_vhImgArr[0]['caption']); ?>">
                <div class="gv-main-overlay">
                    <span class="gv-main-caption" id="gv-main-caption"><?php echo htmlspecialchars($_vhImgArr[0]['caption']); ?></span>
                    <span class="gv-lb-hint">🔍 Vergrößern</span>
                </div>
            </div>
            <div class="gv-thumbs" id="gv-thumbs"></div>
        </div>

        <!-- Lightbox -->
        <div id="vhlb" onclick="if(event.target===this)vhlbClose()">
            <button id="vhlb-close" onclick="vhlbClose()">✕</button>
            <button id="vhlb-prev" onclick="vhlbPrev()">&#8249;</button>
            <div class="vhlb-center">
                <img id="vhlb-img" src="" alt="">
                <div id="vhlb-cap"></div>
            </div>
            <button id="vhlb-next" onclick="vhlbNext()">&#8250;</button>
        </div>

        <?php else: ?>
        <div class="gallery-placeholder">
            <?php foreach([['🏠','Außenansicht'],['🪑','Großer Saal'],['🍽️','Küche'],['☀️','Terrasse'],['🍺','Bar & Theke'],['🎉','Festlich dekoriert']] as [$em,$la]): ?>
            <div class="gp-card"><div><?php echo $em; ?></div><p><?php echo htmlspecialchars($la); ?></p></div>
            <?php endforeach; ?>
        </div>
        <p class="gallery-no-photo-note">Fotos folgen in Kürze – laden Sie Bilder über den Admin-Bereich hoch.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ── PREISE ── -->
<section class="section" id="preise">
    <div class="container">
        <p class="section-label">Konditionen</p>
        <h2 class="section-title">Transparente Preise</h2>
        <p class="section-sub">Keine versteckten Kosten – alles auf einen Blick.</p>

        <div class="pricing-wrap">
            <div class="pricing-card highlight">
                <h3>💶 Mietkosten</h3>
                <div class="price-row">
                    <span>Nutzungsgebühr</span>
                    <strong><?php echo number_format((float)$_prices['miete'], 2, ',', '.'); ?> €</strong>
                </div>
                <div class="price-row">
                    <span>Endreinigung <small style="color:#8a9a8a">(besenrein übergeben)</small></span>
                    <strong><?php echo number_format($_endrnExt, 2, ',', '.'); ?> €</strong>
                </div>
                <?php if ($_endrnMember <= 0 || $_endrnMember < $_endrnExt): ?>
                <div class="price-row" style="background:#f0f7f0;border-radius:6px;padding:8px 12px;margin:4px 0">
                    <span style="color:#2e7d32">👤 Für KGV-Mitglieder</span>
                    <strong style="color:#2e7d32">
                        <?php if ($_endrnMember <= 0): ?>frei (0,00 €)<?php else: ?><?php echo number_format($_endrnMember, 2, ',', '.'); ?> €<?php endif; ?>
                    </strong>
                </div>
                <?php endif; ?>
                <div class="price-row">
                    <span>Kaution (wird zurückerstattet)</span>
                    <strong><?php echo number_format((float)$_prices['kaution'], 2, ',', '.'); ?> €</strong>
                </div>
                <div class="price-row">
                    <span>Stromverbrauch je kWh</span>
                    <strong><?php echo number_format((float)$_prices['strom_kwh'], 2, ',', '.'); ?> €</strong>
                </div>
                <div class="price-note">
                    <strong>Hinweis zur Kaution:</strong> Die Kaution von <?php echo number_format((float)$_prices['kaution'], 0, ',', '.'); ?> € wird nach der Veranstaltung zurückerstattet, sofern keine Schäden entstanden sind.
                </div>
            </div>
            <div class="pricing-card">
                <h3>✅ Im Preis enthalten</h3>
                <div class="price-row"><span>Nutzung des Saals</span><strong>✓</strong></div>
                <div class="price-row"><span>Küche & Geschirrspüler</span><strong>✓</strong></div>
                <div class="price-row"><span>Terrasse & Außenbereich</span><strong>✓</strong></div>
                <div class="price-row"><span>Parkplätze</span><strong>✓</strong></div>
                <div class="price-row"><span>Tische & Stühle</span><strong>✓</strong></div>
                <div class="price-note">
                    Zusatzoptionen (siehe Liste unten) können bei der Buchungsbestätigung hinzugefügt werden.
                </div>
            </div>
        </div>

        <div class="pricing-card" style="max-width:860px;margin:2rem auto 0">
            <h3>➕ Zusätzlich buchbare Optionen</h3>
            <?php foreach ($_bookingOptions as $_opt): ?>
            <div class="price-row">
                <span><?php echo htmlspecialchars($_opt['label'] ?? ''); ?></span>
                <strong><?php echo number_format((float)($_opt['price'] ?? 0), 2, ',', '.'); ?> <?php echo htmlspecialchars($_opt['unit'] ?? '€'); ?></strong>
            </div>
            <?php endforeach; ?>
            <div class="price-note">
                Diese Optionen sind freiwillig und werden erst nach dem Telefongespräch in der Buchungsbestätigung verbindlich hinzugefügt.
            </div>
        </div>
    </div>
</section>

<!-- ── BUCHUNG ── -->
<section class="section section-alt" id="buchung">
    <div class="container">
        <p class="section-label">Buchung</p>
        <h2 class="section-title">Verfügbarkeit & Anfrage</h2>
        <p class="section-sub">Wählen Sie Ihren Wunschtermin im Kalender und senden Sie eine unverbindliche Anfrage.</p>

        <div class="booking-grid">
            <!-- Info & Kontakt -->
            <div class="booking-info-panel">
                <h3>So einfach funktioniert es</h3>
                <div class="steps-list">
                    <div class="step-item">
                        <div class="step-num">1</div>
                        <div class="step-text"><strong>Termin wählen</strong><span>Klicken Sie auf einen freien Tag im Kalender (oder Zeitraum)</span></div>
                    </div>
                    <div class="step-item">
                        <div class="step-num">2</div>
                        <div class="step-text"><strong>Anfrage senden</strong><span>Füllen Sie das Formular aus – dauert unter 2 Minuten</span></div>
                    </div>
                    <div class="step-item">
                        <div class="step-num">3</div>
                        <div class="step-text"><strong>Bestätigung erhalten</strong><span>Wir melden uns innerhalb von 48 Stunden bei Ihnen – bitte auch den SPAM-/Junk-Ordner prüfen</span></div>
                    </div>
                </div>

                <div class="booking-notice">
                    <strong>📩 Antwort nicht erhalten?</strong> Bitte prüfen Sie nach Ihrer Anfrage auch Ihren SPAM-/Junk-Ordner – unsere Antworten auf Kontakt- und Buchungsanfragen landen dort gelegentlich.
                </div>

                <div class="booking-notice">
                    <strong>Bitte beachten:</strong> Ab 22 Uhr gelten die Gartenruhezeiten. Feiern im Vereinshaus sind selbstverständlich auch darüber hinaus möglich – bitte achten Sie ab 22 Uhr lediglich darauf, die Lautstärke nach draußen entsprechend zu dämpfen.
                </div>

                <h3 class="contact-title">Ihre Ansprechpartnerin</h3>
                <div class="contact-card">
                    <img class="contact-avatar" src="/images/avatar.png" alt="Erika Musterfrau">
                    <div>
                        <h4>Erika Musterfrau</h4>
                        <p>Kassiererin & Vermietung<br>
                        📱 +49 000 000 00 00<br>
                        ✉️ kasse@example.org</p>
                    </div>
                </div>
            </div>

            <!-- Kalender & Formular -->
            <div class="calendar-panel">
                <div class="cal-header">
                    <h4 id="currentMonth"></h4>
                    <div class="cal-nav">
                        <button class="cal-btn" onclick="previousMonth()">←</button>
                        <button class="cal-btn" onclick="nextMonth()">→</button>
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
                <div class="cal-legend">
                    <span><span class="cal-legend-dot cal-dot-booked"></span>Gebucht</span>
                    <span><span class="cal-legend-dot cal-dot-pending"></span>Reserviert</span>
                    <span><span class="cal-legend-dot cal-dot-avail"></span>Verfügbar</span>
                </div>
                <p class="cal-hint">Einzelner Tag: einmal klicken · Mehrere Tage: Start- und Endtag klicken</p>

                <div class="booking-notice" style="margin-top:1rem">
                    <strong>Hinweis:</strong> Vermietungen von Montag bis Donnerstag sind grundsätzlich nicht vorgesehen. Ausnahmen sind nur in seltenen Fällen und nach persönlicher Rücksprache mit unserer Ansprechpartnerin Erika Musterfrau möglich.
                </div>

                <div class="booking-form-wrap" id="bookingForm">
                    <div class="booking-form-title">📅 Anfrage für: <span id="selectedDate"></span></div>
                    <button type="button" class="reset-sel" onclick="resetCalendarSelection()">✕ Auswahl zurücksetzen</button>
                    <form onsubmit="submitBooking(event)">
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" name="name" required placeholder="Ihr vollständiger Name">
                        </div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>E-Mail *</label>
                                <input type="email" name="email" required placeholder="ihre@email.de">
                            </div>
                            <div class="form-group">
                                <label>Telefon *</label>
                                <input type="tel" name="phone" required placeholder="0163 ...">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Personenanzahl *</label>
                            <input type="number" name="guests" min="1" max="50" required placeholder="z.B. 25">
                        </div>
                        <div class="form-group">
                            <label>Anlass <span class="label-optional">(optional)</span></label>
                            <textarea name="purpose" rows="2" placeholder="Geburtstag, Familienfeier, Vereinstreffen ..."></textarea>
                        </div>
                        <button type="submit" class="submit-btn-vg">Anfrage unverbindlich senden →</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── FOOTER ── -->
<footer class="footer">
    <div class="container">
        <img src="logo.png" alt="unser Verein Logo" class="footer-logo">
        <p class="footer-tagline">Muster-Kleingartenverein e.V. · Hamburg</p>
        <p class="footer-nav-row">
            <a href="/" title="unser Verein – Startseite">Startseite</a>
            <a href="#ausstattung" title="Ausstattung des Vereinshauses">Ausstattung</a>
            <a href="#galerie" title="Fotos vom Vereinshaus">Galerie</a>
            <a href="#preise" title="Mietpreise und Konditionen">Preise</a>
            <a href="#buchung" title="Buchungsanfrage stellen">Buchung anfragen</a>
        </p>
        <p>
            <a href="/contact.php" title="Kontakt zum unser Verein">Kontakt</a>
            <a href="/impressum.php" title="Impressum – unser Verein">Impressum</a>
            <a href="/datenschutz.php" title="Datenschutzerklärung">Datenschutz</a>
        </p>
        <p class="footer-credit-line">Realisierung: Andreas Schuchard · <a href="https://horizontlabor.de" target="_blank" rel="noopener" title="Horizontlabor – Webentwicklung">Horizontlabor</a></p>
    </div>
</footer>

<script>
const vhlbImages        = <?php echo $_vhJson; ?>;
let adminBlockedDates    = <?= json_encode(array_values(array_column($_blockedDates, 'date')), JSON_UNESCAPED_UNICODE) ?>;
const adminBlockedRanges = <?= json_encode(array_values($_blockedRanges), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/vereinshaus.js?v=20260831" defer></script>
</body>
</html>
