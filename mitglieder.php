<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/settings_loader.php';


// ── Secure session ────────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies','1');
ini_set('session.use_strict_mode', '1');
session_start();

// Session timeout 60 min
if (!empty($_SESSION['member_last_activity']) && (time() - (int)$_SESSION['member_last_activity']) > 3600) {
    unset($_SESSION['kgv_member'], $_SESSION['member_last_activity']);
}
if (!empty($_SESSION['kgv_member'])) {
    $_SESSION['member_last_activity'] = time();
}

$dataDir      = __DIR__ . '/data';
$membersFile  = $dataDir . '/members.json';
$postsFile    = $dataDir . '/member_posts.json';
$filesDir     = $dataDir . '/member_files';
$contentFile  = $dataDir . '/content.json';
$messagesFile = $dataDir . '/member_messages.json';

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['member_csrf'])) {
    $_SESSION['member_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['member_csrf'];

// ── Password reset token handler ──────────────────────────────────────────────
$_resetToken   = (string)($_GET['reset_token'] ?? '');
$_showResetForm = false;
$_resetTokenValid = false;
if ($_resetToken !== '' && empty($_SESSION['kgv_member'])) {
    $members = [];
    if (file_exists($membersFile)) {
        $m = json_decode((string)file_get_contents($membersFile), true);
        if (is_array($m)) $members = $m;
    }
    foreach ($members as $mem) {
        if (($mem['reset_token'] ?? '') !== '' && hash_equals($mem['reset_token'], $_resetToken)) {
            if (($mem['reset_token_expires'] ?? 0) >= time()) {
                $_showResetForm = true;
                $_resetTokenValid = true;
            } else {
                $_showResetForm = true; // show expired message
            }
            break;
        }
    }
    unset($mem, $members, $m);
}

// ── Download handler ──────────────────────────────────────────────────────────
if (isset($_GET['download']) && !empty($_SESSION['kgv_member'])) {
    $filename = basename((string)$_GET['download']);
    // Whitelist: only alphanumeric, dash, underscore, dot — no path traversal, no CRLF
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) { http_response_code(400); exit; }
    $filepath = $filesDir . '/' . $filename;
    $realBase = realpath($filesDir);
    $realFile = realpath($filepath);
    if ($filename !== '' && $realFile !== false && $realBase !== false
        && str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)
        && is_file($realFile)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($realFile) ?: 'application/octet-stream';
        $safeDisp = preg_replace('/[\r\n"\\\\]/', '_', $filename);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $safeDisp . '"');
        header('Content-Length: ' . filesize($realFile));
        header('X-Content-Type-Options: nosniff');
        readfile($realFile);
        exit;
    }
    http_response_code(404); echo 'Datei nicht gefunden.'; exit;
}

