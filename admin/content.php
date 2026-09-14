<?php
declare(strict_types=1);
session_start();

$_canContent = !empty($_SESSION['kgv_admin']) ||
    !empty(array_intersect($_SESSION['kgv_member']['roles'] ?? [], ['vorstand','web','schriftfuehrer']));
if (!$_canContent) {
    header('Location: /intern/');
    exit;
}

// CSRF-Token sicherstellen
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfJs = htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES);

if (isset($_POST['logout'])) {
    if (hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        session_unset();
        session_destroy();
    }
    header('Location: /intern/');
    exit;
}

define('CONTENT_FILE',  dirname(__DIR__) . '/data/content.json');
define('IMAGES_DIR',    dirname(__DIR__) . '/images/');
define('SETTINGS_FILE', dirname(__DIR__) . '/data/settings.json');
// ADMIN_PASS_FALLBACK entfernt — Password-Tab verlangt zwingend $_settings['password_hash'].
define('BACKUP_DIR',    dirname(__DIR__) . '/data/backups');
define('MAX_BACKUPS',   14);

// ── Hilfsfunktionen ─────────────────────────────────────────────────────────
function loadContent(): array {
    if (!file_exists(CONTENT_FILE)) return [];
    return json_decode((string)file_get_contents(CONTENT_FILE), true) ?: [];
}

function saveContent(array $c): void {
    file_put_contents(CONTENT_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function loadSettings(): array {
  require_once dirname(__DIR__) . '/inc/settings_loader.php';
  return load_settings();
}

function saveSettings(array $s): void {
    file_put_contents(SETTINGS_FILE, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
}

$msg = '';

// ── Backup download (GET) — nur SuperAdmin (enthält members.json mit Hashes) ─
if (isset($_GET['tab']) && $_GET['tab'] === 'backup_download' && isset($_GET['file'])) {
    if (empty($_SESSION['kgv_admin'])) { http_response_code(403); exit; }
    $fn = basename((string)$_GET['file']);
    if (!preg_match('/^backup_[\d_\-]+\.zip$/', $fn)) { http_response_code(400); exit; }
    if (!is_dir(BACKUP_DIR)) { http_response_code(404); exit; }
    $fp = BACKUP_DIR.'/'.$fn; $rp = realpath($fp); $rb = realpath(BACKUP_DIR);
    if (!$rp || !$rb || !str_starts_with($rp, $rb.DIRECTORY_SEPARATOR)) { http_response_code(403); exit; }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    header('Content-Length: '.filesize($rp));
    header('X-Content-Type-Options: nosniff');
    readfile($rp); exit;
}

// ── POST-Handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        die('Ungültiger CSRF-Token.');
    }
    $c   = loadContent();
    $tab = trim($_POST['tab'] ?? '');

    if ($tab === 'hero') {
        $c['hero']['headline']    = trim(strip_tags($_POST['headline']    ?? ''));
        $c['hero']['subtitle']    = trim(strip_tags($_POST['subtitle']    ?? ''));
        $c['hero']['description'] = trim($_POST['description'] ?? '');

        // Notification
        $c['notification']['active'] = isset($_POST['notif_active']);
        $c['notification']['text']   = trim($_POST['notif_text'] ?? '');

        // Hero-Bild hochladen
        if (!empty($_FILES['bg_image']['name']) && $_FILES['bg_image']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['bg_image']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                $ext  = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
                $dest = dirname(__DIR__) . '/front.' . $ext;
                if (move_uploaded_file($_FILES['bg_image']['tmp_name'], $dest)) {
                    $c['hero']['bg_image'] = 'front.' . $ext;
                }
            }
        }
        saveContent($c);
        $msg = 'hero_saved';

    } elseif ($tab === 'ticker') {
        $items = $_POST['ticker'] ?? [];
        $clean = [];
        foreach ($items as $item) {
            $t = trim($item);
            if ($t !== '') $clean[] = $t;
        }
        $c['ticker'] = array_values($clean);
        saveContent($c);
        $msg = 'ticker_saved';

    } elseif ($tab === 'gallery') {
        // Themen-Galerie: Section-Titel + Liste [{title, subtitle, cover, images: [{file, caption}]}]
        $titles    = $_POST['g_title']    ?? [];
        $subtitles = $_POST['g_subtitle'] ?? [];
        $covers    = $_POST['g_cover']    ?? [];
        $tDeletes  = $_POST['g_delete']   ?? [];
        $gTitle    = trim(strip_tags((string)($_POST['gallery_title'] ?? '')));
        if ($gTitle === '') $gTitle = 'Eindrücke aus dem Verein';

        if (!is_dir(IMAGES_DIR)) mkdir(IMAGES_DIR, 0755, true);

        // Hilfsfunktion: einzelnen Upload zu Filename verarbeiten
        $_processUpload = function($tmp, $err, $prefix) {
            if ($err !== UPLOAD_ERR_OK) return '';
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return '';
            $ext   = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
            $fname = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            return move_uploaded_file($tmp, IMAGES_DIR . $fname) ? $fname : '';
        };

        $gallery = [];
        $count   = max(count($titles), count($covers));
        for ($i = 0; $i < $count; $i++) {
            if (in_array((string)$i, array_map('strval', $tDeletes), true)) continue;
            $title    = trim(strip_tags((string)($titles[$i]    ?? '')));
            $subtitle = trim(strip_tags((string)($subtitles[$i] ?? '')));
            $coverFile = trim((string)($covers[$i] ?? ($c['gallery'][$i]['cover'] ?? '')));

            // Cover-Upload?
            if (!empty($_FILES['g_cover_upload']['name'][$i]) && $_FILES['g_cover_upload']['error'][$i] === UPLOAD_ERR_OK) {
                $up = $_processUpload($_FILES['g_cover_upload']['tmp_name'][$i], UPLOAD_ERR_OK, 'galerie');
                if ($up !== '') $coverFile = $up;
            }
            // Cover ist Pflicht — sonst Thema verwerfen
            if ($coverFile === '' && $title === '') continue;

            // Sub-Bilder: g_sub_files[$i][] = [Dateinamen aus hidden inputs]
            //              g_sub_captions[$i][] = [Captions]
            //              g_sub_delete[$i][] = [indices to drop]
            //              FILES['g_sub_upload']['name'][$i][$n] = neuer Upload
            $subFiles    = $_POST['g_sub_files'][$i]    ?? [];
            $subCaps     = $_POST['g_sub_captions'][$i] ?? [];
            $subDeletes  = array_map('strval', $_POST['g_sub_delete'][$i] ?? []);
            $images      = [];
            $subCount    = max(count($subFiles), count($subCaps));
            for ($j = 0; $j < $subCount; $j++) {
                if (in_array((string)$j, $subDeletes, true)) continue;
                $sf  = trim((string)($subFiles[$j] ?? ''));
                $cap = trim(strip_tags((string)($subCaps[$j] ?? '')));
                // Neues Bild für diese Slot-Position?
                if (!empty($_FILES['g_sub_upload']['name'][$i][$j]) && ($_FILES['g_sub_upload']['error'][$i][$j] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $up = $_processUpload($_FILES['g_sub_upload']['tmp_name'][$i][$j], UPLOAD_ERR_OK, 'galerie');
                    if ($up !== '') $sf = $up;
                }
                if ($sf === '') continue;
                $images[] = ['file' => $sf, 'caption' => $cap];
            }

            $gallery[] = [
                'title'    => $title,
                'subtitle' => $subtitle,
                'cover'    => $coverFile,
                'images'   => $images,
            ];
        }
        $c['gallery']       = $gallery;
        $c['gallery_title'] = $gTitle;
        saveContent($c);
        $msg = 'gallery_saved';

    } elseif ($tab === 'prices') {
        $c['prices']['miete']                 = max(0, (float)str_replace(',', '.', $_POST['miete']                 ?? '300'));
        $c['prices']['kaution']               = max(0, (float)str_replace(',', '.', $_POST['kaution']               ?? '200'));
        $c['prices']['strom_kwh']             = max(0, (float)str_replace(',', '.', $_POST['strom_kwh']             ?? '0.5'));
        $c['prices']['endreinigung']          = max(0, (float)str_replace(',', '.', $_POST['endreinigung']          ?? '50'));
        $c['prices']['endreinigung_mitglied'] = max(0, (float)str_replace(',', '.', $_POST['endreinigung_mitglied'] ?? '0'));
        saveContent($c);
        $msg = 'prices_saved';

    } elseif ($tab === 'sections') {
        $allowed = ['ticker','about','booking','vorstand','gallery','links','member','kontakt','newsletter'];
        foreach ($allowed as $key) {
            $c['sections_visible'][$key] = isset($_POST['sec_' . $key]);
        }
        saveContent($c);
        $msg = 'sections_saved';

    } elseif ($tab === 'vereinshaus') {
        if (!isset($c['vereinshaus'])) $c['vereinshaus'] = [];
        $c['vereinshaus']['description'] = trim($_POST['vh_description'] ?? '');

        // Hauptbild: aus Bibliothek gewählt
        $vhMainLib = basename(trim($_POST['vh_main_from_lib'] ?? ''));
        if ($vhMainLib !== '' && file_exists(IMAGES_DIR . $vhMainLib)) {
            $c['vereinshaus']['main_image'] = $vhMainLib;
        }
        // Hauptbild: Neu hochgeladen (überschreibt Bibliotheks-Wahl)
        if (!empty($_FILES['vh_main']['name']) && $_FILES['vh_main']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['vh_main']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                $ext   = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
                $fname = 'vh_main_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['vh_main']['tmp_name'], IMAGES_DIR . $fname)) {
                    $c['vereinshaus']['main_image'] = $fname;
                }
            }
        }

        // Galerie
        $captions = $_POST['vh_caption'] ?? [];
        $vimages  = $_POST['vh_img']     ?? [];
        $deletes  = $_POST['vh_delete']  ?? [];
        $count    = count($captions);
        $vgallery = [];
        for ($i = 0; $i < $count; $i++) {
            if (in_array((string)$i, array_map('strval', $deletes), true)) continue;
            $imgFile = trim($vimages[$i] ?? ($c['vereinshaus']['gallery'][$i]['image'] ?? ''));
            if (!empty($_FILES['vh_upload']['name'][$i]) && $_FILES['vh_upload']['error'][$i] === UPLOAD_ERR_OK) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['vh_upload']['tmp_name'][$i]);
                finfo_close($finfo);
                if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    if (!is_dir(IMAGES_DIR)) mkdir(IMAGES_DIR, 0755, true);
                    $ext   = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
                    $fname = 'vh_' . time() . '_' . $i . '.' . $ext;
                    if (move_uploaded_file($_FILES['vh_upload']['tmp_name'][$i], IMAGES_DIR . $fname)) {
                        $imgFile = $fname;
                    }
                }
            }
            $vgallery[] = [
                'image'   => $imgFile,
                'caption' => trim($captions[$i] ?? ''),
            ];
        }
        $c['vereinshaus']['gallery'] = $vgallery;
        saveContent($c);
        $msg = 'vereinshaus_saved';

    } elseif ($tab === 'settings') {
        if (!isset($c['settings'])) $c['settings'] = [];
        $c['settings']['iban']          = trim(strip_tags($_POST['iban']           ?? ''));
        $c['settings']['kontoinhaber']  = trim(strip_tags($_POST['kontoinhaber']   ?? ''));
        $c['settings']['bank']          = trim(strip_tags($_POST['bank']           ?? ''));
        $c['settings']['kontakt_name']  = trim(strip_tags($_POST['kontakt_name']   ?? ''));
        $c['settings']['kontakt_rolle'] = trim(strip_tags($_POST['kontakt_rolle']  ?? ''));
        $c['settings']['telefon']       = trim(strip_tags($_POST['telefon']        ?? ''));
        $c['settings']['email']         = trim(strip_tags($_POST['settings_email'] ?? ''));
        $c['settings']['arbeit_soll_stunden'] = max(1, (int)($_POST['arbeit_soll_stunden'] ?? 4));
        $c['settings']['zahlungsziel_wochen'] = max(1, (int)($_POST['zahlungsziel_wochen'] ?? 4));
        if (!isset($c['settings']['email_notifications'])) $c['settings']['email_notifications'] = [];
        $en = &$c['settings']['email_notifications'];
        $en['enabled']        = isset($_POST['email_notif_enabled']);
        $en['contact_to']     = trim(strip_tags($_POST['notif_contact_to']     ?? ''));
        $en['contact_cc']     = trim(strip_tags($_POST['notif_contact_cc']     ?? ''));
        $en['booking_to']     = trim(strip_tags($_POST['notif_booking_to']     ?? ''));
        $en['booking_cc']     = trim(strip_tags($_POST['notif_booking_cc']     ?? ''));
        $en['member_notify']  = isset($_POST['notif_member_notify']);
        unset($en, $c['settings']['admin_email']);
        saveContent($c);
        $msg = 'settings_saved';

    } elseif ($tab === 'password') {
        // Nur SuperAdmin darf das System-Passwort ändern (sonst könnte jede Rolle das überschreiben)
        if (empty($_SESSION['kgv_admin'])) {
            $msg = 'pw_error_forbidden';
        } else {
            $pw_current = (string)($_POST['pw_current'] ?? '');
            $pw_new     = (string)($_POST['pw_new']     ?? '');
            $pw_new2    = (string)($_POST['pw_new2']    ?? '');
            $s          = loadSettings();
            $storedHash = (string)($s['password_hash'] ?? '');
            // Kein Fallback mehr — ohne password_hash darf nichts geändert werden.
            $validCurrent = ($storedHash !== '') && password_verify($pw_current, $storedHash);
            if (!$validCurrent) {
                $msg = 'pw_error_current';
            } elseif ($pw_new !== $pw_new2) {
                $msg = 'pw_error_match';
            } elseif (strlen($pw_new) < 8) {
                $msg = 'pw_error_length';
            } else {
                $s['password_hash'] = password_hash($pw_new, PASSWORD_BCRYPT);
                saveSettings($s);
                $msg = 'pw_saved';
            }
        }

    } elseif ($tab === 'vorstand') {
        $vsNames   = $_POST['vs_name']   ?? [];
        $vsRoles   = $_POST['vs_role']   ?? [];
        $vsEmails  = $_POST['vs_email']  ?? [];
        $vsPhones  = $_POST['vs_phone']  ?? [];
        $vsPhotos  = $_POST['vs_photo']  ?? [];
        $vsFulls   = $_POST['vs_full']   ?? [];
        $vsDeletes = $_POST['vs_delete'] ?? [];
        if (!empty($_FILES['vs_group']['name']) && $_FILES['vs_group']['error'] === UPLOAD_ERR_OK) {
            $fi = finfo_open(FILEINFO_MIME_TYPE); $mi = finfo_file($fi, $_FILES['vs_group']['tmp_name']); finfo_close($fi);
            if (in_array($mi, ['image/jpeg','image/png','image/webp'], true)) {
                $ex = $mi === 'image/png' ? 'png' : ($mi === 'image/webp' ? 'webp' : 'jpg');
                $fn = 'vs_group_' . time() . '.' . $ex;
                if (move_uploaded_file($_FILES['vs_group']['tmp_name'], IMAGES_DIR . $fn)) $c['vorstand_group_photo'] = $fn;
            }
        }
        $members = [];
        for ($i = 0; $i < max(count($vsNames), count($vsRoles)); $i++) {
            if (in_array((string)$i, array_map('strval', $vsDeletes), true)) continue;
            $nm = trim(strip_tags($vsNames[$i] ?? '')); if ($nm === '') continue;
            $imgFile = trim($vsPhotos[$i] ?? ($c['vorstand'][$i]['photo'] ?? ''));
            if (!empty($_FILES['vs_upload']['name'][$i]) && $_FILES['vs_upload']['error'][$i] === UPLOAD_ERR_OK) {
                $fi = finfo_open(FILEINFO_MIME_TYPE); $mi = finfo_file($fi, $_FILES['vs_upload']['tmp_name'][$i]); finfo_close($fi);
                if (in_array($mi, ['image/jpeg','image/png','image/webp'], true)) {
                    if (!is_dir(IMAGES_DIR)) mkdir(IMAGES_DIR, 0755, true);
                    $ex = $mi === 'image/png' ? 'png' : ($mi === 'image/webp' ? 'webp' : 'jpg');
                    $fn = 'vs_' . time() . '_' . $i . '.' . $ex;
                    if (move_uploaded_file($_FILES['vs_upload']['tmp_name'][$i], IMAGES_DIR . $fn)) $imgFile = $fn;
                }
            }
            $members[] = ['role' => trim(strip_tags($vsRoles[$i] ?? '')), 'name' => $nm,
                'email' => trim(strip_tags($vsEmails[$i] ?? '')), 'phone' => trim(strip_tags($vsPhones[$i] ?? '')),
                'photo' => $imgFile, 'full' => in_array((string)$i, array_map('strval', $vsFulls), true)];
        }
        $c['vorstand'] = $members; saveContent($c); $msg = 'vorstand_saved';

    } elseif ($tab === 'termine') {
        $teDates = $_POST['te_date'] ?? []; $teTitles = $_POST['te_title'] ?? [];
        $teDescs = $_POST['te_desc'] ?? []; $tePublics = $_POST['te_public'] ?? []; $teDels = $_POST['te_delete'] ?? [];
        $termine = [];
        for ($i = 0; $i < max(count($teDates), count($teTitles)); $i++) {
            if (in_array((string)$i, array_map('strval', $teDels), true)) continue;
            $d = trim($teDates[$i] ?? ''); $tz = new DateTimeZone('Europe/Berlin');
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d, $tz);
            if (!$dt || $dt->format('Y-m-d') !== $d) continue;
            $tit = trim(strip_tags($teTitles[$i] ?? '')); if ($tit === '') continue;
            $termine[] = ['date' => $d, 'title' => $tit, 'desc' => trim(strip_tags($teDescs[$i] ?? '')),
                'public' => in_array((string)$i, array_map('strval', $tePublics), true)];
        }
        usort($termine, fn($a, $b) => strcmp($a['date'], $b['date']));
        $c['termine'] = $termine; saveContent($c); $msg = 'termine_saved';

    } elseif ($tab === 'links') {
        $lkEmojis = $_POST['lk_emoji'] ?? []; $lkTitles = $_POST['lk_title'] ?? [];
        $lkDescs  = $_POST['lk_desc']  ?? []; $lkUrls   = $_POST['lk_url']   ?? []; $lkDels = $_POST['lk_delete'] ?? [];
        $links = [];
        for ($i = 0; $i < max(count($lkTitles), count($lkUrls)); $i++) {
            if (in_array((string)$i, array_map('strval', $lkDels), true)) continue;
            $url = trim($lkUrls[$i] ?? '');
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $tit = trim(strip_tags($lkTitles[$i] ?? '')); if ($tit === '') continue;
            $links[] = ['emoji' => trim($lkEmojis[$i] ?? '🔗'), 'title' => $tit,
                'desc' => trim(strip_tags($lkDescs[$i] ?? '')), 'url' => $url];
        }
        $c['links'] = $links; saveContent($c); $msg = 'links_saved';

    } elseif ($tab === 'sperrtage') {
        $sdDates = $_POST['sd_date'] ?? []; $sdReasons = $_POST['sd_reason'] ?? []; $sdDels = $_POST['sd_delete'] ?? [];
        $blocked = [];
        for ($i = 0; $i < count($sdDates); $i++) {
            if (in_array((string)$i, array_map('strval', $sdDels), true)) continue;
            $d = trim($sdDates[$i] ?? ''); $tz = new DateTimeZone('Europe/Berlin');
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d, $tz);
            if (!$dt || $dt->format('Y-m-d') !== $d) continue;
            $blocked[] = ['date' => $d, 'reason' => trim(strip_tags($sdReasons[$i] ?? ''))];
        }
        usort($blocked, fn($a, $b) => strcmp($a['date'], $b['date']));
        $srFroms = $_POST['sr_from'] ?? []; $srTos = $_POST['sr_to'] ?? []; $srReasons = $_POST['sr_reason'] ?? []; $srDels = $_POST['sr_delete'] ?? [];
        $ranges = [];
        for ($i = 0; $i < count($srFroms); $i++) {
            if (in_array((string)$i, array_map('strval', $srDels), true)) continue;
            $tz = new DateTimeZone('Europe/Berlin');
            $f = trim($srFroms[$i] ?? ''); $t = trim($srTos[$i] ?? '');
            $dtF = DateTimeImmutable::createFromFormat('Y-m-d', $f, $tz);
            $dtT = DateTimeImmutable::createFromFormat('Y-m-d', $t, $tz);
            if (!$dtF || $dtF->format('Y-m-d') !== $f || !$dtT || $dtT->format('Y-m-d') !== $t || $dtF > $dtT) continue;
            $ranges[] = ['from' => $f, 'to' => $t, 'reason' => trim(strip_tags($srReasons[$i] ?? ''))];
        }
        usort($ranges, fn($a, $b) => strcmp($a['from'], $b['from']));
        $c['blocked_dates'] = $blocked; $c['blocked_ranges'] = $ranges; saveContent($c); $msg = 'sperrtage_saved';

    } elseif ($tab === 'email_vorlagen') {
        if (!isset($c['email_templates'])) $c['email_templates'] = [];
        foreach (['confirm_subject','confirm_body','reject_subject','reject_body','inquiry_subject','inquiry_body'] as $k) {
            $c['email_templates'][$k] = trim($_POST['et_' . $k] ?? '');
        }
        saveContent($c); $msg = 'email_vorlagen_saved';

    } elseif ($tab === 'test_email') {
        header('Content-Type: application/json');
        $toEmail = strtolower(trim((string)($_POST['test_email_to'] ?? '')));
        $tplKey  = trim((string)($_POST['test_tpl'] ?? 'confirm'));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) { echo json_encode(['status'=>'error','message'=>'Ungültige E-Mail-Adresse']); exit; }
        require_once dirname(__DIR__) . '/inc/email_template.php';
        $_cfg = $c['settings'] ?? [];
        $tplVars = [
            'name' => 'Max Mustermann', 'datum' => date('d.m.Y', strtotime('+7 days')),
            'betrag' => '300,00 EUR', 'gesamt' => '500,00 EUR', 'iban' => $_cfg['iban'] ?? 'DE12 3456 7890',
            'kontoinhaber' => $_cfg['kontoinhaber'] ?? 'KGV Musterstadt e.V.', 'kontakt_name' => $_cfg['kontakt_name'] ?? 'KGV Musterstadt e.V.',
            'telefon' => $_cfg['telefon'] ?? '', 'email_kontakt' => $_cfg['email'] ?? '',
            'zahlungsziel' => (int)($_cfg['zahlungsziel_wochen'] ?? 4) . ' Wochen',
        ];
        $etDefault = [
            'confirm_subject' => 'Buchungsbestätigung – KGV Musterstadt Vereinshaus am {datum}',
            'confirm_body'    => "Liebe/r {name},\n\nwir freuen uns, Ihre Buchungsanfrage hiermit verbindlich zu bestätigen!\n\nZeitraum: {datum}\n\nGesamtbetrag: {betrag}\nKaution: 200,00 EUR\nZu überweisen: {gesamt}\n\nEmpfänger: {kontoinhaber}\nIBAN: {iban}\n\nKontakt: {kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
            'reject_subject'  => 'Zu Ihrer Anfrage – KGV Musterstadt Vereinshaus am {datum}',
            'reject_body'     => "Liebe/r {name},\n\nvielen Dank für Ihre Anfrage zur Nutzung unseres Vereinshauses am {datum}.\n\nLeider können wir Ihnen diesen Zeitraum nicht anbieten.\n\nFür alternative Terminanfragen:\n{kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
            'inquiry_subject' => 'Ihre Anfrage ist eingegangen – KGV Musterstadt Vereinshaus am {datum}',
            'inquiry_body'    => "Liebe/r {name},\n\nvielen Dank für Ihre Buchungsanfrage zum {datum}.\n\nWir haben Ihre Anfrage erhalten und melden uns baldmöglichst bei Ihnen.\n\nKontakt: {kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
        ];
        $tpl = array_merge($etDefault, $c['email_templates'] ?? []);
        $subjectKey = $tplKey . '_subject'; $bodyKey = $tplKey . '_body';
        $subject = str_replace(array_map(fn($k) => '{' . $k . '}', array_keys($tplVars)), array_values($tplVars), $tpl[$subjectKey] ?? 'Testmail KGV Musterstadt');
        $subject = substr(preg_replace('/[\r\n\x00]+/', ' ', $subject) ?? '', 0, 200);
        $body    = str_replace(array_map(fn($k) => '{' . $k . '}', array_keys($tplVars)), array_values($tplVars), $tpl[$bodyKey]    ?? 'Dies ist eine Testmail.');
        $_sig = kgv_get_admin_sig();
        $htmlBody = "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:16px;background:#fff3cd;padding:10px 14px;border-radius:8px;font-size:0.82rem'>⚠️ Dies ist eine <strong>Testmail</strong> — kein echter Versand.</p>"
                  . "<div style='background:#f5f7f2;padding:14px 18px;border-radius:8px;border-left:4px solid #9e9e9e;white-space:pre-wrap;font-size:0.9rem;color:#2d3e2d;line-height:1.6'>" . nl2br(htmlspecialchars($body)) . "</div>";
        $html = kgv_email_html('Hallo, 👋', $htmlBody, '⚠️ Test: ' . htmlspecialchars($tpl[$subjectKey] ?? ''), $_sig['name'], $_sig['phone'], $_sig['email'], $_sig['rolle']);
        $from = 'kontakt@example.org';
        $hdr  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$from}>\r\nReturn-Path: {$from}\r\n";
        $ok = @mail($toEmail, '=?UTF-8?B?' . base64_encode('[TEST] ' . $subject) . '?=', $html, $hdr, "-f{$from}");
        echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $ok ? 'Testmail gesendet an ' . $toEmail : 'mail()-Funktion fehlgeschlagen']);
        exit;

    } elseif ($tab === 'backup_create') {
        header('Content-Type: application/json');
        if (empty($_SESSION['kgv_admin'])) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'Nur SuperAdmin']); exit; }
        if (!is_dir(BACKUP_DIR)) { mkdir(BACKUP_DIR, 0700, true); file_put_contents(BACKUP_DIR.'/.htaccess', "Require all denied\n"); }
        if (!class_exists('ZipArchive')) { echo json_encode(['status'=>'error','message'=>'ZipArchive nicht verfügbar']); exit; }
        $stamp   = date('Y-m-d_H-i-s');
        $zipName = 'backup_' . $stamp . '.zip';
        $zipPath = BACKUP_DIR . '/' . $zipName;
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { echo json_encode(['status'=>'error','message'=>'ZIP-Erstellung fehlgeschlagen']); exit; }
        foreach (glob(dirname(__DIR__).'/data/*.json') ?: [] as $f) $zip->addFile($f, 'data/'.basename($f));
        foreach (['member_files','member_msg_files'] as $sd) {
            $dir = dirname(__DIR__).'/data/'.$sd;
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $fn) { if ($fn==='.'||$fn==='..'||$fn==='.htaccess') continue; $fp=$dir.'/'.$fn; if (is_file($fp)) $zip->addFile($fp,'data/'.$sd.'/'.$fn); }
        }
        $zip->close();
        $existing = glob(BACKUP_DIR.'/backup_*.zip') ?: [];
        usort($existing, fn($a,$b) => filemtime($a)-filemtime($b));
        while (count($existing) > MAX_BACKUPS) @unlink(array_shift($existing));
        echo json_encode(['status'=>'ok','file'=>$zipName,'size'=>filesize($zipPath)]); exit;

    } elseif ($tab === 'backup_delete') {
        header('Content-Type: application/json');
        if (empty($_SESSION['kgv_admin'])) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'Nur SuperAdmin']); exit; }
        $fn = basename((string)($_POST['file'] ?? ''));
        if (preg_match('/^backup_[\d_\-]+\.zip$/', $fn)) {
            $fp = BACKUP_DIR.'/'.$fn; $rp = realpath($fp); $rb = realpath(BACKUP_DIR);
            if ($rp && $rb && str_starts_with($rp, $rb.DIRECTORY_SEPARATOR)) @unlink($rp);
        }
        echo json_encode(['status'=>'ok']); exit;

    } elseif ($tab === 'backup_download') {
        if (empty($_SESSION['kgv_admin'])) { http_response_code(403); exit; }
        $fn = basename((string)($_GET['file'] ?? ''));
        if (!preg_match('/^backup_[\d_\-]+\.zip$/', $fn)) { http_response_code(400); exit; }
        $fp = BACKUP_DIR.'/'.$fn; $rp = realpath($fp); $rb = realpath(BACKUP_DIR);
        if (!$rp || !$rb || !str_starts_with($rp, $rb.DIRECTORY_SEPARATOR)) { http_response_code(403); exit; }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        header('Content-Length: '.filesize($rp));
        header('X-Content-Type-Options: nosniff');
        readfile($rp); exit;

    } elseif ($tab === 'maintenance') {
        $s = loadSettings();
        $s['maintenance']         = isset($_POST['maintenance_on']);
        $s['maintenance_message'] = trim(strip_tags($_POST['maintenance_message'] ?? ''));
        saveSettings($s); $msg = 'maintenance_saved';

    } elseif ($tab === 'member_post') {
        // Create new member post/Aushang
        $postsFile2 = dirname(__DIR__) . '/data/member_posts.json';
        $existPosts = file_exists($postsFile2) ? (json_decode((string)file_get_contents($postsFile2), true) ?: []) : [];
        $newPost = [
            'id'         => 'post_' . uniqid('', true),
            'type'       => in_array($_POST['post_type'] ?? '', ['aushang','gemeinschaftsarbeit','protokoll','info'], true) ? $_POST['post_type'] : 'info',
            'title'      => trim(strip_tags($_POST['post_title']   ?? '')),
            'body'       => trim(strip_tags($_POST['post_body']    ?? '')),
            'date'       => trim($_POST['post_date']   ?? ''),
            'pinned'     => isset($_POST['post_pinned']),
            'created_at' => date('Y-m-d H:i:s'),
            'file'       => '',
        ];
        // Handle file upload
        if (!empty($_FILES['post_file']['tmp_name']) && $_FILES['post_file']['error'] === UPLOAD_ERR_OK) {
            $mfDir = dirname(__DIR__) . '/data/member_files/';
            if (!is_dir($mfDir)) mkdir($mfDir, 0700, true);
            $origName = basename($_FILES['post_file']['name']);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
            $safeName = time() . '_' . $safeName;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['post_file']['tmp_name']);
            $allowed = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','image/jpeg','image/png','image/webp','application/zip'];
            if (in_array($mime, $allowed, true) && $_FILES['post_file']['size'] <= 10485760) {
                move_uploaded_file($_FILES['post_file']['tmp_name'], $mfDir . $safeName);
                $newPost['file'] = $safeName;
            }
        }
        $notifySent = 0;
        if ($newPost['title'] !== '') {
            array_unshift($existPosts, $newPost);
            file_put_contents($postsFile2, json_encode($existPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        // ── E-Mail-Benachrichtigung ───────────────────────────────────────────
        if (isset($_POST['post_notify']) && $newPost['title'] !== '') {
            require_once dirname(__DIR__) . '/inc/email_template.php';
            $membersFile2 = dirname(__DIR__) . '/data/members.json';
            $allMems2 = file_exists($membersFile2) ? (json_decode((string)file_get_contents($membersFile2), true) ?: []) : [];

            $tokensChanged = false;
            foreach ($allMems2 as &$_nm) {
                if (empty($_nm['notify_token'])) {
                    $_nm['notify_token'] = bin2hex(random_bytes(16));
                    $tokensChanged = true;
                }
            }
            unset($_nm);
            if ($tokensChanged) {
                file_put_contents($membersFile2, json_encode($allMems2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }

            $sig = kgv_get_admin_sig();
            $fromEmail = 'kontakt@example.org';
            $typeLabels2 = ['aushang' => 'Aushang', 'protokoll' => 'Protokoll', 'gemeinschaftsarbeit' => 'Gemeinschaftsarbeit', 'info' => 'Info'];
            $typeLabel2  = $typeLabels2[$newPost['type']] ?? 'Mitteilung';
            $bodyPreview = mb_strlen($newPost['body']) > 300 ? mb_substr($newPost['body'], 0, 300) . '…' : $newPost['body'];
            $hasFile     = $newPost['file'] !== '';

            // Log-Eintrag vorbereiten
            $logEntry = [
                'id'         => 'log_' . uniqid('', true),
                'post_title' => $newPost['title'],
                'post_type'  => $typeLabel2,
                'sent_at'    => date('Y-m-d H:i:s'),
                'sent_by'    => $sig['name'],
                'total'      => 0,
                'success'    => 0,
                'failed'     => 0,
                'entries'    => [],
            ];

            foreach ($allMems2 as $nm) {
                if (empty($nm['active']))                      continue;
                if (empty($nm['consents']['contact_allowed'])) continue;
                if (($nm['email_posts'] ?? true) === false)    continue;
                $nmEmail = filter_var(trim((string)($nm['email'] ?? '')), FILTER_VALIDATE_EMAIL);
                if (!$nmEmail)                                 continue;

                $firstName  = explode(' ', trim((string)($nm['name'] ?? 'Mitglied')))[0];
                $unsubToken = (string)($nm['notify_token'] ?? '');
                $unsubUrl   = 'https://kgv461.de/member-api/unsubscribe.php'
                            . '?id='    . urlencode((string)($nm['id'] ?? ''))
                            . '&token=' . urlencode($unsubToken)
                            . '&type=posts';

                $emailSubj = '=?UTF-8?B?' . base64_encode('📌 Neue Mitteilung: ' . $newPost['title']) . '?=';

                $content2  = "<p style='font-size:0.88rem;color:#5a6c5a;margin:0 0 6px'>"
                           . "<span style='display:inline-block;background:#e8f5e9;border-radius:6px;padding:2px 10px;font-size:0.78rem;font-weight:700;color:#2e7d32'>"
                           . htmlspecialchars($typeLabel2) . "</span></p>"
                           . "<h2 style='margin:10px 0 14px;font-size:1.05rem;color:#2d3e2d'>"
                           . htmlspecialchars($newPost['title']) . "</h2>";
                if ($bodyPreview !== '') {
                    $content2 .= "<div style='background:#f5f7f2;border-radius:8px;padding:14px;margin-bottom:16px'>"
                              . "<p style='margin:0;white-space:pre-wrap;font-size:0.88rem;color:#2d3e2d;line-height:1.6'>"
                              . htmlspecialchars($bodyPreview) . "</p></div>";
                }
                if ($hasFile) {
                    $content2 .= "<p style='font-size:0.82rem;color:#5a6c5a;margin:0 0 16px'>📎 Anhang verfügbar</p>";
                }
                $content2 .= "<a href='https://kgv461.de/mitglieder.php?tab=pinnwand' "
                           . "style='display:inline-block;background:#3d6b41;color:#fff;"
                           . "padding:11px 26px;border-radius:8px;text-decoration:none;"
                           . "font-weight:600;font-size:0.88rem'>Zur Pinnwand →</a>";

                $html2 = kgv_email_html(
                    'Hallo ' . htmlspecialchars($firstName) . ',',
                    $content2,
                    'Neue Mitteilung · KGV Musterstadt e.V.',
                    $sig['name'], $sig['phone'], $sig['email'], $sig['rolle'],
                    $unsubUrl, (string)$nmEmail
                );

                $plain = "Hallo {$firstName},\r\n\r\n"
                       . "Es gibt eine neue Mitteilung im Mitglieder-Bereich.\r\n\r\n"
                       . "Typ: {$typeLabel2}\r\nTitel: {$newPost['title']}\r\n\r\n"
                       . ($bodyPreview !== '' ? $bodyPreview . "\r\n\r\n" : '')
                       . "Zur Pinnwand: https://kgv461.de/mitglieder.php?tab=pinnwand\r\n\r\n"
                       . "---\r\nViele Grüße\r\n{$sig['name']}, {$sig['rolle']} · KGV Musterstadt e.V.\r\n\r\n"
                       . "Abmelden: {$unsubUrl}";

                $boundary = 'kgv_' . md5(uniqid('', true));
                $hdr2 = "From: KGV Musterstadt e.V. <{$fromEmail}>\r\n"
                      . "MIME-Version: 1.0\r\n"
                      . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                      . "List-Unsubscribe: <{$unsubUrl}>\r\n"
                      . "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n"
                      . "Reply-To: {$fromEmail}\r\n";

                $body2 = "--{$boundary}\r\n"
                       . "Content-Type: text/plain; charset=UTF-8\r\n"
                       . "Content-Transfer-Encoding: base64\r\n\r\n"
                       . chunk_split(base64_encode($plain))
                       . "--{$boundary}\r\n"
                       . "Content-Type: text/html; charset=UTF-8\r\n"
                       . "Content-Transfer-Encoding: base64\r\n\r\n"
                       . chunk_split(base64_encode($html2))
                       . "--{$boundary}--";

                $logEntry['total']++;
                $sent = @mail((string)$nmEmail, $emailSubj, $body2, $hdr2, "-f{$fromEmail}");
                if ($sent) {
                    $notifySent++;
                    $logEntry['success']++;
                    $logEntry['entries'][] = ['name' => (string)($nm['name'] ?? ''), 'email' => (string)$nmEmail, 'status' => 'ok',    'ts' => date('H:i:s')];
                } else {
                    $logEntry['failed']++;
                    $logEntry['entries'][] = ['name' => (string)($nm['name'] ?? ''), 'email' => (string)$nmEmail, 'status' => 'error', 'ts' => date('H:i:s')];
                }
                if ($logEntry['total'] % 10 === 0) usleep(300000);
            }

            // Speichere Log
            $emailLogFile = dirname(__DIR__) . '/data/email_log.json';
            $emailLog = file_exists($emailLogFile) ? (json_decode((string)file_get_contents($emailLogFile), true) ?: []) : [];
            array_unshift($emailLog, $logEntry);
            if (count($emailLog) > 50) $emailLog = array_slice($emailLog, 0, 50); // max 50 Einträge
            file_put_contents($emailLogFile, json_encode($emailLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        $msg = $notifySent > 0 ? "member_post_saved&sent={$notifySent}" : 'member_post_saved';

    } elseif ($tab === 'delete_member_post') {
        $postsFile2 = dirname(__DIR__) . '/data/member_posts.json';
        $delId = trim($_POST['post_id'] ?? '');
        if ($delId !== '' && file_exists($postsFile2)) {
            $existPosts = json_decode((string)file_get_contents($postsFile2), true) ?: [];
            // Also delete file if attached
            foreach ($existPosts as $ep) {
                if (($ep['id'] ?? '') === $delId && !empty($ep['file'])) {
                    $fpath = dirname(__DIR__) . '/data/member_files/' . basename($ep['file']);
                    if (file_exists($fpath)) @unlink($fpath);
                }
            }
            $existPosts = array_values(array_filter($existPosts, fn($p) => ($p['id'] ?? '') !== $delId));
            file_put_contents($postsFile2, json_encode($existPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        $msg = 'member_post_saved';

    } elseif ($tab === 'edit_member_post') {
        $postsFile2 = dirname(__DIR__) . '/data/member_posts.json';
        $editId = trim($_POST['post_id'] ?? '');
        if ($editId !== '' && file_exists($postsFile2)) {
            $existPosts = json_decode((string)file_get_contents($postsFile2), true) ?: [];
            foreach ($existPosts as &$_ep) {
                if (($_ep['id'] ?? '') !== $editId) continue;
                $_ep['type']   = in_array($_POST['post_type'] ?? '', ['aushang','gemeinschaftsarbeit','protokoll','info'], true) ? $_POST['post_type'] : ($_ep['type'] ?? 'info');
                $_ep['title']  = trim(strip_tags($_POST['post_title']  ?? ''));
                $_ep['body']   = trim(strip_tags($_POST['post_body']   ?? ''));
                $_ep['date']   = trim($_POST['post_date']  ?? '');
                $_ep['pinned'] = isset($_POST['post_pinned']);
                // Replace file only if new one uploaded
                if (!empty($_FILES['post_file']['tmp_name']) && $_FILES['post_file']['error'] === UPLOAD_ERR_OK) {
                    $mfDir2 = dirname(__DIR__) . '/data/member_files/';
                    if (!is_dir($mfDir2)) mkdir($mfDir2, 0700, true);
                    $origName2 = basename($_FILES['post_file']['name']);
                    $safeName2 = time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName2);
                    $finfo2 = new finfo(FILEINFO_MIME_TYPE);
                    $mime2  = $finfo2->file($_FILES['post_file']['tmp_name']);
                    $allowed2 = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','image/jpeg','image/png','image/webp','application/zip'];
                    if (in_array($mime2, $allowed2, true) && $_FILES['post_file']['size'] <= 10485760) {
                        // Delete old file
                        if (!empty($_ep['file'])) { $old = dirname(__DIR__) . '/data/member_files/' . basename($_ep['file']); if (file_exists($old)) @unlink($old); }
                        move_uploaded_file($_FILES['post_file']['tmp_name'], $mfDir2 . $safeName2);
                        $_ep['file'] = $safeName2;
                    }
                }
                break;
            }
            unset($_ep);
            file_put_contents($postsFile2, json_encode($existPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        $msg = 'member_post_saved';

    } elseif ($tab === 'rechtliches') {
        $subTab = trim($_POST['sub_tab'] ?? 'impressum');
        if ($subTab === 'impressum') {
            $c['impressum'] = [
                'verein'            => trim(strip_tags($_POST['verein']            ?? '')),
                'strasse'           => trim(strip_tags($_POST['strasse']           ?? '')),
                'plz_ort'           => trim(strip_tags($_POST['plz_ort']          ?? '')),
                'vertreter'         => trim(strip_tags($_POST['vertreter']         ?? '')),
                'telefon'           => trim(strip_tags($_POST['telefon']           ?? '')),
                'email'             => trim(strip_tags($_POST['imp_email']         ?? '')),
                'postanschrift'     => trim(strip_tags($_POST['postanschrift']     ?? '')),
                'registergericht'   => trim(strip_tags($_POST['registergericht']   ?? '')),
                'registernummer'    => trim(strip_tags($_POST['registernummer']    ?? '')),
                'verantwortlich'    => trim(strip_tags($_POST['verantwortlich']    ?? '')),
                'haftung_text'      => trim($_POST['haftung_text']      ?? ''),
                'urheberrecht_text' => trim($_POST['urheberrecht_text'] ?? ''),
            ];
        } else {
            $titles  = (array)($_POST['ds_titel']  ?? []);
            $inhalte = (array)($_POST['ds_inhalt'] ?? []);
            $sections = [];
            foreach ($titles as $i => $t) {
                $sections[] = ['titel' => trim(strip_tags($t)), 'inhalt' => trim($inhalte[$i] ?? '')];
            }
            $c['datenschutz'] = [
                'stand'            => trim(strip_tags($_POST['ds_stand']            ?? '')),
                'verantwortlicher' => trim(strip_tags($_POST['ds_verantwortlicher'] ?? '')),
                'telefon'          => trim(strip_tags($_POST['ds_telefon']          ?? '')),
                'email'            => trim(strip_tags($_POST['ds_email']            ?? '')),
                'adresse'          => trim(strip_tags($_POST['ds_adresse']          ?? '')),
                'sections'         => $sections,
            ];
        }
        saveContent($c);
        $msg = 'rechtliches_saved';

    } elseif ($tab === 'delete_image') {
        $fname = basename(trim($_POST['img_file'] ?? ''));
        if ($fname !== '' && preg_match('/^[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|webp|gif)$/i', $fname)) {
            $full = IMAGES_DIR . $fname;
            if (file_exists($full) && strpos((string)realpath($full), (string)realpath(IMAGES_DIR)) === 0) {
                unlink($full);
            }
        }
        $msg = 'image_deleted';

    } elseif ($tab === 'crop_image') {
        $fname  = basename(trim($_POST['img_file'] ?? ''));
        $b64    = (string)($_POST['crop_data'] ?? '');
        if ($fname !== '' && preg_match('/^[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|webp|gif)$/i', $fname) && $b64 !== '') {
            $full = IMAGES_DIR . $fname;
            if (file_exists($full) && strpos((string)realpath($full), (string)realpath(IMAGES_DIR)) === 0) {
                // Decode base64 data URL
                if (preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/s', $b64, $m)) {
                    $imgData = base64_decode($m[2]);
                    if ($imgData !== false) {
                        file_put_contents($full, $imgData);
                    }
                }
            }
        }
        $msg = 'image_cropped';
    }
}

// ── Standarddaten (Fallback für noch nicht im CMS gepflegte Inhalte) ────────
$_vsDefault = [
    ['role'=>'1. Vorsitzender','name'=>'Max Mustermann','email'=>'vorstand@example.org','phone'=>'+49 000 000 00 00','photo'=>'','full'=>true],
    ['role'=>'Kassiererin','name'=>'Erika Musterfrau','email'=>'kasse@example.org','phone'=>'+49 000 000 00 00','photo'=>'','full'=>false],
    ['role'=>'Schriftführerin','name'=>'Maria Beispiel','email'=>'schriftfuehrer@example.org','phone'=>'+49 000 000 00 00','photo'=>'','full'=>false],
];
$_lkDefault = [
    ['emoji'=>'🌿','title'=>'Landesverband der Gartenfreunde','desc'=>'Dachverband der regionalen Kleingartenvereine','url'=>'https://www.example.org'],
    ['emoji'=>'🦋','title'=>'Naturschutzbund Hamburg (NABU)','desc'=>'Für Mensch und Natur in Hamburg','url'=>'https://hamburg.nabu.de'],
    ['emoji'=>'📚','title'=>'Garten Wissen','desc'=>'Tipps und Tricks für Ihren Garten','url'=>'https://www.mein-schoener-garten.de'],
];
$_etDefault = [
    'confirm_subject' => 'Buchungsbestätigung – KGV Musterstadt Vereinshaus am {datum}',
    'confirm_body'    => "Liebe/r {name},\n\nwir freuen uns, Ihre Buchungsanfrage hiermit verbindlich zu bestätigen!\n\nZeitraum: {datum}\n\nGesamtbetrag: {betrag} EUR\nKaution (rückzahlbar): 200,00 EUR\nZu überweisen: {gesamt} EUR\n\nEmpfänger: {kontoinhaber}\nIBAN: {iban}\nVerwendungszweck: Vereinshaus {datum} | {name}\n\nBei Rückfragen:\n{kontakt_name} · {telefon} · {email_kontakt}\n\nWir wünschen Ihnen eine schöne Veranstaltung!\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
    'reject_subject'  => 'Zu Ihrer Anfrage – KGV Musterstadt Vereinshaus am {datum}',
    'reject_body'     => "Liebe/r {name},\n\nvielen Dank für Ihre Anfrage zur Nutzung unseres Vereinshauses am {datum}.\n\nLeider können wir Ihnen diesen Zeitraum nicht anbieten.\n\nFür alternative Terminanfragen:\n{kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
    'inquiry_subject' => 'Ihre Buchungsanfrage – KGV Musterstadt Vereinshaus am {datum}',
    'inquiry_body'    => "Liebe/r {name},\n\nvielen Dank für Ihre Buchungsanfrage für den {datum}.\n\nWir haben Ihre Anfrage erhalten und werden uns schnellstmöglich bei Ihnen melden.\n\nMit freundlichen Grüßen\nKGV Musterstadt e.V.",
];

$c            = loadContent();
$hero         = $c['hero']             ?? [];
$ticker       = $c['ticker']           ?? [];
$notif        = $c['notification']     ?? [];
$gallery      = $c['gallery']          ?? [];
$prices       = $c['prices']           ?? ['miete' => 300, 'kaution' => 200, 'strom_kwh' => 0.5];
$vereinshaus  = $c['vereinshaus']      ?? ['description' => '', 'main_image' => '', 'gallery' => []];
$secVis       = $c['sections_visible'] ?? [];
$settings     = $c['settings']         ?? [];
$emailNotif   = $settings['email_notifications'] ?? [];
$vorstandData = $c['vorstand']              ?? $_vsDefault;
$vsGroupPhoto = $c['vorstand_group_photo']  ?? '6e6627d09a782d6b95bd89ba29c242b2.jpg';
$termineData  = $c['termine']              ?? [];
$linksData    = $c['links']                ?? $_lkDefault;
$blockedDates = $c['blocked_dates']        ?? [];
$blockedRanges= $c['blocked_ranges']       ?? [];
$emailTpl     = array_merge($_etDefault, $c['email_templates'] ?? []);
$smSettings   = loadSettings();
$maintenanceOn= (bool)($smSettings['maintenance'] ?? false);
$maintenanceMsg = $smSettings['maintenance_message'] ?? 'Die Website wird gerade aktualisiert. Wir sind gleich zurück.';

// Backup-Liste laden
$_backups = [];
if (is_dir(BACKUP_DIR)) {
    foreach (glob(BACKUP_DIR.'/backup_*.zip') ?: [] as $f)
        $_backups[] = ['name'=>basename($f),'size'=>filesize($f),'mtime'=>filemtime($f)];
    usort($_backups, fn($a,$b) => $b['mtime']-$a['mtime']);
}

$activeTab = $_GET['tab'] ?? ($msg ? match(true) {
    str_contains($msg, 'hero')          => 'hero',
    str_contains($msg, 'ticker')        => 'ticker',
    str_contains($msg, 'gallery')       => 'gallery',
    str_contains($msg, 'prices')        => 'prices',
    str_contains($msg, 'vereinshaus')   => 'vereinshaus',
    str_contains($msg, 'sections')      => 'sections',
    str_contains($msg, 'settings')      => 'settings',
    str_contains($msg, 'pw_')           => 'settings',
    str_contains($msg, 'vorstand')      => 'vorstand',
    str_contains($msg, 'termine')       => 'termine',
    str_contains($msg, 'links')         => 'links',
    str_contains($msg, 'sperrtage')     => 'sperrtage',
    str_contains($msg, 'email_vorlagen')=> 'email_vorlagen',
    str_contains($msg, 'maintenance')   => 'maintenance',
    str_contains($msg, 'member_post')   => 'mitglieder_inhalt',
    str_contains($msg, 'image_deleted')  => 'bild_manager',
    str_contains($msg, 'rechtliches')   => 'rechtliches',
    default                             => 'hero'
} : 'hero');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Seite bearbeiten – KGV Musterstadt</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f4ee;color:#2d3e2d;min-height:100vh}
.main{max-width:860px;margin:0 auto;padding:24px 16px}
.tabs{display:flex;gap:4px;margin-bottom:24px;background:#fff;padding:6px;border-radius:12px;border:1px solid #d4e6c3;flex-wrap:wrap}
.tab-btn{flex:0 0 auto;padding:8px 12px;border:none;background:none;border-radius:8px;cursor:pointer;font-size:0.82rem;font-weight:600;color:#5a6c5a;transition:all .15s}
.tab-btn.active{background:#3d6b41;color:#fff}
.tab-btn:hover:not(.active){background:#f0f4ee}
.tab-btn.warn.active{background:#c62828}
.tab-panel{display:none}
.tab-panel.active{display:block}
.card{background:#fff;border-radius:12px;border:1px solid #e0ead6;padding:24px;margin-bottom:20px}
.card h3{color:#3d6b41;font-size:1rem;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #d4e6c3}
.field{margin-bottom:16px}
.field label{display:block;font-size:0.82rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.field label .hint{font-weight:400;text-transform:none;letter-spacing:0;color:#8a9a8a;font-size:0.78rem}
input[type=text],input[type=number],input[type=password],textarea{width:100%;padding:10px 14px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.95rem;outline:none;font-family:inherit;background:#fff}
input[type=text]:focus,input[type=number]:focus,input[type=password]:focus,textarea:focus{border-color:#3d6b41}
textarea{resize:vertical;min-height:80px}
.toggle-row{display:flex;align-items:center;gap:12px}
.toggle{position:relative;width:46px;height:26px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#ccc;border-radius:26px;cursor:pointer;transition:.2s}
.toggle-slider:before{content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle input:checked + .toggle-slider{background:#3d6b41}
.toggle input:checked + .toggle-slider:before{transform:translateX(20px)}
.ticker-list{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.ticker-row{display:flex;gap:8px;align-items:center}
.ticker-row input{flex:1}
.move-btns{display:flex;flex-direction:column;gap:2px}
.move-btn{background:#f0f4ee;border:1px solid #d4e6c3;border-radius:4px;width:24px;height:22px;cursor:pointer;font-size:0.7rem;color:#3d6b41;display:flex;align-items:center;justify-content:center}
.move-btn:hover{background:#d4e6c3}
.del-btn{background:none;border:none;color:#c62828;cursor:pointer;font-size:1.1rem;padding:4px;line-height:1}
.del-btn:hover{color:#b71c1c}
.add-btn{background:none;border:1px dashed #3d6b41;color:#3d6b41;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:0.88rem;width:100%}
.add-btn:hover{background:#f0f4ee}
.gallery-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin-bottom:12px}
.gallery-edit-card{background:#f9fbf7;border:1px solid #d4e6c3;border-radius:10px;padding:16px;position:relative}
.gallery-edit-card .del-card-btn{position:absolute;top:10px;right:10px;background:none;border:none;color:#c62828;cursor:pointer;font-size:1rem}
.img-preview{width:100%;height:120px;object-fit:cover;border-radius:8px;margin-bottom:8px;border:1px solid #d4e6c3}
.img-placeholder{width:100%;height:120px;background:#e8f0e0;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin-bottom:8px;border:1px dashed #b8d4a8}
.upload-label{display:block;background:#f0f4ee;border:1px dashed #3d6b41;border-radius:6px;padding:7px;text-align:center;cursor:pointer;font-size:0.82rem;color:#3d6b41;margin-top:6px}
.upload-label:hover{background:#e0ead6}
.upload-label input{display:none}
.save-btn{background:#3d6b41;color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer;margin-top:8px}
.save-btn:hover{background:#2d5231}
.success-msg{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;border-radius:8px;padding:12px 18px;margin-bottom:20px;font-weight:600}
.price-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.emoji-hint{font-size:1.4rem;display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.emoji-hint span{cursor:pointer;padding:4px;border-radius:4px;transition:background .1s}
.emoji-hint span:hover{background:#d4e6c3}
/* Themen-Galerie Backoffice */
.theme-card{background:#f9fbf7;border:1px solid #d4e6c3;border-radius:10px;padding:14px 16px 16px;margin-bottom:18px}
.theme-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;color:#3d6b41;font-size:0.9rem}
.theme-card-head .del-card-btn{position:static;background:none;border:none;color:#c62828;cursor:pointer;font-size:1rem}
.theme-card-body{display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start}
.theme-cover .img-preview,.theme-cover .img-placeholder{height:160px}
.theme-meta{display:flex;flex-direction:column;gap:10px}
.theme-subs{margin-top:14px;border-top:1px dashed #d4e6c3;padding-top:14px}
.theme-subs-label{font-size:0.8rem;color:#5a8c5e;text-transform:uppercase;letter-spacing:.04em;font-weight:700;margin-bottom:8px}
.theme-sub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:10px}
.theme-sub-item{background:#fff;border:1px solid #e8f0e0;border-radius:8px;padding:8px;position:relative;display:flex;flex-direction:column;gap:6px}
.theme-sub-item .del-sub-btn{position:absolute;top:4px;right:4px;background:rgba(255,255,255,0.85);border:none;color:#c62828;cursor:pointer;font-size:0.85rem;width:22px;height:22px;border-radius:50%;line-height:1}
.img-preview-sm{width:100%;height:80px;object-fit:cover;border-radius:6px;border:1px solid #d4e6c3}
.img-placeholder-sm{width:100%;height:80px;background:#e8f0e0;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;border:1px dashed #b8d4a8}
.upload-label-sm{display:block;background:#f0f4ee;border:1px dashed #3d6b41;border-radius:5px;padding:4px;text-align:center;cursor:pointer;font-size:0.75rem;color:#3d6b41}
.upload-label-sm input{display:none}
.theme-sub-item input[type="text"]{width:100%;padding:5px 7px;font-size:0.78rem;border:1px solid #d4e6c3;border-radius:4px;font-family:inherit}
.add-btn-sm{background:none;border:1px dashed #3d6b41;color:#3d6b41;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:0.82rem}
.add-btn-sm:hover{background:#f0f4ee}
@media (max-width:700px){.theme-card-body{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__ . '/../inc/admin_nav.php'; ?>

<div class="main">

<?php if ($msg === 'pw_error_current'): ?>
<div class="success-msg" style="background:#fdecea;color:#c62828;border-color:#ef9a9a;">✗ Das aktuelle Passwort ist falsch.</div>
<?php elseif ($msg === 'pw_error_match'): ?>
<div class="success-msg" style="background:#fdecea;color:#c62828;border-color:#ef9a9a;">✗ Die neuen Passwörter stimmen nicht überein.</div>
<?php elseif ($msg === 'pw_error_length'): ?>
<div class="success-msg" style="background:#fdecea;color:#c62828;border-color:#ef9a9a;">✗ Das neue Passwort muss mindestens 8 Zeichen lang sein.</div>
<?php elseif ($msg === 'pw_saved'): ?>
<div class="success-msg">✓ Passwort erfolgreich geändert!</div>
<?php elseif (str_starts_with((string)$msg, 'member_post_saved&sent=')): ?>
<div class="success-msg">✓ Beitrag veröffentlicht · 📧 <?= (int)substr((string)$msg, strlen('member_post_saved&sent=')) ?> E-Mail<?= (int)substr((string)$msg, strlen('member_post_saved&sent=')) !== 1 ? 's' : '' ?> gesendet.</div>
<?php elseif ($msg): ?>
<div class="success-msg">✓ Gespeichert! Die Änderungen sind sofort auf der Website sichtbar.</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <button class="tab-btn <?= $activeTab === 'hero'          ? 'active' : '' ?>" onclick="switchTab('hero')">🏠 Hero</button>
  <button class="tab-btn <?= $activeTab === 'ticker'        ? 'active' : '' ?>" onclick="switchTab('ticker')">📢 Banner</button>
  <button class="tab-btn <?= $activeTab === 'gallery'       ? 'active' : '' ?>" onclick="switchTab('gallery')">🖼️ Galerie</button>
  <button class="tab-btn <?= $activeTab === 'vereinshaus'   ? 'active' : '' ?>" onclick="switchTab('vereinshaus')">🏡 Vereinshaus</button>
  <button class="tab-btn <?= $activeTab === 'vorstand'      ? 'active' : '' ?>" onclick="switchTab('vorstand')">👥 Vorstand</button>
  <button class="tab-btn <?= $activeTab === 'termine'       ? 'active' : '' ?>" onclick="switchTab('termine')">📅 Termine</button>
  <button class="tab-btn <?= $activeTab === 'links'         ? 'active' : '' ?>" onclick="switchTab('links')">🔗 Links</button>
  <button class="tab-btn <?= $activeTab === 'prices'        ? 'active' : '' ?>" onclick="switchTab('prices')">💶 Preise</button>
  <button class="tab-btn <?= $activeTab === 'sperrtage'     ? 'active' : '' ?>" onclick="switchTab('sperrtage')">🚫 Sperrtage</button>
  <button class="tab-btn <?= $activeTab === 'email_vorlagen'? 'active' : '' ?>" onclick="switchTab('email_vorlagen')">✉️ E-Mail</button>
  <button class="tab-btn <?= $activeTab === 'bild_manager'  ? 'active' : '' ?>" onclick="switchTab('bild_manager')">🖼 Bilder</button>
  <button class="tab-btn <?= $activeTab === 'sections'      ? 'active' : '' ?>" onclick="switchTab('sections')">👁 Sektionen</button>
  <button class="tab-btn <?= $activeTab === 'settings'      ? 'active' : '' ?>" onclick="switchTab('settings')">⚙️ Einstellungen</button>
  <button class="tab-btn warn <?= $activeTab === 'maintenance'? 'active' : '' ?>" onclick="switchTab('maintenance')"><?= $maintenanceOn ? '🔴' : '🟢' ?> Wartung</button>
  <button class="tab-btn <?= $activeTab === 'mitglieder_inhalt' ? 'active' : '' ?>" onclick="switchTab('mitglieder_inhalt')">👥 Mitglieder</button>
  <button class="tab-btn <?= $activeTab === 'rechtliches'       ? 'active' : '' ?>" onclick="switchTab('rechtliches')">📋 Rechtliches</button>
</div>

<!-- TAB: HERO -->
<div class="tab-panel <?= $activeTab === 'hero' ? 'active' : '' ?>" id="tab-hero">
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="tab" value="hero">

    <div class="card">
      <h3>🏠 Hero-Bereich (Startseite oben)</h3>
      <div class="field">
        <label>Überschrift</label>
        <input type="text" name="headline" value="<?= htmlspecialchars($hero['headline'] ?? '') ?>" maxlength="100">
      </div>
      <div class="field">
        <label>Untertitel</label>
        <input type="text" name="subtitle" value="<?= htmlspecialchars($hero['subtitle'] ?? '') ?>" maxlength="200">
      </div>
      <div class="field">
        <label>Beschreibungstext <span class="hint">(HTML erlaubt: &lt;br&gt; für Zeilenumbruch, &lt;strong&gt; für Fett)</span></label>
        <textarea name="description"><?= htmlspecialchars($hero['description'] ?? '') ?></textarea>
      </div>
      <div class="field">
        <label>Hintergrundbild <span class="hint">(aktuell: <?= htmlspecialchars($hero['bg_image'] ?? 'front.jpg') ?> · JPG/PNG/WebP · max. 5 MB)</span></label>
        <label class="upload-label">
          📷 Neues Bild auswählen
          <input type="file" name="bg_image" accept="image/jpeg,image/png,image/webp" onchange="previewHero(this)">
        </label>
        <div id="hero-preview" style="margin-top:10px"></div>
      </div>
    </div>

    <div class="card">
      <h3>📣 Ankündigungs-Banner <span style="font-size:0.8rem;color:#8a9a8a;font-weight:400">(gelber Streifen oben auf der Website)</span></h3>
      <div class="field">
        <div class="toggle-row">
          <label class="toggle">
            <input type="checkbox" name="notif_active" <?= !empty($notif['active']) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>
          <span style="font-size:0.9rem">Banner anzeigen</span>
        </div>
      </div>
      <div class="field">
        <label>Banner-Text <span class="hint">(&lt;strong&gt; für Fett)</span></label>
        <textarea name="notif_text"><?= htmlspecialchars($notif['text'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="card" style="margin-top:20px">
      <h3>👁 Live-Vorschau</h3>
      <div style="background:linear-gradient(135deg,#3d6b41,#5a8f5e);padding:28px 24px;border-radius:10px;color:#fff">
        <div id="prev-headline" style="font-size:1.6rem;font-weight:800;margin-bottom:8px"><?= htmlspecialchars($hero['headline'] ?? '') ?></div>
        <div id="prev-subtitle" style="font-size:0.95rem;opacity:0.85;margin-bottom:8px"><?= htmlspecialchars($hero['subtitle'] ?? '') ?></div>
        <div id="prev-desc" style="font-size:0.9rem;opacity:0.75"><?= $hero['description'] ?? '' ?></div>
      </div>
      <div style="margin-top:12px">
        <div style="background:#f9a825;color:#3a2000;padding:8px 16px;border-radius:6px;font-size:0.9rem;display:<?= !empty($notif['active']) ? 'block' : 'none' ?>" id="prev-notif-wrap">
          <span id="prev-notif"><?= $notif['text'] ?? '' ?></span>
        </div>
      </div>
    </div>

    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: TICKER -->
<div class="tab-panel <?= $activeTab === 'ticker' ? 'active' : '' ?>" id="tab-ticker">
  <form method="POST" id="ticker-form">
    <input type="hidden" name="tab" value="ticker">

    <div class="card">
      <h3>📢 Laufbanner-Nachrichten</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Der Text läuft als Banner unter dem Menü durch. Emojis am Anfang empfohlen.</p>
      <div class="emoji-hint">
        <span title="Klicken zum Kopieren" onclick="copyEmoji('📅')">📅</span>
        <span onclick="copyEmoji('🌱')">🌱</span>
        <span onclick="copyEmoji('🎊')">🎊</span>
        <span onclick="copyEmoji('🌻')">🌻</span>
        <span onclick="copyEmoji('🏡')">🏡</span>
        <span onclick="copyEmoji('⚠️')">⚠️</span>
        <span onclick="copyEmoji('📢')">📢</span>
        <span onclick="copyEmoji('✅')">✅</span>
        <span onclick="copyEmoji('🎉')">🎉</span>
        <span onclick="copyEmoji('🌿')">🌿</span>
      </div>
      <div class="ticker-list" id="ticker-list" style="margin-top:14px">
        <?php foreach ($ticker as $i => $item): ?>
        <div class="ticker-row" id="tr_<?= $i ?>">
          <div class="move-btns">
            <button type="button" class="move-btn" onclick="moveTicker(<?= $i ?>,-1)" title="Nach oben">▲</button>
            <button type="button" class="move-btn" onclick="moveTicker(<?= $i ?>,1)" title="Nach unten">▼</button>
          </div>
          <input type="text" name="ticker[]" value="<?= htmlspecialchars($item) ?>">
          <button type="button" class="del-btn" onclick="this.closest('.ticker-row').remove()" title="Löschen">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addTicker()">＋ Neuen Eintrag hinzufügen</button>
    </div>

    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: GALERIE -->
<div class="tab-panel <?= $activeTab === 'gallery' ? 'active' : '' ?>" id="tab-gallery">
  <form method="POST" enctype="multipart/form-data" id="gallery-form">
    <input type="hidden" name="tab" value="gallery">

    <div class="card">
      <h3>🖼️ Themen-Galerie Startseite</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Pro Thema (z.B. „Jubiläum", „Kinderfest", „Bienenprojekt") ein Cover-Bild und beliebig viele Sub-Bilder. Auf der Startseite werden die Themen als Karten angezeigt, ein Klick öffnet die Lightbox mit allen Bildern des Themas.</p>

      <div class="field" style="margin-bottom:22px">
        <label>Überschrift der Galerie-Sektion</label>
        <input type="text" name="gallery_title" value="<?= htmlspecialchars((string)($c['gallery_title'] ?? 'Eindrücke aus dem Verein')) ?>" maxlength="80" placeholder="z.B. Eindrücke aus dem Verein">
      </div>

      <div id="theme-list">
        <?php foreach ($gallery as $i => $g): ?>
        <div class="theme-card" id="thc_<?= $i ?>" data-idx="<?= $i ?>">
          <div class="theme-card-head">
            <strong>Thema #<?= $i + 1 ?></strong>
            <button type="button" class="del-card-btn" onclick="deleteTheme(<?= $i ?>)" title="Thema entfernen">✕</button>
          </div>
          <div class="theme-card-body">
            <div class="theme-cover">
              <?php if (!empty($g['cover'])): ?>
              <img src="/images/<?= htmlspecialchars($g['cover']) ?>" class="img-preview" id="cover_prev_<?= $i ?>">
              <?php else: ?>
              <div class="img-placeholder" id="cover_prev_<?= $i ?>">🖼️</div>
              <?php endif; ?>
              <input type="hidden" name="g_cover[]" value="<?= htmlspecialchars($g['cover'] ?? '') ?>">
              <label class="upload-label">
                📷 Cover austauschen
                <input type="file" name="g_cover_upload[<?= $i ?>]" accept="image/jpeg,image/png,image/webp"
                       onchange="previewTheme(this, <?= $i ?>)">
              </label>
            </div>
            <div class="theme-meta">
              <div class="field">
                <label>Titel</label>
                <input type="text" name="g_title[]" value="<?= htmlspecialchars($g['title'] ?? '') ?>" maxlength="80" placeholder="z.B. 50-jähriges Jubiläum">
              </div>
              <div class="field">
                <label>Untertitel <span class="hint">(Datum / Anlass, optional)</span></label>
                <input type="text" name="g_subtitle[]" value="<?= htmlspecialchars($g['subtitle'] ?? '') ?>" maxlength="80" placeholder="z.B. 19. Juli 2025">
              </div>
            </div>
          </div>
          <!-- Sub-Bilder dieses Themas -->
          <div class="theme-subs" data-theme="<?= $i ?>">
            <div class="theme-subs-label">Weitere Bilder dieses Themas (zusätzlich zum Cover)</div>
            <div class="theme-sub-grid" id="theme_subs_<?= $i ?>">
              <?php foreach (($g['images'] ?? []) as $j => $sub): ?>
              <div class="theme-sub-item" id="tsi_<?= $i ?>_<?= $j ?>">
                <button type="button" class="del-sub-btn" onclick="deleteSub(<?= $i ?>, <?= $j ?>)" title="Bild entfernen">✕</button>
                <?php if (!empty($sub['file'])): ?>
                <img src="/images/<?= htmlspecialchars($sub['file']) ?>" class="img-preview-sm" id="sub_prev_<?= $i ?>_<?= $j ?>">
                <?php else: ?>
                <div class="img-placeholder-sm" id="sub_prev_<?= $i ?>_<?= $j ?>">🖼️</div>
                <?php endif; ?>
                <input type="hidden" name="g_sub_files[<?= $i ?>][]" value="<?= htmlspecialchars($sub['file'] ?? '') ?>">
                <label class="upload-label-sm">📷
                  <input type="file" name="g_sub_upload[<?= $i ?>][<?= $j ?>]" accept="image/jpeg,image/png,image/webp" onchange="previewSub(this, <?= $i ?>, <?= $j ?>)">
                </label>
                <input type="text" name="g_sub_captions[<?= $i ?>][]" value="<?= htmlspecialchars($sub['caption'] ?? '') ?>" maxlength="120" placeholder="Bildunterschrift (optional)">
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="add-btn-sm" onclick="addSub(<?= $i ?>)">＋ Bild zum Thema hinzufügen</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="button" class="add-btn" onclick="addTheme()">＋ Neues Thema hinzufügen</button>
    </div>

    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: VEREINSHAUS -->
<div class="tab-panel <?= $activeTab === 'vereinshaus' ? 'active' : '' ?>" id="tab-vereinshaus">
  <form method="POST" enctype="multipart/form-data" id="vh-form">
    <input type="hidden" name="tab" value="vereinshaus">

    <!-- Hauptbild -->
    <div class="card">
      <h3>📸 Hauptbild (Außenansicht)</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Dieses Bild erscheint groß auf der Website als erstes Eindruck des Vereinshauses.</p>

      <?php if (!empty($vereinshaus['main_image'])): ?>
      <img src="/images/<?= htmlspecialchars($vereinshaus['main_image']) ?>" id="vh-main-prev" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid #d4e6c3;margin-bottom:12px;object-fit:cover;display:block">
      <?php else: ?>
      <div id="vh-main-prev" style="width:100%;height:140px;background:#e8f0e0;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin-bottom:12px;border:1px dashed #b8d4a8">🏠</div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <label class="upload-label" style="margin:0">
          📷 Hochladen
          <input type="file" name="vh_main" accept="image/jpeg,image/png,image/webp"
            onchange="previewVhMain(this)">
        </label>
        <button type="button" class="upload-label" style="margin:0;background:#fff;border:1px dashed #3d6b41;color:#3d6b41" onclick="openImgPicker('main',null)">🖼 Aus Bibliothek</button>
      </div>
    </div>

    <!-- Galerie -->
    <div class="card">
      <h3>🖼️ Galerie-Fotos</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Diese Fotos erscheinen als Thumbnails unter dem Hauptbild und sind in einer Lightbox anklickbar.</p>

      <div class="gallery-cards" id="vh-cards">
        <?php foreach ($vereinshaus['gallery'] as $vi => $vg): ?>
        <div class="gallery-edit-card" id="vhc_<?= $vi ?>">
          <button type="button" class="del-card-btn" onclick="vhDelCard(<?= $vi ?>)" title="Entfernen">✕</button>
          <input type="hidden" name="vh_img[]" value="<?= htmlspecialchars($vg['image'] ?? '') ?>">

          <?php if (!empty($vg['image'])): ?>
          <img src="/images/<?= htmlspecialchars($vg['image']) ?>" class="img-preview" id="vh_prev_<?= $vi ?>">
          <?php else: ?>
          <div class="img-placeholder" id="vh_prev_<?= $vi ?>">🏡</div>
          <?php endif; ?>

          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <label class="upload-label" style="margin:0">
              📷 Hochladen
              <input type="file" name="vh_upload[<?= $vi ?>]" accept="image/jpeg,image/png,image/webp"
                onchange="previewVhCard(this, <?= $vi ?>)">
            </label>
            <button type="button" class="upload-label" style="margin:0;background:#fff;border:1px dashed #3d6b41;color:#3d6b41" onclick="openImgPicker('card',<?= $vi ?>)">🖼 Bibliothek</button>
          </div>

          <div class="field" style="margin-top:10px">
            <label>Beschriftung <span class="hint">(erscheint als Bildunterschrift)</span></label>
            <input type="text" name="vh_caption[]" value="<?= htmlspecialchars($vg['caption'] ?? '') ?>" maxlength="60" placeholder="z.B. Saal mit Platz für 50 Personen">
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="button" class="add-btn" onclick="vhAddCard()">＋ Neues Foto hinzufügen</button>
    </div>

    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- BILDERBIBLIOTHEK MODAL -->
<div id="imgPickerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;overflow-y:auto" onclick="if(event.target===this)closeImgPicker()">
  <div style="background:#fff;border-radius:14px;max-width:720px;margin:40px auto;padding:24px;position:relative">
    <button type="button" onclick="closeImgPicker()" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:1.4rem;cursor:pointer;color:#5a6c5a">✕</button>
    <h3 style="margin:0 0 16px;color:#2d3e2d">🖼 Bild aus Bibliothek wählen</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px">
      <?php
      $libImgs = glob(IMAGES_DIR . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
      foreach ($libImgs as $lp):
        $lb = basename($lp);
      ?>
      <div onclick="pickImg('<?= htmlspecialchars($lb, ENT_QUOTES) ?>')"
           style="cursor:pointer;border:2px solid #e0ead6;border-radius:8px;overflow:hidden;transition:border-color .15s"
           onmouseover="this.style.borderColor='#3d6b41'" onmouseout="this.style.borderColor='#e0ead6'">
        <img src="/images/<?= htmlspecialchars($lb) ?>" style="width:100%;height:90px;object-fit:cover;display:block">
        <div style="font-size:0.65rem;color:#5a6c5a;padding:4px 6px;word-break:break-all;line-height:1.3"><?= htmlspecialchars($lb) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- TAB: PREISE -->
<div class="tab-panel <?= $activeTab === 'prices' ? 'active' : '' ?>" id="tab-prices">
  <form method="POST">
    <input type="hidden" name="tab" value="prices">

    <div class="card">
      <h3>💶 Vermietungspreise</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:20px">Diese Preise erscheinen auf der Website und in der automatischen Bestätigungsmail.</p>
      <div class="price-grid">
        <div class="field">
          <label>Nutzungsgebühr (€)</label>
          <input type="number" name="miete" value="<?= htmlspecialchars((string)($prices['miete'] ?? 300)) ?>" min="0" step="1">
        </div>
        <div class="field">
          <label>Kaution (€)</label>
          <input type="number" name="kaution" value="<?= htmlspecialchars((string)($prices['kaution'] ?? 200)) ?>" min="0" step="1">
        </div>
        <div class="field">
          <label>Strom je kWh (€)</label>
          <input type="number" name="strom_kwh" value="<?= htmlspecialchars((string)($prices['strom_kwh'] ?? 0.5)) ?>" min="0" step="0.01">
        </div>
        <div class="field">
          <label>Endreinigung Extern (€) <span style="color:#5a6c5a;font-size:0.78rem;font-weight:400">· verpflichtend pro Buchung</span></label>
          <input type="number" name="endreinigung" value="<?= htmlspecialchars((string)($prices['endreinigung'] ?? 50)) ?>" min="0" step="1">
        </div>
        <div class="field">
          <label>Endreinigung Mitglieder (€) <span style="color:#2e7d32;font-size:0.78rem;font-weight:400">· 0 = frei für Mitglieder</span></label>
          <input type="number" name="endreinigung_mitglied" value="<?= htmlspecialchars((string)($prices['endreinigung_mitglied'] ?? 0)) ?>" min="0" step="1">
        </div>
      </div>
    </div>

    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: SEKTIONEN -->
<div class="tab-panel <?= $activeTab === 'sections' ? 'active' : '' ?>" id="tab-sections">
  <form method="POST">
    <input type="hidden" name="tab" value="sections">
    <div class="card">
      <h3>👁 Sektionen ein- / ausblenden</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:20px">Deaktivierte Sektionen werden auf der Website vollständig ausgeblendet. Änderungen sind sofort sichtbar.</p>
      <div style="display:flex;flex-direction:column;gap:14px">
<?php
$sectionDefs = [
    'ticker'     => ['📢', 'Laufbanner / News-Ticker'],
    'about'      => ['🌿', 'Über Uns'],
    'booking'    => ['🏡', 'Vereinshaus mieten (Startseite)'],
    'vorstand'   => ['👥', 'Der Vorstand'],
    'gallery'    => ['🖼️', 'Galerie / Veranstaltungen'],
    'termine'    => ['📅', 'Termine & Veranstaltungen'],
    'links'      => ['🔗', 'Links & Partner'],
    'member'     => ['🌱', 'Mitglied werden'],
    'kontakt'    => ['📍', 'Kontakt & Anfahrt'],
    'newsletter' => ['📬', 'Newsletter-Hinweis'],
];
foreach ($sectionDefs as $key => [$icon, $label]):
    $checked = ($secVis[$key] ?? true) ? 'checked' : '';
?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px">
          <span style="font-size:1rem;font-weight:600;color:#2d3e2d"><?= $icon ?> <?= htmlspecialchars($label) ?></span>
          <label class="toggle" title="<?= htmlspecialchars($label) ?> ein/ausblenden">
            <input type="checkbox" name="sec_<?= $key ?>" <?= $checked ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
<?php endforeach; ?>
      </div>
    </div>
    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: VORSTAND -->
<div class="tab-panel <?= $activeTab === 'vorstand' ? 'active' : '' ?>" id="tab-vorstand">
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="tab" value="vorstand">
    <div class="card">
      <h3>📸 Gruppenf‌oto (erscheint unter den Karten)</h3>
      <?php if (!empty($vsGroupPhoto)): ?>
      <img src="/images/<?= htmlspecialchars($vsGroupPhoto) ?>" id="vs-grp-prev" style="max-width:100%;max-height:160px;border-radius:10px;object-fit:cover;margin-bottom:12px;border:1px solid #d4e6c3;display:block">
      <?php else: ?>
      <div id="vs-grp-prev" style="width:100%;height:80px;background:#e8f0e0;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:2rem;margin-bottom:12px">👥</div>
      <?php endif; ?>
      <label class="upload-label">📷 Foto hochladen / ersetzen<input type="file" name="vs_group" accept="image/jpeg,image/png,image/webp" onchange="previewFile(this,'vs-grp-prev')"></label>
    </div>
    <div class="card">
      <h3>👥 Vorstandsmitglieder</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Vollbreite = zentriert und größer dargestellt (für den 1. Vorsitzenden empfohlen).</p>
      <div class="gallery-cards" id="vs-cards">
        <?php foreach ($vorstandData as $vi => $vm): ?>
        <div class="gallery-edit-card" id="vsc_<?= $vi ?>">
          <button type="button" class="del-card-btn" onclick="vsDelCard(<?= $vi ?>)">✕</button>
          <input type="hidden" name="vs_photo[]" value="<?= htmlspecialchars($vm['photo'] ?? '') ?>">
          <?php if (!empty($vm['photo'])): ?>
          <img src="/images/<?= htmlspecialchars($vm['photo']) ?>" class="img-preview" id="vs_prev_<?= $vi ?>">
          <?php else: ?>
          <div class="img-placeholder" id="vs_prev_<?= $vi ?>">👤</div>
          <?php endif; ?>
          <label class="upload-label">📷 Foto<input type="file" name="vs_upload[<?= $vi ?>]" accept="image/jpeg,image/png,image/webp" onchange="previewCard2(this,'vs_prev_',<?= $vi ?>)"></label>
          <div class="field" style="margin-top:8px"><label>Rolle</label><input type="text" name="vs_role[]" value="<?= htmlspecialchars($vm['role'] ?? '') ?>" maxlength="60" placeholder="z.B. 1. Vorsitzender"></div>
          <div class="field"><label>Name</label><input type="text" name="vs_name[]" value="<?= htmlspecialchars($vm['name'] ?? '') ?>" maxlength="80"></div>
          <div class="field"><label>E-Mail</label><input type="text" name="vs_email[]" value="<?= htmlspecialchars($vm['email'] ?? '') ?>" maxlength="120"></div>
          <div class="field"><label>Telefon</label><input type="text" name="vs_phone[]" value="<?= htmlspecialchars($vm['phone'] ?? '') ?>" maxlength="40"></div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
            <label class="toggle"><input type="checkbox" name="vs_full[]" value="<?= $vi ?>" <?= !empty($vm['full']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
            <span style="font-size:0.85rem">Vollbreite</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="vsAddCard()">＋ Mitglied hinzufügen</button>
    </div>
    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: TERMINE -->
<div class="tab-panel <?= $activeTab === 'termine' ? 'active' : '' ?>" id="tab-termine">
  <form method="POST" id="termine-form">
    <input type="hidden" name="tab" value="termine">
    <div class="card">
      <h3>📅 Termine & Veranstaltungen</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Nur öffentliche Termine werden auf der Website angezeigt. Abgelaufene Termine können drin bleiben – sie werden automatisch ausgeblendet.</p>
      <div id="termine-list" style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px">
        <?php foreach ($termineData as $ti => $te): ?>
        <div style="display:grid;grid-template-columns:140px 1fr 1fr auto auto;gap:8px;align-items:center;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:12px" id="ter_<?= $ti ?>">
          <input type="date" name="te_date[]" value="<?= htmlspecialchars($te['date'] ?? '') ?>" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem">
          <input type="text" name="te_title[]" value="<?= htmlspecialchars($te['title'] ?? '') ?>" placeholder="Veranstaltungsname" maxlength="80">
          <input type="text" name="te_desc[]" value="<?= htmlspecialchars($te['desc'] ?? '') ?>" placeholder="Kurzbeschreibung (optional)" maxlength="120">
          <label class="toggle" title="Öffentlich anzeigen"><input type="checkbox" name="te_public[]" value="<?= $ti ?>" <?= !empty($te['public']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
          <button type="button" class="del-btn" onclick="delTermin(<?= $ti ?>)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addTermin()">＋ Termin hinzufügen</button>
    </div>
    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: LINKS -->
<div class="tab-panel <?= $activeTab === 'links' ? 'active' : '' ?>" id="tab-links">
  <form method="POST" id="links-form">
    <input type="hidden" name="tab" value="links">
    <div class="card">
      <h3>🔗 Links & Partner</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:16px">Vollständige URL mit https:// eingeben.</p>
      <div id="links-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
        <?php foreach ($linksData as $li => $lk): ?>
        <div style="display:grid;grid-template-columns:50px 1fr 1fr 2fr auto;gap:8px;align-items:center;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:12px" id="lk_<?= $li ?>">
          <input type="text" name="lk_emoji[]" value="<?= htmlspecialchars($lk['emoji'] ?? '🔗') ?>" maxlength="8" style="text-align:center;font-size:1.3rem;padding:6px">
          <input type="text" name="lk_title[]" value="<?= htmlspecialchars($lk['title'] ?? '') ?>" placeholder="Name / Titel" maxlength="80">
          <input type="text" name="lk_desc[]"  value="<?= htmlspecialchars($lk['desc']  ?? '') ?>" placeholder="Kurzbeschreibung" maxlength="120">
          <input type="text" name="lk_url[]"   value="<?= htmlspecialchars($lk['url']   ?? '') ?>" placeholder="https://..." maxlength="300">
          <button type="button" class="del-btn" onclick="delLink(<?= $li ?>)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addLink()">＋ Link hinzufügen</button>
    </div>
    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: SPERRTAGE -->
<div class="tab-panel <?= $activeTab === 'sperrtage' ? 'active' : '' ?>" id="tab-sperrtage">
  <form method="POST">
    <input type="hidden" name="tab" value="sperrtage">
    <div class="card">
      <h3>🚫 Einzelne gesperrte Tage</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:14px">Diese Tage können im Buchungsformular nicht gewählt werden.</p>
      <div id="sd-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
        <?php foreach ($blockedDates as $sdi => $sd): ?>
        <div style="display:flex;gap:8px;align-items:center" id="sd_<?= $sdi ?>">
          <input type="date" name="sd_date[]" value="<?= htmlspecialchars($sd['date'] ?? '') ?>" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem">
          <input type="text" name="sd_reason[]" value="<?= htmlspecialchars($sd['reason'] ?? '') ?>" placeholder="Grund (optional)" maxlength="60" style="flex:1">
          <button type="button" class="del-btn" onclick="delSd(<?= $sdi ?>)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addSd()">＋ Tag sperren</button>
    </div>
    <div class="card">
      <h3>🚫 Gesperrte Zeiträume</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:14px">Alle Tage innerhalb dieses Zeitraums werden gesperrt.</p>
      <div id="sr-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
        <?php foreach ($blockedRanges as $sri => $sr): ?>
        <div style="display:grid;grid-template-columns:150px 20px 150px 1fr auto;gap:8px;align-items:center" id="sr_<?= $sri ?>">
          <input type="date" name="sr_from[]" value="<?= htmlspecialchars($sr['from'] ?? '') ?>" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem">
          <span style="text-align:center;color:#5a6c5a">–</span>
          <input type="date" name="sr_to[]"   value="<?= htmlspecialchars($sr['to']   ?? '') ?>" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem">
          <input type="text" name="sr_reason[]" value="<?= htmlspecialchars($sr['reason'] ?? '') ?>" placeholder="Grund (optional)" maxlength="60">
          <button type="button" class="del-btn" onclick="delSr(<?= $sri ?>)">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addSr()">＋ Zeitraum sperren</button>
    </div>
    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>
</div>

<!-- TAB: E-MAIL VORLAGEN -->
<div class="tab-panel <?= $activeTab === 'email_vorlagen' ? 'active' : '' ?>" id="tab-email_vorlagen">
  <form method="POST">
    <input type="hidden" name="tab" value="email_vorlagen">
    <div class="card" style="background:#e8f5e9;border-color:#a5d6a7">
      <p style="font-size:0.88rem;color:#2e7d32">
        <strong>Verfügbare Platzhalter:</strong><br>
        <code>{name}</code> Name des Buchenden &nbsp;·&nbsp;
        <code>{datum}</code> Datum &nbsp;·&nbsp;
        <code>{betrag}</code> Nettobetrag &nbsp;·&nbsp;
        <code>{gesamt}</code> Gesamtbetrag inkl. Kaution &nbsp;·&nbsp;
        <code>{iban}</code> IBAN &nbsp;·&nbsp;
        <code>{kontoinhaber}</code> &nbsp;·&nbsp;
        <code>{kontakt_name}</code> &nbsp;·&nbsp;
        <code>{telefon}</code> &nbsp;·&nbsp;
        <code>{email_kontakt}</code> &nbsp;·&nbsp;
        <code>{zahlungsziel}</code> Zahlungsziel (z.B. „7 Tage")
      </p>
    </div>
    <?php
    $tplFields = [
        ['confirm_subject', 'confirm_body',  '✓ Buchungsbestätigung (an Buchenden)', 'E-Mail-Text Bestätigung'],
        ['reject_subject',  'reject_body',   '✕ Absage (an Buchenden)',              'E-Mail-Text Absage'],
        ['inquiry_subject', 'inquiry_body',  '📥 Eingangsbestätigung (sofort nach Anfrage)', 'E-Mail-Text Eingangsbestätigung'],
    ];
    foreach ($tplFields as [$sk, $bk, $label, $bodyLabel]):
    ?>
    <div class="card">
      <h3><?= $label ?></h3>
      <div class="field"><label>Betreff</label><input type="text" name="et_<?= $sk ?>" value="<?= htmlspecialchars($emailTpl[$sk] ?? '') ?>" maxlength="200"></div>
      <div class="field"><label><?= $bodyLabel ?></label><textarea name="et_<?= $bk ?>" style="min-height:140px;font-family:monospace;font-size:0.82rem"><?= htmlspecialchars($emailTpl[$bk] ?? '') ?></textarea></div>
    </div>
    <?php endforeach; ?>
    <button class="save-btn" type="submit">💾 Speichern</button>
  </form>

  <!-- Test-Mail senden -->
  <div class="card" style="margin-top:20px;border-color:#b3c6ff;background:#f0f4ff">
    <h3>🧪 Test-Mail senden</h3>
    <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:14px">Sende eine der Vorlagen als Testmail mit Beispieldaten an eine beliebige E-Mail-Adresse.</p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <div>
        <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Vorlage</label>
        <select id="testTplSelect" style="padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.88rem;font-family:inherit">
          <option value="confirm">✓ Buchungsbestätigung</option>
          <option value="reject">✕ Absage</option>
          <option value="inquiry">📥 Eingangsbestätigung</option>
        </select>
      </div>
      <div>
        <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Empfänger-E-Mail</label>
        <input type="email" id="testEmailAddr" placeholder="test@beispiel.de" style="padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.88rem;font-family:inherit;min-width:220px">
      </div>
      <button onclick="sendTestEmail()" style="background:#3d6b41;color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:0.88rem;font-weight:600;cursor:pointer">📨 Testmail senden</button>
      <span id="testEmailMsg" style="font-size:0.85rem;display:none"></span>
    </div>
  </div>
  <script>
  function sendTestEmail() {
    const to  = document.getElementById('testEmailAddr').value.trim();
    const tpl = document.getElementById('testTplSelect').value;
    const msg = document.getElementById('testEmailMsg');
    if (!to) { alert('Bitte E-Mail-Adresse eingeben.'); return; }
    msg.style.display = 'inline'; msg.style.color = '#5a6c5a'; msg.textContent = '⏳ Wird gesendet …';
    const fd = new FormData();
    fd.append('tab', 'test_email');
    fd.append('test_email_to', to);
    fd.append('test_tpl', tpl);
    fd.append('csrf', document.querySelector('input[name="csrf"]')?.value ?? '');
    fetch(location.pathname, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => { msg.style.color = d.status === 'ok' ? '#2e7d32' : '#c62828'; msg.textContent = d.status === 'ok' ? '✅ ' + d.message : '❌ ' + d.message; })
      .catch(() => { msg.style.color = '#c62828'; msg.textContent = '❌ Netzwerkfehler'; });
  }
  </script>
</div>

<!-- TAB: BILD-MANAGER -->
<div class="tab-panel <?= $activeTab === 'bild_manager' ? 'active' : '' ?>" id="tab-bild_manager">
  <?php
  $allImgs = glob(IMAGES_DIR . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
  // Collect all referenced images from content.json AND PHP files
  $contentRaw = (file_get_contents(CONTENT_FILE) ?: '')
              . (file_get_contents(dirname(__DIR__) . '/index.php') ?: '')
              . (file_get_contents(dirname(__DIR__) . '/mitglieder.php') ?: '')
              . (file_get_contents(dirname(__DIR__) . '/vereinshaus.php') ?: '')
              . (file_get_contents(__DIR__ . '/index.php') ?: '');
  $usedImgs   = [];
  foreach ($allImgs as $imgPath) {
      $bn = basename($imgPath);
      if (strpos($contentRaw, $bn) !== false) $usedImgs[] = $bn;
  }
  ?>
  <div class="card">
    <h3>🖼 Bilderverwaltung (<?= count($allImgs) ?> Dateien)</h3>
    <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:20px">Bilder mit <span style="background:#fdecea;color:#c62828;border-radius:4px;padding:2px 6px;font-size:0.78rem">Ungenutzt</span> werden nirgendwo auf der Website verwendet und können gelöscht werden.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px">
      <?php foreach ($allImgs as $imgPath):
        $bn   = basename($imgPath);
        $size = round(filesize($imgPath) / 1024);
        $used = in_array($bn, $usedImgs, true);
      ?>
      <div style="background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:10px;text-align:center">
        <img src="/images/<?= htmlspecialchars($bn) ?>" style="width:100%;height:100px;object-fit:cover;border-radius:6px;margin-bottom:8px;border:1px solid #e0ead6">
        <div style="font-size:0.72rem;color:#5a6c5a;word-break:break-all;margin-bottom:5px"><?= htmlspecialchars($bn) ?></div>
        <div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:6px"><?= $size ?> KB</div>
        <span style="font-size:0.7rem;font-weight:700;border-radius:10px;padding:2px 8px;<?= $used ? 'background:#e8f5e9;color:#2e7d32' : 'background:#fdecea;color:#c62828' ?>"><?= $used ? 'In Verwendung' : 'Ungenutzt' ?></span>
        <div style="display:flex;gap:5px;justify-content:center;margin-top:8px;flex-wrap:wrap">
          <button type="button" onclick="openCropper('<?= htmlspecialchars($bn, ENT_QUOTES) ?>')" style="font-size:0.75rem;padding:4px 9px;border:1px solid #b8d4a8;border-radius:6px;background:#e8f5e9;color:#2d5234;cursor:pointer">✂ Zuschneiden</button>
          <?php if (!$used): ?>
          <form method="POST" onsubmit="return confirm('Bild <?= addslashes($bn) ?> löschen?')" style="margin:0">
            <input type="hidden" name="tab"      value="delete_image">
            <input type="hidden" name="img_file" value="<?= htmlspecialchars($bn) ?>">
            <button class="del-btn" type="submit" style="font-size:0.75rem;padding:4px 9px;border:1px solid #ffcdd2;border-radius:6px;background:#fdecea;color:#c62828">🗑 Löschen</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($allImgs)): ?>
      <div style="grid-column:1/-1;text-align:center;padding:32px;color:#8a9a8a">Noch keine Bilder hochgeladen.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- TAB: EINSTELLUNGEN -->
<div class="tab-panel <?= $activeTab === 'settings' ? 'active' : '' ?>" id="tab-settings">

  <!-- Bankdaten / Kontakt -->
  <form method="POST">
    <input type="hidden" name="tab" value="settings">
    <div class="card">
      <h3>🏦 Bankverbindung & Kontakt (für Bestätigungsemails)</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:20px">Diese Daten werden automatisch in Buchungsbestätigungen und Absagen eingesetzt.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
          <label>IBAN</label>
          <input type="text" name="iban" value="<?= htmlspecialchars($settings['iban'] ?? '') ?>" placeholder="DE12 3456 7890 1234 5678 90" maxlength="40">
        </div>
        <div class="field">
          <label>Kontoinhaber</label>
          <input type="text" name="kontoinhaber" value="<?= htmlspecialchars($settings['kontoinhaber'] ?? 'KGV Musterstadt e.V.') ?>" maxlength="80">
        </div>
        <div class="field">
          <label>Bank <span class="hint">(optional)</span></label>
          <input type="text" name="bank" value="<?= htmlspecialchars($settings['bank'] ?? '') ?>" placeholder="z.B. Hamburger Volksbank" maxlength="80">
        </div>
      </div>
      <div style="border-top:1px solid #e0ead6;margin:20px 0;padding-top:20px">
        <p style="font-size:0.82rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px">Ansprechpartner (für Rückfragen in Emails)</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="field">
            <label>Name</label>
            <input type="text" name="kontakt_name" value="<?= htmlspecialchars($settings['kontakt_name'] ?? 'Max Mustermann') ?>" maxlength="80">
          </div>
          <div class="field">
            <label>Funktion / Rolle</label>
            <input type="text" name="kontakt_rolle" value="<?= htmlspecialchars($settings['kontakt_rolle'] ?? '1. Vorsitzender') ?>" maxlength="80" placeholder="z.B. 1. Vorsitzender">
          </div>
          <div class="field">
            <label>Telefon</label>
            <input type="text" name="telefon" value="<?= htmlspecialchars($settings['telefon'] ?? '+49 000 000 00 00') ?>" maxlength="40" placeholder="z.B. 040 123 456">
          </div>
          <div class="field">
            <label>E-Mail (öffentlich, für Rückfragen)</label>
            <input type="text" name="settings_email" value="<?= htmlspecialchars($settings['email'] ?? 'kontakt@example.org') ?>" maxlength="120" placeholder="kontakt@example.org">
          </div>
        </div>
      </div>
      <div style="border-top:1px solid #e0ead6;margin:20px 0;padding-top:20px">
        <p style="font-size:0.82rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">🔨 Gemeinschaftsarbeit</p>
        <div style="display:grid;grid-template-columns:1fr 3fr;gap:16px;align-items:center">
          <div class="field">
            <label>Soll-Stunden pro Mitglied</label>
            <input type="number" name="arbeit_soll_stunden" value="<?= (int)($settings['arbeit_soll_stunden'] ?? 4) ?>" min="1" max="100" style="width:100px">
          </div>
          <p style="font-size:0.82rem;color:#5a6c5a;margin:0">Pflichtanzahl Stunden Gemeinschaftsarbeit, die jedes Mitglied pro Jahr leisten muss.</p>
        </div>
      </div>
      <div style="border-top:1px solid #e0ead6;margin:20px 0;padding-top:20px">
        <p style="font-size:0.82rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">💳 Zahlungsbedingungen</p>
        <div style="display:grid;grid-template-columns:1fr 3fr;gap:16px;align-items:center">
          <div class="field">
            <label>Zahlungsziel (Wochen vor Veranstaltung)</label>
            <input type="number" name="zahlungsziel_wochen" value="<?= (int)($settings['zahlungsziel_wochen'] ?? 4) ?>" min="1" max="52" style="width:100px">
          </div>
          <p style="font-size:0.82rem;color:#5a6c5a;margin:0">Anzahl Wochen, bis zu denen die Zahlung <em>vor der Veranstaltung</em> eingehen muss. Wird automatisch in Buchungsbestätigungen eingesetzt (Platzhalter <code>{zahlungsziel}</code>).</p>
        </div>
      </div>
      <div style="border-top:1px solid #e0ead6;margin:20px 0;padding-top:20px">
        <p style="font-size:0.82rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">📬 E-Mail-Benachrichtigungen</p>
        <label style="display:flex;align-items:center;gap:10px;font-size:0.9rem;margin-bottom:18px;cursor:pointer">
          <input type="checkbox" name="email_notif_enabled" value="1" <?= !empty($emailNotif['enabled']) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#3d6b41">
          E-Mail-Benachrichtigungen aktiv <span style="color:#5a6c5a;font-size:0.8rem">(deaktivieren = nur im Admin-Panel sichtbar)</span>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="field">
            <label>Kontaktanfragen → An</label>
            <input type="email" name="notif_contact_to" value="<?= htmlspecialchars($emailNotif['contact_to'] ?? 'vorstand@example.org') ?>" maxlength="120" placeholder="z.B. vorstand@example.org">
          </div>
          <div class="field">
            <label>Kontaktanfragen → CC <span class="hint">(optional)</span></label>
            <input type="email" name="notif_contact_cc" value="<?= htmlspecialchars($emailNotif['contact_cc'] ?? '') ?>" maxlength="120" placeholder="z.B. schriftfuehrer@example.org">
          </div>
          <div class="field">
            <label>Buchungsanfragen → An</label>
            <input type="email" name="notif_booking_to" value="<?= htmlspecialchars($emailNotif['booking_to'] ?? 'vorstand@example.org') ?>" maxlength="120" placeholder="z.B. kasse@example.org">
          </div>
          <div class="field">
            <label>Buchungsanfragen → CC <span class="hint">(optional)</span></label>
            <input type="email" name="notif_booking_cc" value="<?= htmlspecialchars($emailNotif['booking_cc'] ?? '') ?>" maxlength="120" placeholder="z.B. vorstand@example.org">
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:10px;font-size:0.9rem;margin-top:14px;cursor:pointer">
          <input type="checkbox" name="notif_member_notify" value="1" <?= !empty($emailNotif['member_notify']) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#3d6b41">
          Admin per E-Mail benachrichtigen wenn ein Mitglied eine Nachricht sendet <span style="color:#5a6c5a;font-size:0.8rem">(nutzt „Kontaktanfragen → An")</span>
        </label>
        <small style="color:#5a6c5a;font-size:0.78rem">Leer = kein Versand an diese Adresse. CC wird nur gesendet wenn das Feld ausgefüllt ist.</small>
      </div>
    </div>
    <button class="save-btn" type="submit">💾 Einstellungen speichern</button>
  </form>

  <!-- Passwort ändern -->
  <form method="POST" style="margin-top:24px">
    <input type="hidden" name="tab" value="password">
    <div class="card">
      <h3>🔒 Passwort ändern</h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin-bottom:20px">Mindestens 8 Zeichen. Das neue Passwort gilt sofort für alle zukünftigen Anmeldungen.</p>
      <div style="max-width:380px;display:flex;flex-direction:column;gap:14px">
        <div class="field">
          <label>Aktuelles Passwort</label>
          <input type="password" name="pw_current" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label>Neues Passwort <span class="hint">(min. 8 Zeichen)</span></label>
          <input type="password" name="pw_new" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="field">
          <label>Neues Passwort wiederholen</label>
          <input type="password" name="pw_new2" autocomplete="new-password" minlength="8" required>
        </div>
      </div>
    </div>
    <button class="save-btn" type="submit">🔒 Passwort ändern</button>
  </form>

</div>

<!-- TAB: WARTUNGSMODUS -->
<div class="tab-panel <?= $activeTab === 'maintenance' ? 'active' : '' ?>" id="tab-maintenance">
  <form method="POST">
    <input type="hidden" name="tab" value="maintenance">
    <div class="card" style="border-color:<?= $maintenanceOn ? '#ef9a9a' : '#d4e6c3' ?>">
      <h3 style="color:<?= $maintenanceOn ? '#c62828' : '#3d6b41' ?>"><?= $maintenanceOn ? '🔴 Wartungsmodus ist AKTIV' : '🟢 Wartungsmodus ist deaktiviert' ?></h3>
      <p style="font-size:0.85rem;color:#5a6c5a;margin:10px 0 20px">Wenn aktiv, sehen Besucher nur die Wartungsseite. Der Admin-Bereich (/intern/) ist immer erreichbar.</p>
      <div class="field">
        <div class="toggle-row">
          <label class="toggle">
            <input type="checkbox" name="maintenance_on" <?= $maintenanceOn ? 'checked' : '' ?>>
            <span class="toggle-slider" style="<?= $maintenanceOn ? 'background:#c62828' : '' ?>"></span>
          </label>
          <span style="font-size:1rem;font-weight:700;color:<?= $maintenanceOn ? '#c62828' : '#3d6b41' ?>"><?= $maintenanceOn ? 'Website ist für Besucher GESPERRT' : 'Website ist normal erreichbar' ?></span>
        </div>
      </div>
      <div class="field" style="margin-top:16px">
        <label>Nachricht für Besucher</label>
        <textarea name="maintenance_message" style="min-height:80px"><?= htmlspecialchars($maintenanceMsg) ?></textarea>
      </div>
    </div>
    <button class="save-btn" type="submit" style="<?= $maintenanceOn ? 'background:#c62828' : '' ?>">💾 Speichern</button>
  </form>

  <!-- Backup -->
  <div class="card" style="margin-top:20px">
    <h3 style="margin-bottom:6px">🗄️ Datensicherung</h3>
    <p style="font-size:0.83rem;color:#5a6c5a;margin-bottom:16px">Alle JSON-Daten und hochgeladene Dateien werden in einer ZIP-Datei gesichert. Es werden maximal <?= MAX_BACKUPS ?> Sicherungen aufbewahrt.</p>
    <button class="save-btn" type="button" onclick="doBackup()" id="backupBtn" style="background:#2e7d32">🗄️ Jetzt sichern</button>
    <div id="backupStatus" style="display:none;margin-top:10px;padding:9px 14px;border-radius:6px;font-size:0.85rem"></div>
    <?php if (!empty($_backups)): ?>
    <table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:0.85rem">
      <thead><tr>
        <th style="text-align:left;padding:6px 10px;background:#f5f7f2;border-bottom:2px solid #d4e6c3;color:#5a6c5a">Datei</th>
        <th style="text-align:left;padding:6px 10px;background:#f5f7f2;border-bottom:2px solid #d4e6c3;color:#5a6c5a">Größe</th>
        <th style="text-align:left;padding:6px 10px;background:#f5f7f2;border-bottom:2px solid #d4e6c3;color:#5a6c5a">Erstellt</th>
        <th style="padding:6px 10px;background:#f5f7f2;border-bottom:2px solid #d4e6c3"></th>
      </tr></thead>
      <tbody id="backupTableBody">
      <?php foreach ($_backups as $bk): ?>
      <tr id="bkrow-<?= htmlspecialchars($bk['name']) ?>">
        <td style="padding:8px 10px;border-bottom:1px solid #e8f0e0">📦 <?= htmlspecialchars($bk['name']) ?></td>
        <td style="padding:8px 10px;border-bottom:1px solid #e8f0e0"><?= $bk['size'] >= 1048576 ? round($bk['size']/1048576,1).' MB' : round($bk['size']/1024).' KB' ?></td>
        <td style="padding:8px 10px;border-bottom:1px solid #e8f0e0"><?= date('d.m.Y H:i', $bk['mtime']) ?></td>
        <td style="padding:8px 10px;border-bottom:1px solid #e8f0e0;white-space:nowrap;text-align:right">
          <a href="/intern/content.php?tab=backup_download&file=<?= urlencode($bk['name']) ?>" style="display:inline-block;padding:4px 12px;background:#e8f5e9;color:#2e7d32;border-radius:6px;text-decoration:none;font-size:0.8rem;margin-right:6px">⬇ Download</a>
          <button onclick="delBackup('<?= htmlspecialchars($bk['name'], ENT_QUOTES) ?>')" style="padding:4px 10px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:0.8rem">🗑</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="margin-top:14px;font-size:0.83rem;color:#5a6c5a">Noch keine Sicherungen vorhanden.</p>
    <?php endif; ?>
  </div>
</div>

<!-- TAB: MITGLIEDER-INHALT -->
<?php
$_memberPostsFile = dirname(__DIR__) . '/data/member_posts.json';
$_memberPosts = file_exists($_memberPostsFile) ? (json_decode((string)file_get_contents($_memberPostsFile), true) ?: []) : [];

// Count notifiable members
$_notifyMembers = array_values(array_filter(
    file_exists(dirname(__DIR__) . '/data/members.json')
        ? (json_decode((string)file_get_contents(dirname(__DIR__) . '/data/members.json'), true) ?: [])
        : [],
    fn($m) => !empty($m['active'])
           && !empty($m['consents']['contact_allowed'])
           && ($m['email_posts'] ?? true) !== false
));
$_notifyCount = count($_notifyMembers);
?>
<div class="tab-panel <?= $activeTab === 'mitglieder_inhalt' ? 'active' : '' ?>" id="tab-mitglieder_inhalt">

  <!-- Neuen Aushang erstellen -->
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="tab" value="member_post">
    <div class="card">
      <h3>📌 Neuen Aushang / Eintrag erstellen</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="field">
          <label>Typ</label>
          <select name="post_type">
            <option value="aushang">📋 Aushang</option>
            <option value="gemeinschaftsarbeit">🌱 Gemeinschaftsarbeit</option>
            <option value="protokoll">📄 Protokoll</option>
            <option value="info">ℹ️ Info</option>
          </select>
        </div>
        <div class="field">
          <label>Datum (optional)</label>
          <input type="date" name="post_date">
        </div>
        <div class="field" style="grid-column:span 2">
          <label>Titel *</label>
          <input type="text" name="post_title" placeholder="Titel des Eintrags" maxlength="200">
        </div>
        <div class="field" style="grid-column:span 2">
          <label>Inhalt</label>
          <textarea name="post_body" style="min-height:100px" placeholder="Weitere Details..."></textarea>
        </div>
        <div class="field">
          <label>Anhang <span class="hint">(PDF, Word, Excel, max. 10 MB)</span></label>
          <input type="file" name="post_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png,.zip">
        </div>
        <div class="field" style="display:flex;align-items:center;gap:10px;padding-top:28px">
          <input type="checkbox" name="post_pinned" id="post_pinned" style="width:18px;height:18px;accent-color:#3d6b41">
          <label for="post_pinned" style="text-transform:none;font-size:0.88rem;cursor:pointer">📍 Oben anheften</label>
        </div>
        <div class="field" style="grid-column:span 2">
          <div style="background:#e8f5e9;border:1.5px solid #a5d6a7;border-radius:10px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px">
            <input type="checkbox" name="post_notify" id="post_notify" style="width:18px;height:18px;accent-color:#3d6b41;margin-top:2px;flex-shrink:0">
            <label for="post_notify" style="text-transform:none;cursor:pointer">
              <span style="font-weight:700;font-size:0.9rem;color:#2d6b31">📧 E-Mail-Benachrichtigung senden</span><br>
              <span style="font-size:0.8rem;color:#5a6c5a"><?= $_notifyCount ?> Mitglied<?= $_notifyCount !== 1 ? 'er' : '' ?> erhalten eine persönliche E-Mail mit Abmeldemöglichkeit.</span>
            </label>
          </div>
        </div>
      </div>
    </div>
    <button class="save-btn" type="submit">📌 Veröffentlichen</button>
  </form>

  <!-- Liste bestehender Einträge -->
  <div class="card" style="margin-top:24px">
    <h3>📋 Bestehende Einträge (<?= count($_memberPosts) ?>)</h3>
    <?php if (empty($_memberPosts)): ?>
      <p style="color:#5a6c5a;font-size:0.88rem">Noch keine Einträge vorhanden.</p>
    <?php else:
      $typeLabels = ['aushang'=>'Aushang','gemeinschaftsarbeit'=>'Gemeinschaftsarbeit','protokoll'=>'Protokoll','info'=>'Info'];
      $typeColors = ['aushang'=>'#fff3e0','gemeinschaftsarbeit'=>'#e8f5e9','protokoll'=>'#e3f2fd','info'=>'#f5f7f2'];
      foreach ($_memberPosts as $mp): ?>
    <?php $mpId = htmlspecialchars($mp['id'] ?? ''); ?>
    <div style="padding:12px 0;border-bottom:1px solid #e8f0e0">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
        <div>
          <span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:10px;background:<?= $typeColors[$mp['type'] ?? 'info'] ?? '#f5f7f2' ?>"><?= htmlspecialchars($typeLabels[$mp['type'] ?? 'info'] ?? 'Info') ?></span>
          <?php if (!empty($mp['pinned'])): ?><span style="font-size:0.72rem;color:#7cb342;margin-left:4px">📍</span><?php endif; ?>
          <strong style="margin-left:6px;font-size:0.9rem"><?= htmlspecialchars($mp['title'] ?? '') ?></strong>
          <?php if (!empty($mp['date'])): ?><span style="font-size:0.78rem;color:#5a6c5a;margin-left:8px"><?= htmlspecialchars($mp['date']) ?></span><?php endif; ?>
          <?php if (!empty($mp['file'])): ?><span style="font-size:0.78rem;color:#3d6b41;margin-left:8px">📎 <?= htmlspecialchars($mp['file']) ?></span><?php endif; ?>
          <div style="font-size:0.75rem;color:#5a6c5a;margin-top:2px"><?= htmlspecialchars($mp['created_at'] ?? '') ?></div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button type="button" onclick="togglePostEdit('pe_<?= $mpId ?>')"
            style="background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:0.8rem;font-family:inherit">✏️ Bearbeiten</button>
          <form method="POST" style="display:inline">
            <input type="hidden" name="tab" value="delete_member_post">
            <input type="hidden" name="post_id" value="<?= $mpId ?>">
            <button type="submit" onclick="return confirm('Eintrag löschen?')" style="background:#fce8e6;color:#c62828;border:1px solid #f5c6c2;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:0.8rem;font-family:inherit">🗑️ Löschen</button>
          </form>
        </div>
      </div>
      <!-- Inline Edit Form -->
      <div id="pe_<?= $mpId ?>" style="display:none;margin-top:12px;background:#f5f7f2;border:1px solid #d4e6c3;border-radius:10px;padding:16px">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="tab" value="edit_member_post">
          <input type="hidden" name="post_id" value="<?= $mpId ?>">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="field">
              <label>Typ</label>
              <select name="post_type">
                <?php foreach (['aushang'=>'📋 Aushang','gemeinschaftsarbeit'=>'🌱 Gemeinschaftsarbeit','protokoll'=>'📄 Protokoll','info'=>'ℹ️ Info'] as $tv => $tl): ?>
                <option value="<?= $tv ?>" <?= ($mp['type'] ?? '') === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Datum</label>
              <input type="date" name="post_date" value="<?= htmlspecialchars($mp['date'] ?? '') ?>">
            </div>
            <div class="field" style="grid-column:span 2">
              <label>Titel *</label>
              <input type="text" name="post_title" value="<?= htmlspecialchars($mp['title'] ?? '') ?>" maxlength="200">
            </div>
            <div class="field" style="grid-column:span 2">
              <label>Inhalt</label>
              <textarea name="post_body" style="min-height:80px"><?= htmlspecialchars($mp['body'] ?? '') ?></textarea>
            </div>
            <div class="field">
              <label>Neuen Anhang hochladen <span class="hint">(leer lassen = unverändert)</span></label>
              <input type="file" name="post_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png,.zip">
            </div>
            <div class="field" style="display:flex;align-items:center;gap:10px;padding-top:28px">
              <input type="checkbox" name="post_pinned" id="pp_<?= $mpId ?>" style="width:18px;height:18px;accent-color:#3d6b41" <?= !empty($mp['pinned']) ? 'checked' : '' ?>>
              <label for="pp_<?= $mpId ?>" style="text-transform:none;font-size:0.88rem;cursor:pointer">📍 Oben anheften</label>
            </div>
          </div>
          <div style="display:flex;gap:10px;margin-top:12px">
            <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:8px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;font-family:inherit;font-weight:600">💾 Speichern</button>
            <button type="button" onclick="togglePostEdit('pe_<?= $mpId ?>')" style="background:#f5f5f5;color:#555;border:1px solid #ddd;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:0.85rem;font-family:inherit">Abbrechen</button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- ── E-Mail-Versandlog ─────────────────────────────────────────────────── -->
  <?php
  $_emailLogFile = dirname(__DIR__) . '/data/email_log.json';
  $_emailLog = file_exists($_emailLogFile) ? (json_decode((string)file_get_contents($_emailLogFile), true) ?: []) : [];
  ?>
  <?php if (!empty($_emailLog)): ?>
  <div class="card" style="margin-top:24px">
    <h3>📋 E-Mail-Versandlog <span style="font-size:0.78rem;font-weight:400;color:#5a6c5a">(letzte <?= count($_emailLog) ?> Aktionen)</span></h3>
    <?php foreach ($_emailLog as $_le):
        $_hasError = ($_le['failed'] ?? 0) > 0;
    ?>
    <div style="border:1px solid <?= $_hasError ? '#ffcdd2' : '#d4e6c3' ?>;border-radius:10px;margin-bottom:12px;overflow:hidden">
      <!-- Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:10px 14px;background:<?= $_hasError ? '#fff8f8' : '#f5faf2' ?>;cursor:pointer"
           onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
        <div>
          <strong style="font-size:0.88rem"><?= htmlspecialchars($_le['post_title'] ?? '') ?></strong>
          <span style="font-size:0.75rem;color:#5a6c5a;margin-left:8px"><?= htmlspecialchars($_le['sent_at'] ?? '') ?> · <?= htmlspecialchars($_le['sent_by'] ?? '') ?></span>
        </div>
        <div style="display:flex;gap:6px;align-items:center;font-size:0.8rem">
          <span style="background:#e8f5e9;color:#2e7d32;padding:2px 10px;border-radius:10px;font-weight:700">✅ <?= (int)($_le['success'] ?? 0) ?> gesendet</span>
          <?php if ($_hasError): ?>
          <span style="background:#ffebee;color:#c62828;padding:2px 10px;border-radius:10px;font-weight:700">❌ <?= (int)$_le['failed'] ?> Fehler</span>
          <?php endif; ?>
          <span style="color:#aaa">▾</span>
        </div>
      </div>
      <!-- Details (collapsed by default) -->
      <div style="display:none;padding:12px 14px">
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem">
          <thead>
            <tr style="color:#5a6c5a;text-align:left;border-bottom:1px solid #e8f0e0">
              <th style="padding:4px 8px">Name</th>
              <th style="padding:4px 8px">E-Mail</th>
              <th style="padding:4px 8px">Zeit</th>
              <th style="padding:4px 8px">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($_le['entries'] ?? [] as $_ee): ?>
            <tr style="border-bottom:1px solid #f0f5ec">
              <td style="padding:4px 8px"><?= htmlspecialchars($_ee['name'] ?? '') ?></td>
              <td style="padding:4px 8px;color:#5a6c5a"><?= htmlspecialchars($_ee['email'] ?? '') ?></td>
              <td style="padding:4px 8px;color:#5a6c5a"><?= htmlspecialchars($_ee['ts'] ?? '') ?></td>
              <td style="padding:4px 8px">
                <?php if (($_ee['status'] ?? '') === 'ok'): ?>
                  <span style="color:#2e7d32;font-weight:600">✅ OK</span>
                <?php else: ?>
                  <span style="color:#c62828;font-weight:600">❌ Fehler</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- ══ RECHTLICHES ══════════════════════════════════════════════════════════ -->
<?php
$_imp = $c['impressum'] ?? [];
$_imp += [
    'verein'=>'Muster-Kleingartenverein e.V.','strasse'=>'Musterstraße 1',
    'plz_ort'=>'12345 Musterstadt','vertreter'=>'Max Mustermann (1. Vorsitzender)',
    'telefon'=>'+49 000 000 00 00','email'=>'vorstand@example.org',
    'postanschrift'=>'Musterstraße 2, 12345 Musterstadt','registergericht'=>'Amtsgericht Musterstadt',
    'registernummer'=>'12345','verantwortlich'=>'Maria Beispiel',
    'haftung_text'=>'Trotz sorgfältiger inhaltlicher Kontrolle übernehmen wir keine Haftung für die Inhalte externer Links.',
    'urheberrecht_text'=>'Die durch die Seitenbetreiber erstellten Inhalte und Werke unterliegen dem deutschen Urheberrecht.',
];
$_ds = $c['datenschutz'] ?? [];
$_ds += [
    'stand'=>'Juli 2025','verantwortlicher'=>'Max Mustermann',
    'telefon'=>'+49 000 000 00 00','email'=>'vorstand@example.org',
    'adresse'=>'Musterstraße 1, 12345 Musterstadt',
    'sections'=>[
        ['titel'=>'2. Erhebung und Speicherung personenbezogener Daten','inhalt'=>'Beim Aufrufen unserer Website werden durch den auf Ihrem Endgerät zum Einsatz kommenden Browser automatisch Informationen an den Server unserer Website gesendet.'],
        ['titel'=>'3. Weitergabe von Daten','inhalt'=>'Eine Übermittlung Ihrer persönlichen Daten an Dritte findet nicht statt, außer in gesetzlich vorgesehenen Fällen.'],
        ['titel'=>'4. Cookies','inhalt'=>'Diese Website verwendet keine Cookies bzw. nur technisch notwendige Cookies, die keine Zustimmung erfordern.'],
        ['titel'=>'5. Newsletter','inhalt'=>'Wenn Sie unseren Newsletter abonnieren, verwenden wir die von Ihnen angegebene E-Mail-Adresse, um Ihnen regelmäßig Informationen zuzusenden. Die Abmeldung ist jederzeit möglich.'],
        ['titel'=>'6. Betroffenenrechte','inhalt'=>'Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung (Art. 18), Datenübertragbarkeit (Art. 20) sowie Beschwerde bei einer Aufsichtsbehörde (Art. 77).'],
        ['titel'=>'7. Widerspruchsrecht','inhalt'=>'Sofern Ihre personenbezogenen Daten auf Grundlage von berechtigten Interessen gemäß Art. 6 Abs. 1 lit. f DSGVO verarbeitet werden, haben Sie das Recht, Widerspruch einzulegen.'],
        ['titel'=>'8. Datensicherheit','inhalt'=>'Wir verwenden das SSL-Verfahren (Secure Socket Layer) in Verbindung mit der jeweils höchsten Verschlüsselungsstufe sowie geeignete technische und organisatorische Sicherheitsmaßnahmen.'],
        ['titel'=>'9. Aktualität und Änderungen','inhalt'=>'Diese Datenschutzerklärung ist aktuell gültig. Durch die Weiterentwicklung unserer Website kann es notwendig werden, diese Erklärung zu ändern.'],
    ],
];
?>
<div class="tab-panel <?= $activeTab === 'rechtliches' ? 'active' : '' ?>" id="tab-rechtliches">

  <!-- Sub-Tab-Switcher -->
  <div style="display:flex;gap:6px;margin-bottom:20px">
    <button type="button" id="rl-btn-imp" onclick="switchRL('impressum')"
            style="padding:8px 18px;border-radius:8px;border:2px solid #3d6b41;background:#3d6b41;color:#fff;font-weight:700;cursor:pointer;font-size:0.88rem">📋 Impressum</button>
    <button type="button" id="rl-btn-ds" onclick="switchRL('datenschutz')"
            style="padding:8px 18px;border-radius:8px;border:2px solid #d4e6c3;background:#fff;color:#3d6b41;font-weight:700;cursor:pointer;font-size:0.88rem">🔒 Datenschutz</button>
    <a href="/impressum.php" target="_blank" style="margin-left:auto;display:inline-flex;align-items:center;gap:5px;font-size:0.82rem;color:#3d6b41;text-decoration:none;padding:8px 14px;border:1px solid #d4e6c3;border-radius:8px;background:#fff">🌐 Live-Vorschau Impressum ↗</a>
    <a href="/datenschutz.php" target="_blank" style="display:inline-flex;align-items:center;gap:5px;font-size:0.82rem;color:#3d6b41;text-decoration:none;padding:8px 14px;border:1px solid #d4e6c3;border-radius:8px;background:#fff">🌐 Datenschutz ↗</a>
  </div>

  <!-- IMPRESSUM -->
  <div id="rl-impressum">
    <form method="POST">
      <input type="hidden" name="tab" value="rechtliches">
      <input type="hidden" name="sub_tab" value="impressum">
      <input type="hidden" name="csrf" value="<?= $csrfJs ?>">
      <div class="card">
        <h3>📋 Impressum bearbeiten</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="field" style="grid-column:1/-1">
            <label>Vereinsname</label>
            <input type="text" name="verein" value="<?= htmlspecialchars($_imp['verein']) ?>" id="imp-verein">
          </div>
          <div class="field">
            <label>Straße / Adresse</label>
            <input type="text" name="strasse" value="<?= htmlspecialchars($_imp['strasse']) ?>" id="imp-strasse">
          </div>
          <div class="field">
            <label>PLZ & Ort</label>
            <input type="text" name="plz_ort" value="<?= htmlspecialchars($_imp['plz_ort']) ?>" id="imp-plz">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Vertreter <span class="hint">(Name + Funktion)</span></label>
            <input type="text" name="vertreter" value="<?= htmlspecialchars($_imp['vertreter']) ?>" id="imp-vertreter">
          </div>
          <div class="field">
            <label>Telefon</label>
            <input type="text" name="telefon" value="<?= htmlspecialchars($_imp['telefon']) ?>" id="imp-telefon">
          </div>
          <div class="field">
            <label>E-Mail</label>
            <input type="text" name="imp_email" value="<?= htmlspecialchars($_imp['email']) ?>" id="imp-email">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Postanschrift</label>
            <input type="text" name="postanschrift" value="<?= htmlspecialchars($_imp['postanschrift']) ?>">
          </div>
          <div class="field">
            <label>Registergericht</label>
            <input type="text" name="registergericht" value="<?= htmlspecialchars($_imp['registergericht']) ?>">
          </div>
          <div class="field">
            <label>Registernummer</label>
            <input type="text" name="registernummer" value="<?= htmlspecialchars($_imp['registernummer']) ?>">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Inhaltlich Verantwortlicher <span class="hint">(§ 55 Abs. 2 RStV)</span></label>
            <input type="text" name="verantwortlich" value="<?= htmlspecialchars($_imp['verantwortlich']) ?>">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Haftungsausschluss-Text</label>
            <textarea name="haftung_text" rows="3"><?= htmlspecialchars($_imp['haftung_text']) ?></textarea>
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Urheberrecht-Text</label>
            <textarea name="urheberrecht_text" rows="3"><?= htmlspecialchars($_imp['urheberrecht_text']) ?></textarea>
          </div>
        </div>
        <button class="save-btn" type="submit">💾 Impressum speichern</button>
      </div>
    </form>

    <!-- Live-Vorschau -->
    <div class="card" style="margin-top:20px">
      <h3>👁 Live-Vorschau</h3>
      <div style="background:#f8faf8;border:1px solid #d4e6c3;border-radius:12px;padding:24px;font-family:'Segoe UI',sans-serif">
        <div id="prev-imp-verein" style="font-size:1rem;font-weight:700;color:#4a7c4e;margin-bottom:4px"><?= htmlspecialchars($_imp['verein']) ?></div>
        <div id="prev-imp-strasse" style="font-size:0.88rem;color:#5a6c5a"><?= htmlspecialchars($_imp['strasse']) ?></div>
        <div id="prev-imp-plz" style="font-size:0.88rem;color:#5a6c5a;margin-bottom:12px"><?= htmlspecialchars($_imp['plz_ort']) ?></div>
        <div style="font-size:0.78rem;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Vertreter</div>
        <div id="prev-imp-vertreter" style="font-size:0.88rem;color:#2d3e2d;margin-bottom:10px"><?= htmlspecialchars($_imp['vertreter']) ?></div>
        <div style="display:flex;gap:16px;font-size:0.85rem;color:#5a6c5a">
          <span>📞 <span id="prev-imp-telefon"><?= htmlspecialchars($_imp['telefon']) ?></span></span>
          <span>✉️ <span id="prev-imp-email"><?= htmlspecialchars($_imp['email']) ?></span></span>
        </div>
      </div>
    </div>
  </div>

  <!-- DATENSCHUTZ -->
  <div id="rl-datenschutz" style="display:none">
    <form method="POST">
      <input type="hidden" name="tab" value="rechtliches">
      <input type="hidden" name="sub_tab" value="datenschutz">
      <input type="hidden" name="csrf" value="<?= $csrfJs ?>">
      <div class="card">
        <h3>🔒 Datenschutzerklärung bearbeiten</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
          <div class="field">
            <label>Stand <span class="hint">(z.B. Juli 2025)</span></label>
            <input type="text" name="ds_stand" value="<?= htmlspecialchars($_ds['stand']) ?>" id="ds-stand">
          </div>
          <div class="field">
            <label>Verantwortlicher <span class="hint">(Name)</span></label>
            <input type="text" name="ds_verantwortlicher" value="<?= htmlspecialchars($_ds['verantwortlicher']) ?>">
          </div>
          <div class="field">
            <label>Telefon</label>
            <input type="text" name="ds_telefon" value="<?= htmlspecialchars($_ds['telefon']) ?>">
          </div>
          <div class="field">
            <label>E-Mail</label>
            <input type="text" name="ds_email" value="<?= htmlspecialchars($_ds['email']) ?>">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Adresse des Verantwortlichen</label>
            <input type="text" name="ds_adresse" value="<?= htmlspecialchars($_ds['adresse']) ?>">
          </div>
        </div>
        <div style="background:#f0f4ee;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:0.82rem;color:#5a6c5a">
          💡 <strong>Tipp:</strong> Neue Zeile = neuer Absatz auf der Seite. Kein HTML nötig.
        </div>
        <?php foreach ($_ds['sections'] as $si => $sec): ?>
        <div style="border:1px solid #d4e6c3;border-radius:10px;padding:16px;margin-bottom:14px">
          <div class="field">
            <label style="color:#8a9a8a">Abschnitts-Titel <span class="hint">(unveränderlich, rechtlich vorgegeben)</span></label>
            <input type="text" name="ds_titel[]" value="<?= htmlspecialchars($sec['titel']) ?>"
                   style="background:#f5f5f5;color:#5a6c5a;cursor:default" readonly>
          </div>
          <div class="field" style="margin-bottom:0">
            <label>Inhalt</label>
            <textarea name="ds_inhalt[]" rows="4"><?= htmlspecialchars($sec['inhalt']) ?></textarea>
          </div>
        </div>
        <?php endforeach; ?>
        <button class="save-btn" type="submit">💾 Datenschutz speichern</button>
      </div>
    </form>
  </div>

</div><!-- /tab-rechtliches -->

</div><!-- /main -->

<?php $csrfJs = htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES); ?>
<script>
const _csrf = '<?= $csrfJs ?>';
function togglePostEdit(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
async function doBackup() {
    const btn = document.getElementById('backupBtn');
    const st  = document.getElementById('backupStatus');
    btn.disabled = true; btn.textContent = '⟳ Sicherung läuft…';
    try {
        const fd = new FormData();
        fd.append('csrf', _csrf);
        fd.append('tab', 'backup_create');
        const r = await fetch('/intern/content.php', {method:'POST', body: fd});
        const d = await r.json();
        if (d.status === 'ok') {
            st.style.cssText = 'display:block;background:#e8f5e9;border:1px solid #a5d6a7;color:#2e5f32';
            st.textContent = '✅ ' + d.file + ' (' + (d.size >= 1048576 ? (d.size/1048576).toFixed(1)+' MB' : Math.round(d.size/1024)+' KB') + ')';
            setTimeout(() => location.reload(), 1200);
        } else { throw new Error(d.message || 'Fehler'); }
    } catch(e) {
        st.style.cssText = 'display:block;background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c';
        st.textContent = '❌ ' + e.message;
        btn.disabled = false; btn.textContent = '🗄️ Jetzt sichern';
    }
}
async function delBackup(name) {
    if (!confirm('Sicherung löschen?')) return;
    const fd = new FormData();
    fd.append('csrf', _csrf);
    fd.append('tab', 'backup_delete');
    fd.append('file', name);
    const r = await fetch('/intern/content.php', {method:'POST', body: fd});
    const d = await r.json();
    if (d.status === 'ok') { const row = document.getElementById('bkrow-'+name); if (row) row.remove(); }
}
function switchTab(t) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('tab-' + t);
    if (panel) panel.classList.add('active');
    const btn = document.querySelector('.tab-btn[onclick*="\'' + t + '\'"]');
    if (btn) btn.classList.add('active');
    history.replaceState(null,'','?tab=' + t);
}

function switchRL(sub) {
    ['impressum','datenschutz'].forEach(s => {
        document.getElementById('rl-' + s).style.display = s === sub ? '' : 'none';
        const btn = document.getElementById('rl-btn-' + (s === 'impressum' ? 'imp' : 'ds'));
        if (btn) { btn.style.background = s === sub ? '#3d6b41' : ''; btn.style.color = s === sub ? '#fff' : ''; }
    });
}

(function() {
    const map = {
        'imp-verein':   'prev-imp-verein',
        'imp-strasse':  'prev-imp-strasse',
        'imp-plz':      'prev-imp-plz',
        'imp-vertreter':'prev-imp-vertreter',
        'imp-telefon':  'prev-imp-telefon',
        'imp-email':    'prev-imp-email',
    };
    Object.entries(map).forEach(([srcId, dstId]) => {
        const src = document.getElementById(srcId);
        const dst = document.getElementById(dstId);
        if (src && dst) src.addEventListener('input', () => dst.textContent = src.value);
    });
    // Ensure first sub-panel is visible on load
    switchRL('impressum');
})();

function addTicker() {
    const list = document.getElementById('ticker-list');
    const div  = document.createElement('div');
    div.className = 'ticker-row';
    div.innerHTML = `<div class="move-btns">
      <button type="button" class="move-btn" onclick="moveTicker(-1,-1)">▲</button>
      <button type="button" class="move-btn" onclick="moveTicker(-1,1)">▼</button>
    </div>
    <input type="text" name="ticker[]" placeholder="📅 Neuer Eintrag...">
    <button type="button" class="del-btn" onclick="this.closest('.ticker-row').remove()">✕</button>`;
    list.appendChild(div);
    div.querySelector('input').focus();
}

function moveTicker(idx, dir) {
    const list = document.getElementById('ticker-list');
    const rows = [...list.children];
    const row  = rows[idx];
    if (!row) return;
    if (dir === -1 && idx > 0)              list.insertBefore(row, rows[idx - 1]);
    if (dir ===  1 && idx < rows.length-1)  list.insertBefore(rows[idx + 1], row);
}

function copyEmoji(e) {
    const focused = document.querySelector('#ticker-list input:focus');
    if (focused) { focused.value = e + ' ' + focused.value.trim(); focused.focus(); }
    else { navigator.clipboard?.writeText(e); }
}

// ── Themen-Galerie Backoffice ─────────────────────────────────────────────
let themeCount = <?= count($gallery) ?>;
let subCounts  = <?= json_encode(array_map(fn($g) => count($g['images'] ?? []), $gallery)) ?>;

function addTheme() {
    const i = themeCount++;
    subCounts[i] = 0;
    const div = document.createElement('div');
    div.className = 'theme-card';
    div.id = 'thc_' + i;
    div.setAttribute('data-idx', String(i));
    div.innerHTML = `
      <div class="theme-card-head"><strong>Neues Thema</strong>
        <button type="button" class="del-card-btn" onclick="deleteTheme(${i})" title="Thema entfernen">✕</button>
      </div>
      <div class="theme-card-body">
        <div class="theme-cover">
          <div class="img-placeholder" id="cover_prev_${i}">🖼️</div>
          <input type="hidden" name="g_cover[]" value="">
          <label class="upload-label">📷 Cover hochladen
            <input type="file" name="g_cover_upload[${i}]" accept="image/jpeg,image/png,image/webp" onchange="previewTheme(this, ${i})">
          </label>
        </div>
        <div class="theme-meta">
          <div class="field"><label>Titel</label><input type="text" name="g_title[]" value="" maxlength="80" placeholder="z.B. Bienenprojekt 2026"></div>
          <div class="field"><label>Untertitel <span class="hint">(optional)</span></label><input type="text" name="g_subtitle[]" value="" maxlength="80" placeholder="z.B. Mai 2026"></div>
        </div>
      </div>
      <div class="theme-subs" data-theme="${i}">
        <div class="theme-subs-label">Weitere Bilder dieses Themas (zusätzlich zum Cover)</div>
        <div class="theme-sub-grid" id="theme_subs_${i}"></div>
        <button type="button" class="add-btn-sm" onclick="addSub(${i})">＋ Bild zum Thema hinzufügen</button>
      </div>`;
    document.getElementById('theme-list').appendChild(div);
}

function deleteTheme(i) {
    const card = document.getElementById('thc_' + i);
    if (!card) return;
    card.insertAdjacentHTML('beforeend', '<input type="hidden" name="g_delete[]" value="' + i + '">');
    card.style.opacity = '0.3';
    card.querySelectorAll('input:not([name="g_delete[]"]),textarea,button').forEach(el => el.disabled = true);
}

function addSub(themeIdx) {
    const j = subCounts[themeIdx] || 0;
    subCounts[themeIdx] = j + 1;
    const grid = document.getElementById('theme_subs_' + themeIdx);
    if (!grid) return;
    const div = document.createElement('div');
    div.className = 'theme-sub-item';
    div.id = 'tsi_' + themeIdx + '_' + j;
    div.innerHTML = `
      <button type="button" class="del-sub-btn" onclick="deleteSub(${themeIdx}, ${j})" title="Bild entfernen">✕</button>
      <div class="img-placeholder-sm" id="sub_prev_${themeIdx}_${j}">🖼️</div>
      <input type="hidden" name="g_sub_files[${themeIdx}][]" value="">
      <label class="upload-label-sm">📷
        <input type="file" name="g_sub_upload[${themeIdx}][${j}]" accept="image/jpeg,image/png,image/webp" onchange="previewSub(this, ${themeIdx}, ${j})">
      </label>
      <input type="text" name="g_sub_captions[${themeIdx}][]" value="" maxlength="120" placeholder="Bildunterschrift (optional)">`;
    grid.appendChild(div);
}

function deleteSub(themeIdx, j) {
    const item = document.getElementById('tsi_' + themeIdx + '_' + j);
    if (!item) return;
    item.insertAdjacentHTML('beforeend', '<input type="hidden" name="g_sub_delete[' + themeIdx + '][]" value="' + j + '">');
    item.style.opacity = '0.3';
    item.querySelectorAll('input:not([name^="g_sub_delete"]),button').forEach(el => el.disabled = true);
}

function previewTheme(input, i) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('cover_prev_' + i);
        if (!prev) return;
        const img = document.createElement('img');
        img.src = e.target.result; img.className = 'img-preview'; img.id = 'cover_prev_' + i;
        prev.parentNode.replaceChild(img, prev);
    };
    reader.readAsDataURL(input.files[0]);
}

function previewSub(input, themeIdx, j) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('sub_prev_' + themeIdx + '_' + j);
        if (!prev) return;
        const img = document.createElement('img');
        img.src = e.target.result; img.className = 'img-preview-sm'; img.id = 'sub_prev_' + themeIdx + '_' + j;
        prev.parentNode.replaceChild(img, prev);
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Legacy Aliases (für andere Tabs die ggf. noch alte Funktionen erwarten) ──
function addGalleryCard() { addTheme(); }
function deleteCard(i)    { deleteTheme(i); }
function previewCard(input, i) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('prev_' + i);
        if (!prev) return;
        const img = document.createElement('img');
        img.src = e.target.result;
        img.className = 'img-preview';
        img.id = 'prev_' + i;
        prev.parentNode.replaceChild(img, prev);
    };
    reader.readAsDataURL(input.files[0]);
}

function previewHero(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('hero-preview').innerHTML =
            `<img src="${e.target.result}" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #d4e6c3">`;
    };
    reader.readAsDataURL(input.files[0]);
}

// Vereinshaus
function previewVhMain(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const el = document.getElementById('vh-main-prev');
        el.outerHTML = `<img id="vh-main-prev" src="${e.target.result}" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid #d4e6c3;margin-bottom:12px;object-fit:cover;display:block">`;
    };
    reader.readAsDataURL(input.files[0]);
}

function previewVhCard(input, i) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('vh_prev_' + i);
        if (!prev) return;
        const img = document.createElement('img');
        img.src = e.target.result;
        img.className = 'img-preview';
        img.id = 'vh_prev_' + i;
        prev.parentNode.replaceChild(img, prev);
    };
    reader.readAsDataURL(input.files[0]);
}

let vhCount = <?= count($vereinshaus['gallery']) ?>;
function vhAddCard() {
    const i = vhCount++;
    const wrap = document.getElementById('vh-cards');
    const div = document.createElement('div');
    div.className = 'gallery-edit-card';
    div.id = 'vhc_' + i;
    div.innerHTML = `
        <button type="button" class="del-card-btn" onclick="vhDelCard(${i})" title="Entfernen">✕</button>
        <input type="hidden" name="vh_img[]" value="">
        <div class="img-placeholder" id="vh_prev_${i}">🏡</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <label class="upload-label" style="margin:0">📷 Hochladen
            <input type="file" name="vh_upload[${i}]" accept="image/jpeg,image/png,image/webp"
                onchange="previewVhCard(this, ${i})">
          </label>
          <button type="button" class="upload-label" style="margin:0;background:#fff;border:1px dashed #3d6b41;color:#3d6b41" onclick="openImgPicker('card',${i})">🖼 Bibliothek</button>
        </div>
        <div class="field" style="margin-top:10px">
            <label>Beschriftung</label>
            <input type="text" name="vh_caption[]" value="" maxlength="60" placeholder="z.B. Terrasse im Grünen">
        </div>`;
    wrap.appendChild(div);
}

// ── Bilderbibliothek Picker ───────────────────────────────────────────────────
let _pickerMode = null, _pickerIdx = null;
function openImgPicker(mode, idx) {
    _pickerMode = mode; _pickerIdx = idx;
    document.getElementById('imgPickerModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeImgPicker() {
    document.getElementById('imgPickerModal').style.display = 'none';
    document.body.style.overflow = '';
}
function pickImg(filename) {
    if (_pickerMode === 'main') {
        const prev = document.getElementById('vh-main-prev');
        prev.outerHTML = `<img id="vh-main-prev" src="/images/${filename}" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid #d4e6c3;margin-bottom:12px;object-fit:cover;display:block">`;
        // Store selection: use a hidden input for main override
        let hi = document.getElementById('vh_main_from_lib');
        if (!hi) { hi = document.createElement('input'); hi.type='hidden'; hi.id='vh_main_from_lib'; hi.name='vh_main_from_lib'; document.getElementById('vh-form').appendChild(hi); }
        hi.value = filename;
    } else if (_pickerMode === 'card' && _pickerIdx !== null) {
        const prev = document.getElementById('vh_prev_' + _pickerIdx);
        const img = document.createElement('img');
        img.src = '/images/' + filename;
        img.className = 'img-preview';
        img.id = 'vh_prev_' + _pickerIdx;
        prev.parentNode.replaceChild(img, prev);
        // Update hidden input
        const card = document.getElementById('vhc_' + _pickerIdx);
        if (card) { const hi = card.querySelector('input[name="vh_img[]"]'); if (hi) hi.value = filename; }
    }
    closeImgPicker();
}

function vhDelCard(i) {
    const el = document.getElementById('vhc_' + i);
    if (el) el.remove();
}

// Generic file preview helper
function previewFile(input, previewId) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const el = document.getElementById(previewId);
        if (!el) return;
        const img = document.createElement('img');
        img.src = e.target.result;
        img.id = previewId;
        img.style.cssText = el.tagName === 'IMG' ? el.style.cssText : 'max-width:100%;max-height:160px;border-radius:10px;object-fit:cover;margin-bottom:12px;border:1px solid #d4e6c3;display:block';
        el.parentNode.replaceChild(img, el);
    };
    reader.readAsDataURL(input.files[0]);
}
function previewCard2(input, prefix, i) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById(prefix + i);
        if (!prev) return;
        const img = document.createElement('img');
        img.src = e.target.result; img.className = 'img-preview'; img.id = prefix + i;
        prev.parentNode.replaceChild(img, prev);
    };
    reader.readAsDataURL(input.files[0]);
}

// Vorstand
let vsCount = <?= count($vorstandData) ?>;
function vsAddCard() {
    const i = vsCount++;
    const div = document.createElement('div');
    div.className = 'gallery-edit-card'; div.id = 'vsc_' + i;
    div.innerHTML = `<button type="button" class="del-card-btn" onclick="vsDelCard(${i})">✕</button>
    <input type="hidden" name="vs_photo[]" value="">
    <div class="img-placeholder" id="vs_prev_${i}">👤</div>
    <label class="upload-label">📷 Foto<input type="file" name="vs_upload[${i}]" accept="image/jpeg,image/png,image/webp" onchange="previewCard2(this,'vs_prev_',${i})"></label>
    <div class="field" style="margin-top:8px"><label>Rolle</label><input type="text" name="vs_role[]" value="" maxlength="60" placeholder="z.B. Kassiererin"></div>
    <div class="field"><label>Name</label><input type="text" name="vs_name[]" value="" maxlength="80"></div>
    <div class="field"><label>E-Mail</label><input type="text" name="vs_email[]" value="" maxlength="120"></div>
    <div class="field"><label>Telefon</label><input type="text" name="vs_phone[]" value="" maxlength="40"></div>
    <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
      <label class="toggle"><input type="checkbox" name="vs_full[]" value="${i}"><span class="toggle-slider"></span></label>
      <span style="font-size:0.85rem">Vollbreite</span>
    </div>`;
    document.getElementById('vs-cards').appendChild(div);
}
function vsDelCard(i) { const el = document.getElementById('vsc_' + i); if (el) el.remove(); }

// Termine
let teCount = <?= count($termineData) ?>;
function addTermin() {
    const i = teCount++;
    const div = document.createElement('div');
    div.id = 'ter_' + i;
    div.style.cssText = 'display:grid;grid-template-columns:140px 1fr 1fr auto auto;gap:8px;align-items:center;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:12px';
    div.innerHTML = `<input type="date" name="te_date[]" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem">
    <input type="text" name="te_title[]" placeholder="Veranstaltungsname" maxlength="80">
    <input type="text" name="te_desc[]" placeholder="Kurzbeschreibung" maxlength="120">
    <label class="toggle" title="Öffentlich"><input type="checkbox" name="te_public[]" value="${i}" checked><span class="toggle-slider"></span></label>
    <button type="button" class="del-btn" onclick="delTermin(${i})">✕</button>`;
    document.getElementById('termine-list').appendChild(div);
}
function delTermin(i) { const el = document.getElementById('ter_' + i); if (el) el.remove(); }

// Links
let lkCount = <?= count($linksData) ?>;
function addLink() {
    const i = lkCount++;
    const div = document.createElement('div');
    div.id = 'lk_' + i;
    div.style.cssText = 'display:grid;grid-template-columns:50px 1fr 1fr 2fr auto;gap:8px;align-items:center;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:12px';
    div.innerHTML = `<input type="text" name="lk_emoji[]" value="🔗" maxlength="8" style="text-align:center;font-size:1.3rem;padding:6px">
    <input type="text" name="lk_title[]" placeholder="Name / Titel" maxlength="80">
    <input type="text" name="lk_desc[]" placeholder="Kurzbeschreibung" maxlength="120">
    <input type="text" name="lk_url[]" placeholder="https://..." maxlength="300">
    <button type="button" class="del-btn" onclick="delLink(${i})">✕</button>`;
    document.getElementById('links-list').appendChild(div);
}
function delLink(i) { const el = document.getElementById('lk_' + i); if (el) el.remove(); }

// Sperrtage
let sdCount = <?= count($blockedDates) ?>;
let srCount = <?= count($blockedRanges) ?>;
function addSd() {
    const i = sdCount++;
    const div = document.createElement('div');
    div.id = 'sd_' + i; div.style.cssText = 'display:flex;gap:8px;align-items:center';
    div.innerHTML = `<input type="date" name="sd_date[]" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem">
    <input type="text" name="sd_reason[]" placeholder="Grund (optional)" maxlength="60" style="flex:1">
    <button type="button" class="del-btn" onclick="delSd(${i})">✕</button>`;
    document.getElementById('sd-list').appendChild(div);
}
function delSd(i) { const el = document.getElementById('sd_' + i); if (el) el.remove(); }
function addSr() {
    const i = srCount++;
    const div = document.createElement('div');
    div.id = 'sr_' + i; div.style.cssText = 'display:grid;grid-template-columns:150px 20px 150px 1fr auto;gap:8px;align-items:center';
    div.innerHTML = `<input type="date" name="sr_from[]" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem">
    <span style="text-align:center;color:#5a6c5a">–</span>
    <input type="date" name="sr_to[]" style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem">
    <input type="text" name="sr_reason[]" placeholder="Grund (optional)" maxlength="60">
    <button type="button" class="del-btn" onclick="delSr(${i})">✕</button>`;
    document.getElementById('sr-list').appendChild(div);
}
function delSr(i) { const el = document.getElementById('sr_' + i); if (el) el.remove(); }

// Live-Vorschau (Hero-Tab)
(function initPreview() {
    const map = {
        'input[name=headline]': 'prev-headline',
        'input[name=subtitle]': 'prev-subtitle',
    };
    for (const [sel, id] of Object.entries(map)) {
        const el = document.querySelector(sel);
        const tgt = document.getElementById(id);
        if (el && tgt) {
            tgt.textContent = el.value;
            el.addEventListener('input', () => tgt.textContent = el.value);
        }
    }
    const desc = document.querySelector('textarea[name=description]');
    const descPrev = document.getElementById('prev-desc');
    if (desc && descPrev) {
        descPrev.innerHTML = desc.value;
        desc.addEventListener('input', () => descPrev.innerHTML = desc.value);
    }
    const notifText = document.querySelector('textarea[name=notif_text]');
    const notifPrev = document.getElementById('prev-notif');
    if (notifText && notifPrev) {
        notifPrev.innerHTML = notifText.value;
        notifText.addEventListener('input', () => notifPrev.innerHTML = notifText.value);
    }
})();
</script>
<script>
// ── Bild-Cropper ─────────────────────────────────────────────────────────────
let _cropperInstance = null, _cropFilename = '';
function openCropper(filename) {
    _cropFilename = filename;
    if (_cropperInstance) { _cropperInstance.destroy(); _cropperInstance = null; }
    const modal = document.getElementById('cropModal');
    const img   = document.getElementById('cropImg');
    img.src = '';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    img.onload = function() {
        _cropperInstance = new Cropper(img, {
            viewMode: 1,
            autoCropArea: 0.9,
            dragMode: 'move',
            background: false,
            responsive: true,
        });
    };
    // Cache busting sorgt dafür dass onload immer feuert
    img.src = '/images/' + encodeURIComponent(filename) + '?nc=' + Date.now();
}
function closeCropper() {
    document.getElementById('cropModal').style.display = 'none';
    document.body.style.overflow = '';
    if (_cropperInstance) { _cropperInstance.destroy(); _cropperInstance = null; }
}
function saveCrop() {
    if (!_cropperInstance) return;
    const canvas = _cropperInstance.getCroppedCanvas({ maxWidth: 2000, maxHeight: 2000 });
    const ext = _cropFilename.split('.').pop().toLowerCase();
    const mime = (ext === 'png') ? 'image/png' : (ext === 'webp') ? 'image/webp' : 'image/jpeg';
    canvas.toBlob(blob => {
        const reader = new FileReader();
        reader.onload = e => {
            const fd = new FormData();
            fd.append('tab',       'crop_image');
            fd.append('img_file',  _cropFilename);
            fd.append('crop_data', e.target.result);
            fd.append('csrf',      document.querySelector('[name="csrf"]')?.value || '');
            fetch('', { method: 'POST', body: fd })
              .then(r => r.text())
              .then(() => { closeCropper(); location.reload(); });
        };
        reader.readAsDataURL(blob);
    }, mime);
}

// CSRF-Token automatisch in alle Formulare einfügen
(function(){
  var t = '<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>';
  document.querySelectorAll('form[method="POST"]').forEach(function(f){
    if (!f.querySelector('[name="csrf"]')) {
      var i = document.createElement('input');
      i.type='hidden'; i.name='csrf'; i.value=t;
      f.prepend(i);
    }
  });
})();
</script>

<!-- Cropper.js -->
<link rel="stylesheet" href="/assets/vendor/cropperjs/cropper.min.css">
<script src="/assets/vendor/cropperjs/cropper.min.js"></script>

<!-- Crop-Modal -->
<div id="cropModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:10000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:20px;max-width:90vw;width:800px;display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0;color:#2d3e2d">✂ Bild zuschneiden</h3>
      <button onclick="closeCropper()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#5a6c5a">✕</button>
    </div>
    <div id="cropContainer" style="height:480px;background:#222;border-radius:8px">
      <img id="cropImg" style="display:block;max-width:100%;max-height:100%">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button onclick="closeCropper()" style="padding:9px 20px;border:1px solid #d4e6c3;border-radius:8px;background:#f5f7f2;color:#3d6b41;cursor:pointer">Abbrechen</button>
      <button onclick="saveCrop()" style="padding:9px 22px;border:none;border-radius:8px;background:#3d6b41;color:#fff;font-weight:700;cursor:pointer">💾 Speichern</button>
    </div>
  </div>
</div>
</body>
</html>