// ── Consent revoke (POST) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['kgv_member'])) {
    $action = (string)($_POST['action'] ?? '');
    $postCsrf = (string)($_POST['member_csrf'] ?? '');
    if (!hash_equals($csrf, $postCsrf)) {
        http_response_code(403); echo 'Ungültiger Token.'; exit;
    }
    if ($action === 'submit_schaden') {
        if (trim((string)($_POST['__website'] ?? '')) !== '') {
            header('Location: /mitglieder.php?tab=schaden&schaden=ok'); exit;
        }
        $catKey  = trim((string)($_POST['category'] ?? ''));
        $title   = trim(strip_tags((string)($_POST['title']    ?? '')));
        $desc    = trim((string)($_POST['description'] ?? ''));
        if ($title === '' || $desc === '' || $catKey === '') {
            header('Location: /mitglieder.php?tab=schaden&err=empty'); exit;
        }
        require_once __DIR__ . '/inc/schriftfuehrung.php';
        sf_ensure_dirs();
        $member = $_SESSION['kgv_member'] ?? [];

        $photoFilename = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['photo']['tmp_name'];
            $size = (int)$_FILES['photo']['size'];
            if ($size <= 5 * 1024 * 1024) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $extMap = ['image/png'=>'png','image/jpeg'=>'jpg'];
                if (isset($extMap[$mime])) {
                    $sid = sf_uniq_id('schaden');
                    $photoFilename = $sid . '_photo.' . $extMap[$mime];
                    @move_uploaded_file($tmp, SF_UPLOAD_SCHAEDEN . '/' . $photoFilename);
                }
            }
        }

        $items   = sf_load_json(SF_SCHAEDEN);
        $items[] = [
            'id'           => sf_uniq_id('schaden'),
            'from_name'    => (string)($member['name']  ?? ''),
            'from_email'   => (string)($member['email'] ?? ''),
            'from_id'      => (string)($member['id']    ?? ''),
            'category'     => $catKey,
            'title'        => mb_substr($title, 0, 120),
            'description'  => mb_substr($desc, 0, 2000),
            'photo_filename' => $photoFilename,
            'status'       => 'gemeldet',
            'admin_note'   => '',
            'reported_at'  => date('Y-m-d H:i:s'),
            'ip'           => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ];
        sf_save_json(SF_SCHAEDEN, $items);

        // Notification an Sandra
        $cfg = sf_config();
        $to  = (string)($cfg['antrag_to'] ?? '');
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $catLabels = sf_schaden_categories();
            $cat = $catLabels[$catKey] ?? '🔧 Sonstige';
            $subj = '[KGV Musterstadt · Schaden] ' . $cat . ' — ' . $title;
            $txt  = "Neue Schadenmeldung\n\n"
                  . "Kategorie: {$cat}\nTitel: {$title}\n"
                  . "Von: " . ($member['name'] ?? '?') . " <" . ($member['email'] ?? '?') . ">\n\n"
                  . "Beschreibung:\n{$desc}\n\n"
                  . "Im Cockpit ansehen: " . site_url() . "/intern/?tab=schriftfuehrung&sub=schaeden";
            $html = kgv_email_html(
                'Hallo Sandra 👋,',
                "<p>Es ist eine neue Schadenmeldung eingegangen.</p>"
                . "<table style='width:100%;border-collapse:collapse;margin:14px 0'>"
                . "<tr><td style='padding:8px 12px;background:#ffebee;font-weight:700;color:#b71c1c'>Kategorie</td><td style='padding:8px 12px;background:#fff'>" . htmlspecialchars($cat) . "</td></tr>"
                . "<tr><td style='padding:8px 12px;background:#ffebee;font-weight:700;color:#b71c1c'>Titel</td><td style='padding:8px 12px;background:#fff'>" . htmlspecialchars($title) . "</td></tr>"
                . "<tr><td style='padding:8px 12px;background:#ffebee;font-weight:700;color:#b71c1c'>Gemeldet von</td><td style='padding:8px 12px;background:#fff'>" . htmlspecialchars((string)($member['name'] ?? '?')) . "</td></tr>"
                . "</table>"
                . "<div style='background:#fafafa;border-left:4px solid #b71c1c;border-radius:8px;padding:14px 18px;white-space:pre-wrap'>" . htmlspecialchars($desc) . "</div>"
                . ($photoFilename ? "<p>📸 Ein Foto wurde mitgesendet — im Cockpit ansehen.</p>" : '')
                . "<p style='text-align:center;margin-top:18px'><a href='" . site_url() . "/intern/?tab=schriftfuehrung&sub=schaeden' style='display:inline-block;background:#b71c1c;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700'>→ Im Cockpit bearbeiten</a></p>",
                'Neue Schadenmeldung',
                'Schriftführung-Cockpit', '', 'kontakt@example.org', 'Automatische Benachrichtigung'
            );
            $b = 'b_' . md5(uniqid('', true));
            $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$txt}\r\n\r\n";
            $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$b}--";
            $hdr   = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
            $hdr  .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: " . (string)($member['email'] ?? 'kontakt@example.org') . "\r\nReturn-Path: kontakt@example.org\r\n";
            @mail($to, $subj, $body, $hdr, '-fkontakt@example.org');
        }

        header('Location: /mitglieder.php?tab=schaden&schaden=ok');
        exit;
    }
    if ($action === 'submit_application') {
        // Honeypot
        if (trim((string)($_POST['__website'] ?? '')) !== '') {
            header('Location: /mitglieder.php?tab=antrag&antrag=ok'); exit; // silent for bot
        }
        $title   = trim(strip_tags((string)($_POST['title']   ?? '')));
        $message = trim((string)($_POST['message'] ?? ''));
        require_once __DIR__ . '/inc/schriftfuehrung.php';
        $arten   = sf_application_arten();
        $art     = (string)($_POST['art'] ?? '');
        if (!isset($arten[$art])) $art = '';
        if ($title === '' || $message === '' || $art === '') {
            header('Location: /mitglieder.php?tab=antrag&err=empty'); exit;
        }
        sf_ensure_dirs();
        $member  = $_SESSION['kgv_member'] ?? [];
        $uploads = sf_store_antrag_uploads('files', 6);
        $apps    = sf_load_json(SF_APPLICATIONS);
        $apps[]  = [
            'id'             => sf_uniq_id('app'),
            'art'            => $art,
            'from_name'      => (string)($member['name']  ?? ''),
            'from_email'     => (string)($member['email'] ?? ''),
            'from_id'        => (string)($member['id']    ?? ''),
            'from_parzelle'  => (string)($member['parzelle'] ?? ''),
            'title'          => mb_substr($title, 0, 120),
            'message'        => mb_substr($message, 0, 3000),
            'attachments'    => $uploads,
            'status'         => 'eingegangen',
            'admin_note'     => '',
            'decision_note'  => '',
            'decision_files' => [],
            'decided_at'     => null,
            'decided_by'     => '',
            'decision_sent_at' => null,
            'read_at'        => null,
            'history'        => [['status' => 'eingegangen', 'at' => date('Y-m-d H:i:s'), 'by' => 'Mitglied']],
            'submitted_at'   => date('Y-m-d H:i:s'),
            'ip'             => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ];
        sf_save_json(SF_APPLICATIONS, $apps);

        // Notification an Sandra
        $cfg = sf_config();
        $to  = $cfg['antrag_to'] ?? '';
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $artLabel = $arten[$art];
            $parz     = (string)($member['parzelle'] ?? '');
            $nAtt     = count($uploads);
            $subj = '[KGV Musterstadt · ' . $artLabel . '] ' . $title . ' — ' . ($member['name'] ?? '?');
            $txt  = "Neuer Antrag eingegangen.\n\n"
                  . "Art: {$artLabel}\n"
                  . "Von: " . ($member['name'] ?? '?') . ($parz !== '' ? " (Parzelle {$parz})" : '') . "\n"
                  . "E-Mail: " . ($member['email'] ?? '?') . "\n"
                  . ($nAtt > 0 ? "Anhänge: {$nAtt}\n" : '')
                  . "\nTitel: {$title}\n\n"
                  . "Antrag:\n{$message}\n\n"
                  . "Im Cockpit ansehen: " . site_url() . "/intern/?tab=schriftfuehrung&sub=applications";
            $html = kgv_email_html(
                'Hallo Sandra 👋,',
                "<p>Es ist ein neuer Antrag eingegangen.</p>"
                . "<table style='width:100%;border-collapse:collapse;margin:14px 0'>"
                . "<tr><td style='padding:8px 12px;background:#f5f0fa;border-radius:6px 0 0 6px;font-weight:700;color:#5e35b1'>Art</td><td style='padding:8px 12px;background:#f9fbf7'>" . htmlspecialchars($artLabel) . "</td></tr>"
                . "<tr><td style='padding:8px 12px;background:#f5f0fa;font-weight:700;color:#5e35b1'>Von</td><td style='padding:8px 12px;background:#f9fbf7'>" . htmlspecialchars((string)($member['name'] ?? '?')) . ($parz !== '' ? ' · Parzelle ' . htmlspecialchars($parz) : '') . " &lt;" . htmlspecialchars((string)($member['email'] ?? '?')) . "&gt;</td></tr>"
                . "<tr><td style='padding:8px 12px;background:#f5f0fa;font-weight:700;color:#5e35b1'>Titel</td><td style='padding:8px 12px;background:#f9fbf7'>" . htmlspecialchars($title) . "</td></tr>"
                . ($nAtt > 0 ? "<tr><td style='padding:8px 12px;background:#f5f0fa;font-weight:700;color:#5e35b1'>Anhänge</td><td style='padding:8px 12px;background:#f9fbf7'>" . $nAtt . " Datei(en) — im Cockpit ansehen</td></tr>" : '')
                . "</table>"
                . "<div style='background:#fff8e1;border-left:4px solid #f9a825;border-radius:8px;padding:14px 18px;margin:14px 0;white-space:pre-wrap;line-height:1.6'>" . htmlspecialchars($message) . "</div>"
                . "<p style='text-align:center;margin-top:18px'><a href='" . site_url() . "/intern/?tab=schriftfuehrung&sub=applications' style='display:inline-block;background:#01579b;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700'>→ Im Cockpit bearbeiten</a></p>",
                'Neuer Antrag',
                'Schriftführung-Cockpit', '', 'kontakt@example.org', 'Automatische Benachrichtigung'
            );
            $b = 'b_' . md5(uniqid('', true));
            $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$txt}\r\n\r\n";
            $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$b}--";
            $hdr   = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
            $hdr  .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: " . (string)($member['email'] ?? 'kontakt@example.org') . "\r\nReturn-Path: kontakt@example.org\r\n";
            @mail($to, $subj, $body, $hdr, '-fkontakt@example.org');
        }

        header('Location: /mitglieder.php?tab=meine-antraege&antrag=ok');
        exit;
    }
    if ($action === 'withdraw_application') {
        require_once __DIR__ . '/inc/schriftfuehrung.php';
        $member = $_SESSION['kgv_member'] ?? [];
        $appId  = trim((string)($_POST['app_id'] ?? ''));
        $mid    = (string)($member['id'] ?? '');
        $apps   = sf_load_json(SF_APPLICATIONS);
        $withdrawnTitle = '';
        foreach ($apps as &$a) {
            if (($a['id'] ?? '') !== $appId) continue;
            if ((string)($a['from_id'] ?? '') !== $mid || $mid === '') break;
            if (!in_array($a['status'] ?? '', ['eingegangen', 'offen'], true)) break;
            $a['status']     = 'zurueckgezogen';
            $a['updated_at'] = date('Y-m-d H:i:s');
            $a['history'][]  = ['status' => 'zurueckgezogen', 'at' => date('Y-m-d H:i:s'), 'by' => 'Mitglied'];
            $withdrawnTitle  = (string)($a['title'] ?? '');
            break;
        }
        unset($a);
        sf_save_json(SF_APPLICATIONS, $apps);

        if ($withdrawnTitle !== '') {
            $cfg = sf_config();
            $to  = $cfg['antrag_to'] ?? '';
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $subj = '[KGV Musterstadt · Antrag zurückgezogen] ' . $withdrawnTitle . ' — ' . ($member['name'] ?? '?');
                $txt  = ($member['name'] ?? '?') . " hat den Antrag \"{$withdrawnTitle}\" zurückgezogen.\n\n"
                      . "Im Cockpit: " . site_url() . "/intern/?tab=schriftfuehrung&sub=applications";
                $html = kgv_email_html(
                    'Hallo Sandra 👋,',
                    "<p><strong>" . htmlspecialchars((string)($member['name'] ?? '?')) . "</strong> hat den Antrag "
                    . "„<strong>" . htmlspecialchars($withdrawnTitle) . "</strong>\" zurückgezogen.</p>"
                    . "<p style='text-align:center;margin-top:18px'><a href='" . site_url() . "/intern/?tab=schriftfuehrung&sub=applications' style='display:inline-block;background:#01579b;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700'>→ Im Cockpit ansehen</a></p>",
                    'Antrag zurückgezogen',
                    'Schriftführung-Cockpit', '', 'kontakt@example.org', 'Automatische Benachrichtigung'
                );
                $b = 'b_' . md5(uniqid('', true));
                $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$txt}\r\n\r\n";
                $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$b}--";
                $hdr   = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
                $hdr  .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: " . (string)($member['email'] ?? 'kontakt@example.org') . "\r\nReturn-Path: kontakt@example.org\r\n";
                @mail($to, $subj, $body, $hdr, '-fkontakt@example.org');
            }
        }
        header('Location: /mitglieder.php?tab=meine-antraege&withdrawn=1');
        exit;
    }
    if ($action === 'update_contact_data') {
        $newEmail = trim(strtolower((string)($_POST['new_email'] ?? '')));
        $newPhone = trim((string)($_POST['new_phone'] ?? ''));
        $reconfirm = ($_POST['consent_reconfirm'] ?? '') === '1';
        $_profileError = '';
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_profileError = 'Ungültige E-Mail-Adresse.';
        } elseif (!$reconfirm) {
            $_profileError = 'Bitte bestätigen Sie die Zustimmung zur Datenverarbeitung.';
        } else {
            $mid = $_SESSION['kgv_member']['id'];
            $members = [];
            if (file_exists($membersFile)) {
                $m = json_decode((string)file_get_contents($membersFile), true);
                if (is_array($m)) $members = $m;
            }
            // Check email uniqueness
            foreach ($members as $chk) {
                if ($chk['id'] !== $mid && strtolower($chk['email'] ?? '') === $newEmail) {
                    $_profileError = 'Diese E-Mail-Adresse wird bereits von einem anderen Mitglied verwendet.';
                    break;
                }
            }
            if (!$_profileError) {
                $now = date('Y-m-d H:i:s');
                foreach ($members as &$mem) {
                    if ($mem['id'] !== $mid) continue;
                    $emailChanged = strtolower($mem['email'] ?? '') !== $newEmail;
                    $phoneChanged = ($mem['phone'] ?? '') !== $newPhone;
                    if ($emailChanged || $phoneChanged) {
                        if ($emailChanged) {
                            $mem['email'] = $newEmail;
                            $_SESSION['kgv_member']['email'] = $newEmail;
                        }
                        $mem['phone'] = $newPhone;
                        // Reset both consents — data changed, re-confirmation required
                        $mem['consents']['contact_allowed']           = false;
                        $mem['consents']['contact_revoked_at']        = $now;
                        $mem['consents']['in_phonelist']              = false;
                        $mem['consents']['in_phonelist_revoked_at']   = $now;
                    }
                    break;
                }
                unset($mem);
                file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                header('Location: /mitglieder.php?tab=profil&saved=contact');
                exit;
            }
        }
    }
    if ($action === 'save_birthday') {
        $bday = trim((string)($_POST['geburtstag'] ?? ''));
        // Accept DD.MM or MM-DD
        if (preg_match('/^(\d{2})\.(\d{2})$/', $bday, $bm)) $bday = $bm[2] . '-' . $bm[1];
        if (!preg_match('/^\d{2}-\d{2}$/', $bday)) $bday = '';
        $mid = $_SESSION['kgv_member']['id'];
        $members = [];
        if (file_exists($membersFile)) { $m = json_decode((string)file_get_contents($membersFile), true); if (is_array($m)) $members = $m; }
        foreach ($members as &$mem) { if ($mem['id'] !== $mid) continue; $mem['geburtstag'] = $bday; break; }
        unset($mem);
        file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: /mitglieder.php?tab=profil&saved=birthday'); exit;
    }
    if (in_array($action, ['revoke_contact', 'revoke_phonelist', 'grant_contact', 'grant_phonelist', 'change_password'], true)) {
        $mid = $_SESSION['kgv_member']['id'];
        $members = [];
        if (file_exists($membersFile)) {
            $m = json_decode((string)file_get_contents($membersFile), true);
            if (is_array($m)) $members = $m;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($members as &$mem) {
            if ($mem['id'] !== $mid) continue;
            if ($action === 'revoke_contact') {
                $mem['consents']['contact_allowed'] = false;
                $mem['consents']['contact_revoked_at'] = $now;
            } elseif ($action === 'grant_contact') {
                $mem['consents']['contact_allowed'] = true;
                $mem['consents']['contact_allowed_at'] = $now;
                $mem['consents']['contact_revoked_at'] = null;
            } elseif ($action === 'revoke_phonelist') {
                $mem['consents']['in_phonelist'] = false;
                $mem['consents']['in_phonelist_revoked_at'] = $now;
            } elseif ($action === 'grant_phonelist') {
                $mem['consents']['in_phonelist'] = true;
                $mem['consents']['in_phonelist_at'] = $now;
                $mem['consents']['in_phonelist_revoked_at'] = null;
            } elseif ($action === 'change_password') {
                $pwCurrent   = (string)($_POST['pw_current'] ?? '');
                $pwNew       = (string)($_POST['pw_new']     ?? '');
                $pwNew2      = (string)($_POST['pw_new2']    ?? '');
                $isMustChange = !empty($mem['must_change_password']);
                $pwCurrentOk  = $isMustChange
                    ? password_verify($pwCurrent, (string)($mem['password_hash'] ?? ''))
                    : password_verify($pwCurrent, (string)($mem['password_hash'] ?? ''));
                if (!$pwCurrentOk) {
                    $_pwError = 'Aktuelles Passwort falsch.';
                } elseif ($pwNew !== $pwNew2) {
                    $_pwError = 'Passwörter stimmen nicht überein.';
                } elseif (strlen($pwNew) < 8) {
                    $_pwError = 'Mindestens 8 Zeichen erforderlich.';
                } elseif (!preg_match('/[A-Z]/', $pwNew)) {
                    $_pwError = 'Mindestens ein Großbuchstabe erforderlich.';
                } elseif (!preg_match('/[a-z]/', $pwNew)) {
                    $_pwError = 'Mindestens ein Kleinbuchstabe erforderlich.';
                } elseif (!preg_match('/[0-9]/', $pwNew)) {
                    $_pwError = 'Mindestens eine Zahl erforderlich.';
                } else {
                    $mem['password_hash']      = password_hash($pwNew, PASSWORD_BCRYPT);
                    $mem['must_change_password'] = false;
                    $_SESSION['kgv_member']['must_change_pw'] = false;
                    $_pwSuccess = true;
                }
            }
            break;
        }
        unset($mem);
        file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($action !== 'change_password') {
            header('Location: /mitglieder.php?tab=profil&saved=1');
            exit;
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$isLoggedIn   = !empty($_SESSION['kgv_member']);
$member       = $isLoggedIn ? $_SESSION['kgv_member'] : null;
$mustChangePw = $isLoggedIn && !empty($_SESSION['kgv_member']['must_change_pw']);

// Load content for termine
$contentData = [];
if (file_exists($contentFile)) {
    $cd = json_decode((string)file_get_contents($contentFile), true);
    if (is_array($cd)) $contentData = $cd;
}
$publicTermine   = array_filter($contentData['termine'] ?? [], fn($t) => !empty($t['public']));
usort($publicTermine, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
$nbBlockedDates  = array_values(array_column($contentData['blocked_dates']  ?? [], 'date'));
$nbBlockedRanges = array_values($contentData['blocked_ranges'] ?? []);

// Load posts
$posts = [];
if ($isLoggedIn && file_exists($postsFile)) {
    $p = json_decode((string)file_get_contents($postsFile), true);
    if (is_array($p)) $posts = $p;
    usort($posts, function($a, $b) {
        if (!empty($a['pinned']) && empty($b['pinned'])) return -1;
        if (empty($a['pinned']) && !empty($b['pinned'])) return 1;
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
}

// Load files list
$memberFiles = [];
if ($isLoggedIn && is_dir($filesDir)) {
    foreach (scandir($filesDir) as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
        $fp = $filesDir . '/' . $f;
        if (!is_file($fp)) continue;
        $memberFiles[] = [
            'name'  => $f,
            'size'  => filesize($fp),
            'mtime' => filemtime($fp),
        ];
    }
    usort($memberFiles, fn($a, $b) => $b['mtime'] - $a['mtime']);
}

// Load members for phonelist
$allMembers = [];
if ($isLoggedIn && file_exists($membersFile)) {
    $ml = json_decode((string)file_get_contents($membersFile), true);
    if (is_array($ml)) $allMembers = $ml;
}
$phonelist = array_filter($allMembers, fn($m) =>
    !empty($m['active']) && !empty($m['consents']['in_phonelist'])
);
usort($phonelist, function($a, $b) {
    $pa = (int)($a['parzelle'] ?? 0);
    $pb = (int)($b['parzelle'] ?? 0);
    if ($pa === 0 && $pb === 0) return strcmp($a['name'] ?? '', $b['name'] ?? '');
    if ($pa === 0) return 1;
    if ($pb === 0) return -1;
    return $pa - $pb;
});

// Load member message threads
$memberThreads = [];
if ($isLoggedIn && file_exists($messagesFile)) {
    $mt = json_decode((string)file_get_contents($messagesFile), true);
    if (is_array($mt)) {
        $memberThreads = array_values(array_filter($mt, fn($t) => ($t['member_id'] ?? '') === ($member['id'] ?? '') && empty($t['deleted_by_member'])));
        usort($memberThreads, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    }
}
$openThreads = count(array_filter($memberThreads, fn($t) => ($t['status'] ?? '') === 'open'));

// Build role member map for recipient preview in contact form
$roleMemberMap = ['vorstand' => [], 'kassier' => [], 'koppel' => [], 'web' => []];
foreach ($allMembers as $_rm) {
    if (empty($_rm['active'])) continue;
    foreach ($_rm['roles'] ?? [] as $_role) {
        if (isset($roleMemberMap[$_role])) {
            $roleMemberMap[$_role][] = ['id' => $_rm['id'], 'name' => $_rm['name'] ?? ''];
        }
    }
}

// Contactable members (active + consent_contact_allowed, not self)
$contactableMembers = [];
if ($isLoggedIn) {
    $contactableMembers = array_values(array_filter($allMembers, fn($m) =>
        !empty($m['active']) && !empty($m['consents']['contact_allowed'])
        && ($m['id'] ?? '') !== ($member['id'] ?? '')
    ));
    usort($contactableMembers, fn($a, $b) => (int)($a['parzelle'] ?? 0) - (int)($b['parzelle'] ?? 0));
}

// Inbox: messages sent TO me by other members
$inboxMemberThreads = [];
if ($isLoggedIn && file_exists($messagesFile)) {
    $mt2 = json_decode((string)file_get_contents($messagesFile), true);
    if (is_array($mt2)) {
        $inboxMemberThreads = array_values(array_filter($mt2, fn($t) =>
            ($t['recipient_member_id'] ?? '') === ($member['id'] ?? '')
            && empty($t['deleted_by_recipient'])
        ));
        usort($inboxMemberThreads, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    }
}

// Current member full data (for profile tab)
$memberFull = null;
if ($isLoggedIn) {
    foreach ($allMembers as $m) {
        if ($m['id'] === $member['id']) { $memberFull = $m; break; }
    }
}

// ── Gemeinschaftsarbeit ───────────────────────────────────────────────────────
$arbeitFile = $dataDir . '/arbeitsstunden.json';
$arbeitData = file_exists($arbeitFile) ? (json_decode((string)file_get_contents($arbeitFile), true) ?: []) : [];
$arbeitYear = (int)date('Y');
$arbeitSoll = max(1, (int)(($contentData['settings']['arbeit_soll_stunden'] ?? 4)));
$myMemberId = $member['id'] ?? '';
$myArbeitEntries = array_values(array_filter($arbeitData[$myMemberId] ?? [], fn($e) => (int)($e['year'] ?? 0) === $arbeitYear));
$myHours    = array_sum(array_column($myArbeitEntries, 'hours'));

// ── Eigene Buchungen ──────────────────────────────────────────────────────────
$bookingsFile  = $dataDir . '/bookings.json';
$allBookings   = file_exists($bookingsFile) ? (json_decode((string)file_get_contents($bookingsFile), true) ?: []) : [];
$myBookings    = [];
if ($isLoggedIn && $member) {
    $myEmail = strtolower(trim($member['email'] ?? ''));
    foreach ($allBookings as $bk) {
        if (strtolower(trim($bk['email'] ?? '')) === $myEmail) {
            $myBookings[] = $bk;
        }
    }
    usort($myBookings, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
}

// ── Stornierungsanfrage Handler ───────────────────────────────────────────────
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'cancel_request') {
        $postCsrf = (string)($_POST['member_csrf'] ?? '');
        if (hash_equals($csrf, $postCsrf)) {
            $cancelBkgId = trim((string)($_POST['booking_id'] ?? ''));
            $myEmailLc   = strtolower(trim($member['email'] ?? ''));
            $allBkgs     = file_exists($bookingsFile) ? (json_decode((string)file_get_contents($bookingsFile), true) ?: []) : [];
            foreach ($allBkgs as &$bk) {
                if (($bk['id'] ?? '') !== $cancelBkgId) continue;
                if (strtolower(trim($bk['email'] ?? '')) !== $myEmailLc) break;
                if (in_array($bk['status'] ?? '', ['pending','confirmed'], true)) {
                    $bk['cancel_requested']    = true;
                    $bk['cancel_requested_at'] = date('Y-m-d H:i:s');
                    $bk['cancel_requested_by'] = $member['id'];
                }
                break;
            }
            unset($bk);
            file_put_contents($bookingsFile, json_encode($allBkgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            header('Location: /mitglieder.php?tab=buchungen&cancelled=1'); exit;
        }
    }
}
$isKoppel   = $isLoggedIn && in_array('koppel',  $member['roles'] ?? [], true);
$isKassier  = $isLoggedIn && in_array('kassier', $member['roles'] ?? [], true);

// ── Koppelmann/frau: Arbeitsstunden-Handler ───────────────────────────────────
if ($isKoppel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kAction = trim((string)($_POST['koppel_action'] ?? ''));
    if ($kAction !== '') {
        if (!hash_equals($csrf, (string)($_POST['member_csrf'] ?? ''))) {
            http_response_code(403); exit('CSRF');
        }
        if ($kAction === 'save_arbeit') {
            $kMemId  = trim((string)($_POST['kmem_id'] ?? ''));
            $kYear   = (int)($_POST['arbeit_year']  ?? $arbeitYear);
            $kHours  = max(0.0, (float)str_replace(',', '.', (string)($_POST['arbeit_hours'] ?? '0')));
            $kDate   = trim((string)($_POST['arbeit_date'] ?? date('Y-m-d')));
            $kNote   = substr(trim(strip_tags((string)($_POST['arbeit_note'] ?? ''))), 0, 200);
            if ($kMemId !== '') {
                if (!isset($arbeitData[$kMemId])) $arbeitData[$kMemId] = [];
                $arbeitData[$kMemId][] = [
                    'year' => $kYear, 'hours' => $kHours, 'date' => $kDate, 'note' => $kNote,
                    'recorded_by' => $myMemberId, 'recorded_at' => date('Y-m-d H:i:s'),
                ];
                file_put_contents($arbeitFile, json_encode($arbeitData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            header('Location: /mitglieder.php?tab=koppel&ok=1'); exit;
        }
        if ($kAction === 'delete_arbeit') {
            $kMemId = trim((string)($_POST['kmem_id'] ?? ''));
            $kIdx   = (int)($_POST['arbeit_idx'] ?? -1);
            if ($kMemId !== '' && isset($arbeitData[$kMemId])) {
                $allE   = $arbeitData[$kMemId];
                $yearKeys = array_keys(array_filter($allE, fn($e) => (int)($e['year'] ?? 0) === $arbeitYear));
                if (isset($yearKeys[$kIdx])) {
                    array_splice($allE, $yearKeys[$kIdx], 1);
                    $arbeitData[$kMemId] = array_values($allE);
                    file_put_contents($arbeitFile, json_encode($arbeitData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                }
            }
            header('Location: /mitglieder.php?tab=koppel&ok=1'); exit;
        }
    }
}

// ── Rollen-Posteingang laden (koppel / kassier) ───────────────────────────────
$myRoleInbox = [];
if ($isKoppel || $isKassier) {
    $myRole = $isKoppel ? 'koppel' : 'kassier';
    if (file_exists($messagesFile)) {
        $allMsgsRaw = json_decode((string)file_get_contents($messagesFile), true) ?: [];
        $myRoleInbox = array_values(array_filter($allMsgsRaw,
            fn($t) => ($t['recipient_role'] ?? 'vorstand') === $myRole
                   && empty($t['deleted_by_admin'])
        ));
        usort($myRoleInbox, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    }
}

// ── Rollen-Reply-Handler (koppel / kassier antwortet auf eingehende Nachrichten) ──
if (($isKoppel || $isKassier) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rAction = trim((string)($_POST['role_action'] ?? ''));
    if ($rAction === 'role_reply') {
        if (!hash_equals($csrf, (string)($_POST['member_csrf'] ?? ''))) {
            http_response_code(403); exit('CSRF');
        }
        $rThreadId = trim((string)($_POST['r_thread_id'] ?? ''));
        $rBody     = substr(trim(strip_tags((string)($_POST['r_body'] ?? ''))), 0, 5000);
        if ($rThreadId !== '' && $rBody !== '') {
            $myRole   = $isKoppel ? 'koppel' : 'kassier';
            $allMsgsR = file_exists($messagesFile) ? (json_decode((string)file_get_contents($messagesFile), true) ?: []) : [];
            $rMemberEmail = '';
            $rMemberName  = '';
            $rSubject     = '';
            foreach ($allMsgsR as &$rT) {
                if (($rT['id'] ?? '') !== $rThreadId) continue;
                if (($rT['recipient_role'] ?? 'vorstand') !== $myRole) break;
                if (empty($rT['first_reply_by_id'])) {
                    $rT['first_reply_by_id']   = $member['id'];
                    $rT['first_reply_by_name'] = $member['name'];
                }
                $rT['messages'][]  = ['from' => 'admin', 'body' => $rBody, 'created_at' => date('Y-m-d H:i:s'), 'admin_name' => $member['name']];
                $rT['status']      = 'answered';
                $rT['updated_at']  = date('Y-m-d H:i:s');
                $rT['deleted_by_member'] = false;
                $rMemberEmail = $rT['member_email'] ?? '';
                $rMemberName  = $rT['member_name']  ?? '';
                $rSubject     = $rT['subject']       ?? '';
                break;
            }
            unset($rT);
            file_put_contents($messagesFile, json_encode($allMsgsR, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            // Send email to member
            if ($rMemberEmail !== '' && filter_var($rMemberEmail, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/inc/email_template.php';
                $_rfrom = 'kontakt@example.org';
                $_rsubj = 'Antwort auf Ihre Anfrage: ' . $rSubject;
                $_rcontent = "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:14px'>Ihre Anfrage wurde beantwortet von <strong>" . htmlspecialchars($member['name']) . "</strong>.</p>"
                           . "<div style='background:#f5f7f2;border-radius:8px;padding:14px;margin-bottom:14px'><p style='margin:0;white-space:pre-wrap;color:#2d3e2d'>" . htmlspecialchars($rBody) . "</p></div>"
                           . "<a href='" . site_url() . "/mitglieder.php?tab=kontakt' style='display:inline-block;background:#3d6b41;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem'>Antwort ansehen →</a>";
                $_rhtml = kgv_email_html('Hallo ' . htmlspecialchars($rMemberName) . ',', $_rcontent, 'Antwort auf Ihre Anfrage');
                $_rhdr  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$_rfrom}>\r\nReturn-Path: {$_rfrom}\r\n";
                @mail($rMemberEmail, '=?UTF-8?B?' . base64_encode($_rsubj) . '?=', $_rhtml, $_rhdr, "-f{$_rfrom}");
            }
        }
        header('Location: /mitglieder.php?tab=kontakt&replied=1'); exit;
    }
}

$activeTab = (string)($_GET['tab'] ?? 'pinnwand');
$allowedTabs = ['pinnwand','termine','veranstaltungen','buchungen','gemeinschaft','downloads','telefonliste','kontakt','antrag','meine-antraege','profil','passwort'];
if ($isKoppel) $allowedTabs[] = 'koppel';
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'pinnwand';

// ── Eigene Anträge (für Tab „Meine Anträge") ─────────────────────────────────
$myApplications = [];
if ($isLoggedIn && $member) {
    require_once __DIR__ . '/inc/schriftfuehrung.php';
    $_myAppId = (string)($member['id'] ?? '');
    foreach (sf_load_json(SF_APPLICATIONS) as $_app) {
        if ($_myAppId !== '' && (string)($_app['from_id'] ?? '') === $_myAppId) $myApplications[] = $_app;
    }
    usort($myApplications, fn($a, $b) => strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? ''));
}
$myApplicationArten    = function_exists('sf_application_arten') ? sf_application_arten() : [];
$myApplicationStatuses = function_exists('sf_application_statuses') ? sf_application_statuses() : [];
// Force password change: only passwort tab allowed
if ($mustChangePw) $activeTab = 'passwort';

// Badge: unread posts (simple: posts newer than last_login)
$newPosts = 0;
if ($isLoggedIn && $memberFull) {
    $lastLogin = $memberFull['last_login'] ?? '2000-01-01';
    foreach ($posts as $p) {
        if (($p['created_at'] ?? '') > $lastLogin) $newPosts++;
    }
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function postTypeLabel(string $type): array {
    return match($type) {
        'gemeinschaftsarbeit' => ['Gemeinschaftsarbeit', '#2e7d32', '#e8f5e9'],
        'protokoll'           => ['Protokoll', '#1565c0', '#e3f2fd'],
        'aushang'             => ['Aushang', '#e65100', '#fff3e0'],
        default               => ['Info', '#5a6c5a', '#f5f7f2'],
    };
}

function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="KGV Musterstadt">
<meta name="theme-color" content="#3d6b41">
<title>Mitgliederbereich – KGV Musterstadt e.V.</title>
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/icon-180.png">
<link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">
<link rel="stylesheet" href="/assets/fonts/fonts.css">
<style>
:root {
    --green: #3d6b41;
    --green-light: #7cb342;
    --green-pale: #f0f5ec;
    --green-border: #d4e6c3;
    --text: #2d3e2d;
    --text-muted: #5a6c5a;
    --white: #ffffff;
    --radius: 12px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: var(--green-pale); color: var(--text); min-height: 100vh; }


/* ── LOGIN PAGE ── */
.login-wrap { min-height: calc(100vh - 60px); display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
.login-card { background: #fff; border-radius: var(--radius); border: 1px solid var(--green-border); padding: 40px 36px; width: 100%; max-width: 420px; box-shadow: 0 4px 24px rgba(61,107,65,0.08); }
.login-card h1 { font-family: 'Playfair Display', serif; color: var(--green); font-size: 1.6rem; margin-bottom: 6px; }
.login-card p { color: var(--text-muted); font-size: 0.85rem; margin-bottom: 28px; }
.field { margin-bottom: 18px; }
.field label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.field input, .field textarea, .field select { width: 100%; padding: 10px 14px; border: 1.5px solid #dde8d0; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.9rem; color: var(--text); background: #fafcf8; transition: border-color .2s; }
.field input:focus, .field textarea:focus { outline: none; border-color: var(--green); background: #fff; }
.field textarea { min-height: 100px; resize: vertical; }
.btn-primary { width: 100%; background: var(--green); color: #fff; border: none; padding: 12px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: background .2s; }
.btn-primary:hover { background: #2d5234; }
.btn-secondary { background: #f0f5ec; color: var(--green); border: 1.5px solid var(--green-border); padding: 10px 18px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.88rem; font-weight: 500; cursor: pointer; transition: background .2s; }
.btn-secondary:hover { background: #e4edda; }
.error-msg { background: #fce8e6; color: #c62828; border: 1px solid #f5c6c2; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 16px; display: none; }
.success-msg { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 16px; display: none; }
.register-link { text-align: center; margin-top: 20px; font-size: 0.85rem; color: var(--text-muted); }
.register-link a { color: var(--green); font-weight: 500; cursor: pointer; }

/* ── DASHBOARD ── */
.dashboard { max-width: 900px; margin: 0 auto; padding: 28px 16px 60px; }
.tab-nav { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 24px; background: #fff; border: 1px solid var(--green-border); border-radius: var(--radius); padding: 6px; }
.tab-btn { background: none; border: none; padding: 9px 16px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.85rem; font-weight: 500; color: var(--text-muted); cursor: pointer; transition: all .2s; white-space: nowrap; position: relative; }
.tab-btn:hover { background: var(--green-pale); color: var(--text); }
.tab-btn.active { background: var(--green); color: #fff; }
.tab-btn .badge { display: inline-flex; align-items: center; justify-content: center; background: #e53935; color: #fff; font-size: 0.65rem; font-weight: 700; border-radius: 10px; min-width: 16px; height: 16px; padding: 0 4px; margin-left: 5px; vertical-align: middle; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
/* ── Tab Icon/Label Wrapper (Desktop: inline; Mobile: stack) ── */
.tab-icon-wrap { display: inline; font-size: inherit; position: relative; }
.tab-label     { display: inline; font-size: inherit; }
.tab-dot       { display: none !important; }
.card { background: #fff; border: 1px solid var(--green-border); border-radius: var(--radius); padding: 24px; margin-bottom: 16px; }
.card h3 { font-family: 'Playfair Display', serif; color: var(--green); font-size: 1.1rem; margin-bottom: 16px; }

/* Posts */
.post-item { border: 1px solid var(--green-border); border-radius: 10px; padding: 18px 20px; margin-bottom: 12px; background: #fafcf8; }
.post-item.pinned { border-color: var(--green-light); background: #f4fbef; }
.post-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.post-badge { font-size: 0.7rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.post-title { font-weight: 600; font-size: 0.95rem; flex: 1; }
.post-date { font-size: 0.78rem; color: var(--text-muted); white-space: nowrap; }
.post-body { font-size: 0.88rem; color: var(--text-muted); line-height: 1.6; }
.post-file { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; color: var(--green); font-size: 0.82rem; font-weight: 500; text-decoration: none; }
.post-file:hover { text-decoration: underline; }
.pin-icon { font-size: 0.75rem; color: var(--green-light); }

/* Termine */
.termin-item { display: flex; gap: 16px; padding: 14px 0; border-bottom: 1px solid var(--green-border); align-items: flex-start; }
.termin-item:last-child { border-bottom: none; }
.termin-date-box { background: var(--green); color: #fff; border-radius: 8px; padding: 8px 12px; text-align: center; min-width: 54px; }
.termin-date-box .day { font-size: 1.3rem; font-weight: 700; line-height: 1; }
.termin-date-box .mon { font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; opacity: .85; }
.termin-title { font-weight: 600; font-size: 0.92rem; }
.termin-tag { font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; display: inline-block; margin-top: 4px; }

/* Downloads */
.file-item { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--green-border); }
.file-item:last-child { border-bottom: none; }
.file-icon { font-size: 1.6rem; }
.file-info { flex: 1; }
.file-name { font-weight: 500; font-size: 0.9rem; }
.file-meta { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }
.btn-download { background: var(--green); color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.82rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: background .2s; }
.btn-download:hover { background: #2d5234; }

/* Telefonliste */
.phonelist-wrap { position: relative; }
.phonelist-watermark {
    position: fixed; inset: 0; pointer-events: none; z-index: 10;
    display: none;
    overflow: hidden;
}
.phonelist-watermark span {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: clamp(16px, 3vw, 28px); font-weight: 700;
    color: rgba(61,107,65,0.12); white-space: nowrap;
    text-align: center; line-height: 2.5;
    width: 200%; letter-spacing: 2px;
}
.phonelist-table { width: 100%; border-collapse: collapse; }
.phonelist-table th { background: var(--green); color: #fff; padding: 10px 14px; text-align: left; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.phonelist-table th:first-child { border-radius: 8px 0 0 0; }
.phonelist-table th:last-child { border-radius: 0 8px 0 0; }
.phonelist-table td { padding: 10px 14px; font-size: 0.88rem; border-bottom: 1px solid var(--green-border); }
.phonelist-table tr:last-child td { border-bottom: none; }
.phonelist-table tr:nth-child(even) td { background: #fafcf8; }
.phonelist-notice { font-size: 0.78rem; color: var(--text-muted); margin-top: 12px; padding: 10px 14px; background: #fff8e1; border-radius: 8px; border: 1px solid #ffe082; }

/* Profil */
.consent-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--green-border); flex-wrap: wrap; }
.consent-row:last-child { border-bottom: none; }
.consent-info strong { display: block; font-size: 0.9rem; margin-bottom: 4px; }
.consent-info span { font-size: 0.82rem; color: var(--text-muted); }
.consent-status { font-size: 0.78rem; padding: 3px 10px; border-radius: 20px; font-weight: 600; white-space: nowrap; }
.consent-status.active { background: #e8f5e9; color: #2e7d32; }
.consent-status.revoked { background: #fce8e6; color: #c62828; }
.btn-revoke { background: #fce8e6; color: #c62828; border: 1px solid #f5c6c2; padding: 7px 14px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.8rem; font-weight: 500; cursor: pointer; transition: background .2s; }
.btn-revoke:hover { background: #f8b4ae; }
.btn-grant { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 7px 14px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.8rem; font-weight: 500; cursor: pointer; transition: background .2s; }
.btn-grant:hover { background: #c8e6c9; }
.profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
.profile-field { background: var(--green-pale); border-radius: 8px; padding: 12px 16px; }
.profile-field .label { font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.profile-field .value { font-size: 0.9rem; font-weight: 500; }

/* Modal */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; padding: 16px; }
.modal-overlay.open { display: flex; }
.modal { background: #fff; border-radius: var(--radius); padding: 32px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; position: relative; }
.modal h2 { font-family: 'Playfair Display', serif; color: var(--green); font-size: 1.3rem; margin-bottom: 6px; }
.modal p.sub { font-size: 0.83rem; color: var(--text-muted); margin-bottom: 24px; }
.modal-close { position: absolute; top: 14px; right: 16px; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: var(--text-muted); line-height: 1; }
.check-row { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; font-size: 0.85rem; }
.check-row input[type=checkbox] { margin-top: 2px; width: 16px; height: 16px; accent-color: var(--green); flex-shrink: 0; }
.hint { font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; }

/* Kontakt form */
.contact-form .field { margin-bottom: 16px; }

/* ── Select-Dropdown Styling ── */
.field select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235a6c5a' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
    cursor: pointer;
}

@media (max-width: 600px) {
    .login-card { padding: 28px 20px; }
    .profile-grid { grid-template-columns: 1fr; }
    .dashboard { padding: 16px 12px 60px; }
    /* Karten-Padding reduzieren */
    .card { padding: 16px; }
    /* iOS-Zoom verhindern: Inputs mind. 16px */
    .field input, .field textarea, .field select { font-size: 1rem; }
    /* Buttons volle Breite auf Mobile */
    .btn-primary { font-size: 1rem; padding: 14px; }
    .contact-form .btn-primary { max-width: 100% !important; }
    /* Modal Mobile */
    .modal { padding: 20px 16px; border-radius: 10px; }
    .modal h2 { font-size: 1.1rem; }
    /* Post-Items kompakter */
    .post-item { padding: 14px 14px; }
    /* Telefonliste scrollbar */
    .phonelist-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .phonelist-table td, .phonelist-table th { padding: 8px 10px; font-size: 0.8rem; white-space: nowrap; }
    /* ── App-like Tab Navigation ── */
    .tab-nav {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding: 6px 4px;
        gap: 2px;
        margin-bottom: 16px;
        /* Fade-Gradient rechts als Scroll-Hinweis */
        box-shadow: inset -28px 0 18px -10px rgba(255,255,255,0.97);
    }
    .tab-nav::-webkit-scrollbar { display: none; }
    .tab-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        gap: 3px;
        min-width: 62px;
        min-height: 56px;
        padding: 8px 10px 7px;
        border-radius: 10px;
        font-size: 0.65rem;
        -webkit-tap-highlight-color: transparent;
        transition: background .15s, color .15s;
    }
    /* Icon-Wrapper: groß, Badge positionierbar */
    .tab-icon-wrap {
        display: block;
        position: relative;
        font-size: 1.35rem;
        line-height: 1;
    }
    /* Label: Text unter Icon */
    .tab-label {
        display: block;
        font-size: 0.65rem;
        line-height: 1.1;
        text-align: center;
        white-space: nowrap;
    }
    /* Badge-Dot: roter Kreis oben-rechts am Icon */
    .tab-dot {
        display: block !important;
        position: absolute;
        top: -2px;
        right: -4px;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: #e53935;
        border: 1.5px solid #fff;
    }
    /* Badge-Text auf Mobile ausblenden — nur Dot */
    .tab-btn .badge { display: none; }
    /* Termine kompakter */
    .termin-item { gap: 10px; padding: 12px 0; }
    .termin-date-box { min-width: 46px; padding: 6px 8px; }
    .termin-date-box .day { font-size: 1.1rem; }
}
/* Bottom-Nav komplett entfernen */
.bottom-nav { display: none !important; }
</style>
</head>
<body>

<?php include __DIR__ . '/inc/admin_nav.php'; ?>

<?php if (!$isLoggedIn): ?>
<!-- ══ LOGIN PAGE ══════════════════════════════════════════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div style="text-align:center;margin-bottom:8px"><img src="<?= site_url() ?>/images/logo.png" alt="KGV Musterstadt e.V." style="max-height:72px;width:auto"></div>
    <h1>Mitgliederbereich</h1>
    <p>Bitte melden Sie sich mit Ihrer E-Mail-Adresse und Ihrem Passwort an.</p>
    <div id="loginError" class="error-msg"></div>
    <div id="loginSuccess" class="success-msg"></div>
    <div class="field">
      <label>E-Mail-Adresse</label>
      <input type="email" id="loginEmail" placeholder="ihre@email.de" autocomplete="email">
    </div>
    <div class="field">
      <label>Passwort</label>
      <input type="password" id="loginPassword" placeholder="Ihr Passwort" autocomplete="current-password">
    </div>
    <button class="btn-primary" onclick="doLogin()">Anmelden</button>
    <div class="register-link">
      Noch kein Zugang? <a onclick="document.getElementById('registerModal').classList.add('open')">Zugang beantragen</a>
      &nbsp;·&nbsp; <a onclick="document.getElementById('resetModal').classList.add('open')" style="color:#5a6c5a">Passwort vergessen?</a>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('resetModal').classList.remove('open')">&times;</button>
    <h2>Passwort zurücksetzen</h2>
    <p class="sub">Geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link zum Zurücksetzen Ihres Passworts.</p>
    <div id="resetError" class="error-msg"></div>
    <div id="resetSuccess" class="success-msg" style="display:none">
      ✅ Falls ein Konto mit dieser E-Mail existiert, erhalten Sie in Kürze eine E-Mail mit einem Reset-Link. Bitte prüfen Sie auch Ihren Spam-Ordner.
    </div>
    <div id="resetForm">
      <div class="field"><label>E-Mail-Adresse</label><input type="email" id="resetEmail" placeholder="ihre@email.de"></div>
      <button class="btn-primary" onclick="doRequestReset()" style="margin-top:10px">Link anfordern</button>
    </div>
  </div>
</div>

<?php if ($_showResetForm): ?>
<!-- Reset password confirm overlay (shown via JS on page load) -->
<div class="modal-overlay open" id="resetConfirmModal">
  <div class="modal">
    <h2>Neues Passwort festlegen</h2>
    <?php if (!$_resetTokenValid): ?>
      <div class="error-msg" style="display:block">Dieser Reset-Link ist abgelaufen oder ungültig. Bitte fordern Sie einen neuen Link an.</div>
      <div style="margin-top:16px"><a onclick="document.getElementById('resetConfirmModal').classList.remove('open');document.getElementById('resetModal').classList.add('open')" class="btn-primary" style="cursor:pointer;display:inline-block">Neuen Link anfordern</a></div>
    <?php else: ?>
      <p class="sub">Wählen Sie ein neues Passwort (min. 8 Zeichen, Groß-/Kleinbuchstaben, Zahl).</p>
      <div id="resetConfirmError" class="error-msg"></div>
      <div id="resetConfirmSuccess" class="success-msg" style="display:none">✅ Passwort geändert! Sie können sich jetzt anmelden.</div>
      <div id="resetConfirmForm">
        <input type="hidden" id="resetConfirmToken" value="<?= htmlspecialchars($_resetToken) ?>">
        <div class="field"><label>Neues Passwort</label><input type="password" id="rcPassword" placeholder="Neues Passwort" autocomplete="new-password"></div>
        <div class="field"><label>Passwort wiederholen</label><input type="password" id="rcPassword2" placeholder="Passwort wiederholen" autocomplete="new-password"></div>
        <button class="btn-primary" onclick="doConfirmReset()" style="margin-top:10px">Passwort speichern</button>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Register Modal -->
<div class="modal-overlay" id="registerModal">
  <div class="modal">
    <button class="modal-close" onclick="document.getElementById('registerModal').classList.remove('open')">&times;</button>
    <h2>Zugang beantragen</h2>
    <p class="sub">Ihr Antrag wird vom Vorstand geprüft. Nach Freigabe erhalten Sie Ihre Zugangsdaten per E-Mail.</p>
    <div id="regError" class="error-msg"></div>
    <div id="regSuccess" class="success-msg" style="display:none">
      ✅ Ihr Antrag wurde eingereicht. Der Vorstand wird ihn prüfen und sich per E-Mail melden.
    </div>
    <div id="regForm">
      <div class="field">
        <label>Mitgliedsart *</label>
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:6px">
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;text-transform:none;cursor:pointer"><input type="radio" name="regTyp" value="paechter" checked onchange="onRegTypChange()"> 🌱 Pächter</label>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;text-transform:none;cursor:pointer"><input type="radio" name="regTyp" value="paechterpartner" onchange="onRegTypChange()"> 🤝 Pächterpartner</label>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;text-transform:none;cursor:pointer"><input type="radio" name="regTyp" value="foerdermitglied" onchange="onRegTypChange()"> 💛 Fördermitglied</label>
        </div>
      </div>
      <div class="field"><label>Name *</label><input type="text" id="regName" placeholder="Vor- und Nachname"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="field"><label>E-Mail *</label><input type="email" id="regEmail" placeholder="ihre@email.de"></div>
        <div class="field" id="regParzelleWrapper">
          <label>Parzelle-Nr. *</label>
          <input type="text" id="regParzelle" placeholder="z.B. 42">
          <div id="regPartnerHint" style="display:none;font-size:0.78rem;color:#5a6c5a;margin-top:4px">Bitte die Parzellennummer des Hauptpächters eintragen, zu dem Sie als Partner hinzugefügt werden möchten.</div>
        </div>
      </div>
      <div class="field"><label>Telefon <span style="font-weight:400;text-transform:none">(optional)</span></label><input type="tel" id="regPhone" placeholder="0163 ..."></div>
      <div class="field"><label>Nachricht <span style="font-weight:400;text-transform:none">(optional)</span></label><textarea id="regMessage" placeholder="Kurze Nachricht an den Vorstand..."></textarea></div>
      <div style="background:#f5f7f2;border-radius:8px;padding:14px;margin-bottom:18px">
        <p style="font-size:0.8rem;font-weight:600;color:#3d6b41;margin-bottom:10px">Einwilligungen (DSGVO)</p>
        <div class="check-row">
          <input type="checkbox" id="regConsentContact">
          <div>
            <div>Ich bin damit einverstanden, dass der Vorstand mich per E-Mail oder Telefon kontaktieren darf.</div>
          </div>
        </div>
        <div class="check-row">
          <input type="checkbox" id="regConsentPhonelist">
          <div>
            <div>Mein Name, meine Parzelle, Telefonnummer und E-Mail-Adresse dürfen in der internen Mitglieder-Telefonliste erscheinen.</div>
            <div class="hint">Nur für andere eingeloggte Mitglieder sichtbar. Jederzeit widerrufbar.</div>
          </div>
        </div>
      </div>
      <button class="btn-primary" onclick="doRegister()">Antrag einreichen</button>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ══ DASHBOARD ═══════════════════════════════════════════════════════════════ -->
<div class="dashboard">

  <?php if ($mustChangePw): ?>
  <div style="background:linear-gradient(135deg,#3d6b41 0%,#2d5234 100%);border-radius:12px;padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 12px rgba(61,107,65,.25)">
    <div style="width:44px;height:44px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.4rem">🔑</div>
    <div>
      <div style="color:#fff;font-weight:700;font-size:1rem;margin-bottom:3px">Passwort ändern erforderlich</div>
      <div style="color:#b8d9ba;font-size:0.85rem;line-height:1.5">Du hast ein temporäres Passwort erhalten. Bitte wähle jetzt ein eigenes sicheres Passwort, um fortzufahren.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php
    $_vcBadge    = !empty(array_filter($myBookings, fn($b) => !empty($b['cancel_requested']) && ($b['status']??'') === 'confirmed'));
    $_arbDoneNav = isset($arbeitSoll, $myHours) && $myHours >= $arbeitSoll;
    $_arbPendNav = isset($arbeitSoll, $myHours) && $myHours < $arbeitSoll && $myHours > 0;
    $unreadReplies = count(array_filter($memberThreads, fn($t) =>
        ($t['status'] ?? '') === 'open' &&
        !empty($t['messages']) &&
        (end($t['messages'])['from'] ?? '') === 'admin'
    ));
    $inboxUnread = count(array_filter($inboxMemberThreads, fn($t) =>
        !empty($t['messages']) && (end($t['messages'])['from'] ?? '') === 'member'
    ));
    $unreadReplies += $inboxUnread;
  ?>
  <?php if (!$mustChangePw): ?>
  <nav class="tab-nav">
    <button class="tab-btn <?= $activeTab === 'pinnwand' ? 'active' : '' ?>" onclick="switchTab('pinnwand')">
      <span class="tab-icon-wrap">📌<?php if ($newPosts > 0): ?><span class="tab-dot"></span><?php endif; ?></span>
      <span class="tab-label">Pinnwand<?php if ($newPosts > 0): ?><span class="badge"><?= $newPosts ?></span><?php endif; ?></span>
    </button>
    <button class="tab-btn <?= $activeTab === 'termine' ? 'active' : '' ?>" onclick="switchTab('termine')">
      <span class="tab-icon-wrap">📅</span>
      <span class="tab-label">Termine</span>
    </button>
    <?php
    // Mitglieder-Events vorladen, damit Badge angezeigt werden kann
    if (file_exists(dirname(__FILE__) . '/inc/events.php')) {
        require_once dirname(__FILE__) . '/inc/events.php';
        $_memberEvents = array_values(array_filter(kgv_events_all(),
            fn($e) => !empty($e['show_in_member_area']) && kgv_event_is_active($e)));
    } else { $_memberEvents = []; }
    ?>
    <?php if (!empty($_memberEvents)): ?>
    <button class="tab-btn <?= $activeTab === 'veranstaltungen' ? 'active' : '' ?>" onclick="switchTab('veranstaltungen')">
      <span class="tab-icon-wrap">🎪</span>
      <span class="tab-label">Veranstaltungen<span class="badge"><?= count($_memberEvents) ?></span></span>
    </button>
    <?php endif; ?>
    <button class="tab-btn <?= $activeTab === 'buchungen' ? 'active' : '' ?>" onclick="switchTab('buchungen')">
      <span class="tab-icon-wrap">🏡<?php if ($_vcBadge): ?><span class="tab-dot"></span><?php endif; ?></span>
      <span class="tab-label">Vereinshaus<?php if ($_vcBadge): ?><span class="badge">!</span><?php endif; ?></span>
    </button>
    <button class="tab-btn <?= $activeTab === 'gemeinschaft' ? 'active' : '' ?>" onclick="switchTab('gemeinschaft')">
      <span class="tab-icon-wrap">🔨<?php if (!$_arbDoneNav && $_arbPendNav): ?><span class="tab-dot" style="background:#e65100"></span><?php endif; ?></span>
      <span class="tab-label">Arbeit<?php if (!$_arbDoneNav && $_arbPendNav): ?><span class="badge" style="background:#e65100">!</span><?php endif; ?></span>
    </button>
    <button class="tab-btn <?= $activeTab === 'downloads' ? 'active' : '' ?>" onclick="switchTab('downloads')">
      <span class="tab-icon-wrap">📥</span>
      <span class="tab-label">Downloads</span>
    </button>
    <button class="tab-btn <?= $activeTab === 'telefonliste' ? 'active' : '' ?>" onclick="switchTab('telefonliste')">
      <span class="tab-icon-wrap">📞</span>
      <span class="tab-label">Telefon</span>
    </button>
    <button class="tab-btn <?= $activeTab === 'kontakt' ? 'active' : '' ?>" onclick="switchTab('kontakt')">
      <span class="tab-icon-wrap">✉️<?php if ($unreadReplies > 0): ?><span class="tab-dot"></span><?php endif; ?></span>
      <span class="tab-label">Kontakt<?php if ($unreadReplies > 0): ?><span class="badge"><?= $unreadReplies ?></span><?php endif; ?></span>
    </button>
    <button class="tab-btn <?= $activeTab === 'antrag' ? 'active' : '' ?>" onclick="switchTab('antrag')">
      <span class="tab-icon-wrap">📩</span>
      <span class="tab-label">Antrag</span>
    </button>
    <button class="tab-btn <?= $activeTab === 'meine-antraege' ? 'active' : '' ?>" onclick="switchTab('meine-antraege')">
      <span class="tab-icon-wrap">📋</span>
      <span class="tab-label">Meine Anträge<?php if (count($myApplications) > 0): ?><span class="badge" style="background:#5a6c5a"><?= count($myApplications) ?></span><?php endif; ?></span>
    </button>
    <button class="tab-btn <?= $activeTab === 'schaden' ? 'active' : '' ?>" onclick="switchTab('schaden')">
      <span class="tab-icon-wrap">🔧</span>
      <span class="tab-label">Schaden</span>
    </button>
    <button class="tab-btn <?= $activeTab === 'profil' ? 'active' : '' ?>" onclick="switchTab('profil')">
      <span class="tab-icon-wrap">👤</span>
      <span class="tab-label">Profil</span>
    </button>
    <button class="tab-btn <?= $activeTab === 'passwort' ? 'active' : '' ?>" onclick="switchTab('passwort')">
      <span class="tab-icon-wrap">🔑</span>
      <span class="tab-label">Passwort</span>
    </button>
  </nav>
  <?php else: ?>
  <nav class="tab-nav">
    <button class="tab-btn active">🔑 Passwort ändern</button>
  </nav>
  <?php endif; ?>

  <!-- ── PINNWAND ── -->
  <div class="tab-panel <?= $activeTab === 'pinnwand' ? 'active' : '' ?>" id="tab-pinnwand">
    <div class="card">
      <h3>📌 Aktuelle Aushänge &amp; Nachrichten</h3>
      <?php
        $pinnwandPosts = array_filter($posts, fn($p) => ($p['type'] ?? 'info') !== 'gemeinschaftsarbeit');
      ?>
      <?php if (empty($pinnwandPosts)): ?>
        <p style="color:var(--text-muted);font-size:0.88rem">Noch keine Einträge vorhanden.</p>
      <?php else: ?>
        <?php foreach ($pinnwandPosts as $post):
            [$typeLabel, $typeColor, $typeBg] = postTypeLabel($post['type'] ?? 'info');
            $isPinned = !empty($post['pinned']);
            $dateFormatted = '';
            if (!empty($post['date'])) {
                $dt = DateTime::createFromFormat('Y-m-d', $post['date']);
                $dateFormatted = $dt ? $dt->format('d.m.Y') : $post['date'];
            }
        ?>
        <div class="post-item <?= $isPinned ? 'pinned' : '' ?>">
          <div class="post-header">
            <span class="post-badge" style="background:<?= $typeBg ?>;color:<?= $typeColor ?>"><?= $typeLabel ?></span>
            <?php if ($isPinned): ?><span class="pin-icon">📍</span><?php endif; ?>
            <span class="post-title"><?= he($post['title'] ?? '') ?></span>
            <?php if ($dateFormatted): ?><span class="post-date"><?= $dateFormatted ?></span><?php endif; ?>
          </div>
          <?php if (!empty($post['body'])): ?>
          <div class="post-body"><?= nl2br($post['body']) ?></div>
          <?php endif; ?>
          <?php if (!empty($post['file'])): ?>
          <a href="/mitglieder.php?download=<?= urlencode($post['file']) ?>" class="post-file">📎 Anhang herunterladen</a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── TERMINE ── -->
  <div class="tab-panel <?= $activeTab === 'termine' ? 'active' : '' ?>" id="tab-termine">
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <h3 style="margin:0">🌱 Gemeinschaftsarbeit 2026</h3>
        <a href="/mitglieder.php?download=Gemeinschaftsarbeit_2026.pdf" class="post-file" style="margin:0;font-size:0.82rem">📄 Jahresplan PDF</a>
      </div>
      <?php
        $gaTermine = array_values(array_filter($posts, fn($p) => ($p['type'] ?? '') === 'gemeinschaftsarbeit'));
        usort($gaTermine, fn($a,$b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
        if (empty($gaTermine)): ?>
        <p style="color:var(--text-muted);font-size:0.88rem">Keine Gemeinschaftsarbeit-Termine eingetragen.</p>
      <?php else:
        $months = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
        foreach ($gaTermine as $t):
            $dt = !empty($t['date']) ? DateTime::createFromFormat('Y-m-d', $t['date']) : null;
            $day = $dt ? $dt->format('d') : '–';
            $mon = $dt ? $months[(int)$dt->format('n') - 1] : '';
            $isPin = !empty($t['pinned']);
        ?>
        <div class="termin-item" style="<?= $isPin ? 'background:var(--green-pale,#f0f7ec);border-radius:8px;padding:8px 10px' : '' ?>">
          <div class="termin-date-box"><div class="day"><?= $day ?></div><div class="mon"><?= $mon ?></div></div>
          <div>
            <div class="termin-title"><?= he($t['title'] ?? '') ?><?= $isPin ? ' 🎉' : '' ?></div>
            <?php if (!empty($t['body'])): ?><div style="font-size:0.83rem;color:var(--text-muted);margin-top:4px;white-space:pre-line"><?= he($t['body']) ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php if (!empty($publicTermine)): ?>
    <div class="card">
      <h3>📅 Vereinstermine</h3>
      <?php foreach ($publicTermine as $t):
          $dt = !empty($t['date']) ? DateTime::createFromFormat('Y-m-d', $t['date']) : null;
          $day = $dt ? $dt->format('d') : '–';
          $months = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
          $mon = $dt ? $months[(int)$dt->format('n') - 1] : '';
      ?>
      <div class="termin-item">
        <div class="termin-date-box"><div class="day"><?= $day ?></div><div class="mon"><?= $mon ?></div></div>
        <div class="termin-title"><?= he($t['title'] ?? '') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── VERANSTALTUNGEN (intern für Mitglieder) ── -->
  <div class="tab-panel <?= $activeTab === 'veranstaltungen' ? 'active' : '' ?>" id="tab-veranstaltungen">
    <h2 style="color: var(--green, #3d6b41); margin-bottom: 18px;">🎪 Veranstaltungen</h2>
    <?php if (empty($_memberEvents)): ?>
      <div style="background:#f5f7f2;border-radius:10px;padding:24px;text-align:center;color:#5a6c5a">
        Aktuell sind keine internen Veranstaltungen ausgeschrieben. Sobald der Vorstand eine neue Veranstaltung anlegt, erscheint sie hier.
      </div>
    <?php else: ?>
      <p style="color:#5a6c5a;margin-bottom:20px;line-height:1.6">Hier siehst du alle aktuellen Veranstaltungen, zu denen wir uns freuen wenn du dabei bist. Klick auf eine Karte, um direkt zur Anmeldung zu kommen.</p>
      <div class="mv-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px">
        <?php foreach ($_memberEvents as $ev):
            $_evUrl = '/event/' . rawurlencode($ev['slug'] ?? '');
            $_evDate = '';
            if (!empty($ev['event_date'])) {
                $_dt = DateTimeImmutable::createFromFormat('Y-m-d', $ev['event_date']);
                $_evDate = $_dt ? $_dt->format('d.m.Y') : (string)$ev['event_date'];
            }
            $_hasCustomFlyer = !empty($ev['use_custom_flyer']) && !empty($ev['custom_flyer_file']);
            $_flyerExt = $_hasCustomFlyer ? strtolower(pathinfo($ev['custom_flyer_file'], PATHINFO_EXTENSION)) : '';
            $_flyerUrl = $_hasCustomFlyer ? '/images/event_flyers/' . rawurlencode($ev['custom_flyer_file']) : '';
            $_isImageFlyer = in_array($_flyerExt, ['jpg','jpeg','png'], true);
        ?>
        <div class="mv-card" style="background:#fff;border:1px solid #d4e6c3;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.06);display:flex;flex-direction:column">
          <?php if ($_isImageFlyer): ?>
          <!-- Bild-Flyer (PNG/JPG) als Hero anzeigen -->
          <a href="<?= $_flyerUrl ?>" target="_blank" style="display:block;background:#e8f0e0">
            <img src="<?= $_flyerUrl ?>" alt="<?= htmlspecialchars($ev['title'] ?? '') ?>" style="width:100%;height:200px;object-fit:cover;display:block">
          </a>
          <?php elseif ($_hasCustomFlyer && $_flyerExt === 'pdf'): ?>
          <!-- PDF-Flyer: Vorschau-Banner mit Download-Hinweis -->
          <a href="<?= $_flyerUrl ?>" target="_blank" style="display:block;background:linear-gradient(135deg,#e8f5e9 0%,#c8e6c9 100%);padding:36px 24px;text-align:center;text-decoration:none;color:#2e7d32">
            <div style="font-size:2.4rem;margin-bottom:4px">📄</div>
            <div style="font-weight:700;font-size:0.92rem">Flyer als PDF</div>
            <div style="font-size:0.78rem;color:#5a6c5a;margin-top:4px">Klick zum Öffnen</div>
          </a>
          <?php else: ?>
          <!-- Kein Flyer hochgeladen — generischer Header -->
          <div style="background:linear-gradient(135deg,#3d6b41 0%,#5a8c5e 100%);color:#fff;padding:28px 20px">
            <div style="font-size:0.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:0.85;margin-bottom:6px">🎪 Veranstaltung</div>
            <div style="font-size:1.15rem;font-weight:700;line-height:1.3"><?= htmlspecialchars($ev['title'] ?? '') ?></div>
          </div>
          <?php endif; ?>

          <div style="padding:16px 18px 18px;flex:1;display:flex;flex-direction:column">
            <h3 style="margin:0 0 4px;color:#2d3e2d;font-size:1.05rem"><?= htmlspecialchars($ev['title'] ?? '') ?></h3>
            <?php if (!empty($ev['subtitle'])): ?>
            <div style="color:#8a9a8a;font-size:0.86rem;margin-bottom:10px"><?= htmlspecialchars($ev['subtitle']) ?></div>
            <?php endif; ?>
            <?php if ($_evDate): ?>
            <div style="font-size:0.84rem;color:#3d6b41;margin-bottom:10px"><strong>📅 <?= htmlspecialchars($_evDate) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ev['description'])): ?>
            <p style="margin:0 0 14px;color:#5a6c5a;font-size:0.88rem;line-height:1.55;white-space:pre-wrap"><?= htmlspecialchars(mb_strimwidth($ev['description'], 0, 160, '…', 'UTF-8')) ?></p>
            <?php endif; ?>

            <div style="margin-top:auto;display:flex;gap:8px;flex-wrap:wrap">
              <a href="<?= $_evUrl ?>" target="_blank" style="background:#3d6b41;color:#fff;padding:8px 16px;border-radius:7px;text-decoration:none;font-weight:600;font-size:0.86rem">→ Zur Anmeldung</a>
              <?php if ($_hasCustomFlyer): ?>
              <a href="<?= $_flyerUrl ?>" target="_blank" style="background:#f0f4ee;color:#3d6b41;padding:8px 14px;border-radius:7px;text-decoration:none;font-weight:600;font-size:0.82rem">📄 Flyer ansehen</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── BUCHUNGEN ── -->
  <div class="tab-panel <?= $activeTab === 'buchungen' ? 'active' : '' ?>" id="tab-buchungen">

    <?php if (isset($_GET['cancelled'])): ?>
    <div class="success-msg" style="display:block;margin-bottom:16px">✅ Stornierungsanfrage gesendet. Der Vorstand wird sich melden.</div>
    <?php endif; ?>

    <!-- Meine Vereinshaus-Buchungen -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">📅 Meine Vereinshaus-Buchungen</h3>
        <button class="btn-secondary" onclick="document.getElementById('newBookingForm').style.display=document.getElementById('newBookingForm').style.display==='none'?'block':'none';this.textContent=document.getElementById('newBookingForm').style.display==='block'?'✕ Abbrechen':'➕ Neue Buchung'" style="font-size:0.82rem;padding:7px 14px">➕ Neue Buchung</button>
      </div>

      <!-- Neue Buchung (inline) -->
      <div id="newBookingForm" style="display:none;background:var(--green-pale);border-radius:10px;padding:18px;margin-bottom:18px;border:1px solid var(--green-border)">
        <div style="font-size:0.88rem;font-weight:600;color:var(--green);margin-bottom:14px">🏡 Vereinshaus anfragen</div>
        <div id="nbError" class="error-msg"></div>
        <div id="nbSuccess" class="success-msg"></div>
        <div class="field" style="margin-bottom:12px">
          <label>Datum wählen *</label>
          <div id="nbCal" style="background:#fff;border:1px solid var(--green-border);border-radius:10px;padding:12px;margin-top:6px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
              <button type="button" onclick="nbPrevMonth()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--green);padding:2px 8px">&#8249;</button>
              <span id="nbMonthLabel" style="font-weight:700;font-size:0.88rem;color:var(--green)"></span>
              <button type="button" onclick="nbNextMonth()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--green);padding:2px 8px">&#8250;</button>
            </div>
            <div id="nbCalGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center"></div>
            <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;font-size:0.72rem;color:var(--text-muted)">
              <span><span style="display:inline-block;width:10px;height:10px;background:#e0e0e0;border-radius:2px;margin-right:3px"></span>Gesperrt</span>
              <span><span style="display:inline-block;width:10px;height:10px;background:#ef9a9a;border-radius:2px;margin-right:3px"></span>Vergeben</span>
              <span><span style="display:inline-block;width:10px;height:10px;background:#fff9c4;border-radius:2px;margin-right:3px;border:1px solid #f9a825"></span>Angefragt</span>
              <span><span style="display:inline-block;width:10px;height:10px;background:#3d6b41;border-radius:2px;margin-right:3px"></span>Gewählt</span>
            </div>
            <div id="nbSelLabel" style="margin-top:8px;font-size:0.82rem;font-weight:600;color:var(--green);min-height:18px"></div>
          </div>
          <input type="hidden" id="nb_date">
        </div>
        <div class="field">
          <label>Personenanzahl *</label>
          <input type="number" id="nb_guests" min="1" max="50" placeholder="z.B. 20">
        </div>
        <div class="field">
          <label>Anlass *</label>
          <input type="text" id="nb_purpose" placeholder="z.B. Geburtstagsfeier, Vereinstreffen ...">
        </div>
        <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:12px">
          Name &amp; E-Mail werden automatisch aus Ihrem Profil übernommen: <strong><?= he($member['name']) ?></strong> · <?= he($member['email']) ?>
        </div>
        <button class="btn-primary" onclick="doMemberBooking()" style="max-width:200px">Anfrage senden</button>
      </div>

      <?php if (empty($myBookings)): ?>
      <p style="font-size:0.88rem;color:var(--text-muted)">Noch keine Buchungen vorhanden.</p>
      <?php else:
        $bStatusLabel = ['pending' => '⏳ Ausstehend', 'confirmed' => '✅ Bestätigt', 'rejected' => '❌ Abgelehnt'];
        $bStatusColor = ['pending' => '#e65100', 'confirmed' => '#2e7d32', 'rejected' => '#9e9e9e'];
        $bStatusBg    = ['pending' => '#fff3e0', 'confirmed' => '#e8f5e9', 'rejected' => '#f5f5f5'];
        foreach ($myBookings as $bk):
          $bkStatus  = $bk['status'] ?? 'pending';
          $bkDates   = $bk['dates'] ?? (isset($bk['date']) ? [$bk['date']] : []);
          $bkDateFmt = count($bkDates) === 1
            ? (($dtmp = DateTime::createFromFormat('Y-m-d', $bkDates[0])) ? $dtmp->format('d.m.Y') : $bkDates[0])
            : (($dtmp = DateTime::createFromFormat('Y-m-d', $bkDates[0])) ? $dtmp->format('d.m.Y') : $bkDates[0])
              . ' – ' . (($dtmp2 = DateTime::createFromFormat('Y-m-d', end($bkDates))) ? $dtmp2->format('d.m.Y') : end($bkDates));
          $bkCancelReq = !empty($bk['cancel_requested']);
          $bkCanCancel = in_array($bkStatus, ['pending','confirmed'], true) && !$bkCancelReq;
      ?>
      <div style="border:1px solid var(--green-border);border-radius:10px;padding:14px 16px;margin-bottom:10px;<?= $bkStatus === 'rejected' ? 'opacity:.65' : '' ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <div style="font-weight:600;font-size:0.92rem;margin-bottom:4px">📅 <?= he($bkDateFmt) ?></div>
            <div style="font-size:0.82rem;color:var(--text-muted)">
              <?= !empty($bk['purpose']) ? he($bk['purpose']) : '' ?>
              <?= !empty($bk['guests']) ? ' · ' . (int)$bk['guests'] . ' Personen' : '' ?>
            </div>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:3px">Anfrage vom <?= he(substr($bk['created_at'] ?? '', 0, 10)) ?></div>
            <?php if ($bkCancelReq): ?>
            <div style="font-size:0.76rem;color:#c62828;margin-top:4px;font-weight:600">🔄 Stornierung beantragt</div>
            <?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap">
            <span style="font-size:0.75rem;padding:3px 10px;border-radius:20px;font-weight:700;background:<?= $bStatusBg[$bkStatus] ?? '#f5f5f5' ?>;color:<?= $bStatusColor[$bkStatus] ?? '#666' ?>">
              <?= $bStatusLabel[$bkStatus] ?? $bkStatus ?>
            </span>
            <?php if ($bkCanCancel): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Stornierungsanfrage für den <?= he($bkDateFmt) ?> stellen?')">
              <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
              <input type="hidden" name="action" value="cancel_request">
              <input type="hidden" name="booking_id" value="<?= he($bk['id']) ?>">
              <button type="submit" style="background:#fce8e6;color:#c62828;border:1px solid #f5c6c2;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:500;cursor:pointer;font-family:inherit">Stornieren</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ── Belegungskalender ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">📅 Belegungskalender Vereinshaus</h3>
      </div>
      <div style="background:#fff3e0;border:1px solid #ffcc80;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:0.83rem;color:#e65100;line-height:1.5">
        ⚠️ <strong>Abkippstation:</strong> An <strong>rot</strong> markierten Tagen bitte die Abkippstation nicht nutzen — Geruchsbelästigung für Vereinshausgäste.
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <button type="button" id="vcPrevBtn" style="background:none;border:none;cursor:pointer;font-size:1.4rem;color:var(--green);padding:4px 10px;line-height:1">&#8249;</button>
        <span id="vcMonthLabel" style="font-weight:700;font-size:0.95rem;color:var(--green)"></span>
        <button type="button" id="vcNextBtn" style="background:none;border:none;cursor:pointer;font-size:1.4rem;color:var(--green);padding:4px 10px;line-height:1">&#8250;</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center;margin-bottom:4px">
        <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $_wd): ?>
        <div style="font-weight:700;color:#8a9a8a;font-size:0.72rem;padding:3px 0"><?= $_wd ?></div>
        <?php endforeach; ?>
      </div>
      <div id="vcCalGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center"></div>
      <div style="display:flex;gap:12px;margin-top:14px;flex-wrap:wrap;font-size:0.75rem;color:var(--text-muted)">
        <span><span style="display:inline-block;width:12px;height:12px;background:#ef9a9a;border-radius:3px;margin-right:4px;vertical-align:middle"></span>Vermietet – Abkippstation gesperrt</span>
        <span><span style="display:inline-block;width:12px;height:12px;background:#fff9c4;border:1px solid #f9a825;border-radius:3px;margin-right:4px;vertical-align:middle"></span>Anfrage läuft</span>
        <span><span style="display:inline-block;width:12px;height:12px;background:#e0e0e0;border-radius:3px;margin-right:4px;vertical-align:middle"></span>Gesperrt</span>
        <span><span style="display:inline-block;width:12px;height:12px;background:#f0f7f0;border:1px solid #c8e6c9;border-radius:3px;margin-right:4px;vertical-align:middle"></span>Frei</span>
      </div>
    </div>

  </div>

  <!-- ── GEMEINSCHAFTSARBEIT ── -->
  <div class="tab-panel <?= $activeTab === 'gemeinschaft' ? 'active' : '' ?>" id="tab-gemeinschaft">
    <div class="card">
      <?php
        $arbPct  = $arbeitSoll > 0 ? min(100, (int)(($myHours / $arbeitSoll) * 100)) : 0;
        $arbDone = $myHours >= $arbeitSoll;
        $arbColor = $arbDone ? '#2e7d32' : ($myHours > 0 ? '#e65100' : '#9e9e9e');
        $arbBg    = $arbDone ? '#e8f5e9' : ($myHours > 0 ? '#fff3e0' : '#f5f5f5');
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🔨 Gemeinschaftsarbeit <?= $arbeitYear ?></h3>
        <span style="font-size:0.78rem;padding:3px 10px;border-radius:20px;font-weight:700;background:<?= $arbBg ?>;color:<?= $arbColor ?>">
          <?= $arbDone ? '✅ Erfüllt' : ($myHours > 0 ? '⏳ In Arbeit' : '○ Noch offen') ?>
        </span>
      </div>
      <!-- Fortschrittsbalken -->
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;font-size:0.82rem;color:var(--text-muted);margin-bottom:5px">
          <span><?= number_format($myHours, 1, ',', '.') ?> von <?= $arbeitSoll ?> Stunden geleistet</span>
          <span style="font-weight:700;color:<?= $arbColor ?>"><?= $arbPct ?>%</span>
        </div>
        <div style="height:10px;border-radius:10px;background:#e8f0e0;overflow:hidden">
          <div style="height:100%;border-radius:10px;width:<?= $arbPct ?>%;background:<?= $arbColor ?>;transition:width .5s"></div>
        </div>
      </div>
      <!-- Einzel-Einträge -->
      <?php if (!empty($myArbeitEntries)): ?>
      <div style="margin-top:14px;border-top:1px solid var(--green-border);padding-top:12px">
        <div style="font-size:0.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Meine Einträge</div>
        <?php foreach ($myArbeitEntries as $ae):
            $aeDate = $ae['date'] ?? '';
            $aeDt   = $aeDate ? DateTime::createFromFormat('Y-m-d', $aeDate) : null;
            $aeDateFmt = $aeDt ? $aeDt->format('d.m.Y') : $aeDate;
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f0f5ec;font-size:0.86rem;gap:8px;flex-wrap:wrap">
          <span style="color:var(--text-muted)"><?= he($aeDateFmt) ?></span>
          <span style="font-weight:600;color:var(--green)"><?= number_format((float)($ae['hours'] ?? 0), 1, ',', '.') ?>h</span>
          <?php if (!empty($ae['note'])): ?><span style="flex:1;color:var(--text-muted);font-size:0.8rem;text-align:right">📝 <?= he($ae['note']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px">Noch keine Stunden für <?= $arbeitYear ?> eingetragen.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── DOWNLOADS ── -->
  <div class="tab-panel <?= $activeTab === 'downloads' ? 'active' : '' ?>" id="tab-downloads">
    <div class="card">
      <h3>📥 Dokumente &amp; Downloads</h3>
      <?php if (empty($memberFiles)): ?>
        <p style="color:var(--text-muted);font-size:0.88rem">Noch keine Dokumente vorhanden.</p>
      <?php else:
        $icons = ['pdf' => '📄', 'doc' => '📝', 'docx' => '📝', 'xls' => '📊', 'xlsx' => '📊', 'jpg' => '🖼️', 'png' => '🖼️', 'zip' => '🗜️'];
        foreach ($memberFiles as $f):
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $icon = $icons[$ext] ?? '📎';
            $date = date('d.m.Y', $f['mtime']);
      ?>
      <div class="file-item">
        <div class="file-icon"><?= $icon ?></div>
        <div class="file-info">
          <div class="file-name"><?= he(str_replace('_', ' ', pathinfo($f['name'], PATHINFO_FILENAME))) ?></div>
          <div class="file-meta"><?= he(strtoupper($ext)) ?> · <?= formatBytes($f['size']) ?> · <?= $date ?></div>
        </div>
        <a href="/mitglieder.php?download=<?= urlencode($f['name']) ?>" class="btn-download">⬇ Download</a>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── TELEFONLISTE ── -->
  <div class="tab-panel <?= $activeTab === 'telefonliste' ? 'active' : '' ?>" id="tab-telefonliste">
    <div class="phonelist-wrap" id="phonelistWrap">
      <div class="phonelist-watermark" id="phoneWatermark">
        <span id="watermarkText"></span>
      </div>
      <div class="card" style="overflow-x:auto">
        <h3>📞 Mitglieder-Telefonliste</h3>
        <?php if (empty($phonelist)): ?>
          <p style="color:var(--text-muted);font-size:0.88rem">Noch keine Einträge mit erteilter Zustimmung vorhanden.</p>
        <?php else: ?>
        <table class="phonelist-table" id="phonelistTable">
          <thead>
            <tr>
              <th>Parzelle</th>
              <th>Name</th>
              <th>Telefonnummer</th>
              <th>E-Mail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($phonelist as $m):
              $mTyp = $m['rolle_typ'] ?? 'paechter';
              $mBadge = match($mTyp) {
                'paechterpartner' => ' <span style="font-size:0.7rem;color:#1565c0;background:#e3f2fd;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600">Partner</span>',
                'foerdermitglied' => ' <span style="font-size:0.7rem;color:#e65100;background:#fff3e0;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600">Fördermitglied</span>',
                default           => '',
              };
            ?>
            <tr>
              <td><?= $mTyp === 'foerdermitglied' ? '–' : he((string)($m['parzelle'] ?? '–')) ?></td>
              <td><?= he($m['name'] ?? '–') ?><?= $mBadge ?></td>
              <td><?= he($m['phone'] ?? '–') ?></td>
              <td><?= he($m['email'] ?? '–') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <p class="phonelist-notice">⚠️ Diese Liste enthält personenbezogene Daten. Sie ist ausschließlich zur internen Nutzung bestimmt und darf nicht weitergegeben, kopiert oder gespeichert werden. Zuwiderhandlungen können vereinsrechtliche Konsequenzen haben.</p>
      </div>
    </div>
  </div>

  <!-- ── KONTAKT ── -->
  <div class="tab-panel <?= $activeTab === 'kontakt' ? 'active' : '' ?>" id="tab-kontakt">

    <!-- Neue Nachricht -->
    <div class="card" id="newMsgCard">
      <h3>✉️ Neue Nachricht senden</h3>
      <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:18px">Absender: <strong><?= he($member['name']) ?>, Parzelle <?= he($member['parzelle']) ?></strong></p>
      <div id="contactError" class="error-msg"></div>
      <div id="contactSuccess" class="success-msg"></div>
      <div class="contact-form">
        <div class="field">
          <label>An *</label>
          <select id="contactRecipient" onchange="updateRecipientChips(this.value)">
            <optgroup label="Gremien">
              <option value="vorstand">👑 Vorstand</option>
              <option value="kassier">💶 Kassier/in</option>
              <option value="koppel">🔨 Wegewart/in</option>
              <option value="web">🔧 Technische Probleme (Website)</option>
            </optgroup>
            <?php if (!empty($contactableMembers)): ?>
            <optgroup label="Mitglieder">
              <?php foreach ($contactableMembers as $_cm): ?>
              <option value="member_<?= he($_cm['id']) ?>">👤 <?= he($_cm['name'] ?? '') ?>, Parzelle <?= he((string)($_cm['parzelle'] ?? '')) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
          <!-- Recipient preview / deselect chips -->
          <div id="recipientPreview" style="margin-top:8px;display:none">
            <div style="font-size:0.77rem;color:#5a6c5a;margin-bottom:6px">Nachricht wird gesendet an — klicke × zum Abwählen:</div>
            <div id="recipientChips" style="display:flex;flex-wrap:wrap;gap:6px"></div>
            <div id="recipientWarn" style="display:none;font-size:0.77rem;color:#e65100;margin-top:6px">⚠️ Bitte mindestens einen Empfänger auswählen.</div>
          </div>
        </div>
        <div class="field"><label>Betreff *</label><input type="text" id="contactSubject" placeholder="Worum geht es?"></div>
        <div class="field"><label>Nachricht *</label><textarea id="contactMessage" placeholder="Ihre Nachricht..."></textarea></div>
        <div class="field">
          <label>Anhang <span style="font-weight:400;color:var(--text-muted)">(optional · Bilder, PDF · max. 5 MB)</span></label>
          <input type="file" id="contactFile" accept="image/*,.pdf,.doc,.docx" style="font-size:0.85rem">
          <div id="contactFilePreview" style="margin-top:8px"></div>
        </div>
        <button class="btn-primary" onclick="doContact()" style="max-width:200px">Nachricht senden</button>
      </div>
    </div>

    <!-- Thread-Liste -->
    <?php if (!empty($memberThreads)): ?>
    <div style="margin-top:24px">
      <h3 style="font-family:'Playfair Display',serif;color:var(--green);font-size:1rem;margin-bottom:14px">💬 Meine Nachrichten (<?= count($memberThreads) ?>)</h3>
      <?php foreach ($memberThreads as $thread):
          $isOpen     = ($thread['status'] ?? '') === 'open';
          $lastMsg    = end($thread['messages']);
          $lastFrom   = $lastMsg['from'] ?? 'member';
          $threadId   = $thread['id'] ?? '';
          $hasReply   = count(array_filter($thread['messages'], fn($m) => $m['from'] === 'admin')) > 0;
      ?>
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:12px">
        <!-- Thread header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:var(--green-pale);cursor:pointer;gap:12px"
             onclick="toggleThread('thread-<?= he($threadId) ?>')">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <strong style="font-size:0.92rem"><?= he($thread['subject'] ?? '') ?></strong>
              <?php if ($hasReply && $lastFrom === 'admin'): ?>
                <span style="font-size:0.72rem;padding:2px 8px;border-radius:10px;background:#e8f5e9;color:#2e7d32;font-weight:700">✅ Antwort erhalten</span>
              <?php elseif ($isOpen): ?>
                <span style="font-size:0.72rem;padding:2px 8px;border-radius:10px;background:#fff3e0;color:#e65100;font-weight:700">⏳ Offen</span>
              <?php endif; ?>
            </div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:3px"><?= he(substr($thread['updated_at'] ?? '',0,16)) ?> · <?= count($thread['messages']) ?> Nachricht(en)</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0" onclick="event.stopPropagation()">
            <button onclick="doDeleteThread('<?= he($threadId) ?>')" title="Löschen"
                    style="background:none;border:none;cursor:pointer;font-size:1rem;opacity:0.5;padding:4px;line-height:1">🗑</button>
            <span style="color:var(--text-muted);font-size:0.9rem">▾</span>
          </div>
        </div>
        <!-- Thread messages -->
        <div id="thread-<?= he($threadId) ?>" style="display:none;padding:16px 18px">
          <?php foreach ($thread['messages'] as $tmsg):
              $isAdmin = ($tmsg['from'] ?? '') === 'admin';
          ?>
          <div style="display:flex;gap:10px;margin-bottom:14px;justify-content:<?= $isAdmin ? 'flex-start' : 'flex-end' ?>">
            <div style="max-width:80%;background:<?= $isAdmin ? '#f0f5ec' : 'var(--green)' ?>;color:<?= $isAdmin ? 'var(--text)' : '#fff' ?>;border-radius:<?= $isAdmin ? '4px 12px 12px 12px' : '12px 4px 12px 12px' ?>;padding:10px 14px">
              <div style="font-size:0.7rem;font-weight:600;opacity:0.7;margin-bottom:5px"><?= $isAdmin ? '🌿 '.he($tmsg['admin_name'] ?? 'Vorstand') : '👤 '.he($member['name']) ?> · <?= he(substr($tmsg['created_at'] ?? '',0,16)) ?></div>
              <div style="font-size:0.88rem;white-space:pre-wrap;line-height:1.5"><?= he($tmsg['body'] ?? '') ?></div>
              <?php if (!empty($tmsg['file'])): $tf = $tmsg['file']; $tfUrl = '/member-api/msg_file.php?f='.urlencode($tf['stored'] ?? ''); $tfImg = str_starts_with($tf['mime'] ?? '', 'image/'); ?>
              <div style="margin-top:8px">
                <?php if ($tfImg): ?>
                  <a href="<?= $tfUrl ?>" target="_blank" rel="noopener noreferrer"><img src="<?= $tfUrl ?>" style="max-width:180px;max-height:160px;border-radius:8px;display:block;object-fit:cover;cursor:pointer" alt="<?= he($tf['orig'] ?? '') ?>"></a>
                <?php else: ?>
                  <a href="<?= $tfUrl ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:5px;font-size:0.8rem;padding:5px 10px;background:rgba(0,0,0,0.08);border-radius:6px;text-decoration:none;color:inherit">📎 <?= he($tf['orig'] ?? '') ?></a>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <!-- Reply input -->
          <div style="border-top:1px solid var(--green-border);padding-top:12px;margin-top:4px">
            <div class="field" style="margin-bottom:8px">
              <textarea id="reply-<?= he($threadId) ?>" placeholder="Antwort schreiben..." style="min-height:70px"></textarea>
            </div>
            <div class="field" style="margin-bottom:10px">
              <input type="file" id="reply-file-<?= he($threadId) ?>" accept="image/*,.pdf,.doc,.docx" style="font-size:0.82rem">
              <div id="reply-file-preview-<?= he($threadId) ?>" style="margin-top:6px"></div>
            </div>
            <div id="reply-err-<?= he($threadId) ?>" class="error-msg"></div>
            <div id="reply-ok-<?= he($threadId) ?>" class="success-msg"></div>
            <button class="btn-secondary" onclick="doReply('<?= he($threadId) ?>')">Antwort senden ↩</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['replied'])): ?>
    <div class="success-msg" style="display:block;margin-bottom:16px">✅ Ihre Antwort wurde gesendet.</div>
    <?php endif; ?>

    <!-- ── Posteingang: Nachrichten von anderen Mitgliedern ── -->
    <?php if (!empty($inboxMemberThreads)): ?>
    <div style="margin-top:28px">
      <h3 style="font-family:'Playfair Display',serif;color:var(--green);font-size:1rem;margin-bottom:14px">📬 Eingegangen von Mitgliedern (<?= count($inboxMemberThreads) ?>)</h3>
      <?php foreach ($inboxMemberThreads as $iThread):
          $itId     = $iThread['id'] ?? '';
          $itName   = $iThread['member_name'] ?? '?';
          $itParz   = (string)($iThread['member_parzelle'] ?? '');
          $itMsgs   = $iThread['messages'] ?? [];
          $lastIM   = !empty($itMsgs) ? end($itMsgs) : [];
          $hasNew   = ($lastIM['from'] ?? '') === 'member';
      ?>
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:12px;border:2px solid <?= $hasNew ? '#f9a825' : '#d4e6c3' ?>">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:<?= $hasNew ? '#fffdf0' : 'var(--green-pale)' ?>;cursor:pointer;gap:12px"
             onclick="toggleThread('ithread-<?= he($itId) ?>')">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <strong style="font-size:0.92rem"><?= he($iThread['subject'] ?? '') ?></strong>
              <?php if ($hasNew): ?>
                <span style="font-size:0.72rem;padding:2px 8px;border-radius:10px;background:#fff3e0;color:#e65100;font-weight:700">🔔 Neue Nachricht</span>
              <?php endif; ?>
            </div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:3px">Von: <?= he($itName) ?>, Parzelle <?= he($itParz) ?> · <?= he(substr($iThread['updated_at'] ?? '',0,16)) ?></div>
          </div>
          <span style="color:var(--text-muted);font-size:0.9rem">▾</span>
        </div>
        <div id="ithread-<?= he($itId) ?>" style="display:none;padding:16px 18px">
          <?php foreach ($itMsgs as $im):
              $fromOther = ($im['from'] ?? '') === 'member';  // original sender = other member
              $fromMe    = ($im['from'] ?? '') === 'peer';    // I replied
              $imName    = $fromOther ? $itName : ($member['name'] ?? 'Ich');
          ?>
          <div style="display:flex;gap:10px;margin-bottom:14px;justify-content:<?= $fromOther ? 'flex-start' : 'flex-end' ?>">
            <div style="max-width:80%;background:<?= $fromOther ? '#f0f5ec' : 'var(--green)' ?>;color:<?= $fromOther ? 'var(--text)' : '#fff' ?>;border-radius:<?= $fromOther ? '4px 12px 12px 12px' : '12px 4px 12px 12px' ?>;padding:10px 14px">
              <div style="font-size:0.7rem;font-weight:600;opacity:0.7;margin-bottom:5px">👤 <?= he($imName) ?> · <?= he(substr($im['created_at'] ?? '',0,16)) ?></div>
              <div style="font-size:0.88rem;white-space:pre-wrap;line-height:1.5"><?= he($im['body'] ?? '') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <!-- Reply -->
          <div style="border-top:1px solid var(--green-border);padding-top:12px;margin-top:4px">
            <div class="field" style="margin-bottom:8px">
              <textarea id="ireply-<?= he($itId) ?>" placeholder="Antwort schreiben..." style="min-height:70px"></textarea>
            </div>
            <div id="ireply-err-<?= he($itId) ?>" class="error-msg"></div>
            <div id="ireply-ok-<?= he($itId) ?>" class="success-msg"></div>
            <button class="btn-secondary" onclick="doPeerReply('<?= he($itId) ?>')">Antworten ↩</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Rollen-Posteingang (koppel / kassier) ── -->
    <?php if (!empty($myRoleInbox)): ?>
    <div style="margin-top:28px">
      <?php $myRoleLabel = $isKoppel ? '🔨 Wegewart/in' : '💶 Kassier/in'; ?>
      <h3 style="font-family:'Playfair Display',serif;color:var(--green);font-size:1rem;margin-bottom:14px">📥 Eingehende Anfragen (<?= $myRoleLabel ?>) — <?= count($myRoleInbox) ?></h3>
      <?php foreach ($myRoleInbox as $rt):
          $rtId       = $rt['id'] ?? '';
          $rtAlready  = !empty($rt['first_reply_by_id']);
          $rtByMe     = ($rt['first_reply_by_id'] ?? '') === ($member['id'] ?? '');
          $rtByName   = $rt['first_reply_by_name'] ?? '';
      ?>
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:12px;border:2px solid <?= $rtAlready ? '#d4e6c3' : '#f9a825' ?>">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:<?= $rtAlready ? '#f5faf2' : '#fffdf0' ?>;cursor:pointer;gap:12px"
             onclick="toggleThread('ri-<?= he($rtId) ?>')">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <strong style="font-size:0.92rem"><?= he($rt['subject'] ?? '') ?></strong>
              <?php if ($rtAlready): ?>
                <span style="font-size:0.72rem;padding:2px 8px;border-radius:10px;background:#e8f5e9;color:#2e7d32;font-weight:700">✅ Beantwortet von <?= he($rtByName) ?></span>
              <?php else: ?>
                <span style="font-size:0.72rem;padding:2px 8px;border-radius:10px;background:#fff3e0;color:#e65100;font-weight:700">⏳ Offen</span>
              <?php endif; ?>
            </div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:3px">
              👤 <strong><?= he($rt['member_name'] ?? '') ?></strong> · Parzelle <?= he($rt['member_parzelle'] ?? '') ?> · <?= he(substr($rt['updated_at'] ?? '', 0, 16)) ?>
            </div>
          </div>
          <span style="color:var(--text-muted);font-size:0.9rem;flex-shrink:0">▾</span>
        </div>
        <!-- Body -->
        <div id="ri-<?= he($rtId) ?>" style="display:none;padding:16px 18px">
          <?php foreach ($rt['messages'] as $rtMsg):
              $rtIsAdmin = ($rtMsg['from'] ?? '') === 'admin';
          ?>
          <div style="display:flex;gap:10px;margin-bottom:14px;justify-content:<?= $rtIsAdmin ? 'flex-start' : 'flex-end' ?>">
            <div style="max-width:80%;background:<?= $rtIsAdmin ? '#f0f5ec' : 'var(--green)' ?>;color:<?= $rtIsAdmin ? 'var(--text)' : '#fff' ?>;border-radius:<?= $rtIsAdmin ? '4px 12px 12px 12px' : '12px 4px 12px 12px' ?>;padding:10px 14px">
              <div style="font-size:0.7rem;font-weight:600;opacity:0.7;margin-bottom:5px"><?= $rtIsAdmin ? '🌿 '.he($rtMsg['admin_name'] ?? 'Team') : '👤 '.he($rt['member_name'] ?? '') ?> · <?= he(substr($rtMsg['created_at'] ?? '', 0, 16)) ?></div>
              <div style="font-size:0.88rem;white-space:pre-wrap;line-height:1.5"><?= he($rtMsg['body'] ?? '') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <!-- Reply form -->
          <?php if (!$rtAlready): ?>
          <div style="border-top:1px solid var(--green-border);padding-top:12px;margin-top:4px">
            <form method="POST">
              <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
              <input type="hidden" name="role_action" value="role_reply">
              <input type="hidden" name="r_thread_id" value="<?= he($rtId) ?>">
              <div class="field" style="margin-bottom:8px">
                <textarea name="r_body" placeholder="Antwort an <?= he($rt['member_name'] ?? '') ?>..." style="min-height:70px;width:100%;padding:10px;border:1.5px solid var(--green-border);border-radius:8px;font-family:inherit;font-size:0.88rem;resize:vertical"></textarea>
              </div>
              <button type="submit" class="btn-primary" style="max-width:200px">Antwort senden ↩</button>
            </form>
          </div>
          <?php else: ?>
          <div style="padding-top:10px;margin-top:4px;border-top:1px solid var(--green-border);font-size:0.82rem;color:var(--text-muted)">
            ✅ <?= $rtByMe ? 'Du hast bereits geantwortet.' : he($rtByName) . ' hat bereits geantwortet.' ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── ANTRAG STELLEN ── -->
  <div class="tab-panel <?= $activeTab === 'antrag' ? 'active' : '' ?>" id="tab-antrag">
    <div style="background:linear-gradient(135deg,#01579b,#002f6c);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h2 style="margin:0 0 8px;font-size:1.3rem">📩 Antrag an den Vorstand</h2>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Hier kannst du formal einen Antrag stellen: einen Themenwunsch für die nächste Sitzung, eine Beschwerde,
        eine Anregung oder einen offiziellen Antrag. Anträge gehen an die Schriftführung und werden in der
        nächsten Vorstandssitzung behandelt.
      </p>
    </div>

    <?php if (isset($_GET['err']) && $_GET['err'] === 'empty'): ?>
      <div style="background:#ffebee;border-left:4px solid #c62828;border-radius:8px;padding:14px 18px;margin-bottom:18px;color:#b71c1c">
        ⚠ Bitte Art, Titel und Antragstext ausfüllen.
      </div>
    <?php endif; ?>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
      <form method="POST" action="/mitglieder.php?tab=antrag" enctype="multipart/form-data">
        <input type="hidden" name="member_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="submit_application">
        <input type="text" name="__website" value="" autocomplete="off" style="position:absolute;left:-9999px" tabindex="-1">

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Art des Antrags *</label>
        <select name="art" required style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;margin-bottom:14px;background:#fff;box-sizing:border-box">
          <option value="" disabled selected>-- bitte wählen --</option>
          <?php foreach (($myApplicationArten ?: []) as $_ak => $_al): ?>
          <option value="<?= htmlspecialchars($_ak) ?>"><?= htmlspecialchars($_al) ?></option>
          <?php endforeach; ?>
        </select>

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Titel des Antrags *</label>
        <input type="text" name="title" required maxlength="120" placeholder="z.B. Bauantrag Gerätehaus Parzelle 12"
               style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;margin-bottom:14px;box-sizing:border-box">

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Antragstext *</label>
        <textarea name="message" required rows="10" maxlength="3000" placeholder="Beschreibe deinen Antrag möglichst genau — Begründung, gewünschte Maßnahme, eventuelle Termine …"
                  style="width:100%;padding:12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;font-family:inherit;line-height:1.6;box-sizing:border-box;resize:vertical;margin-bottom:14px"></textarea>

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Anhänge <span style="font-weight:400;color:#8a9a8a">(optional — bei Bauanträgen bitte Bauplan/Skizze/Fotos anhängen)</span></label>
        <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.heic,image/*,application/pdf" style="margin-bottom:6px">
        <p style="margin:0 0 14px;font-size:0.74rem;color:#8a9a8a">PDF, JPG, PNG oder HEIC · max. 25 MB pro Datei · bis zu 6 Dateien</p>

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <button type="submit" style="background:#01579b;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">📩 Antrag absenden</button>
          <small style="color:#8a9a8a">Dein Name, deine E-Mail und deine Parzelle werden automatisch übernommen.</small>
        </div>
      </form>
    </div>

    <div style="background:#fff8e1;border-radius:10px;padding:14px 18px;margin-top:18px;border-left:4px solid #f9a825">
      <strong style="color:#f57f17;font-size:0.85rem">💡 Tipp</strong>
      <p style="margin:6px 0 0;font-size:0.84rem;color:#5a6c5a;line-height:1.6">
        Bei Anträgen für die nächste Mitgliederversammlung beachte bitte die in der Satzung genannte Frist
        (in der Regel 2 Wochen vor der Versammlung). Anträge, die zu spät eingehen, können nicht abgestimmt werden.
      </p>
    </div>
  </div>

  <!-- ── MEINE ANTRÄGE ── -->
  <div class="tab-panel <?= $activeTab === 'meine-antraege' ? 'active' : '' ?>" id="tab-meine-antraege">
    <div style="background:linear-gradient(135deg,#455a64,#263238);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h2 style="margin:0 0 8px;font-size:1.3rem">📋 Meine Anträge</h2>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Hier siehst du den Stand aller Anträge, die du gestellt hast — von „Eingegangen" bis zur Entscheidung.
      </p>
    </div>

    <?php if (isset($_GET['antrag']) && $_GET['antrag'] === 'ok'): ?>
      <div style="background:#e8f5e9;border-left:4px solid #2e7d32;border-radius:8px;padding:14px 18px;margin-bottom:18px;color:#1b5e20">
        ✓ <strong>Dein Antrag ist eingegangen!</strong> Die Schriftführung kümmert sich um die Bearbeitung — den Stand siehst du hier.
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['withdrawn'])): ?>
      <div style="background:#eceff1;border-left:4px solid #607d8b;border-radius:8px;padding:14px 18px;margin-bottom:18px;color:#37474f">
        Dein Antrag wurde zurückgezogen. Bei Bedarf kannst du jederzeit einen neuen Antrag stellen.
      </div>
    <?php endif; ?>

    <p style="margin:0 0 16px"><a href="/mitglieder.php?tab=antrag" onclick="switchTab('antrag');return false;" style="display:inline-block;background:#01579b;color:#fff;padding:9px 18px;border-radius:8px;text-decoration:none;font-size:0.88rem;font-weight:600">📩 Neuen Antrag stellen</a></p>

    <?php if (empty($myApplications)): ?>
      <div style="background:#fff;border-radius:12px;padding:50px 22px;border:1px solid #d4e6c3;text-align:center">
        <div style="font-size:2.6rem;margin-bottom:8px">📭</div>
        <h4 style="margin:0 0 6px;color:#2d3e2d">Noch keine Anträge</h4>
        <p style="margin:0;color:#8a9a8a;font-size:0.9rem">Sobald du über „Antrag" einen Antrag stellst, erscheint er hier mit Status.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:14px">
        <?php foreach ($myApplications as $_ma):
          $_maSt   = (string)($_ma['status'] ?? 'eingegangen');
          $_maCol  = $myApplicationStatuses[$_maSt][0] ?? '#5a6c5a';
          $_maLab  = $myApplicationStatuses[$_maSt][1] ?? 'Eingegangen';
          $_maArt  = $myApplicationArten[$_ma['art'] ?? ''] ?? 'Antrag';
          $_maId   = (string)($_ma['id'] ?? '');
          $_maAtt  = (array)($_ma['attachments'] ?? []);
          $_maDec  = trim((string)($_ma['decision_note'] ?? ''));
          $_maDecF = (array)($_ma['decision_files'] ?? []);
          $_maCanWithdraw = in_array($_maSt, ['eingegangen', 'offen'], true);
        ?>
        <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;border-left:4px solid <?= $_maCol ?>">
          <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
            <div style="flex:1;min-width:0">
              <span style="display:inline-block;background:#eceff1;color:#455a64;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-bottom:4px"><?= he($_maArt) ?></span>
              <div style="font-weight:700;color:#2d3e2d;font-size:1rem"><?= he((string)($_ma['title'] ?? '(Ohne Titel)')) ?></div>
              <div style="color:#8a9a8a;font-size:0.8rem;margin-top:3px">gestellt am <?= he(substr((string)($_ma['submitted_at'] ?? ''), 0, 16)) ?></div>
            </div>
            <span style="background:<?= $_maCol ?>;color:#fff;padding:4px 12px;border-radius:14px;font-size:0.76rem;font-weight:700;white-space:nowrap"><?= he($_maLab) ?></span>
          </div>

          <div style="background:#f9fbf7;border-radius:8px;padding:12px 14px;margin:8px 0;color:#2d3e2d;line-height:1.55;font-size:0.9rem;white-space:pre-wrap"><?= he((string)($_ma['message'] ?? '')) ?></div>

          <?php if (!empty($_maAtt)): ?>
            <div style="margin:8px 0;font-size:0.85rem">
              <strong style="color:#5a6c5a">📎 Deine Anhänge:</strong>
              <?php foreach ($_maAtt as $_af): ?>
                <a href="/member-api/antrag-file.php?app=<?= urlencode($_maId) ?>&id=<?= urlencode((string)($_af['id'] ?? '')) ?>&dl=1" style="display:inline-block;margin:2px 4px;padding:3px 10px;background:#eceff1;border-radius:6px;color:#37474f;text-decoration:none"><?= he((string)($_af['title'] ?? 'Anhang')) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($_maDec !== '' || !empty($_maDecF)): ?>
            <div style="background:#e8f5e9;border-left:3px solid #2e7d32;border-radius:6px;padding:12px 14px;margin:10px 0;font-size:0.9rem;color:#1b5e20">
              <strong>Rückmeldung des Vorstands:</strong>
              <?php if ($_maDec !== ''): ?><div style="white-space:pre-wrap;margin-top:4px"><?= he($_maDec) ?></div><?php endif; ?>
              <?php if (!empty($_maDecF)): ?>
                <div style="margin-top:6px">
                  <?php foreach ($_maDecF as $_df): ?>
                    <a href="/member-api/antrag-file.php?app=<?= urlencode($_maId) ?>&id=<?= urlencode((string)($_df['id'] ?? '')) ?>&dl=1" style="display:inline-block;margin:2px 4px;padding:3px 10px;background:#c8e6c9;border-radius:6px;color:#1b5e20;text-decoration:none">📄 <?= he((string)($_df['title'] ?? 'Dokument')) ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($_maCanWithdraw): ?>
            <form method="POST" action="/mitglieder.php?tab=meine-antraege" style="margin-top:8px" onsubmit="return confirm('Diesen Antrag wirklich zurückziehen?');">
              <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
              <input type="hidden" name="action" value="withdraw_application">
              <input type="hidden" name="app_id" value="<?= he($_maId) ?>">
              <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;padding:6px 14px;border-radius:6px;font-size:0.8rem;cursor:pointer">Antrag zurückziehen</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── SCHADEN MELDEN ── -->
  <div class="tab-panel <?= $activeTab === 'schaden' ? 'active' : '' ?>" id="tab-schaden">
    <div style="background:linear-gradient(135deg,#b71c1c,#7b1c1c);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h2 style="margin:0 0 8px;font-size:1.3rem">🔧 Schaden melden</h2>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Defektes Türschloss? Wasserrohrbruch? Loch im Zaun? Hier kannst du Schäden im Vereinshaus oder
        auf der Anlage melden — gerne mit Foto. Sandra/Vorstand bekommt eine Mail und kümmert sich.
      </p>
    </div>

    <?php if (isset($_GET['schaden']) && $_GET['schaden'] === 'ok'): ?>
      <div style="background:#e8f5e9;border-left:4px solid #2e7d32;border-radius:8px;padding:14px 18px;margin-bottom:18px;color:#1b5e20">
        ✓ <strong>Deine Schadenmeldung ist eingegangen!</strong> Wir kümmern uns so schnell wie möglich.
      </div>
    <?php endif; ?>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
      <form method="POST" action="/mitglieder.php?tab=schaden" enctype="multipart/form-data">
        <input type="hidden" name="member_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="submit_schaden">
        <input type="text" name="__website" value="" autocomplete="off" style="position:absolute;left:-9999px" tabindex="-1">

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Was ist betroffen? *</label>
        <select name="category" required style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;margin-bottom:14px;background:#fff;box-sizing:border-box">
          <option value="">-- bitte wählen --</option>
          <option value="vereinshaus">🏠 Vereinshaus (Tür, Fenster, Innenraum)</option>
          <option value="sanitaer">🚿 Sanitär / Wasser (Toilette, Wasserhahn, Leckage)</option>
          <option value="elektrik">⚡ Elektrik (Steckdose, Lampe, Sicherung)</option>
          <option value="wege">🛤 Wege / Außenanlagen</option>
          <option value="zaun">🚧 Zaun / Tore</option>
          <option value="spielplatz">🎠 Spielplatz</option>
          <option value="sonstige">🔧 Sonstige</option>
        </select>

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Kurztitel *</label>
        <input type="text" name="title" required maxlength="120" placeholder="z.B. Türschloss Vereinshaus kaputt"
               style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;margin-bottom:14px;box-sizing:border-box">

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Beschreibung *</label>
        <textarea name="description" required rows="6" maxlength="2000" placeholder="Wann hast du es bemerkt? Wo genau? Wie äußert sich der Schaden?"
                  style="width:100%;padding:12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;font-family:inherit;line-height:1.6;box-sizing:border-box;resize:vertical;margin-bottom:14px"></textarea>

        <label style="display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Foto (optional, hilft sehr!)</label>
        <input type="file" name="photo" accept="image/jpeg,image/png" style="margin-bottom:14px">
        <span style="font-size:0.74rem;color:#8a9a8a;margin-left:8px">JPG/PNG max 5 MB</span>

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px">
          <button type="submit" style="background:#b71c1c;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">🔧 Schaden melden</button>
          <small style="color:#8a9a8a">Dein Name und deine E-Mail-Adresse werden automatisch übernommen.</small>
        </div>
      </form>
    </div>
  </div>

  <!-- ── PROFIL / DATENSCHUTZ ── -->
  <div class="tab-panel <?= $activeTab === 'profil' ? 'active' : '' ?>" id="tab-profil">
    <?php if (isset($_GET['saved'])): ?>
    <div class="success-msg" style="display:block;margin-bottom:16px">
      <?= $_GET['saved'] === 'contact'
        ? '✅ Kontaktdaten aktualisiert. Ihre Einwilligungen wurden zurückgesetzt – bitte erneut erteilen.'
        : ($_GET['saved'] === 'birthday'
          ? '🎂 Geburtstag gespeichert!'
          : '✅ Einstellung gespeichert.') ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($_profileError)): ?>
    <div class="error-msg" style="display:block;margin-bottom:16px">⚠️ <?= he($_profileError) ?></div>
    <?php endif; ?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <h3 style="margin:0">👤 Mein Profil</h3>
        <button class="btn-secondary" type="button" onclick="toggleEditContact()" id="editContactBtn" style="font-size:0.82rem;padding:6px 14px">✏️ Bearbeiten</button>
      </div>
      <div class="profile-grid">
        <div class="profile-field"><div class="label">Name</div><div class="value"><?= he($member['name']) ?></div></div>
        <div class="profile-field"><div class="label">Parzelle</div><div class="value"><?= he($member['parzelle']) ?></div></div>
        <div class="profile-field"><div class="label">E-Mail</div><div class="value"><?= he($member['email']) ?></div></div>
        <div class="profile-field"><div class="label">Telefon</div><div class="value"><?= he($memberFull['phone'] ?? '–') ?></div></div>
      </div>
      <!-- Geburtstag -->
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--green-border);display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div>
          <div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Geburtstag</div>
          <div style="font-size:0.9rem;font-weight:500">
            <?php
              $_bday = $memberFull['geburtstag'] ?? '';
              if ($_bday !== '' && preg_match('/^(\d{2})-(\d{2})$/', $_bday, $_bm)) {
                  echo (int)$_bm[2] . '. ' . ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'][(int)$_bm[1]] . ' <span style="font-size:0.75rem;color:var(--text-muted)">(Jahr nicht gespeichert)</span>';
              } else {
                  echo '<span style="color:var(--text-muted)">Nicht angegeben</span>';
              }
              // Convert stored MM-DD to display TT.MM
              $_bdayDisplay = '';
              if ($_bday !== '' && preg_match('/^(\d{2})-(\d{2})$/', $_bday, $_bdm)) $_bdayDisplay = $_bdm[2] . '.' . $_bdm[1];
            ?>
          </div>
        </div>
        <form method="POST" style="display:flex;align-items:center;gap:8px">
          <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
          <input type="hidden" name="action" value="save_birthday">
          <input type="text" name="geburtstag" value="<?= he($_bdayDisplay) ?>"
                 placeholder="TT.MM (z.B. 22.06)"
                 maxlength="5"
                 style="width:110px;padding:6px 10px;border:1.5px solid var(--green-border);border-radius:8px;font-size:0.85rem;font-family:inherit"
                 title="Format: TT.MM, z.B. 22.06 für 22. Juni">
          <button type="submit" class="btn-secondary" style="font-size:0.82rem;padding:6px 12px">🎂 Speichern</button>
        </form>
        <div style="font-size:0.72rem;color:var(--text-muted)">Nur Monat &amp; Tag — kein Jahrgang.</div>
      </div>

      <!-- Edit form (hidden by default) -->
      <div id="editContactForm" style="display:none;margin-top:20px;padding-top:18px;border-top:1px solid var(--green-border)">
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:14px">
          ⚠️ Bei Änderung von E-Mail oder Telefonnummer werden Ihre <strong>Datenschutz-Einwilligungen zurückgesetzt</strong> und müssen anschließend erneut erteilt werden.<br>
          Name und Parzellennummer können nur vom Vorstand geändert werden.
        </p>
        <form method="POST">
          <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
          <input type="hidden" name="action" value="update_contact_data">
          <div class="profile-grid" style="margin-bottom:14px">
            <div class="field">
              <label>E-Mail-Adresse</label>
              <input type="email" name="new_email" value="<?= he($member['email']) ?>" required autocomplete="email">
            </div>
            <div class="field">
              <label>Telefonnummer <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
              <input type="tel" name="new_phone" value="<?= he($memberFull['phone'] ?? '') ?>" autocomplete="tel" placeholder="z.B. 0163 1234567">
            </div>
          </div>
          <label style="display:flex;align-items:flex-start;gap:10px;font-size:0.85rem;cursor:pointer;margin-bottom:16px;line-height:1.5">
            <input type="checkbox" name="consent_reconfirm" value="1" required style="margin-top:3px;flex-shrink:0">
            <span>Ich stimme der Verarbeitung meiner aktualisierten Kontaktdaten durch den KGV Musterstadt zu (Art.&nbsp;6 Abs.&nbsp;1 lit.&nbsp;a DSGVO).</span>
          </label>
          <div style="display:flex;gap:10px">
            <button type="submit" class="btn-primary" style="font-size:0.88rem">Speichern</button>
            <button type="button" class="btn-secondary" onclick="toggleEditContact()" style="font-size:0.88rem">Abbrechen</button>
          </div>
        </form>
      </div>
    </div>
    <div class="card">
      <h3>🔒 Datenschutz-Einwilligungen</h3>
      <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:20px">Sie können Ihre Zustimmungen jederzeit widerrufen. Nach Widerruf werden Ihre Daten sofort aus der Mitglieder-Ansicht entfernt. Der Vorstand behält Ihre Daten intern für administrative Zwecke.</p>
      <?php
        $consents = $memberFull['consents'] ?? [];
        $contactActive = !empty($consents['contact_allowed']);
        $phoneActive   = !empty($consents['in_phonelist']);
        $contactDate   = $contactActive ? ($consents['contact_allowed_at'] ?? '') : ($consents['contact_revoked_at'] ?? '');
        $phoneDate     = $phoneActive   ? ($consents['in_phonelist_at'] ?? '')    : ($consents['in_phonelist_revoked_at'] ?? '');
      ?>
      <div class="consent-row">
        <div class="consent-info">
          <strong>Kontaktaufnahme durch den Vorstand</strong>
          <span>Der Vorstand darf mich per E-Mail oder Telefon kontaktieren.</span>
          <?php if ($contactDate): ?><br><span style="font-size:0.75rem"><?= $contactActive ? 'Erteilt am' : 'Widerrufen am' ?>: <?= he(substr($contactDate,0,10)) ?></span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span class="consent-status <?= $contactActive ? 'active' : 'revoked' ?>"><?= $contactActive ? '✅ Erteilt' : '❌ Widerrufen' ?></span>
          <form method="POST" style="display:inline">
            <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
            <input type="hidden" name="action" value="<?= $contactActive ? 'revoke_contact' : 'grant_contact' ?>">
            <button type="submit" class="<?= $contactActive ? 'btn-revoke' : 'btn-grant' ?>"><?= $contactActive ? 'Widerrufen' : 'Erteilen' ?></button>
          </form>
        </div>
      </div>
      <div class="consent-row">
        <div class="consent-info">
          <strong>In der Mitglieder-Telefonliste erscheinen</strong>
          <span>Name, Parzelle, Telefon und E-Mail sind in der internen Liste sichtbar.</span>
          <?php if ($phoneDate): ?><br><span style="font-size:0.75rem"><?= $phoneActive ? 'Erteilt am' : 'Widerrufen am' ?>: <?= he(substr($phoneDate,0,10)) ?></span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span class="consent-status <?= $phoneActive ? 'active' : 'revoked' ?>"><?= $phoneActive ? '✅ Erteilt' : '❌ Widerrufen' ?></span>
          <form method="POST" style="display:inline">
            <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
            <input type="hidden" name="action" value="<?= $phoneActive ? 'revoke_phonelist' : 'grant_phonelist' ?>">
            <button type="submit" class="<?= $phoneActive ? 'btn-revoke' : 'btn-grant' ?>"><?= $phoneActive ? 'Widerrufen' : 'Erteilen' ?></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ── PASSWORT ── -->
  <div class="tab-panel <?= $activeTab === 'passwort' ? 'active' : '' ?>" id="tab-passwort">
    <div style="max-width:440px">
      <div class="card" style="padding:0;overflow:hidden">
        <!-- Card header -->
        <div style="background:linear-gradient(135deg,#f0f7f0 0%,#e8f5e9 100%);border-bottom:1px solid var(--green-border);padding:20px 24px;display:flex;align-items:center;gap:12px">
          <div style="width:38px;height:38px;background:var(--green);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">🔑</div>
          <div>
            <div style="font-family:'Playfair Display',serif;color:var(--green);font-size:1.1rem;font-weight:700">Passwort ändern</div>
            <div style="font-size:0.78rem;color:var(--text-muted);margin-top:1px">Wähle ein starkes Passwort für dein Konto</div>
          </div>
        </div>
        <!-- Card body -->
        <div style="padding:24px">
          <?php if (isset($_pwError)): ?>
            <div class="error-msg" style="display:block;margin-bottom:20px"><?= he($_pwError) ?></div>
          <?php elseif (isset($_pwSuccess)): ?>
            <div class="success-msg" style="display:block;margin-bottom:20px">✅ Passwort erfolgreich geändert.</div>
          <?php endif; ?>
          <form method="POST" onsubmit="return checkPwStrength()">
            <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
            <input type="hidden" name="action" value="change_password">

            <!-- Current password -->
            <div class="field">
              <label>Aktuelles Passwort</label>
              <div style="position:relative">
                <input type="password" name="pw_current" id="pw_current" autocomplete="current-password" required style="padding-right:42px">
                <button type="button" onclick="togglePw('pw_current',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1rem;color:var(--text-muted);padding:4px;line-height:1" title="Anzeigen">👁</button>
              </div>
            </div>

            <!-- New password -->
            <div class="field">
              <label>Neues Passwort</label>
              <div style="position:relative">
                <input type="password" name="pw_new" id="pw_new" autocomplete="new-password" required oninput="updatePwStrength()" style="padding-right:42px">
                <button type="button" onclick="togglePw('pw_new',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1rem;color:var(--text-muted);padding:4px;line-height:1" title="Anzeigen">👁</button>
              </div>
              <!-- Strength bar -->
              <div style="margin-top:10px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                  <span style="font-size:0.75rem;color:var(--text-muted)">Passwortstärke</span>
                  <span id="pw-strength-label" style="font-size:0.75rem;font-weight:600;color:var(--text-muted)"></span>
                </div>
                <div style="height:6px;border-radius:6px;background:#e8f0e0;overflow:hidden">
                  <div id="pw-strength-fill" style="height:100%;border-radius:6px;width:0;transition:width .35s,background .35s"></div>
                </div>
              </div>
              <!-- Rules checklist -->
              <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:5px 10px">
                <div id="rule-len"   class="pw-rule">Mindestens 8 Zeichen</div>
                <div id="rule-upper" class="pw-rule">Großbuchstabe (A–Z)</div>
                <div id="rule-lower" class="pw-rule">Kleinbuchstabe (a–z)</div>
                <div id="rule-digit" class="pw-rule">Zahl (0–9)</div>
              </div>
            </div>

            <!-- Confirm password -->
            <div class="field">
              <label>Neues Passwort bestätigen</label>
              <div style="position:relative">
                <input type="password" name="pw_new2" id="pw_new2" autocomplete="new-password" required oninput="checkPwMatch()" style="padding-right:42px">
                <button type="button" onclick="togglePw('pw_new2',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1rem;color:var(--text-muted);padding:4px;line-height:1" title="Anzeigen">👁</button>
              </div>
              <div id="pw-match-hint" style="font-size:0.78rem;margin-top:5px;min-height:18px"></div>
            </div>

            <button type="submit" class="btn-primary" style="margin-top:4px">Passwort ändern</button>
          </form>
        </div>
      </div>
    </div>
    <style>
    .pw-rule { font-size:0.78rem; color:var(--text-muted); display:flex; align-items:center; gap:5px; transition:color .2s; }
    .pw-rule::before { content:'○'; font-size:0.7rem; flex-shrink:0; }
    .pw-rule.ok { color:#2e7d32; font-weight:600; }
    .pw-rule.ok::before { content:'✓'; color:#43a047; }
    </style>
    <script>
    function togglePw(id, btn) {
        const el = document.getElementById(id);
        if (el.type === 'password') { el.type = 'text'; btn.style.opacity = '1'; }
        else { el.type = 'password'; btn.style.opacity = '0.5'; }
    }
    function updatePwStrength() {
        const pw = document.getElementById('pw_new').value;
        const rules = {len: pw.length >= 8, upper: /[A-Z]/.test(pw), lower: /[a-z]/.test(pw), digit: /[0-9]/.test(pw)};
        const ok = Object.values(rules).filter(Boolean).length;
        const fills  = ['0%','25%','50%','75%','100%'];
        const colors = ['#e0e0e0','#e53935','#fb8c00','#fdd835','#43a047'];
        const labels = ['','Schwach','Mittel','Gut','Stark'];
        document.getElementById('pw-strength-fill').style.width      = fills[ok];
        document.getElementById('pw-strength-fill').style.background = colors[ok];
        document.getElementById('pw-strength-label').textContent     = pw.length ? labels[ok] : '';
        document.getElementById('pw-strength-label').style.color     = colors[ok];
        ['len','upper','lower','digit'].forEach(r => {
            document.getElementById('rule-' + r).classList.toggle('ok', rules[r]);
        });
        checkPwMatch();
    }
    function checkPwMatch() {
        const pw  = document.getElementById('pw_new').value;
        const pw2 = document.getElementById('pw_new2').value;
        const hint = document.getElementById('pw-match-hint');
        if (!pw2) { hint.textContent = ''; return; }
        if (pw === pw2) { hint.textContent = '✓ Passwörter stimmen überein'; hint.style.color = '#2e7d32'; }
        else            { hint.textContent = '✗ Passwörter stimmen nicht überein'; hint.style.color = '#c62828'; }
    }
    function checkPwStrength() {
        const pw = document.getElementById('pw_new').value;
        if (pw.length < 8 || !/[A-Z]/.test(pw) || !/[a-z]/.test(pw) || !/[0-9]/.test(pw)) {
            alert('Bitte alle Passwort-Anforderungen erfüllen.');
            return false;
        }
        if (pw !== document.getElementById('pw_new2').value) {
            alert('Passwörter stimmen nicht überein.');
            return false;
        }
        return true;
    }
    </script>
  </div>

  <?php if ($isKoppel): ?>
  <!-- ── KOPPELMANN/FRAU ── -->
  <div class="tab-panel <?= $activeTab === 'koppel' ? 'active' : '' ?>" id="tab-koppel">
    <div class="card">
      <h3>🔨 Gemeinschaftsarbeit verwalten – <?= $arbeitYear ?></h3>
      <?php if (!empty($_GET['ok'])): ?>
      <div style="background:#e8f5e9;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:0.88rem;color:#2e7d32">✅ Gespeichert.</div>
      <?php endif; ?>
      <?php
        $koppelMembers = $allMembers;
        usort($koppelMembers, function($a, $b) {
            $pa = (int)($a['parzelle'] ?? 0); $pb = (int)($b['parzelle'] ?? 0);
            if ($pa === 0 && $pb === 0) return strcmp($a['name'] ?? '', $b['name'] ?? '');
            if ($pa === 0) return 1; if ($pb === 0) return -1;
            return $pa - $pb;
        });
      ?>
      <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:0.85rem">
        <thead>
          <tr style="background:#3d6b41;color:#fff">
            <th style="padding:8px 12px;text-align:left">Parzelle</th>
            <th style="padding:8px 12px;text-align:left">Name</th>
            <th style="padding:8px 12px;text-align:center">Geleistet</th>
            <th style="padding:8px 12px;text-align:center">Status</th>
            <th style="padding:8px 12px;text-align:left">Einträge</th>
            <th style="padding:8px 12px;text-align:center">+</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($koppelMembers as $_km):
            $_kmId   = $_km['id'] ?? '';
            $_kmEntr = array_values(array_filter($arbeitData[$_kmId] ?? [], fn($e) => (int)($e['year'] ?? 0) === $arbeitYear));
            $_kmH    = array_sum(array_column($_kmEntr, 'hours'));
            $_kmDone = $_kmH >= $arbeitSoll;
        ?>
        <tr style="border-bottom:1px solid #e8f0e0">
          <td style="padding:7px 12px"><?= he($_km['parzelle'] ?? '–') ?></td>
          <td style="padding:7px 12px;font-weight:500"><?= he($_km['name'] ?? '') ?></td>
          <td style="padding:7px 12px;text-align:center;font-weight:700;color:<?= $_kmDone ? '#2e7d32' : '#e65100' ?>"><?= number_format($_kmH, 1, ',', '.') ?>h</td>
          <td style="padding:7px 12px;text-align:center">
            <span style="font-size:0.72rem;padding:2px 7px;border-radius:10px;font-weight:600;background:<?= $_kmDone ? '#e8f5e9' : '#fff3e0' ?>;color:<?= $_kmDone ? '#2e7d32' : '#e65100' ?>">
              <?= $_kmDone ? '✅ Erfüllt' : '⏳ Offen' ?>
            </span>
          </td>
          <td style="padding:7px 12px;font-size:0.78rem;color:#5a6c5a">
            <?php foreach ($_kmEntr as $_kidx => $_ke): ?>
            <span style="display:inline-flex;align-items:center;gap:3px;background:#f0f5ec;border-radius:4px;padding:1px 5px;margin:1px">
              <?= he(substr($_ke['date'] ?? '', 5)) ?> · <?= number_format((float)($_ke['hours'] ?? 0), 1, ',', '.') ?>h
              <?php if (!empty($_ke['note'])): ?><span title="<?= he($_ke['note']) ?>">💬</span><?php endif; ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
                <input type="hidden" name="koppel_action" value="delete_arbeit">
                <input type="hidden" name="kmem_id" value="<?= he($_kmId) ?>">
                <input type="hidden" name="arbeit_idx" value="<?= $_kidx ?>">
                <button type="submit" style="background:none;border:none;cursor:pointer;font-size:0.72rem;color:#c62828;padding:0 2px" onclick="return confirm('Eintrag löschen?')">✕</button>
              </form>
            </span>
            <?php endforeach; ?>
          </td>
          <td style="padding:7px 12px;text-align:center">
            <button onclick="toggleKoppelForm('kf-<?= he($_kmId) ?>')" style="background:none;border:none;cursor:pointer;font-size:0.95rem;color:#3d6b41" title="Stunden eintragen">➕</button>
          </td>
        </tr>
        <tr id="kf-<?= he($_kmId) ?>" style="display:none;background:#f9fbf7">
          <td colspan="6" style="padding:10px 12px">
            <form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
              <input type="hidden" name="member_csrf" value="<?= he($csrf) ?>">
              <input type="hidden" name="koppel_action" value="save_arbeit">
              <input type="hidden" name="kmem_id" value="<?= he($_kmId) ?>">
              <input type="hidden" name="arbeit_year" value="<?= $arbeitYear ?>">
              <div><label style="font-size:0.72rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:3px">Stunden</label>
                <input type="text" name="arbeit_hours" value="4" style="width:65px;padding:6px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit"></div>
              <div><label style="font-size:0.72rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:3px">Datum</label>
                <input type="date" name="arbeit_date" value="<?= date('Y-m-d') ?>" style="padding:6px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit"></div>
              <div><label style="font-size:0.72rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:3px">Notiz</label>
                <input type="text" name="arbeit_note" placeholder="z.B. Hecken schneiden" style="width:180px;padding:6px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit"></div>
              <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;cursor:pointer;font-family:inherit">Eintragen</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div><!-- /tab-koppel -->
  <?php endif; ?>

</div><!-- /dashboard -->
<?php endif; ?>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('tab-' + tab);
    if (panel) panel.classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(b => {
        if (b.getAttribute('onclick') === "switchTab('" + tab + "')") {
            b.classList.add('active');
            // Aktiven Tab ins sichtbare Bereich scrollen
            b.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        }
    });
    history.replaceState(null, '', '/mitglieder.php?tab=' + tab);
    if (tab === 'telefonliste') activatePhonelistProtection();
    else deactivatePhonelistProtection();
}

// ── Phonelist protection ──────────────────────────────────────────────────────
function activatePhonelistProtection() {
    const wm = document.getElementById('phoneWatermark');
    const wt = document.getElementById('watermarkText');
    if (wm && wt) {
        <?php if ($isLoggedIn && $member): ?>
        const name = <?= json_encode($member['name']) ?>;
        const parc = <?= json_encode($member['parzelle']) ?>;
        wt.textContent = name + ' · Parzelle ' + parc + '     ' + name + ' · Parzelle ' + parc;
        <?php endif; ?>
        wm.style.display = 'block';
    }
    document.addEventListener('contextmenu', blockCtx);
}
function deactivatePhonelistProtection() {
    const wm = document.getElementById('phoneWatermark');
    if (wm) wm.style.display = 'none';
    document.removeEventListener('contextmenu', blockCtx);
}
function blockCtx(e) {
    const tl = document.getElementById('tab-telefonliste');
    if (tl && tl.classList.contains('active')) e.preventDefault();
}

// ── WAF bypass: base64-encode payload so STRATO WAF doesn't block email patterns ──
function wafFetch(url, obj) {
    const enc = btoa(unescape(encodeURIComponent(JSON.stringify(obj))));
    return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({_p: enc})});
}

// ── Login ─────────────────────────────────────────────────────────────────────
async function doLogin() {
    const email    = document.getElementById('loginEmail')?.value.trim();
    const password = document.getElementById('loginPassword')?.value;
    const errEl    = document.getElementById('loginError');
    if (!email || !password) { showErr(errEl, 'Bitte E-Mail und Passwort eingeben.'); return; }
    const r = await wafFetch('/member-api/login.php', {email, password});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        window.location.href = '/mitglieder.php';
    } else {
        const msgs = {invalid_credentials:'E-Mail oder Passwort falsch.', account_inactive:'Ihr Konto ist noch nicht freigeschaltet.', rate_limited:'Zu viele Versuche. Bitte 15 Minuten warten.'};
        showErr(errEl, msgs[d.message] || 'Anmeldung fehlgeschlagen.');
    }
}
document.getElementById('loginPassword')?.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });

// ── Password reset request ────────────────────────────────────────────────────
async function doRequestReset() {
    const email = document.getElementById('resetEmail')?.value.trim();
    const errEl = document.getElementById('resetError');
    const sucEl = document.getElementById('resetSuccess');
    if (!email) { showErr(errEl, 'Bitte E-Mail-Adresse eingeben.'); return; }
    const r = await wafFetch('/member-api/reset_password.php', {action:'request', email});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok' || d.status === 'error') {
        document.getElementById('resetForm').style.display = 'none';
        sucEl.style.display = 'block';
    } else {
        showErr(errEl, 'Fehler beim Senden. Bitte später erneut versuchen.');
    }
}

// ── Password reset confirm ────────────────────────────────────────────────────
async function doConfirmReset() {
    const token    = document.getElementById('resetConfirmToken')?.value;
    const password  = document.getElementById('rcPassword')?.value;
    const password2 = document.getElementById('rcPassword2')?.value;
    const errEl    = document.getElementById('resetConfirmError');
    const sucEl    = document.getElementById('resetConfirmSuccess');
    if (!password || !password2) { showErr(errEl, 'Bitte beide Felder ausfüllen.'); return; }
    const r = await wafFetch('/member-api/reset_password.php', {action:'confirm', token, password, password2});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        document.getElementById('resetConfirmForm').style.display = 'none';
        sucEl.style.display = 'block';
        setTimeout(() => { window.location.href = '/mitglieder.php'; }, 2500);
    } else {
        const msgs = {token_expired:'Dieser Link ist abgelaufen.', invalid_token:'Ungültiger Link.', passwords_mismatch:'Passwörter stimmen nicht überein.', password_too_short:'Mindestens 8 Zeichen erforderlich.', password_no_upper:'Mindestens ein Großbuchstabe erforderlich.', password_no_lower:'Mindestens ein Kleinbuchstabe erforderlich.', password_no_digit:'Mindestens eine Zahl erforderlich.'};
        showErr(errEl, msgs[d.message] || 'Fehler beim Speichern.');
    }
}

// ── Register ──────────────────────────────────────────────────────────────────
function onRegTypChange() {
    const typ     = document.querySelector('input[name="regTyp"]:checked')?.value;
    const wrapper = document.getElementById('regParzelleWrapper');
    const hint    = document.getElementById('regPartnerHint');
    if (wrapper) wrapper.style.display = (typ === 'foerdermitglied') ? 'none' : '';
    if (hint)    hint.style.display    = (typ === 'paechterpartner') ? 'block' : 'none';
}

async function doRegister() {
    const rolle_typ = document.querySelector('input[name="regTyp"]:checked')?.value ?? 'paechter';
    const name      = document.getElementById('regName')?.value.trim();
    const email     = document.getElementById('regEmail')?.value.trim();
    const parzelle  = (rolle_typ === 'foerdermitglied') ? '' : (document.getElementById('regParzelle')?.value.trim() ?? '');
    const phone     = document.getElementById('regPhone')?.value.trim();
    const message   = document.getElementById('regMessage')?.value.trim();
    const consent_contact   = document.getElementById('regConsentContact')?.checked;
    const consent_phonelist = document.getElementById('regConsentPhonelist')?.checked;
    const errEl = document.getElementById('regError');
    const sucEl = document.getElementById('regSuccess');
    if (!name || !email) { showErr(errEl, 'Bitte Name und E-Mail ausfüllen.'); return; }
    if (rolle_typ !== 'foerdermitglied' && !parzelle) { showErr(errEl, 'Bitte Parzelle-Nr. ausfüllen.'); return; }
    const r = await wafFetch('/member-api/register.php', {name, email, parzelle, phone, message, consent_contact, consent_phonelist, rolle_typ});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        document.getElementById('regForm').style.display = 'none';
        sucEl.style.display = 'block';
        errEl.style.display = 'none';
    } else {
        const msgs = {
            already_requested:    'Eine Anfrage mit dieser E-Mail ist bereits in Bearbeitung.',
            email_exists:         'Diese E-Mail ist bereits registriert.',
            rate_limited:         'Zu viele Anfragen. Bitte später erneut versuchen.',
            parzelle_exists:      'Diese Parzelle ist bereits registriert. Falls Sie Pächterpartner sind, wählen Sie bitte die entsprechende Mitgliedsart.',
            parzelle_not_found:   'Diese Parzelle ist nicht bekannt. Bitte die Nummer des bestehenden Pächters eingeben.',
            partner_already_exists: 'Für diese Parzelle ist bereits ein Pächterpartner registriert.',
        };
        showErr(errEl, msgs[d.message] || 'Fehler beim Einreichen.');
    }
}

// ── File preview helper ───────────────────────────────────────────────────────
function setupFilePreview(inputId, previewId) {
    const input   = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    if (!input || !preview) return;
    input.addEventListener('change', () => {
        preview.innerHTML = '';
        const file = input.files[0];
        if (!file) return;
        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.style.cssText = 'max-width:120px;max-height:100px;border-radius:8px;object-fit:cover;display:block';
            img.src = URL.createObjectURL(file);
            preview.appendChild(img);
        } else {
            preview.innerHTML = `<span style="font-size:0.82rem;color:var(--text-muted)">📎 ${file.name}</span>`;
        }
    });
}
setupFilePreview('contactFile', 'contactFilePreview');
document.querySelectorAll('[id^="reply-file-"]').forEach(inp => {
    if (!inp.id.includes('preview')) setupFilePreview(inp.id, inp.id.replace('reply-file-', 'reply-file-preview-'));
});

// ── Contact recipient chips ───────────────────────────────────────────────────
const _roleMembers = <?= json_encode($roleMemberMap, JSON_UNESCAPED_UNICODE) ?>;
let _selectedRecipients = {};
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('contactRecipient');
    if (sel) updateRecipientChips(sel.value);
});

function updateRecipientChips(val) {
    const preview = document.getElementById('recipientPreview');
    const chips   = document.getElementById('recipientChips');
    const warn    = document.getElementById('recipientWarn');
    if (!preview || !chips) return;
    const members = _roleMembers[val];
    if (!members || members.length === 0) {
        preview.style.display = 'none';
        _selectedRecipients = {};
        return;
    }
    _selectedRecipients = {};
    members.forEach(m => { _selectedRecipients[m.id] = m.name; });
    preview.style.display = 'block';
    renderRecipientChips(chips, warn);
}

function renderRecipientChips(chips, warn) {
    chips.innerHTML = '';
    Object.entries(_selectedRecipients).forEach(([id, name]) => {
        const chip = document.createElement('span');
        chip.style.cssText = 'display:inline-flex;align-items:center;gap:5px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:20px;padding:4px 12px 4px 10px;font-size:0.82rem;color:#2d6b31;font-weight:500';
        chip.innerHTML = `👤 ${name} <button type="button" onclick="removeRecipient('${id.replace(/'/g,"\\\'")}')" style="background:none;border:none;cursor:pointer;font-size:1rem;line-height:1;color:#888;padding:0 0 0 4px" title="Abwählen">×</button>`;
        chips.appendChild(chip);
    });
    if (warn) warn.style.display = Object.keys(_selectedRecipients).length === 0 ? 'block' : 'none';
}

function removeRecipient(id) {
    delete _selectedRecipients[id];
    renderRecipientChips(document.getElementById('recipientChips'), document.getElementById('recipientWarn'));
}

// ── Contact ───────────────────────────────────────────────────────────────────
async function doContact() {
    const subject       = document.getElementById('contactSubject')?.value.trim();
    const message       = document.getElementById('contactMessage')?.value.trim();
    const fileInput     = document.getElementById('contactFile');
    const recipientVal  = document.getElementById('contactRecipient')?.value || 'vorstand';
    const isMemberMsg   = recipientVal.startsWith('member_');
    const isRoleMsg     = !isMemberMsg;
    const errEl         = document.getElementById('contactError');
    const sucEl         = document.getElementById('contactSuccess');
    if (!subject || !message) { showErr(errEl, 'Bitte Betreff und Nachricht ausfüllen.'); return; }
    // Validate role recipients (chips visible = role has known members)
    const previewVisible = document.getElementById('recipientPreview')?.style.display !== 'none';
    if (isRoleMsg && previewVisible && Object.keys(_selectedRecipients).length === 0) {
        showErr(errEl, 'Bitte mindestens einen Empfänger auswählen.');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'new');
    fd.append('subject', subject);
    fd.append('body', message);
    fd.append('recipient_role', recipientVal);
    // Pass individually selected recipient IDs for role messages
    if (isRoleMsg && previewVisible) {
        Object.keys(_selectedRecipients).forEach(id => fd.append('recipient_ids[]', id));
    }
    if (fileInput?.files[0]) fd.append('attachment', fileInput.files[0]);
    const r = await fetch('/member-api/contact.php', {method:'POST', body: fd});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        sucEl.textContent = isMemberMsg
            ? '✅ Ihre Nachricht wurde gesendet.'
            : '✅ Ihre Nachricht wurde gesendet. Der Vorstand wird sich melden.';
        sucEl.style.display = 'block';
        errEl.style.display = 'none';
        document.getElementById('contactSubject').value = '';
        document.getElementById('contactMessage').value = '';
        if (fileInput) { fileInput.value = ''; document.getElementById('contactFilePreview').innerHTML = ''; }
        setTimeout(() => location.reload(), 1500);
    } else {
        showErr(errEl, 'Fehler beim Senden. Bitte erneut versuchen.');
    }
}

// ── Reply to thread ───────────────────────────────────────────────────────────
async function doReply(threadId) {
    const ta        = document.getElementById('reply-' + threadId);
    const fileInput = document.getElementById('reply-file-' + threadId);
    const errEl     = document.getElementById('reply-err-' + threadId);
    const sucEl     = document.getElementById('reply-ok-'  + threadId);
    const body      = ta?.value.trim();
    if (!body) { showErr(errEl, 'Bitte Nachricht eingeben.'); return; }
    const fd = new FormData();
    fd.append('action', 'reply');
    fd.append('thread_id', threadId);
    fd.append('body', body);
    if (fileInput?.files[0]) fd.append('attachment', fileInput.files[0]);
    const r = await fetch('/member-api/contact.php', {method:'POST', body: fd});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        sucEl.textContent = '✅ Antwort gesendet.';
        sucEl.style.display = 'block';
        errEl.style.display = 'none';
        ta.value = '';
        setTimeout(() => location.reload(), 1000);
    } else {
        const msgs = {not_logged_in:'Sitzung abgelaufen.', thread_not_found:'Thread nicht gefunden.', missing_fields:'Bitte Nachricht eingeben.', invalid_file_type:'Dateityp nicht erlaubt (nur Bilder und PDF).', file_too_large:'Datei zu groß (max. 5 MB).'};
        showErr(errEl, msgs[d.message] || 'Fehler beim Senden.');
    }
}

// ── Reply to inbox thread (received from another member) ─────────────────────
async function doPeerReply(threadId) {
    const ta    = document.getElementById('ireply-' + threadId);
    const errEl = document.getElementById('ireply-err-' + threadId);
    const sucEl = document.getElementById('ireply-ok-'  + threadId);
    const body  = ta?.value.trim();
    if (!body) { showErr(errEl, 'Bitte Nachricht eingeben.'); return; }
    const fd = new FormData();
    fd.append('action', 'peer_reply');
    fd.append('thread_id', threadId);
    fd.append('body', body);
    const r = await fetch('/member-api/contact.php', {method:'POST', body: fd});
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        sucEl.textContent = '✅ Antwort gesendet.';
        sucEl.style.display = 'block';
        errEl.style.display = 'none';
        ta.value = '';
        setTimeout(() => location.reload(), 1000);
    } else {
        showErr(errEl, 'Fehler beim Senden. Bitte erneut versuchen.');
    }
}

// ── Edit contact data toggle ──────────────────────────────────────────────────
function toggleEditContact() {
    const form = document.getElementById('editContactForm');
    const btn  = document.getElementById('editContactBtn');
    if (!form) return;
    const open = form.style.display === 'none';
    form.style.display = open ? 'block' : 'none';
    if (btn) btn.textContent = open ? '✕ Schließen' : '✏️ Bearbeiten';
}
<?php if (!empty($_profileError)): ?>
document.addEventListener('DOMContentLoaded', () => toggleEditContact());
<?php endif; ?>

// ── Delete thread (member side) ───────────────────────────────────────────────
async function doDeleteThread(threadId) {
    if (!confirm('Nachricht löschen? Sie wird nur auf Ihrer Seite entfernt.')) return;
    try {
        const res = await wafFetch('/member-api/contact.php', { action: 'delete', thread_id: threadId });
        const data = await res.json();
        if (data.status === 'ok') {
            const row = document.getElementById('thread-' + threadId)?.closest('.card');
            if (row) row.remove();
        }
    } catch(e) { alert('Fehler beim Löschen.'); }
}

// ── Toggle thread ─────────────────────────────────────────────────────────────
function toggleThread(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function showErr(el, msg) { if (el) { el.textContent = msg; el.style.display = 'block'; } }

// ── Buchungs-Minikalender ─────────────────────────────────────────────────────
const nbBlockedDates  = <?= json_encode($nbBlockedDates,  JSON_UNESCAPED_UNICODE) ?>;
const nbBlockedRanges = <?= json_encode($nbBlockedRanges, JSON_UNESCAPED_UNICODE) ?>;
let nbBookedDates = [], nbPendingDates = [];
let nbCurDate = new Date(); nbCurDate.setDate(1);
let nbSelected = null;
const nbMonths = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
const nbDayHd  = ['Mo','Di','Mi','Do','Fr','Sa','So'];

function nbIsBlocked(ds) {
    if (nbBlockedDates.includes(ds)) return true;
    const d = new Date(ds + 'T00:00:00');
    for (const r of nbBlockedRanges)
        if (r.from && r.to && d >= new Date(r.from+'T00:00:00') && d <= new Date(r.to+'T00:00:00')) return true;
    return false;
}
async function nbLoadData() {
    try {
        const res = await fetch('/calendar-data.php');
        const d   = await res.json();
        nbBookedDates  = d.confirmed || [];
        nbPendingDates = d.pending   || [];
    } catch(e) {}
    nbRenderCal();
}
function nbRenderCal() {
    const y = nbCurDate.getFullYear(), m = nbCurDate.getMonth();
    document.getElementById('nbMonthLabel').textContent = nbMonths[m] + ' ' + y;
    const grid = document.getElementById('nbCalGrid');
    if (!grid) return;
    grid.innerHTML = '';
    nbDayHd.forEach(d => {
        const h = document.createElement('div');
        h.textContent = d;
        h.style.cssText = 'font-weight:700;color:#888;padding:2px 0;font-size:0.7rem;text-align:center';
        grid.appendChild(h);
    });
    const startDow = (new Date(y, m, 1).getDay() + 6) % 7;
    for (let i = 0; i < startDow; i++) grid.appendChild(document.createElement('div'));
    const today = new Date(); today.setHours(0,0,0,0);
    const pad = n => String(n).padStart(2,'0');
    for (let day = 1; day <= new Date(y, m+1, 0).getDate(); day++) {
        const dt = new Date(y, m, day);
        const ds = `${y}-${pad(m+1)}-${pad(day)}`;
        const el = document.createElement('div');
        el.textContent = day;
        el.style.cssText = 'padding:5px 2px;border-radius:5px;font-size:0.78rem;text-align:center;transition:background .1s';
        const isPast    = dt < today;
        const isBlocked = !isPast && nbIsBlocked(ds);
        const isBooked  = !isPast && !isBlocked && nbBookedDates.includes(ds);
        const isPending = !isPast && !isBlocked && !isBooked && nbPendingDates.includes(ds);
        const isSel     = ds === nbSelected;
        if      (isPast)    { el.style.cssText += ';color:#ccc;cursor:default'; }
        else if (isBlocked) { el.style.cssText += ';background:#e0e0e0;color:#9e9e9e;cursor:not-allowed'; el.title='Gesperrt'; }
        else if (isBooked)  { el.style.cssText += ';background:#ef9a9a;color:#b71c1c;cursor:not-allowed'; el.title='Bereits vergeben'; }
        else if (isPending) { el.style.cssText += ';background:#fff9c4;color:#e65100;border:1px solid #f9a825;cursor:not-allowed'; el.title='Anfrage ausstehend'; }
        else if (isSel)     { el.style.cssText += ';background:#3d6b41;color:#fff;font-weight:700;cursor:default'; }
        else {
            el.style.cssText += ';background:#f0f7f0;color:#2d3e2d;cursor:pointer';
            el.onmouseenter = () => { el.style.background='#c8e6c9'; };
            el.onmouseleave = () => { el.style.background='#f0f7f0'; };
            el.addEventListener('click', () => {
                nbSelected = ds;
                document.getElementById('nb_date').value = ds;
                const dObj = new Date(ds+'T00:00:00');
                document.getElementById('nbSelLabel').textContent = 'Gewählt: ' + dObj.toLocaleDateString('de-DE',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
                nbRenderCal();
            });
        }
        grid.appendChild(el);
    }
}
function nbPrevMonth() { nbCurDate.setMonth(nbCurDate.getMonth()-1); nbRenderCal(); }
function nbNextMonth() { nbCurDate.setMonth(nbCurDate.getMonth()+1); nbRenderCal(); }
document.addEventListener('DOMContentLoaded', () => {
    const bBtn = document.querySelector('.tab-btn[onclick*="buchungen"]');
    if (bBtn) bBtn.addEventListener('click', () => { nbBookedDates.length || nbPendingDates.length ? nbRenderCal() : nbLoadData(); });
    if (document.getElementById('tab-buchungen')?.classList.contains('active')) nbLoadData();
    else nbRenderCal();
});

// ── Belegungskalender Vereinshaus ────────────────────────────────────────────
let vcConfirmed = [], vcPending = [], vcLoaded = false;
let vcCurDate = new Date(); vcCurDate.setDate(1);

function vcIsBlocked(ds) {
    if (nbBlockedDates.includes(ds)) return true;
    const d = new Date(ds + 'T00:00:00');
    for (const r of nbBlockedRanges)
        if (r.from && r.to && d >= new Date(r.from+'T00:00:00') && d <= new Date(r.to+'T00:00:00')) return true;
    return false;
}

async function vcLoadData() {
    if (vcLoaded) { vcRender(); return; }
    try {
        const res = await fetch('/calendar-data.php');
        const d   = await res.json();
        vcConfirmed = d.confirmed || [];
        vcPending   = d.pending   || [];
        vcLoaded = true;
    } catch(e) {}
    vcRender();
}

function vcRender() {
    const y = vcCurDate.getFullYear(), m = vcCurDate.getMonth();
    const months = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    const lbl = document.getElementById('vcMonthLabel');
    if (lbl) lbl.textContent = months[m] + ' ' + y;
    const grid = document.getElementById('vcCalGrid');
    if (!grid) return;
    grid.innerHTML = '';
    const pad = n => String(n).padStart(2,'0');
    const today = new Date(); today.setHours(0,0,0,0);
    const startDow = (new Date(y, m, 1).getDay() + 6) % 7;
    for (let i = 0; i < startDow; i++) grid.appendChild(document.createElement('div'));
    for (let day = 1; day <= new Date(y, m+1, 0).getDate(); day++) {
        const dt  = new Date(y, m, day);
        const ds  = `${y}-${pad(m+1)}-${pad(day)}`;
        const el  = document.createElement('div');
        el.textContent = day;
        const isPast      = dt < today;
        const isConfirmed = vcConfirmed.includes(ds);
        const isPending   = !isConfirmed && vcPending.includes(ds);
        const isBlocked   = vcIsBlocked(ds);
        let bg = '#f0f7f0', border = '1px solid #c8e6c9', color = '#2d3e2d', fw = '400';
        if (isPast)        { bg = 'none'; border = 'none'; color = '#ccc'; }
        else if (isConfirmed) { bg = '#ef9a9a'; border = 'none'; color = '#b71c1c'; fw = '700'; el.title = 'Vermietet – Abkippstation gesperrt'; }
        else if (isPending)   { bg = '#fff9c4'; border = '1px solid #f9a825'; color = '#e65100'; el.title = 'Buchungsanfrage läuft'; }
        else if (isBlocked)   { bg = '#e0e0e0'; border = 'none'; color = '#9e9e9e'; el.title = 'Gesperrt'; }
        el.style.cssText = `padding:6px 2px;border-radius:6px;font-size:0.82rem;background:${bg};border:${border};color:${color};font-weight:${fw}`;
        grid.appendChild(el);
    }
}

document.getElementById('vcPrevBtn')?.addEventListener('click', () => { vcCurDate.setMonth(vcCurDate.getMonth()-1); vcRender(); });
document.getElementById('vcNextBtn')?.addEventListener('click', () => { vcCurDate.setMonth(vcCurDate.getMonth()+1); vcRender(); });
document.addEventListener('DOMContentLoaded', () => {
    const bBtn = document.querySelector('.tab-btn[onclick*="buchungen"]');
    if (bBtn) bBtn.addEventListener('click', () => vcLoadData());
    if (document.getElementById('tab-buchungen')?.classList.contains('active')) vcLoadData();
    else vcRender();
});

// ── Mitglieder-Direktbuchung ──────────────────────────────────────────────────
async function doMemberBooking() {
    const date    = document.getElementById('nb_date')?.value;
    const guests  = document.getElementById('nb_guests')?.value;
    const purpose = document.getElementById('nb_purpose')?.value.trim();
    const errEl   = document.getElementById('nbError');
    const sucEl   = document.getElementById('nbSuccess');
    if (errEl) errEl.style.display = 'none';
    if (sucEl) sucEl.style.display = 'none';
    if (!date)    { showErr(errEl, 'Bitte ein Datum wählen.'); return; }
    if (!guests || parseInt(guests) < 1) { showErr(errEl, 'Bitte Personenanzahl angeben.'); return; }
    if (!purpose) { showErr(errEl, 'Bitte einen Anlass eingeben.'); return; }
    const r = await wafFetch('/booking.php', {
        name:    <?= json_encode($member['name'] ?? '') ?>,
        email:   <?= json_encode($member['email'] ?? '') ?>,
        phone:   <?= json_encode($memberFull['phone'] ?? '') ?>,
        purpose: purpose,
        guests:  parseInt(guests),
        dates:   [date],
    });
    const d = await r.json().catch(() => ({}));
    if (d.status === 'ok') {
        if (sucEl) { sucEl.textContent = '✅ Buchungsanfrage gesendet! Sie erhalten eine Bestätigung per E-Mail.'; sucEl.style.display = 'block'; }
        document.getElementById('nb_date').value    = '';
        document.getElementById('nb_guests').value  = '';
        document.getElementById('nb_purpose').value = '';
        setTimeout(() => location.reload(), 2000);
    } else {
        const msgs = {
            date_unavailable: 'Dieser Termin ist leider bereits vergeben.',
            date_blocked:     'Dieser Termin ist gesperrt.',
            date_in_past:     'Das Datum liegt in der Vergangenheit.',
            rate_limited:     'Zu viele Anfragen. Bitte später erneut versuchen.',
        };
        showErr(errEl, msgs[d.message] || 'Fehler beim Senden. Bitte erneut versuchen.');
    }
}

function toggleKoppelForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}

// ── Phonelist: disable selection & print ─────────────────────────────────────
(function() {
    const style = document.createElement('style');
    style.textContent = `
        #tab-telefonliste { -webkit-user-select:none; -moz-user-select:none; user-select:none; }
        @media print { #tab-telefonliste, .phonelist-wrap { display:none !important; } }
    `;
    document.head.appendChild(style);
})();

// Init phonelist protection if tab is active on load
<?php if ($activeTab === 'telefonliste'): ?>
activatePhonelistProtection();
<?php endif; ?>
</script>

<!-- PWA: Install Banner -->
<div id="pwa-banner" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;
     background:#2d5231;color:#fff;padding:14px 16px;
     align-items:center;justify-content:space-between;gap:12px;
     box-shadow:0 -2px 12px rgba(0,0,0,.3);">
  <div style="flex:1;font-size:14px;line-height:1.3">
    <strong>KGV Musterstadt installieren</strong><br>
    <span style="opacity:.85;font-size:12px">App auf dem Homescreen speichern</span>
  </div>
  <button id="pwa-install-btn" style="background:#4a7c4e;color:#fff;border:none;border-radius:8px;
          padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">
    Installieren
  </button>
  <button id="pwa-dismiss-btn" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4);
          border-radius:8px;padding:10px 12px;font-size:13px;cursor:pointer;white-space:nowrap">
    Nicht jetzt
  </button>
</div>

<script>
// Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}

// Install banner
(function() {
    if (localStorage.getItem('pwa-dismissed')) return;
    let deferredPrompt = null;
    const banner = document.getElementById('pwa-banner');

    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        deferredPrompt = e;
        banner.style.display = 'flex';
        banner.style.alignItems = 'center';
        banner.style.justifyContent = 'space-between';
        banner.style.gap = '12px';
    });

    document.getElementById('pwa-install-btn').addEventListener('click', () => {
        banner.style.display = 'none';
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(() => { deferredPrompt = null; });
        }
    });

    document.getElementById('pwa-dismiss-btn').addEventListener('click', () => {
        banner.style.display = 'none';
        localStorage.setItem('pwa-dismissed', '1');
    });

    window.addEventListener('appinstalled', () => { banner.style.display = 'none'; });
})();
</script>
</body>
</html>
