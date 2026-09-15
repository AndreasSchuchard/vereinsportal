<?php
declare(strict_types=1);

// ── Secure session configuration ──────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies','1');
ini_set('session.use_strict_mode', '1');
session_start();

// ADMIN_PASS-Default entfernt — Login geht ausschließlich über $_settings['password_hash'].
define('SESSION_TIMEOUT', 3600);          // 60 Minuten Inaktivitäts-Timeout
define('BOOKINGS_FILE',   dirname(__DIR__) . '/data/bookings.json');
define('SETTINGS_FILE',   dirname(__DIR__) . '/data/settings.json');

// ── Auth: Admin-Passwort ODER Mitglied mit Rolle ──────────────────────────────
$isSuperAdmin = !empty($_SESSION['kgv_admin']);
$_memberRoles = $_SESSION['kgv_member']['roles'] ?? [];
$_elevated    = ['vorstand', 'buchung', 'schriftfuehrer', 'web'];
$isMemberAdmin = !$isSuperAdmin && !empty(array_intersect($_memberRoles, $_elevated));

// Determine effective role for UI visibility
if ($isSuperAdmin) {
    $effectiveRole = 'superadmin';
} elseif (in_array('vorstand', $_memberRoles, true)) {
    $effectiveRole = 'vorstand';
} elseif (in_array('schriftfuehrer', $_memberRoles, true)) {
    $effectiveRole = 'schriftfuehrer';
} elseif (in_array('buchung', $_memberRoles, true)) {
    $effectiveRole = 'buchung';
} elseif (in_array('web', $_memberRoles, true)) {
    $effectiveRole = 'web';
} else {
    $effectiveRole = 'none';
}
$canSeeBookings   = in_array($effectiveRole, ['superadmin','vorstand','buchung','schriftfuehrer','web']);
$canDeleteBooking = $isSuperAdmin || !empty(array_intersect($_memberRoles, ['vorstand', 'buchung']));
$canSeeContacts = in_array($effectiveRole, ['superadmin','vorstand','schriftfuehrer','web']);
$canSeeCalendar = in_array($effectiveRole, ['superadmin','vorstand','buchung','schriftfuehrer','web']);
$canSeeMembers  = in_array($effectiveRole, ['superadmin','vorstand','schriftfuehrer','web']);
$canEditContent = in_array($effectiveRole, ['superadmin','vorstand','web']);
$canManageRoles = in_array($effectiveRole, ['superadmin','vorstand','web']);
$canWriteTodo   = $isSuperAdmin || in_array('vorstand', $_memberRoles, true) || in_array('web', $_memberRoles, true);

// ── Session timeout ───────────────────────────────────────────────────────────
if ($isSuperAdmin && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset(); session_destroy();
    header('Location: /intern/?timeout=1'); exit;
}
if ($isSuperAdmin) $_SESSION['last_activity'] = time();
if ($isMemberAdmin && !empty($_SESSION['member_last_activity']) && (time() - $_SESSION['member_last_activity']) > 3600) {
    session_unset(); session_destroy();
    header('Location: /mitglieder.php?timeout=1'); exit;
}
if ($isMemberAdmin) $_SESSION['member_last_activity'] = time();

// ── Logout ────────────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    $wasMember = $isMemberAdmin;
    session_unset(); session_destroy();
    header('Location: ' . ($wasMember ? '/mitglieder.php' : '/intern/')); exit;
}

// ── Login (Admin-Passwort) ─────────────────────────────────────────────────────
$loginError    = false;
$loginCsrfFail = false;

if (!$isSuperAdmin && !$isMemberAdmin && !empty($_POST['password'])) {
    // Rate-limit: max 5 attempts per 15 min per IP
    $_ip     = md5((string)($_SERVER['REMOTE_ADDR'] ?? 'x'));
    $_rlFile = dirname(__DIR__) . '/data/rl_adm_' . $_ip . '.json';
    $_now    = time();
    $_hits   = [];
    if (file_exists($_rlFile)) {
        $_d = json_decode((string)file_get_contents($_rlFile), true);
        if (is_array($_d)) $_hits = array_values(array_filter($_d, fn($t) => is_int($t) && ($_now - $t) < 900));
    }
    $loginToken = $_POST['login_token'] ?? '';
    if (!isset($_SESSION['login_token']) || !hash_equals($_SESSION['login_token'], $loginToken)) {
        $loginCsrfFail = true;
    } elseif (count($_hits) >= 5) {
        $loginError = true; // rate limited — show generic error
    } else {
        require_once dirname(__DIR__) . '/inc/settings_loader.php';
        $storedSettings = load_settings();
        $storedHash     = (string)($storedSettings['password_hash'] ?? '');
        // Kein Fallback mehr — ohne password_hash in settings.json ist Login deaktiviert.
        $passwordValid  = ($storedHash !== '') && password_verify((string)$_POST['password'], $storedHash);
    }
    if (!$loginCsrfFail && ($passwordValid ?? false)) {
        session_regenerate_id(true);
        $_SESSION['kgv_admin']     = true;
        $_SESSION['csrf']          = bin2hex(random_bytes(16));
        $_SESSION['last_activity'] = time();
        unset($_SESSION['login_token']);
        header('Location: /intern/'); exit;
    } elseif (!$loginCsrfFail) {
        $loginError = true;
        $_hits[] = $_now;
        file_put_contents($_rlFile, json_encode($_hits), LOCK_EX);
    }
}
if (empty($_SESSION['login_token'])) {
    $_SESSION['login_token'] = bin2hex(random_bytes(16));
}

$loggedIn = $isSuperAdmin || $isMemberAdmin;
$usingDefaultPass = $isSuperAdmin && (function() {
    require_once dirname(__DIR__) . '/inc/settings_loader.php';
    $s = load_settings();
    return empty($s['password_hash']);
})();

$bookings = [];
$stats    = ['pending' => 0, 'confirmed' => 0, 'rejected' => 0, 'expired' => 0];

if ($loggedIn) {
    // 24h Auto-Expire pending bookings (lazy cleanup beim Backoffice-Aufruf)
    require_once dirname(__DIR__) . '/inc/booking_expire.php';
    $_expiredCount = kgv_expire_old_pending_bookings(
        BOOKINGS_FILE,
        24,
        dirname(__DIR__) . '/data/admin.log'
    );

    if (file_exists(BOOKINGS_FILE)) {
        $raw = json_decode((string)file_get_contents(BOOKINGS_FILE), true);
        if (is_array($raw)) $bookings = $raw;
    }
    foreach ($bookings as $b) {
        $s = $b['status'] ?? 'pending';
        if (isset($stats[$s])) $stats[$s]++;
    }
    usort($bookings, function($a, $b) {
        $ord = ['pending' => 0, 'confirmed' => 1, 'expired' => 2, 'rejected' => 3];
        $sa  = $ord[$a['status'] ?? ''] ?? 4;
        $sb  = $ord[$b['status'] ?? ''] ?? 4;
        if ($sa !== $sb) return $sa - $sb;
        $da  = ($a['dates'][0] ?? $a['date'] ?? '9999');
        $db  = ($b['dates'][0] ?? $b['date'] ?? '9999');
        return strcmp($da, $db);
    });

    // Finanzberechnung
    $finance = [
        'revenue'          => 0.0,
        'revenue_miete'    => 0.0,
        'revenue_extras'   => 0.0,
        'kaution_open'     => 0.0,
        'kaution_returned' => 0.0,
        'paid_count'       => 0,
        'paid_rows'        => [],
        'extras_stats'     => [],   // label => [count, revenue]
        'monthly'          => [],   // YYYY-MM => [count, revenue_miete, revenue_extras]
    ];
    foreach ($bookings as $b) {
        if (($b['status'] ?? '') !== 'confirmed' || empty($b['paid_at'])) continue;
        $finance['paid_count']++;
        $extrasNet = 0.0;
        foreach ($b['extras'] ?? [] as $ex) {
            $sub = (float)($ex['price'] ?? 0) * (int)($ex['qty'] ?? 1);
            $extrasNet += $sub;
            $label = trim($ex['label'] ?? 'Sonstiges');
            if (!isset($finance['extras_stats'][$label])) $finance['extras_stats'][$label] = ['count' => 0, 'qty' => 0, 'revenue' => 0.0];
            $finance['extras_stats'][$label]['count']++;
            $finance['extras_stats'][$label]['qty']     += (int)($ex['qty'] ?? 1);
            $finance['extras_stats'][$label]['revenue'] += $sub;
        }
        $raummiete = (float)($b['raummiete'] ?? 300.0);
        $net = $raummiete + $extrasNet;
        $finance['revenue']        += $net;
        $finance['revenue_miete']  += $raummiete;
        $finance['revenue_extras'] += $extrasNet;
        if (empty($b['kaution_returned_at'])) {
            $finance['kaution_open'] += 200.0;
        } else {
            $finance['kaution_returned'] += (float)($b['kaution_settlement']['returned_amount'] ?? 200.0);
        }
        // Monatliche Aufschlüsselung (nach Bezahldatum)
        $month = substr($b['paid_at'], 0, 7); // YYYY-MM
        if (!isset($finance['monthly'][$month])) $finance['monthly'][$month] = ['count' => 0, 'miete' => 0.0, 'extras' => 0.0];
        $finance['monthly'][$month]['count']++;
        $finance['monthly'][$month]['miete']  += $raummiete;
        $finance['monthly'][$month]['extras'] += $extrasNet;

        $finance['paid_rows'][] = [
            'id'                 => $b['id'] ?? '',
            'name'               => $b['name'] ?? '',
            'dates'              => formatDates($b),
            'net'                => $net,
            'raummiete'          => $raummiete,
            'is_member_tarif'    => !empty($b['is_member_tarif']),
            'paid_at'            => $b['paid_at'],
            'kaution_returned_at'=> $b['kaution_returned_at'] ?? '',
            'kaution_settlement' => $b['kaution_settlement'] ?? null,
        ];
    }
    // Extras nach Häufigkeit sortieren
    uasort($finance['extras_stats'], fn($a, $b) => $b['count'] - $a['count']);
    // Monate chronologisch
    ksort($finance['monthly']);
}

function formatDates(array $b): string {
    $dates = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    if (count($dates) === 0) return '—';
    if (count($dates) === 1) {
        $d = DateTime::createFromFormat('Y-m-d', $dates[0]);
        return $d ? $d->format('d.m.Y') : $dates[0];
    }
    $first = DateTime::createFromFormat('Y-m-d', $dates[0]);
    $last  = DateTime::createFromFormat('Y-m-d', end($dates));
    return ($first ? $first->format('d.m.Y') : $dates[0]) . ' – ' . ($last ? $last->format('d.m.Y') : end($dates)) . ' (' . count($dates) . ' Tage)';
}

function formatExtras(array $extras): string {
    if (empty($extras)) return '';
    $rows = '';
    $total = 300;
    foreach ($extras as $e) {
        $price = (float)($e['price'] ?? 0);
        $qty   = (int)($e['qty'] ?? 1);
        $label = htmlspecialchars($e['label'] ?? '');
        $sub   = $price * $qty;
        $total += $sub;
        $rows .= "<tr><td style='padding:5px 0;color:#5a6c5a;font-size:0.85rem;'>{$label}" . ($qty > 1 ? " × {$qty}" : '') . "</td>"
               . "<td style='padding:5px 0;text-align:right;color:#2d3e2d;font-size:0.85rem;'>" . number_format($sub, 2, ',', '.') . " €</td></tr>";
    }
    return $rows;
}

$statusLabel = ['pending' => 'Offen', 'confirmed' => 'Bestätigt', 'rejected' => 'Abgelehnt', 'expired' => 'Abgelaufen (24h)'];
$statusColor = ['pending' => '#e65100', 'confirmed' => '#2e7d32', 'rejected' => '#616161', 'expired' => '#8a8a8a'];
$statusBg    = ['pending' => '#fff3e0', 'confirmed' => '#e8f5e9', 'rejected' => '#f5f5f5', 'expired' => '#fafafa'];

// CSRF-Token für Member-Admins generieren falls noch nicht vorhanden
if ($isMemberAdmin && empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'] ?? '';

require_once dirname(__DIR__) . '/inc/email_template.php';
// ── Mitglieder ─────────────────────────────────────────────────────────────
define('MEMBERS_FILE',   dirname(__DIR__) . '/data/members.json');
define('REQUESTS_FILE',  dirname(__DIR__) . '/data/member_requests.json');
define('MESSAGES_FILE',  dirname(__DIR__) . '/data/member_messages.json');
define('ACTIVITY_FILE',  dirname(__DIR__) . '/data/activity.json');
define('ARBEIT_FILE',    dirname(__DIR__) . '/data/arbeitsstunden.json');


if ($loggedIn && $canSeeMembers && !empty($_POST['member_action'])) {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $mAction = (string)($_POST['member_action'] ?? '');
        $reqId   = trim((string)($_POST['req_id']   ?? ''));
        $memId   = trim((string)($_POST['mem_id']   ?? ''));

        // Sensible Aktionen brauchen $canManageRoles (vorstand/web/superadmin)
        $_writeRoles = ['approve_request','reject_request','toggle_member','delete_member','reset_password','delete_request'];
        if (in_array($mAction, $_writeRoles, true) && !$canManageRoles) {
            header('Location: /intern/?tab=members'); exit;
        }

        if ($mAction === 'approve_request' && $reqId !== '') {
            $reqs = file_exists(REQUESTS_FILE) ? (json_decode((string)file_get_contents(REQUESTS_FILE), true) ?: []) : [];
            $mems = file_exists(MEMBERS_FILE)  ? (json_decode((string)file_get_contents(MEMBERS_FILE),  true) ?: []) : [];
            foreach ($reqs as &$req) {
                if (($req['id'] ?? '') !== $reqId || ($req['status'] ?? '') !== 'pending') continue;
                $req['status'] = 'approved';
                // Kein Klartext-Passwort mehr in der Welcome-Mail: Setup-Link mit Token (7 Tage gültig).
                // Account-Hash wird auf einen langen Random-String gesetzt, den niemand kennt — Login geht
                // erst nach Setup über den Token.
                $setupToken = bin2hex(random_bytes(32));
                $pwHash     = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $now        = date('Y-m-d H:i:s');
                $mems[] = [
                    'id'           => 'm_' . uniqid('', true),
                    'name'         => $req['name'],
                    'email'        => $req['email'],
                    'phone'        => $req['phone'] ?? '',
                    'parzelle'     => $req['parzelle'],
                    'rolle_typ'    => $req['rolle_typ'] ?? 'paechter',
                    'password_hash'=> $pwHash,
                    'active'               => true,
                    'must_change_password' => true,
                    'reset_token'          => $setupToken,
                    'reset_token_expires'  => time() + 7 * 24 * 3600, // 7 Tage
                    'created_at'           => $now,
                    'activated_at'         => $now,
                    'last_login'           => '',
                    'consents'     => [
                        'contact_allowed'         => (bool)($req['consent_contact']   ?? false),
                        'in_phonelist'             => (bool)($req['consent_phonelist'] ?? false),
                        'contact_allowed_at'       => (bool)($req['consent_contact']   ?? false) ? $now : null,
                        'in_phonelist_at'          => (bool)($req['consent_phonelist'] ?? false) ? $now : null,
                        'contact_revoked_at'       => null,
                        'in_phonelist_revoked_at'  => null,
                    ],
                ];
                // Setup-Link für Welcome-Mail
                $_setupLink = site_url() . '/mitglieder.php?reset_token=' . urlencode($setupToken);
                $_from = 'kontakt@example.org';
                $_subj = 'Ihr Mitgliederzugang KGV Musterstadt – Passwort einrichten';
                $_text = "Liebe/r {$req['name']},\n\nIhr Zugang zum Mitgliederbereich des KGV Musterstadt e.V. wurde freigeschaltet. 🎉\n\nBitte klicken Sie auf den folgenden Link, um Ihr persönliches Passwort einzurichten (gültig 7 Tage):\n\n{$_setupLink}\n\nLogin nach Einrichtung: " . site_url() . "/mitglieder.php\nE-Mail: {$req['email']}\n\nMit freundlichen Grüßen\nKGV Musterstadt e.V.";
                $_activContent =
                    "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:18px'>Dein Zugang zum Mitgliederbereich ist freigeschaltet! 🎉</p>"
                  . "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:14px'>Bitte richte jetzt dein persönliches Passwort ein. Der Link ist <strong>7 Tage gültig</strong>:</p>"
                  . "<p style='margin:22px 0;text-align:center'><a href='" . htmlspecialchars($_setupLink) . "' style='display:inline-block;background:#3d6b41;color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:700'>Passwort jetzt einrichten →</a></p>"
                  . "<table style='width:100%;border-collapse:collapse;margin-bottom:18px;font-size:0.9rem'>"
                  . "<tr><td style='padding:6px 0;color:#5a6c5a'>Login (nach Einrichtung)</td><td style='padding:6px 0'><a href='" . site_url() . "/mitglieder.php' style='color:#3d6b41;font-weight:600'>Mitgliederbereich</a></td></tr>"
                  . "<tr><td style='padding:6px 0;color:#5a6c5a;border-top:1px solid #e8f0e0'>E-Mail</td><td style='padding:6px 0;border-top:1px solid #e8f0e0'>" . htmlspecialchars($req['email']) . "</td></tr>"
                  . "</table>"
                  . "<p style='font-size:0.83rem;color:#8a9a8a;line-height:1.6'>Falls der Link in deinem Mail-Programm nicht funktioniert, kopiere ihn bitte in die Adresszeile deines Browsers:<br><span style='font-family:monospace;word-break:break-all'>" . htmlspecialchars($_setupLink) . "</span></p>";
                $_html = kgv_email_html(
                    'Hallo ' . htmlspecialchars($req['name']) . ' 👋,',
                    $_activContent,
                    'Willkommen im Mitgliederbereich'
                );
                $_hdr  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$_from}>\r\nReturn-Path: {$_from}\r\n";
                $_mailOk = @mail($req['email'], '=?UTF-8?B?' . base64_encode($_subj) . '?=', $_html, $_hdr, "-f{$_from}");
                error_log("[admin] approve member={$req['email']} setup-link sent mail=" . ($_mailOk ? 'ok' : 'fail') . "\n", 3, dirname(__DIR__) . '/data/admin.log');
                break;
            }
            unset($req);
            file_put_contents(REQUESTS_FILE, json_encode($reqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            file_put_contents(MEMBERS_FILE,  json_encode($mems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            kgv_log_action('Mitglied genehmigt', $req['name'] ?? $reqId);

        } elseif ($mAction === 'reject_request' && $reqId !== '') {
            $reqs = file_exists(REQUESTS_FILE) ? (json_decode((string)file_get_contents(REQUESTS_FILE), true) ?: []) : [];
            $_rejName = '';
            foreach ($reqs as &$req) {
                if (($req['id'] ?? '') === $reqId) { $_rejName = $req['name'] ?? ''; $req['status'] = 'rejected'; break; }
            }
            unset($req);
            file_put_contents(REQUESTS_FILE, json_encode($reqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            kgv_log_action('Anfrage abgelehnt', $_rejName);

        } elseif ($mAction === 'toggle_member' && $memId !== '') {
            $mems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
            $_togName = ''; $_togNew = false;
            foreach ($mems as &$mem) {
                if (($mem['id'] ?? '') === $memId) { $mem['active'] = !($mem['active'] ?? true); $_togName = $mem['name'] ?? ''; $_togNew = $mem['active']; break; }
            }
            unset($mem);
            file_put_contents(MEMBERS_FILE, json_encode($mems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            kgv_log_action($_togNew ? 'Mitglied entsperrt' : 'Mitglied gesperrt', $_togName);

        } elseif ($mAction === 'delete_member' && $memId !== '') {
            $mems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
            $_delName = ''; foreach ($mems as $_dm) { if (($_dm['id'] ?? '') === $memId) { $_delName = $_dm['name'] ?? ''; break; } }
            $mems = array_values(array_filter($mems, fn($m) => ($m['id'] ?? '') !== $memId));
            file_put_contents(MEMBERS_FILE, json_encode($mems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            kgv_log_action('Mitglied gelöscht', $_delName);

        } elseif ($mAction === 'reset_password' && $memId !== '') {
            $mems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
            foreach ($mems as &$mem) {
                if (($mem['id'] ?? '') !== $memId) continue;
                // Kein Klartext-Passwort mehr — Reset-Link mit Token (1 Stunde gültig, analog member-api/reset_password.php).
                // Aktuelles Passwort wird sofort entwertet (neuer Random-Hash), Account ist erst nach Link-Click wieder nutzbar.
                $resetToken            = bin2hex(random_bytes(32));
                $mem['password_hash']  = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $mem['reset_token']         = $resetToken;
                $mem['reset_token_expires'] = time() + 3600;
                $mem['must_change_password'] = true;
                $_resetLink = site_url() . '/mitglieder.php?reset_token=' . urlencode($resetToken);
                $_from = 'kontakt@example.org';
                $_subj = 'Passwort zurücksetzen – KGV Musterstadt Mitgliederbereich';
                $_pwContent =
                    "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:14px'>Dein Passwort wurde durch den Vorstand zurückgesetzt.</p>"
                  . "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:14px'>Bitte klicke auf den folgenden Link, um ein neues Passwort zu setzen (gültig <strong>1 Stunde</strong>):</p>"
                  . "<p style='margin:22px 0;text-align:center'><a href='" . htmlspecialchars($_resetLink) . "' style='display:inline-block;background:#3d6b41;color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:700'>Neues Passwort setzen →</a></p>"
                  . "<p style='font-size:0.82rem;color:#8a9a8a;line-height:1.6'>Falls der Link nicht funktioniert, kopiere ihn in die Adresszeile deines Browsers:<br><span style='font-family:monospace;word-break:break-all'>" . htmlspecialchars($_resetLink) . "</span></p>";
                $_html = kgv_email_html(
                    'Hallo ' . htmlspecialchars($mem['name']) . ',',
                    $_pwContent,
                    'Passwort zurückgesetzt'
                );
                $_hdr  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$_from}>\r\nReturn-Path: {$_from}\r\n";
                @mail($mem['email'], $_subj, $_html, $_hdr, "-f{$_from}");
                error_log("[admin] reset_password id={$mem['id']} link-sent\n", 3, dirname(__DIR__) . '/data/admin.log');
                break;
            }
            unset($mem);
            file_put_contents(MEMBERS_FILE, json_encode($mems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        } elseif ($mAction === 'save_member_roles' && $canManageRoles) {
            $mems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
            $allowed   = ['mitglied','vorstand','buchung','schriftfuehrer','web','koppel','kassier'];
            $sigRolle  = substr(trim(strip_tags((string)($_POST['sig_rolle'] ?? ''))), 0, 100);
            foreach ($mems as &$mem) {
                if (($mem['id'] ?? '') !== $memId) continue;
                $posted = (array)($_POST['roles'] ?? []);
                $mem['roles']     = array_values(array_intersect($posted, $allowed));
                if (empty($mem['roles'])) $mem['roles'] = ['mitglied'];
                $mem['sig_rolle'] = $sigRolle;
                // Update session if admin is editing their own profile
                if (!empty($_SESSION['kgv_member']['id']) && $_SESSION['kgv_member']['id'] === $memId) {
                    $_SESSION['kgv_member']['sig_rolle'] = $sigRolle;
                }
                break;
            }
            unset($mem);
            file_put_contents(MEMBERS_FILE, json_encode($mems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        } elseif ($mAction === 'mark_message_read') {
            $threadId = trim((string)($_POST['thread_id'] ?? ''));
            if ($threadId !== '') {
                $allMsgs = file_exists(MESSAGES_FILE) ? (json_decode((string)file_get_contents(MESSAGES_FILE), true) ?: []) : [];
                foreach ($allMsgs as &$_t) {
                    if (($_t['id'] ?? '') === $threadId) {
                        $_t['status']     = 'answered';
                        $_t['updated_at'] = date('Y-m-d H:i:s');
                        break;
                    }
                }
                unset($_t);
                file_put_contents(MESSAGES_FILE, json_encode($allMsgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                kgv_log_action('Nachricht als gelesen markiert', $threadId);
            }
            header('Location: /intern/?tab=members');
            exit;

        } elseif ($mAction === 'delete_message') {
            $threadId = trim((string)($_POST['thread_id'] ?? ''));
            if ($threadId !== '') {
                $allMsgs = file_exists(MESSAGES_FILE) ? (json_decode((string)file_get_contents(MESSAGES_FILE), true) ?: []) : [];
                foreach ($allMsgs as &$_t) {
                    if (($_t['id'] ?? '') === $threadId) { $_t['deleted_by_admin'] = true; break; }
                }
                unset($_t);
                file_put_contents(MESSAGES_FILE, json_encode($allMsgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            header('Location: /intern/?tab=members');
            exit;

        } elseif ($mAction === 'reply_message') {
            $threadId  = trim((string)($_POST['thread_id'] ?? ''));
            $replyBody = trim((string)($_POST['reply_body'] ?? ''));
            if ($threadId !== '' && $replyBody !== '') {
                // Handle optional file attachment from admin
                $adminFileInfo = null;
                if (!empty($_FILES['reply_file']) && $_FILES['reply_file']['error'] === UPLOAD_ERR_OK) {
                    $filesDir = dirname(__DIR__) . '/data/member_msg_files';
                    if (!is_dir($filesDir)) mkdir($filesDir, 0700, true);
                    $origName = basename((string)$_FILES['reply_file']['name']);
                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $allowedExts  = ['jpg','jpeg','png','gif','webp','pdf','doc','docx'];
                    $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf',
                                     'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    $detectedMime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['reply_file']['tmp_name']);
                    if (in_array($ext, $allowedExts, true) && in_array($detectedMime, $allowedMimes, true) && (int)$_FILES['reply_file']['size'] <= 5 * 1024 * 1024) {
                        $storedName = 'mf_' . uniqid('', true) . '.' . $ext;
                        if (move_uploaded_file($_FILES['reply_file']['tmp_name'], $filesDir . '/' . $storedName)) {
                            $adminFileInfo = ['orig' => $origName, 'stored' => $storedName, 'mime' => $detectedMime, 'size' => (int)$_FILES['reply_file']['size']];
                        }
                    }
                }
                $allMsgs = file_exists(MESSAGES_FILE) ? (json_decode((string)file_get_contents(MESSAGES_FILE), true) ?: []) : [];
                $memberEmail    = '';
                $memberName     = '';
                $threadSubj     = '';
                $threadMessages = [];
                foreach ($allMsgs as &$thr) {
                    if (($thr['id'] ?? '') !== $threadId) continue;
                    $threadMessages = $thr['messages'] ?? []; // history BEFORE new reply
                    $_sig = kgv_get_admin_sig();
                    $newAdminMsg = ['from' => 'admin', 'admin_name' => $_sig['name'], 'body' => $replyBody, 'created_at' => date('Y-m-d H:i:s')];
                    if ($adminFileInfo !== null) $newAdminMsg['file'] = $adminFileInfo;
                    if (empty($thr['first_reply_by_id'])) {
                        $thr['first_reply_by_id']   = $isSuperAdmin ? 'admin' : ($_SESSION['kgv_member']['id'] ?? 'admin');
                        $thr['first_reply_by_name']  = $_sig['name'];
                    }
                    $thr['messages'][]       = $newAdminMsg;
                    $thr['status']           = 'answered';
                    $thr['updated_at']       = date('Y-m-d H:i:s');
                    $thr['deleted_by_member'] = false; // Mitglied sieht Antwort wieder
                    $memberEmail = $thr['member_email'] ?? '';
                    $memberName  = $thr['member_name']  ?? '';
                    $threadSubj  = $thr['subject']      ?? '';
                    break;
                }
                unset($thr);
                file_put_contents(MESSAGES_FILE, json_encode($allMsgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                // Send email to member
                if ($memberEmail !== '' && filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
                    $_from = 'kontakt@example.org';
                    $_subj = 'Antwort von ' . (kgv_get_admin_sig()['name']) . ': ' . $threadSubj;

                    // Build conversation history HTML (previous messages)
                    $_histHtml = '';
                    // Load signature before history loop (needed as fallback for admin_name)
                    $_admsig    = kgv_get_admin_sig();
                    $_sig_name  = $_admsig['name'];
                    $_sig_phone = $_admsig['phone'];
                    $_sig_email = $_admsig['email'];
                    $_sig_role  = $_admsig['rolle'];

                    foreach ($threadMessages as $_msg) {
                        $_isAdmin = ($_msg['from'] ?? '') === 'admin';
                        $_ts   = htmlspecialchars(isset($_msg['created_at']) ? date('d.m.Y H:i', strtotime($_msg['created_at'])) : '');
                        $_body = nl2br(htmlspecialchars((string)($_msg['body'] ?? '')));
                        $_fileNote = '';
                        if (!empty($_msg['file'])) {
                            $_fileNote = "<span style='font-size:0.75rem;color:#7a9c7e;display:block;margin-top:6px'>📎 " . htmlspecialchars($_msg['file']['orig'] ?? '') . "</span>";
                        }
                        $_msgSender = $_isAdmin ? htmlspecialchars($_msg['admin_name'] ?? $_sig_name) : 'Sie';
                        if ($_isAdmin) {
                            $_histHtml .= "<div style='margin-bottom:12px;padding:12px 16px;background:#e8f5e9;border-radius:8px;border-left:3px solid #3d6b41'>"
                                        . "<div style='font-size:0.73rem;color:#5a6c5a;font-weight:700;margin-bottom:5px'>{$_msgSender} &middot; {$_ts}</div>"
                                        . "<div style='font-size:0.88rem;color:#1a3320;line-height:1.55'>{$_body}</div>{$_fileNote}</div>";
                        } else {
                            $_histHtml .= "<div style='margin-bottom:12px;padding:12px 16px;background:#f5f7f2;border-radius:8px;border-left:3px solid #9ec49f'>"
                                        . "<div style='font-size:0.73rem;color:#5a6c5a;font-weight:700;margin-bottom:5px'>Sie &middot; {$_ts}</div>"
                                        . "<div style='font-size:0.88rem;color:#2d3e2d;line-height:1.55'>{$_body}</div>{$_fileNote}</div>";
                        }
                    }

                    $_replyTs    = date('d.m.Y \u\m H:i \U\h\r');
                    $_adminFile  = $adminFileInfo ? "<div style='margin-top:8px;font-size:0.78rem;color:#5a6c5a'>📎 Anhang: " . htmlspecialchars($adminFileInfo['orig']) . " (im Mitgliederbereich ansehen)</div>" : '';
                    $_mn = htmlspecialchars($memberName);
                    $_subjectE = htmlspecialchars($threadSubj);
                    $_replyE   = nl2br(htmlspecialchars($replyBody));
                    $_link = site_url() . '/mitglieder.php?tab=kontakt';

                    $_sig_name_e = htmlspecialchars($_sig_name);
                    $_replyContent =
                          "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:16px'><strong>{$_sig_name_e}</strong> hat deine Nachricht zu &#8222;{$_subjectE}&#8220; beantwortet.</p>"
                        . "<div style='background:#e8f5e9;border-left:4px solid #3d6b41;border-radius:0 8px 8px 0;padding:14px 18px;margin-bottom:16px'>"
                        . "<div style='font-size:0.72rem;font-weight:700;color:#3d6b41;margin-bottom:8px'>{$_sig_name_e} &middot; {$_replyTs}</div>"
                        . "<div style='font-size:0.92rem;color:#1a3320;line-height:1.6;white-space:pre-wrap'>{$_replyE}</div>"
                        . "{$_adminFile}"
                        . "</div>"
                        . "<div style='text-align:center;margin:20px 0'>"
                        . "<a href='{$_link}' style='display:inline-block;background:#3d6b41;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.88rem'>Zur Nachricht im Mitgliederbereich →</a>"
                        . "</div>"
                        . ($_histHtml !== '' ?
                             "<div style='margin-top:16px;padding-top:14px;border-top:1px solid #e8f0e0'>"
                           . "<div style='font-size:0.72rem;font-weight:700;color:#6b8c6e;margin-bottom:12px'>Gesprächsverlauf</div>"
                           . $_histHtml . "</div>"
                          : '');
                    $_html = kgv_email_html(
                        'Hallo ' . $_mn . ' 👋,',
                        $_replyContent,
                        'Antwort vom Vorstand',
                        $_sig_name, $_sig_phone, $_sig_email, $_sig_role
                    );

                    $_hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$_from}>\r\nReturn-Path: {$_from}\r\n";
                    @mail($memberEmail, $_subj, $_html, $_hdr, "-f{$_from}");
                }
                kgv_log_action('Nachricht beantwortet', $memberName . ' · ' . $threadSubj);
            }
        } elseif ($mAction === 'bulk_message' && $canSeeMembers) {
            $bmSubject = substr(trim(strip_tags((string)($_POST['bm_subject'] ?? ''))), 0, 200);
            $bmBody    = substr(trim(strip_tags((string)($_POST['bm_body']    ?? ''))), 0, 5000);
            $bmSendEmail = !empty($_POST['bm_send_email']);
            if ($bmSubject !== '' && $bmBody !== '') {
                $_bmMems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
                $_bmMsgs = file_exists(MESSAGES_FILE) ? (json_decode((string)file_get_contents(MESSAGES_FILE), true) ?: []) : [];
                $_bmsig  = kgv_get_admin_sig();
                $now     = date('Y-m-d H:i:s');
                $sentCount = 0;
                foreach ($_bmMems as $_bm) {
                    if (empty($_bm['active'])) continue;
                    $_bmMsgs[] = [
                        'id'              => 'msg_' . uniqid('', true),
                        'member_id'       => $_bm['id'],
                        'member_name'     => $_bm['name'],
                        'member_email'    => $_bm['email'],
                        'member_parzelle' => $_bm['parzelle'] ?? '',
                        'subject'         => $bmSubject,
                        'status'          => 'answered',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                        'messages'        => [['from' => 'admin', 'admin_name' => $_bmsig['name'], 'body' => $bmBody, 'created_at' => $now]],
                        'deleted_by_member' => false,
                    ];
                    if ($bmSendEmail && !empty($_bm['consents']['contact_allowed']) && filter_var($_bm['email'], FILTER_VALIDATE_EMAIL)) {
                        $_from = 'kontakt@example.org';
                        $_bmContent =
                              "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:16px'><strong>" . htmlspecialchars($_bmsig['name']) . "</strong> hat eine Nachricht an alle Mitglieder geschickt.</p>"
                            . "<div style='background:#e8f5e9;border-left:4px solid #3d6b41;border-radius:0 8px 8px 0;padding:14px 18px;margin-bottom:20px'>"
                            . "<div style='font-size:0.88rem;color:#1a3320;line-height:1.6;white-space:pre-wrap'>" . nl2br(htmlspecialchars($bmBody)) . "</div>"
                            . "</div>"
                            . "<div style='text-align:center;margin:20px 0'><a href='" . site_url() . "/mitglieder.php?tab=kontakt' style='display:inline-block;background:#3d6b41;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.88rem'>Im Mitgliederbereich ansehen →</a></div>";
                        $_bmHtml = kgv_email_html('Hallo ' . htmlspecialchars($_bm['name']) . ' 👋,', $_bmContent, $bmSubject, $_bmsig['name'], $_bmsig['phone'], $_bmsig['email'], $_bmsig['rolle']);
                        $_hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$_from}>\r\nReturn-Path: {$_from}\r\n";
                        @mail($_bm['email'], '=?UTF-8?B?' . base64_encode($bmSubject) . '?=', $_bmHtml, $_hdr, "-f{$_from}");
                        $sentCount++;
                    }
                }
                file_put_contents(MESSAGES_FILE, json_encode($_bmMsgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                kgv_log_action('Massen-Nachricht gesendet', $bmSubject . ' · ' . count($_bmMems) . ' Mitglieder' . ($bmSendEmail ? " · {$sentCount} E-Mails" : ''));
            }

        } elseif ($mAction === 'save_arbeit' && $memId !== '') {
            $aYear  = (int)($_POST['arbeit_year']   ?? date('Y'));
            $aHours = max(0.0, (float)str_replace(',', '.', (string)($_POST['arbeit_hours'] ?? '0')));
            $aDate  = trim((string)($_POST['arbeit_date'] ?? date('Y-m-d')));
            $aNote  = substr(trim(strip_tags((string)($_POST['arbeit_note'] ?? ''))), 0, 200);
            $aAll   = file_exists(ARBEIT_FILE) ? (json_decode((string)file_get_contents(ARBEIT_FILE), true) ?: []) : [];
            if (!isset($aAll[$memId])) $aAll[$memId] = [];
            $aAll[$memId][] = ['year' => $aYear, 'hours' => $aHours, 'date' => $aDate, 'note' => $aNote, 'recorded_at' => date('Y-m-d H:i:s')];
            file_put_contents(ARBEIT_FILE, json_encode($aAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            $_logName = ''; foreach ($allMembers as $_lm) { if (($_lm['id'] ?? '') === $memId) { $_logName = $_lm['name'] ?? ''; break; } }
            $_aDateFmt = $aDate !== '' ? date('d.m.Y', strtotime($aDate)) : '–';
            kgv_log_action('Arbeitsstunden eingetragen', ($_logName ?: $memId) . ' · ' . number_format($aHours, 1, ',', '.') . 'h am ' . $_aDateFmt);
            header('Location: /intern/?tab=arbeit&aj=' . $aYear); exit;

        } elseif ($mAction === 'delete_arbeit' && $memId !== '') {
            $aIdx  = (int)($_POST['arbeit_idx'] ?? -1);
            $aYear = (int)($_POST['arbeit_year'] ?? date('Y'));
            $aAll  = file_exists(ARBEIT_FILE) ? (json_decode((string)file_get_contents(ARBEIT_FILE), true) ?: []) : [];
            if (isset($aAll[$memId][$aIdx])) {
                array_splice($aAll[$memId], $aIdx, 1);
                file_put_contents(ARBEIT_FILE, json_encode($aAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            header('Location: /intern/?tab=arbeit&aj=' . $aYear); exit;

        } elseif ($mAction === 'save_birthday' && $memId !== '') {
            $bday = trim((string)($_POST['geburtstag'] ?? ''));
            // Accept MM-DD or DD.MM format
            if (preg_match('/^(\d{2})\.(\d{2})$/', $bday, $m)) $bday = $m[2] . '-' . $m[1];
            if (!preg_match('/^\d{2}-\d{2}$/', $bday)) $bday = '';
            $mems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
            foreach ($mems as &$mem) {
                if (($mem['id'] ?? '') === $memId) { $mem['geburtstag'] = $bday; break; }
            }
            unset($mem);
            file_put_contents(MEMBERS_FILE, json_encode($mems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        } elseif ($mAction === 'new_message_to_member') {
            $targetId  = trim((string)($_POST['target_member_id'] ?? ''));
            $nmSubject = trim(strip_tags((string)($_POST['nm_subject'] ?? '')));
            $nmSubject = preg_replace('/[\r\n\x00]+/', ' ', $nmSubject) ?? '';
            $nmSubject = substr($nmSubject, 0, 200);
            $nmBody    = trim(strip_tags((string)($_POST['nm_body']    ?? '')));
            if ($targetId !== '' && $nmSubject !== '' && $nmBody !== '') {
                $_nmMems = file_exists(MEMBERS_FILE) ? (json_decode((string)file_get_contents(MEMBERS_FILE), true) ?: []) : [];
                $targetMem = null;
                foreach ($_nmMems as $_m) {
                    if (($_m['id'] ?? '') === $targetId) { $targetMem = $_m; break; }
                }
                if ($targetMem !== null) {
                    $_sig = kgv_get_admin_sig();
                    $now  = date('Y-m-d H:i:s');
                    $newThread = [
                        'id'              => 'msg_' . uniqid('', true),
                        'member_id'       => $targetMem['id'],
                        'member_name'     => $targetMem['name'],
                        'member_email'    => $targetMem['email'],
                        'member_parzelle' => $targetMem['parzelle'] ?? '',
                        'subject'         => $nmSubject,
                        'status'          => 'answered',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                        'messages'        => [[
                            'from'       => 'admin',
                            'admin_name' => $_sig['name'],
                            'body'       => $nmBody,
                            'created_at' => $now,
                        ]],
                        'deleted_by_member' => false,
                    ];
                    $allMsgs = file_exists(MESSAGES_FILE) ? (json_decode((string)file_get_contents(MESSAGES_FILE), true) ?: []) : [];
                    $allMsgs[] = $newThread;
                    file_put_contents(MESSAGES_FILE, json_encode($allMsgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

                    $hasConsent = !empty($targetMem['consents']['contact_allowed']);
                    if ($hasConsent && filter_var($targetMem['email'], FILTER_VALIDATE_EMAIL)) {
                        $_from  = 'kontakt@example.org';
                        $_mn    = htmlspecialchars($targetMem['name']);
                        $_link  = site_url() . '/mitglieder.php?tab=kontakt';
                        $_bodyE = nl2br(htmlspecialchars($nmBody));
                        $_nmContent =
                              "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:16px'><strong>" . htmlspecialchars($_sig['name']) . "</strong> hat dir eine Nachricht geschickt.</p>"
                            . "<div style='background:#e8f5e9;border-left:4px solid #3d6b41;border-radius:0 8px 8px 0;padding:14px 18px;margin-bottom:20px'>"
                            . "<div style='font-size:0.72rem;font-weight:700;color:#3d6b41;margin-bottom:8px'>" . htmlspecialchars($_sig['name']) . " &middot; " . date('d.m.Y \u\m H:i \U\h\r') . "</div>"
                            . "<div style='font-size:0.92rem;color:#1a3320;line-height:1.6;white-space:pre-wrap'>{$_bodyE}</div>"
                            . "</div>"
                            . "<div style='text-align:center;margin:20px 0'>"
                            . "<a href='{$_link}' style='display:inline-block;background:#3d6b41;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.88rem'>Zur Nachricht im Mitgliederbereich →</a>"
                            . "</div>";
                        $_html = kgv_email_html('Hallo ' . $_mn . ' 👋,', $_nmContent, 'Nachricht vom Vorstand',
                            $_sig['name'], $_sig['phone'], $_sig['email'], $_sig['rolle']);
                        $_hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$_from}>\r\nReturn-Path: {$_from}\r\n";
                        @mail($targetMem['email'], $nmSubject . ' – KGV Musterstadt', $_html, $_hdr, "-f{$_from}");
                        error_log("[admin] new_msg email={$targetMem['email']} subj={$nmSubject}\n", 3, dirname(__DIR__) . '/data/member.log');
                    }
                }
            }
        }
    }
    header('Location: /intern/?tab=members');
    exit;
}

// Load member data
$memberRequests = [];
$pendingRequestsCount = 0;
$allMembers = [];
if ($loggedIn) {
    if (file_exists(REQUESTS_FILE)) {
        $mr = json_decode((string)file_get_contents(REQUESTS_FILE), true);
        if (is_array($mr)) {
            $memberRequests = array_reverse($mr);
            $pendingRequestsCount = count(array_filter($memberRequests, fn($r) => ($r['status'] ?? '') === 'pending'));
        }
    }
    if (file_exists(MEMBERS_FILE)) {
        $mm = json_decode((string)file_get_contents(MEMBERS_FILE), true);
        if (is_array($mm)) {
            usort($mm, fn($a, $b) => (int)($a['parzelle'] ?? 0) <=> (int)($b['parzelle'] ?? 0));
            $allMembers = $mm;
        }
    }
}
$memberMessages = [];
$openMessagesCount = 0;
if ($loggedIn && file_exists(MESSAGES_FILE)) {
    $mx = json_decode((string)file_get_contents(MESSAGES_FILE), true);
    if (is_array($mx)) {
        $memberMessages = array_values(array_filter(array_reverse($mx), fn($t) => empty($t['deleted_by_admin'])));
        $openMessagesCount = count(array_filter($memberMessages, fn($t) => ($t['status'] ?? '') === 'open'));
    }
}
$pendingRequestsCount += $openMessagesCount;

// ── Todos ──────────────────────────────────────────────────────────────────
define('TODOS_FILE', dirname(__DIR__) . '/data/todos.json');
$todos = [];
$openTodosCount = 0;
if ($loggedIn && file_exists(TODOS_FILE)) {
    $td = json_decode((string)file_get_contents(TODOS_FILE), true);
    if (is_array($td)) {
        // Neueste oben, erledigte ans Ende
        usort($td, fn($a, $b) => (empty($a['done']) === empty($b['done']))
            ? strcmp($b['created_at'] ?? '', $a['created_at'] ?? '')
            : (empty($a['done']) ? -1 : 1));
        $todos = $td;
        $openTodosCount = count(array_filter($todos, fn($t) => empty($t['done'])));
    }
}

// ── Vereinstermine speichern (content.json) ────────────────────────────────
if ($loggedIn && $canEditContent && !empty($_POST['save_termine'])) {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $_ccFile = dirname(__DIR__) . '/data/content.json';
        $_cc = file_exists($_ccFile) ? (json_decode((string)file_get_contents($_ccFile), true) ?: []) : [];
        $_teDates  = (array)($_POST['te_date']   ?? []);
        $_teTitles = (array)($_POST['te_title']  ?? []);
        $_teDescs  = (array)($_POST['te_desc']   ?? []);
        $_teDels   = array_map('strval', (array)($_POST['te_delete'] ?? []));
        $_tz       = new DateTimeZone('Europe/Berlin');
        $_termine  = [];
        for ($_i = 0; $_i < max(count($_teDates), count($_teTitles)); $_i++) {
            if (in_array((string)$_i, $_teDels, true)) continue;
            $_d  = trim($_teDates[$_i] ?? '');
            $_dt = DateTimeImmutable::createFromFormat('Y-m-d', $_d, $_tz);
            if (!$_dt || $_dt->format('Y-m-d') !== $_d) continue;
            $_tit = trim(strip_tags($_teTitles[$_i] ?? '')); if ($_tit === '') continue;
            $_termine[] = ['date' => $_d, 'title' => $_tit, 'desc' => trim(strip_tags($_teDescs[$_i] ?? '')), 'public' => true];
        }
        usort($_termine, fn($a, $b) => strcmp($a['date'], $b['date']));
        $_cc['termine'] = $_termine;
        file_put_contents($_ccFile, json_encode($_cc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: /intern/?tab=termine&saved=1'); exit;
    }
}

// ── Veranstaltung speichern (events.json) ──────────────────────────────────
// Recht: SuperAdmin + vorstand + web + schriftfuehrer (Sandra)
$_canManageEvents = $loggedIn && in_array($effectiveRole, ['superadmin','vorstand','web','schriftfuehrer'], true);
if ($_canManageEvents && !empty($_POST['save_event'])) {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        require_once dirname(__DIR__) . '/inc/events.php';
        $_evAll = kgv_events_all();
        $_evId  = trim((string)($_POST['ev_id'] ?? ''));
        $_isNew = ($_evId === '');
        if ($_isNew) $_evId = 'evt_' . time() . '_' . bin2hex(random_bytes(4));

        $_title    = trim(strip_tags((string)($_POST['ev_title']    ?? '')));
        $_subtitle = trim(strip_tags((string)($_POST['ev_subtitle'] ?? '')));
        $_desc     = trim((string)($_POST['ev_description'] ?? ''));
        $_type     = in_array($_POST['ev_type'] ?? '', ['internal','public'], true) ? $_POST['ev_type'] : 'public';
        $_active   = !empty($_POST['ev_active']);
        $_showMem  = !empty($_POST['ev_show_in_member_area']);
        $_showCat  = !empty($_POST['ev_show_catering']);
        $_deadline = trim((string)($_POST['ev_deadline']   ?? ''));
        $_evdate   = trim((string)($_POST['ev_event_date'] ?? ''));

        // ── PDF-Aushang-Anpassungen ──
        $_pdfEyebrow    = trim(strip_tags((string)($_POST['ev_pdf_eyebrow']    ?? '')));
        $_pdfIntroText  = trim((string)($_POST['ev_pdf_intro_text']  ?? ''));
        $_pdfCtaMain    = trim(strip_tags((string)($_POST['ev_pdf_cta_main']    ?? '')));
        $_pdfCtaSub     = trim(strip_tags((string)($_POST['ev_pdf_cta_sub']     ?? '')));
        $_pdfFooterNote = trim((string)($_POST['ev_pdf_footer_note'] ?? ''));
        $_pdfShowDescription = !empty($_POST['ev_pdf_show_description']);

        // Slug zuerst ableiten — der Custom-Flyer-Upload braucht ihn für den Dateinamen
        $_slug     = trim((string)($_POST['ev_slug'] ?? ''));
        if ($_slug === '') $_slug = kgv_slugify($_title);
        $_slug = kgv_slugify($_slug);

        // ── Custom-Flyer hochladen (z.B. Canva-Export) ──
        $_customFlyerFile = trim((string)($_POST['ev_custom_flyer_existing'] ?? ''));
        $_useCustomFlyer  = !empty($_POST['ev_use_custom_flyer']);
        $_removeCustom    = !empty($_POST['ev_remove_custom_flyer']);

        $_flyersDir = dirname(__DIR__) . '/images/event_flyers';
        if (!is_dir($_flyersDir)) {
            if (!@mkdir($_flyersDir, 0755, true) && !is_dir($_flyersDir)) {
                @error_log("[event] flyer dir create failed: {$_flyersDir}\n", 3, dirname(__DIR__) . '/data/admin.log');
            }
        }

        $_uploadErrors = [];
        if (!empty($_FILES['ev_custom_flyer']['name']) && ($_FILES['ev_custom_flyer']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $_uf      = $_FILES['ev_custom_flyer'];
                $_uSize   = (int)($_uf['size'] ?? 0);
                $_uMaxMB  = 12;
                if (!function_exists('finfo_open')) {
                    throw new \RuntimeException('PHP fileinfo extension missing');
                }
                $_finfo   = finfo_open(FILEINFO_MIME_TYPE);
                $_mime    = $_finfo ? finfo_file($_finfo, (string)$_uf['tmp_name']) : '';
                if ($_finfo) finfo_close($_finfo);
                $_allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                if ($_uSize > $_uMaxMB * 1024 * 1024) {
                    $_uploadErrors[] = 'too_large';
                } elseif (!isset($_allowed[$_mime])) {
                    $_uploadErrors[] = 'bad_mime:' . $_mime;
                } else {
                    $_ext      = $_allowed[$_mime];
                    $_fnSlug   = preg_replace('/[^a-z0-9-]/', '', (string)$_slug) ?: 'event';
                    $_fname    = 'flyer_' . $_fnSlug . '_' . time() . '.' . $_ext;
                    $_destPath = $_flyersDir . '/' . $_fname;
                    if (!is_dir($_flyersDir)) {
                        $_uploadErrors[] = 'dir_missing';
                    } elseif (!is_writable($_flyersDir)) {
                        $_uploadErrors[] = 'dir_not_writable';
                    } elseif (!move_uploaded_file((string)$_uf['tmp_name'], $_destPath)) {
                        $_uploadErrors[] = 'move_failed';
                    } else {
                        // Alten Flyer löschen wenn überschrieben
                        if ($_customFlyerFile !== '' && $_customFlyerFile !== $_fname) {
                            @unlink($_flyersDir . '/' . basename($_customFlyerFile));
                        }
                        $_customFlyerFile = $_fname;
                        $_useCustomFlyer  = true;
                    }
                }
            } catch (\Throwable $_te) {
                $_uploadErrors[] = 'exception:' . $_te->getMessage();
            }
            if ($_uploadErrors) {
                @error_log("[event] flyer upload error: " . implode('; ', $_uploadErrors)
                    . " · size=" . ($_FILES['ev_custom_flyer']['size'] ?? '?')
                    . " · name=" . ($_FILES['ev_custom_flyer']['name'] ?? '?')
                    . "\n", 3, dirname(__DIR__) . '/data/admin.log');
            }
        }

        if ($_removeCustom && $_customFlyerFile !== '') {
            @unlink($_flyersDir . '/' . basename($_customFlyerFile));
            $_customFlyerFile = '';
            $_useCustomFlyer  = false;
        }

        // Slug-Eindeutigkeit prüfen (außer es ist genau dieses Event)
        $_slugClash = false;
        foreach ($_evAll as $_e) {
            if (($_e['id'] ?? '') === $_evId) continue;
            if (mb_strtolower((string)($_e['slug'] ?? ''), 'UTF-8') === mb_strtolower($_slug, 'UTF-8')) {
                $_slugClash = true; break;
            }
        }
        if ($_slugClash) $_slug .= '-' . substr(uniqid(), -4);

        // Datum-Felder strikt validieren
        $_tz = new DateTimeZone('Europe/Berlin');
        foreach ([&$_deadline, &$_evdate] as &$_d) {
            if ($_d === '') continue;
            $_dt = DateTimeImmutable::createFromFormat('Y-m-d', $_d, $_tz);
            if (!$_dt || $_dt->format('Y-m-d') !== $_d) $_d = '';
        }
        unset($_d);

        if ($_title === '') {
            header('Location: /intern/?tab=events&error=title_required'); exit;
        }

        // Bestehende Anmeldungen + Metadaten erhalten
        $_pdfBlock = [
            'pdf_eyebrow'          => mb_substr($_pdfEyebrow,    0, 80),
            'pdf_intro_text'       => mb_substr($_pdfIntroText,  0, 800),
            'pdf_cta_main'         => mb_substr($_pdfCtaMain,    0, 80),
            'pdf_cta_sub'          => mb_substr($_pdfCtaSub,     0, 80),
            'pdf_footer_note'      => mb_substr($_pdfFooterNote, 0, 400),
            'pdf_show_description' => $_pdfShowDescription,
            'custom_flyer_file'    => $_customFlyerFile,
            'use_custom_flyer'     => $_useCustomFlyer && $_customFlyerFile !== '',
        ];
        $_idx = kgv_event_index_by_id($_evAll, $_evId);
        if ($_idx >= 0) {
            $_existing = $_evAll[$_idx];
            $_evAll[$_idx] = array_merge($_existing, [
                'slug'                => $_slug,
                'title'               => mb_substr($_title, 0, 120),
                'subtitle'            => mb_substr($_subtitle, 0, 160),
                'description'         => mb_substr($_desc, 0, 4000),
                'type'                => $_type,
                'show_catering'       => $_showCat,
                'active'              => $_active,
                'show_in_member_area' => $_showMem,
                'deadline'            => $_deadline,
                'event_date'          => $_evdate,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], $_pdfBlock);
        } else {
            $_evAll[] = array_merge([
                'id'                  => $_evId,
                'slug'                => $_slug,
                'title'               => mb_substr($_title, 0, 120),
                'subtitle'            => mb_substr($_subtitle, 0, 160),
                'description'         => mb_substr($_desc, 0, 4000),
                'type'                => $_type,
                'show_catering'       => $_showCat,
                'active'              => $_active,
                'show_in_member_area' => $_showMem,
                'deadline'            => $_deadline,
                'event_date'          => $_evdate,
                'created_at'          => date('Y-m-d H:i:s'),
                'created_by'          => (string)($_SESSION['kgv_member']['name'] ?? 'SuperAdmin'),
                'registrations'       => [],
            ], $_pdfBlock);
        }
        kgv_events_save($_evAll);
        kgv_log_action($_isNew ? 'Veranstaltung erstellt' : 'Veranstaltung aktualisiert', $_title);
        header('Location: /intern/?tab=events&saved=' . urlencode($_evId)); exit;
    }
}

// ── Veranstaltung löschen ─────────────────────────────────────────────────
if ($_canManageEvents && !empty($_POST['delete_event'])) {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        require_once dirname(__DIR__) . '/inc/events.php';
        $_evId  = trim((string)($_POST['ev_id'] ?? ''));
        $_evAll = kgv_events_all();
        $_idx   = kgv_event_index_by_id($_evAll, $_evId);
        if ($_idx >= 0) {
            $_evTitle = (string)($_evAll[$_idx]['title'] ?? $_evId);
            array_splice($_evAll, $_idx, 1);
            kgv_events_save($_evAll);
            kgv_log_action('Veranstaltung gelöscht', $_evTitle);
        }
        header('Location: /intern/?tab=events&deleted=1'); exit;
    }
}

// ── Anmeldung löschen / wieder reaktivieren ───────────────────────────────
if ($_canManageEvents && !empty($_POST['reg_action'])) {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        require_once dirname(__DIR__) . '/inc/events.php';
        $_act   = (string)$_POST['reg_action'];
        $_evId  = trim((string)($_POST['ev_id']  ?? ''));
        $_regId = trim((string)($_POST['reg_id'] ?? ''));
        $_evAll = kgv_events_all();
        $_idx   = kgv_event_index_by_id($_evAll, $_evId);
        if ($_idx >= 0 && $_regId !== '') {
            $regs = $_evAll[$_idx]['registrations'] ?? [];
            foreach ($regs as $i => $r) {
                if (($r['id'] ?? '') !== $_regId) continue;
                if ($_act === 'cancel') {
                    $regs[$i]['cancelled_at'] = date('Y-m-d H:i:s');
                    $regs[$i]['cancel_token'] = '';
                    kgv_log_action('Veranstaltungs-Anmeldung storniert', ($r['fullname'] ?? '?'));
                } elseif ($_act === 'reactivate') {
                    $regs[$i]['cancelled_at'] = null;
                    kgv_log_action('Veranstaltungs-Anmeldung reaktiviert', ($r['fullname'] ?? '?'));
                } elseif ($_act === 'delete') {
                    array_splice($regs, $i, 1);
                    kgv_log_action('Veranstaltungs-Anmeldung gelöscht', ($r['fullname'] ?? '?'));
                }
                break;
            }
            $_evAll[$_idx]['registrations'] = $regs;
            kgv_events_save($_evAll);
        }
        header('Location: /intern/?tab=events&id=' . urlencode($_evId)); exit;
    }
}

// ── Gemeinschaftsarbeit-Termine speichern (member_posts.json) ───────────────
if ($loggedIn && $canEditContent && !empty($_POST['save_ga_termine'])) {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $_mpFile  = dirname(__DIR__) . '/data/member_posts.json';
        $_allPosts = file_exists($_mpFile) ? (json_decode((string)file_get_contents($_mpFile), true) ?: []) : [];
        // Alle bestehenden GA-Posts rausfiltern
        $_otherPosts = array_values(array_filter($_allPosts, fn($p) => ($p['type'] ?? '') !== 'gemeinschaftsarbeit'));
        $_gaIds    = (array)($_POST['ga_id']    ?? []);
        $_gaDates  = (array)($_POST['ga_date']  ?? []);
        $_gaTitles = (array)($_POST['ga_title'] ?? []);
        $_gaBodies = (array)($_POST['ga_body']  ?? []);
        $_gaDels   = array_map('strval', (array)($_POST['ga_delete'] ?? []));
        $_tz       = new DateTimeZone('Europe/Berlin');
        $_newGa    = [];
        for ($_i = 0; $_i < max(count($_gaDates), count($_gaTitles)); $_i++) {
            if (in_array((string)$_i, $_gaDels, true)) continue;
            $_d   = trim($_gaDates[$_i] ?? '');
            $_dt  = DateTimeImmutable::createFromFormat('Y-m-d', $_d, $_tz);
            if (!$_dt || $_dt->format('Y-m-d') !== $_d) continue;
            $_tit = trim(strip_tags($_gaTitles[$_i] ?? '')); if ($_tit === '') continue;
            $_id  = trim($_gaIds[$_i] ?? '') ?: 'post_ga_' . uniqid();
            $_newGa[] = [
                'id'         => $_id,
                'type'       => 'gemeinschaftsarbeit',
                'title'      => $_tit,
                'body'       => trim($_gaBodies[$_i] ?? ''),
                'date'       => $_d,
                'created_at' => date('Y-m-d H:i:s'),
                'pinned'     => false,
            ];
        }
        usort($_newGa, fn($a, $b) => strcmp($a['date'], $b['date']));
        // Andere Posts zuerst behalten, GA-Posts anhängen
        $_mergedPosts = array_merge($_otherPosts, $_newGa);
        file_put_contents($_mpFile, json_encode($_mergedPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: /intern/?tab=termine&saved=ga'); exit;
    }
}

// ── Kontaktanfragen ────────────────────────────────────────────────────────
define('CONTACTS_FILE', dirname(__DIR__) . '/data/contacts.json');

if ($loggedIn && !empty($_POST['mark_read'])) {
    if (hash_equals($csrf, (string)($_POST['csrf_contact'] ?? ''))) {
        $cid = trim((string)($_POST['contact_id'] ?? ''));
        if ($cid !== '' && file_exists(CONTACTS_FILE)) {
            $allC = json_decode((string)file_get_contents(CONTACTS_FILE), true) ?: [];
            foreach ($allC as &$ci) {
                if (($ci['id'] ?? '') === $cid) { $ci['status'] = 'read'; break; }
            }
            unset($ci);
            file_put_contents(CONTACTS_FILE, json_encode($allC, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }
    header('Location: /intern/?tab=contacts');
    exit;
}

if ($loggedIn && $canManageRoles && !empty($_POST['delete_contact'])) {
    if (hash_equals($csrf, (string)($_POST['csrf_contact'] ?? ''))) {
        $cid = trim((string)($_POST['contact_id'] ?? ''));
        if ($cid !== '' && file_exists(CONTACTS_FILE)) {
            $allC = json_decode((string)file_get_contents(CONTACTS_FILE), true) ?: [];
            $allC = array_values(array_filter($allC, fn($c) => ($c['id'] ?? '') !== $cid));
            file_put_contents(CONTACTS_FILE, json_encode($allC, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            kgv_log_action('Kontaktanfrage gelöscht', $cid);
        }
    }
    header('Location: /intern/?tab=contacts');
    exit;
}

if ($loggedIn && !empty($_POST['reply_contact'])) {
    if (hash_equals($csrf, (string)($_POST['csrf_contact'] ?? ''))) {
        $cid   = trim((string)($_POST['contact_id'] ?? ''));
        $rBody = trim((string)($_POST['reply_body']  ?? ''));
        if ($cid !== '' && $rBody !== '' && file_exists(CONTACTS_FILE)) {
            $allC = json_decode((string)file_get_contents(CONTACTS_FILE), true) ?: [];
            $toEmail = $toName = $origSubj = $origMsg = '';
            $prevReplies = [];
            foreach ($allC as &$_rc) {
                if (($_rc['id'] ?? '') !== $cid) continue;
                $toEmail  = $_rc['email']   ?? '';
                $toName   = $_rc['name']    ?? '';
                $origSubj = $_rc['subject'] ?? '';
                $origMsg  = $_rc['message'] ?? '';
                $origDate = $_rc['date']    ?? '';
                $_rc['status'] = 'replied';
                // Migrate old single-reply to array format
                if (!isset($_rc['replies'])) {
                    $_rc['replies'] = [];
                    if (!empty($_rc['reply'])) {
                        $_rc['replies'][] = ['type' => 'outgoing', 'body' => $_rc['reply'], 'replied_at' => $_rc['replied_at'] ?? '', 'admin_name' => ''];
                    }
                    unset($_rc['reply'], $_rc['replied_at']);
                }
                $prevReplies = $_rc['replies']; // snapshot before adding new reply
                $_sig_r = kgv_get_admin_sig();
                $newReplyEntry = ['type' => 'outgoing', 'body' => $rBody, 'replied_at' => date('Y-m-d H:i:s'), 'admin_name' => $_sig_r['name']];
                $_rc['replies'][] = $newReplyEntry;
                break;
            }
            unset($_rc);
            file_put_contents(CONTACTS_FILE, json_encode($allC, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                $_admsig2 = kgv_get_admin_sig();
                $_sn = $_admsig2['name'];
                $_sp = $_admsig2['phone'];
                $_se = $_admsig2['email'];
                $_sr = $_admsig2['rolle'];
                $fromEmail = 'kontakt@example.org';
                $eSubj  = 'Re: ' . $origSubj;

                // Build chat-style thread for email
                $_threadHtml = '<div style="font-size:0.75rem;font-weight:700;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">💬 Nachrichtenverlauf</div>';

                // Original message (left/gray)
                $_oDate = ($origDate !== '') ? date('d.m.Y H:i', strtotime($origDate)) : '';
                $_threadHtml .= '<div style="margin-bottom:8px">'
                    . '<div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px">'
                    . '📨 ' . htmlspecialchars($toName) . ($origDate !== '' ? ' · ' . $_oDate : '') . '</div>'
                    . '<div style="background:#f0f2f0;border-radius:0 10px 10px 10px;padding:9px 13px;font-size:0.85rem;color:#2d3e2d;white-space:pre-wrap;line-height:1.5;border:1px solid #e0e8dc;display:inline-block;max-width:85%">'
                    . htmlspecialchars($origMsg) . '</div></div>';

                // Previous replies
                foreach ($prevReplies as $_pr) {
                    $_prType = $_pr['type'] ?? 'outgoing';
                    $_prDate = (isset($_pr['replied_at']) && $_pr['replied_at'] !== '') ? date('d.m.Y H:i', strtotime($_pr['replied_at'])) : '';
                    if ($_prType === 'incoming') {
                        $_threadHtml .= '<div style="margin-bottom:8px">'
                            . '<div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px">'
                            . '📨 ' . htmlspecialchars($_pr['from_name'] ?? $toName) . ($_prDate !== '' ? ' · ' . $_prDate : '') . '</div>'
                            . '<div style="background:#f0f2f0;border-radius:0 10px 10px 10px;padding:9px 13px;font-size:0.85rem;color:#2d3e2d;white-space:pre-wrap;line-height:1.5;border:1px solid #e0e8dc;display:inline-block;max-width:85%">'
                            . htmlspecialchars($_pr['body'] ?? '') . '</div></div>';
                    } else {
                        $_threadHtml .= '<div style="margin-bottom:8px;text-align:right">'
                            . '<div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px">'
                            . '✉️ ' . htmlspecialchars($_pr['admin_name'] ?? 'Vorstand') . ($_prDate !== '' ? ' · ' . $_prDate : '') . '</div>'
                            . '<div style="background:#e8f5e9;border-radius:10px 0 10px 10px;padding:9px 13px;font-size:0.85rem;color:#1b4d1e;white-space:pre-wrap;line-height:1.5;border:1px solid #c8e6c9;display:inline-block;max-width:85%;text-align:left">'
                            . htmlspecialchars($_pr['body'] ?? '') . '</div></div>';
                    }
                }

                // New reply (right/green, highlighted)
                $_threadHtml .= '<div style="margin-bottom:4px;text-align:right">'
                    . '<div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px">'
                    . '✉️ ' . htmlspecialchars($_sn) . ' · ' . date('d.m.Y H:i') . '</div>'
                    . '<div style="background:#d0eddb;border-radius:10px 0 10px 10px;padding:9px 13px;font-size:0.85rem;color:#1b4d1e;white-space:pre-wrap;line-height:1.5;border:2px solid #4caf50;display:inline-block;max-width:85%;text-align:left">'
                    . htmlspecialchars($rBody) . '</div></div>';

                $_contactReplyContent = $_threadHtml;

                $eHtml = kgv_email_html(
                    'Hallo ' . htmlspecialchars($toName) . ' 👋,',
                    $_contactReplyContent,
                    'Antwort auf deine Anfrage',
                    $_sn, $_sp, $_se, $_sr
                );
                $hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt e.V. <{$fromEmail}>\r\nReturn-Path: {$fromEmail}\r\n";
                @mail($toEmail, '=?UTF-8?B?' . base64_encode($eSubj) . '?=', $eHtml, $hdr, "-f{$fromEmail}");
            }
        }
    }
    header('Location: /intern/?tab=contacts');
    exit;
}

// ── Posteingang abrufen (IMAP) ─────────────────────────────────────────────
if ($loggedIn && $canSeeContacts && !empty($_POST['fetch_incoming'])) {
    if (hash_equals($csrf, (string)($_POST['csrf_contact'] ?? ''))) {
        require_once dirname(__DIR__) . '/inc/fetch_incoming_mail.php';
        $fetchResult = fetch_incoming_contact_replies(CONTACTS_FILE);
        header('Location: /intern/?tab=contacts&fetched=' . $fetchResult['added'] .
               ($fetchResult['errors'] ? '&fetch_err=1' : ''));
        exit;
    }
    header('Location: /intern/?tab=contacts');
    exit;
}

// ── Auto-Posteingang (alle 5 Minuten beim Seitenaufruf) ───────────────────
if ($loggedIn && $canSeeContacts) {
    $_fetchTs   = dirname(__DIR__) . '/data/imap_last_fetch.txt';
    $_lastFetch = file_exists($_fetchTs) ? (int)file_get_contents($_fetchTs) : 0;
    if ((time() - $_lastFetch) >= 300) { // 5 Minuten
        file_put_contents($_fetchTs, (string)time(), LOCK_EX);
        require_once dirname(__DIR__) . '/inc/fetch_incoming_mail.php';
        @fetch_incoming_contact_replies(CONTACTS_FILE);
    }
}

$contacts = [];
$newContactsCount = 0;
if ($loggedIn && file_exists(CONTACTS_FILE)) {
    $rawC = json_decode((string)file_get_contents(CONTACTS_FILE), true);
    if (is_array($rawC)) {
        $contacts = array_reverse($rawC);
        $newContactsCount = count(array_filter($contacts, fn($c) => ($c['status'] ?? 'new') === 'new'));
    }
}

// ── Blocked dates for booking calendar ────────────────────────────────────
$_adminCcf    = dirname(__DIR__) . '/data/content.json';
$_adminCc     = file_exists($_adminCcf) ? (json_decode((string)file_get_contents($_adminCcf), true) ?: []) : [];
$_stromKwhCfg     = (float)($_adminCc['prices']['strom_kwh']             ?? 0.35);
$_kautionCfg      = (float)($_adminCc['prices']['kaution']               ?? 200.0);
$_mieteCfg        = (float)($_adminCc['prices']['miete']                 ?? 300.0);
$_mieteMemberCfg  = round($_mieteCfg / 2, 2);
$_endreinigungCfg       = (float)($_adminCc['prices']['endreinigung']          ?? 50.0);
$_endreinigungMemberCfg = (float)($_adminCc['prices']['endreinigung_mitglied'] ?? 0.0);
$_blockedDatesAdmin       = array_values(array_column($_adminCc['blocked_dates']  ?? [], 'date'));
$_blockedRangesAdmin      = array_values($_adminCc['blocked_ranges'] ?? []);
$_blockedDatesFull        = array_values($_adminCc['blocked_dates']  ?? []);  // incl. label
$_termineAdmin            = $_adminCc['termine'] ?? [];
$_mpFileAdmin = dirname(__DIR__) . '/data/member_posts.json';
$_allPostsAdmin = file_exists($_mpFileAdmin) ? (json_decode((string)file_get_contents($_mpFileAdmin), true) ?: []) : [];
$_gaTermineAdmin = array_values(array_filter($_allPostsAdmin, fn($p) => ($p['type'] ?? '') === 'gemeinschaftsarbeit'));
usort($_gaTermineAdmin, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

// ── Dashboard data ─────────────────────────────────────────────────────────
$today       = date('Y-m-d');
$in14Days    = date('Y-m-d', strtotime('+14 days'));
$in30Days    = date('Y-m-d', strtotime('+30 days'));
$thisMonthKey = date('Y-m');
$dashUpcoming = [];
$dashRecentPaid = [];
$dashActivity   = [];
$dashBirthdays  = [];
$arbeitData     = [];

if ($loggedIn) {
    foreach ($bookings as $_db) {
        if (($_db['status'] ?? '') !== 'confirmed') continue;
        $_dd = $_db['dates'][0] ?? $_db['date'] ?? '';
        if ($_dd >= $today && $_dd <= $in14Days) $dashUpcoming[] = $_db;
    }
    usort($dashUpcoming, fn($a,$b) => strcmp($a['dates'][0] ?? $a['date'] ?? '', $b['dates'][0] ?? $b['date'] ?? ''));
    $dashRecentPaid = array_slice(array_reverse(array_values(array_filter($bookings, fn($b) => !empty($b['paid_at'])))), 0, 5);

    if (file_exists(ACTIVITY_FILE)) {
        $_la = json_decode((string)file_get_contents(ACTIVITY_FILE), true);
        if (is_array($_la)) $dashActivity = array_slice($_la, 0, 15);
    }
    // Resolve legacy "MemberID=xxx ..." details to human-readable text
    foreach ($dashActivity as &$_dae) {
        $_det = $_dae['detail'] ?? '';
        if (preg_match('/^MemberID=(\S+)\s+(.*)$/', $_det, $_dm)) {
            $_mid = $_dm[1]; $_rest = trim($_dm[2]);
            $_mname = ''; foreach ($allMembers as $_mam) { if (($_mam['id'] ?? '') === $_mid) { $_mname = $_mam['name'] ?? ''; break; } }
            $_rest = preg_replace_callback('/\d{4}-\d{2}-\d{2}/', fn($m) => date('d.m.Y', strtotime($m[0])), $_rest);
            $_rest = preg_replace('/(\d+)\.(\d+)h/', '$1,$2h', $_rest);
            $_rest = str_replace('Jahr=', '', $_rest);
            $_dae['detail'] = ($_mname ?: $_mid) . ' · ' . $_rest;
        }
    }
    unset($_dae);

    $arbeitData    = file_exists(ARBEIT_FILE)    ? (json_decode((string)file_get_contents(ARBEIT_FILE),    true) ?: []) : [];

    foreach ($allMembers as $_bm) {
        $_bday = $_bm['geburtstag'] ?? '';
        if ($_bday === '' || !preg_match('/^\d{2}-\d{2}$/', $_bday)) continue;
        $_bdThis = date('Y') . '-' . $_bday;
        $_diff   = (int)round((strtotime($_bdThis) - strtotime($today)) / 86400);
        if ($_diff < 0) { $_bdThis = (date('Y') + 1) . '-' . $_bday; $_diff = (int)round((strtotime($_bdThis) - strtotime($today)) / 86400); }
        if ($_diff <= 30) $dashBirthdays[] = ['name' => $_bm['name'], 'parzelle' => $_bm['parzelle'] ?? '', 'date' => $_bdThis, 'diff' => $_diff];
    }
    usort($dashBirthdays, fn($a,$b) => $a['diff'] - $b['diff']);
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backoffice – KGV Musterstadt</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f4ee;color:#2d3e2d;min-height:100vh}
.main{max-width:1100px;margin:0 auto;padding:24px 16px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.stat{background:#fff;border-radius:12px;padding:16px 20px;border:1px solid #d4e6c3;text-align:center}
.stat-n{font-size:2rem;font-weight:700}
.stat-n.pending{color:#e65100}
.stat-n.confirmed{color:#2e7d32}
.stat-n.rejected{color:#9e9e9e}
.stat-l{font-size:0.8rem;color:#5a6c5a;margin-top:4px}
.section-title{font-size:1rem;font-weight:700;color:#3d6b41;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #d4e6c3}
.booking-card{background:#fff;border-radius:12px;border:1px solid #e0ead6;margin-bottom:12px;overflow:hidden}
.booking-card.pending{border-left:4px solid #e65100}
.booking-card.confirmed{border-left:4px solid #2e7d32}
.booking-card.rejected{border-left:4px solid #9e9e9e;opacity:0.7}
.card-head{padding:14px 20px;display:flex;align-items:center;gap:12px;cursor:pointer;user-select:none}
.card-head:hover{background:#f9fbf7}
.status-badge{font-size:0.72rem;font-weight:700;letter-spacing:.05em;padding:3px 10px;border-radius:20px;white-space:nowrap}
.card-date{font-weight:700;color:#2d3e2d;font-size:0.95rem}
.card-name{color:#5a6c5a;font-size:0.9rem;margin-left:auto}
.card-chevron{color:#8a9a8a;font-size:0.8rem;margin-left:8px;transition:transform .2s}
.card-body{padding:0 20px 20px;border-top:1px solid #e8f0e0;display:none}
.card-body.open{display:block}
.info-table{width:100%;border-collapse:collapse;margin-top:14px}
.info-table td{padding:7px 0;font-size:0.9rem;border-bottom:1px solid #f0f4ee}
.info-table td:first-child{color:#5a6c5a;width:110px;font-weight:500}
.extras-box{background:#f5f7f2;border-radius:8px;padding:12px 16px;margin-top:12px;font-size:0.85rem}
.extras-box strong{color:#3d6b41;display:block;margin-bottom:6px}
.note-box{background:#fffde7;border-radius:8px;padding:10px 14px;margin-top:8px;font-size:0.85rem;color:#5a4a00;border-left:3px solid #f9a825}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.btn{padding:10px 22px;border:none;border-radius:8px;cursor:pointer;font-size:0.9rem;font-weight:600;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-confirm{background:#2e7d32;color:#fff}
.btn-reject{background:#c62828;color:#fff}
.btn-note{background:#f5f5f5;color:#333;border:1px solid #ddd}
.btn-paid{background:#1565c0;color:#fff}
.btn-kaution{background:#6a1b9a;color:#fff}
.finance-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.finance-card{background:#fff;border-radius:12px;padding:16px 20px;border:1px solid #d4e6c3}
.finance-card .fc-num{font-size:1.8rem;font-weight:700;margin-bottom:2px}
.finance-card .fc-lbl{font-size:0.8rem;color:#5a6c5a}
.finance-card .fc-sub{font-size:0.75rem;color:#8a9a8a;margin-top:4px}
.finance-table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e0ead6;margin-bottom:24px}
.finance-table th{background:#f5f7f2;color:#3d6b41;padding:9px 14px;text-align:left;font-size:0.82rem;font-weight:700;border-bottom:2px solid #d4e6c3}
.finance-table td{padding:9px 14px;font-size:0.88rem;border-bottom:1px solid #f0f4ee;vertical-align:middle}
.finance-table tr:last-child td{border-bottom:none}
.paid-badge{display:inline-flex;align-items:center;gap:6px;background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;border-radius:8px;padding:7px 14px;font-size:0.85rem;font-weight:600}
/* Main tabs */
.main-tabs{display:flex;gap:4px;margin-bottom:24px;background:#fff;padding:6px;border-radius:12px;border:1px solid #d4e6c3}
.main-tab-btn{flex:1;padding:10px;border:none;background:none;border-radius:8px;cursor:pointer;font-size:0.9rem;font-weight:600;color:#5a6c5a;transition:all .15s;position:relative}
.main-tab-btn.active{background:#3d6b41;color:#fff}
.main-tab-btn:hover:not(.active){background:#f0f4ee}
.badge{display:inline-flex;align-items:center;justify-content:center;background:#e53935;color:#fff;border-radius:50%;width:18px;height:18px;font-size:0.7rem;font-weight:700;margin-left:5px;vertical-align:middle}
.main-tab-btn.active .badge{background:rgba(255,255,255,0.3)}
.mtab{display:none}
.mtab.active{display:block}
/* Contact inbox */
.contact-card{background:#fff;border-radius:12px;border:1px solid #e0ead6;margin-bottom:10px;overflow:hidden}
.contact-card.new{border-left:4px solid #3d6b41}
.contact-card.read{border-left:4px solid #e0ead6;opacity:0.8}
.contact-head{padding:14px 20px;display:flex;align-items:center;gap:12px;cursor:pointer}
.contact-head:hover{background:#f9fbf7}
.contact-new-dot{width:8px;height:8px;border-radius:50%;background:#3d6b41;flex-shrink:0}
.contact-body{padding:0 20px 20px;border-top:1px solid #e8f0e0;display:none}
.contact-body.open{display:block}
/* Calendar */
.cal-nav{display:flex;align-items:center;gap:16px;margin-bottom:16px}
.cal-nav button{background:#f0f4ee;border:1px solid #d4e6c3;color:#3d6b41;border-radius:8px;padding:7px 18px;cursor:pointer;font-weight:700;font-size:0.9rem}
.cal-nav button:hover{background:#d4e6c3}
.cal-month{font-size:1.1rem;font-weight:700;color:#2d3e2d;flex:1;text-align:center}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.cal-day-name{text-align:center;font-size:0.75rem;font-weight:700;color:#5a6c5a;padding:6px 0}
.cal-day{min-height:70px;border-radius:8px;border:1px solid #e0ead6;padding:5px;background:#fff;font-size:0.78rem;cursor:default;position:relative}
.cal-day.other-month{background:#f9f9f9;color:#bbb}
.cal-day.today{border-color:#3d6b41;background:#f0f9f0}
.cal-day.blocked{background:#fff0f0;border-color:#f5c6c6}
.cal-day.blocked .day-num{color:#c62828}
.cal-block-label{font-size:0.68rem;color:#c62828;font-weight:600;margin-top:2px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-block-range{font-size:0.68rem;color:#e65100;font-weight:600;margin-top:2px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-day.blocked-range{background:#fff5f0;border-color:#ffccbc}
.cal-day.blocked-range .day-num{color:#bf360c}
.cal-day .day-num{font-weight:700;color:#2d3e2d;margin-bottom:3px}
.cal-booking-dot{border-radius:4px;padding:2px 5px;margin-top:2px;font-size:0.7rem;font-weight:600;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cal-booking-dot.pending{background:#fff3e0;color:#e65100}
.cal-booking-dot.confirmed{background:#e8f5e9;color:#2e7d32}
.cal-booking-dot.rejected{background:#f5f5f5;color:#9e9e9e}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f4ee}
.login-box{background:#fff;border-radius:16px;border:1px solid #d4e6c3;padding:40px;width:100%;max-width:360px;text-align:center}
.login-logo{font-size:2.5rem;margin-bottom:8px}
.login-box h2{color:#3d6b41;margin-bottom:4px}
.login-box p{color:#5a6c5a;font-size:0.85rem;margin-bottom:24px}
.login-input{width:100%;padding:12px 16px;border:2px solid #d4e6c3;border-radius:8px;font-size:1rem;outline:none;margin-bottom:12px}
.login-input:focus{border-color:#3d6b41}
.login-btn{width:100%;padding:12px;background:#3d6b41;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer}
.login-btn:hover{background:#2d5231}
.error-msg{background:#ffebee;color:#c62828;padding:10px;border-radius:8px;font-size:0.85rem;margin-bottom:14px}
.empty{text-align:center;padding:48px;color:#8a9a8a}

/* Modal */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,0.2)}
.modal-header{background:#3d6b41;padding:18px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-header h3{color:#fff;font-size:1rem;font-weight:700}
.modal-close{background:none;border:none;color:rgba(255,255,255,0.8);font-size:1.4rem;cursor:pointer;line-height:1}
.modal-body{padding:24px}
.modal-section{margin-bottom:20px}
.modal-section label{display:block;font-size:0.8rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}
.extra-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap}
.extra-row input[type=checkbox]{width:18px;height:18px;accent-color:#3d6b41;cursor:pointer;flex-shrink:0}
.extra-row .extra-label{flex:1;font-size:0.9rem;min-width:140px}
.extra-row .qty-input{width:60px;padding:6px;border:1px solid #d4e6c3;border-radius:6px;font-size:0.85rem;text-align:center}
.extra-row .price-input{width:80px;padding:6px;border:1px solid #d4e6c3;border-radius:6px;font-size:0.85rem;text-align:right}
.extra-row .unit{font-size:0.8rem;color:#8a9a8a}
.add-extra-btn{background:none;border:1px dashed #3d6b41;color:#3d6b41;padding:7px 14px;border-radius:8px;cursor:pointer;font-size:0.85rem;width:100%;margin-top:4px}
.add-extra-btn:hover{background:#f0f4ee}
textarea.modal-textarea{width:100%;padding:10px 14px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;resize:vertical;min-height:72px;outline:none;font-family:inherit}
textarea.modal-textarea:focus{border-color:#3d6b41}
.price-summary{background:#f5f7f2;border-radius:10px;padding:14px 18px}
.price-row{display:flex;justify-content:space-between;font-size:0.9rem;padding:4px 0}
.price-row.total{font-weight:700;font-size:1rem;border-top:2px solid #d4e6c3;margin-top:8px;padding-top:10px}
.price-row.kaution{color:#8a9a8a;font-size:0.82rem}
.price-row.grand{font-weight:700;color:#3d6b41;border-top:1px solid #d4e6c3;margin-top:6px;padding-top:8px}
.modal-footer{padding:16px 24px;border-top:1px solid #e8f0e0;display:flex;gap:10px;justify-content:flex-end}
.btn-modal-cancel{background:#f5f5f5;color:#333;border:1px solid #ddd;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.9rem;font-weight:600}
.btn-modal-confirm{background:#2e7d32;color:#fff;border:none;padding:10px 24px;border-radius:8px;cursor:pointer;font-size:0.9rem;font-weight:700}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo"><img src="<?= site_url() ?>/images/logo.png" alt="KGV Musterstadt e.V." style="max-height:80px;width:auto"></div>
    <h2>KGV Musterstadt Backoffice</h2>
    <p>Buchungsverwaltung · Muster-Kleingartenverein</p>
    <?php if (!empty($loginError)): ?>
    <div class="error-msg">Falsches Passwort. Bitte erneut versuchen.</div>
    <?php elseif (!empty($loginCsrfFail)): ?>
    <div class="error-msg">Ungültige Anfrage. Bitte Seite neu laden.</div>
    <?php elseif (!empty($_GET['timeout'])): ?>
    <div class="error-msg">Sitzung abgelaufen. Bitte erneut anmelden.</div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="login_token" value="<?= htmlspecialchars($_SESSION['login_token'], ENT_QUOTES) ?>">
      <input class="login-input" type="password" name="password" placeholder="Notfall-Passwort" autofocus required autocomplete="current-password">
      <button class="login-btn" type="submit">Anmelden</button>
    </form>
    <p style="margin-top:16px;font-size:0.8rem;color:#5a6c5a;border-top:1px solid #e0ead6;padding-top:12px">
      Vorstandsmitglieder melden sich über den<br>
      <a href="/mitglieder.php" style="color:#3d6b41;font-weight:600">👥 Mitglieder-Bereich</a> an.
    </p>
  </div>
</div>

<?php else: ?>
<?php include __DIR__ . '/../inc/admin_nav.php'; ?>

<div class="main">
  <div class="stats">
    <div class="stat"><div class="stat-n pending"><?= $stats['pending'] ?></div><div class="stat-l">Offen / Neu</div></div>
    <div class="stat"><div class="stat-n confirmed"><?= $stats['confirmed'] ?></div><div class="stat-l">Bestätigt</div></div>
    <div class="stat"><div class="stat-n rejected"><?= $stats['rejected'] ?></div><div class="stat-l">Abgelehnt</div></div>
  </div>

  <?php if ($usingDefaultPass): ?>
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:0.88rem;color:#856404">
    <span style="font-size:1.2rem">⚠️</span>
    <span>Standard-Passwort aktiv — bitte unter <strong><a href="/intern/content.php?tab=settings" style="color:#856404">Einstellungen → Passwort ändern</a></strong> ein sicheres Passwort setzen.</span>
  </div>
  <?php endif; ?>

  <!-- Main tab navigation -->
  <div class="main-tabs">
    <button class="main-tab-btn" id="mtbtn-dashboard" onclick="switchMainTab('dashboard')">🏠 Dashboard</button>
    <?php if ($canSeeBookings): ?>
    <button class="main-tab-btn" id="mtbtn-bookings" onclick="switchMainTab('bookings')">📋 Buchungen</button>
    <?php endif; ?>
    <?php if ($canSeeContacts): ?>
    <button class="main-tab-btn" id="mtbtn-contacts" onclick="switchMainTab('contacts')">📬 Kontaktanfragen<?php if ($newContactsCount > 0): ?><span class="badge"><?= $newContactsCount ?></span><?php endif; ?></button>
    <?php endif; ?>
    <?php if ($canSeeCalendar): ?>
    <button class="main-tab-btn" id="mtbtn-calendar" onclick="switchMainTab('calendar')">📅 Kalender</button>
    <?php endif; ?>
    <?php if ($canSeeMembers): ?>
    <button class="main-tab-btn" id="mtbtn-members" onclick="switchMainTab('members')">👥 Mitglieder<?php if ($pendingRequestsCount > 0): ?><span class="badge"><?= $pendingRequestsCount ?></span><?php endif; ?></button>
    <?php endif; ?>
    <button class="main-tab-btn" id="mtbtn-todos" onclick="switchMainTab('todos')">✅ ToDo's<?php if ($openTodosCount > 0): ?><span class="badge" style="background:#e65100"><?= $openTodosCount ?></span><?php endif; ?></button>
    <?php if ($canEditContent): ?>
    <button class="main-tab-btn" id="mtbtn-termine" onclick="switchMainTab('termine')">📅 Termine</button>
    <?php endif; ?>
    <?php if ($_canManageEvents): ?>
    <?php
      // Pending-Counter über alle Events (Anmeldungen die nicht storniert sind)
      $_evAllForBadge = function_exists('kgv_events_all') ? kgv_events_all() : [];
      $_evRegCount = 0;
      foreach ($_evAllForBadge as $_ev) {
          foreach (($_ev['registrations'] ?? []) as $_r) {
              if (empty($_r['cancelled_at'])) $_evRegCount++;
          }
      }
    ?>
    <button class="main-tab-btn" id="mtbtn-events" onclick="switchMainTab('events')">🎪 Veranstaltungen<?php if ($_evRegCount > 0): ?><span class="badge"><?= $_evRegCount ?></span><?php endif; ?></button>
    <?php endif; ?>
    <?php
    // Schriftführung-Cockpit: SuperAdmin + Schriftführer + Web-Rolle.
    // Vorstand bleibt vorerst ausgeschlossen, kann später ergänzt werden:
    // || in_array('vorstand', $_memberRoles, true)
    $_canSF = $isSuperAdmin
        || in_array('schriftfuehrer', $_memberRoles, true)
        || in_array('web', $_memberRoles, true);
    if ($_canSF):
        require_once dirname(__DIR__) . '/inc/schriftfuehrung.php';
        $_sfCounts = sf_inbox_counts();
        $_sfBadge  = $_sfCounts['birthdays'] + $_sfCounts['anniversaries'];
    ?>
    <button class="main-tab-btn" id="mtbtn-schriftfuehrung" onclick="switchMainTab('schriftfuehrung')" style="color:#5e35b1">📋 Schriftführung<?php if ($_sfBadge > 0): ?><span class="badge" style="background:#5e35b1"><?= $_sfBadge ?></span><?php endif; ?></button>
    <?php endif; ?>
  </div>

  <!-- Tab: Dashboard -->
  <div id="mtab-dashboard" class="mtab">

  <!-- Quick-Stats-Kacheln -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px">
    <?php if ($canSeeBookings): ?>
    <div onclick="switchMainTab('bookings')" style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:16px 20px;text-align:center;cursor:pointer;border-left:4px solid #e65100">
      <div style="font-size:1.8rem;font-weight:700;color:#e65100"><?= $stats['pending'] ?></div>
      <div style="font-size:0.78rem;color:#5a6c5a;margin-top:4px">📋 Offene Buchungen</div>
    </div>
    <?php endif; ?>
    <?php if ($canSeeContacts): ?>
    <div onclick="switchMainTab('contacts')" style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:16px 20px;text-align:center;cursor:pointer;border-left:4px solid<?= $newContactsCount > 0 ? ' #1565c0' : ' #9e9e9e' ?>">
      <div style="font-size:1.8rem;font-weight:700;color:<?= $newContactsCount > 0 ? '#1565c0' : '#9e9e9e' ?>"><?= $newContactsCount ?></div>
      <div style="font-size:0.78rem;color:#5a6c5a;margin-top:4px">📬 Neue Kontaktanfragen</div>
    </div>
    <?php endif; ?>
    <?php if ($canSeeMembers): ?>
    <div onclick="switchMainTab('members')" style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:16px 20px;text-align:center;cursor:pointer;border-left:4px solid<?= $pendingRequestsCount > 0 ? ' #7b1fa2' : ' #9e9e9e' ?>">
      <div style="font-size:1.8rem;font-weight:700;color:<?= $pendingRequestsCount > 0 ? '#7b1fa2' : '#9e9e9e' ?>"><?= $pendingRequestsCount ?></div>
      <div style="font-size:0.78rem;color:#5a6c5a;margin-top:4px">👥 Anfragen &amp; Nachrichten</div>
    </div>
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:16px 20px;text-align:center;border-left:4px solid #2e7d32">
      <div style="font-size:1.8rem;font-weight:700;color:#2e7d32"><?= count($allMembers) ?></div>
      <div style="font-size:0.78rem;color:#5a6c5a;margin-top:4px">🌱 Aktive Mitglieder</div>
    </div>
    <?php endif; ?>
    <?php if ($canSeeBookings && $finance['paid_count'] > 0): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:16px 20px;text-align:center;border-left:4px solid #f9a825">
      <div style="font-size:1.4rem;font-weight:700;color:#f57f17"><?= number_format(($finance['monthly'][$thisMonthKey]['miete'] ?? 0) + ($finance['monthly'][$thisMonthKey]['extras'] ?? 0), 0, ',', '.') ?> €</div>
      <div style="font-size:0.78rem;color:#5a6c5a;margin-top:4px">💰 Einnahmen <?= date('M Y') ?></div>
    </div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- Upcoming Bookings -->
    <?php if ($canSeeBookings): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:20px">
      <div class="section-title" style="margin-bottom:14px">📅 Nächste 14 Tage</div>
      <?php if (empty($dashUpcoming)): ?>
        <div style="color:#8a9a8a;font-size:0.85rem">Keine bestätigten Buchungen in den nächsten 14 Tagen.</div>
      <?php else: foreach ($dashUpcoming as $_ub): $_ud = $_ub['dates'][0] ?? $_ub['date'] ?? ''; ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0f4ee">
          <div style="min-width:70px;font-size:0.78rem;font-weight:700;color:#3d6b41;background:#e8f5e9;padding:3px 7px;border-radius:6px;text-align:center"><?= $_ud ? (new DateTime($_ud))->format('d.m.') : '' ?></div>
          <div>
            <div style="font-size:0.88rem;font-weight:600"><?= htmlspecialchars($_ub['name'] ?? '') ?></div>
            <div style="font-size:0.75rem;color:#5a6c5a"><?= htmlspecialchars($_ub['purpose'] ?? '') ?></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <!-- Auslastung (Buchungen pro Monat) -->
    <?php if ($canSeeBookings && !empty($finance['monthly'])): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:20px">
      <div class="section-title" style="margin-bottom:14px">📊 Einnahmen pro Monat</div>
      <?php
        $_maxRev = max(array_map(fn($m) => $m['miete'] + $m['extras'], $finance['monthly']));
        $_monthNames = ['01'=>'Jan','02'=>'Feb','03'=>'Mär','04'=>'Apr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Dez'];
        foreach ($finance['monthly'] as $_mk => $_mv):
            $_rev = $_mv['miete'] + $_mv['extras'];
            $_pct = $_maxRev > 0 ? round($_rev / $_maxRev * 100) : 0;
            [$_my, $_mm] = explode('-', $_mk);
      ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
        <div style="width:36px;font-size:0.72rem;color:#5a6c5a;text-align:right"><?= ($_monthNames[$_mm] ?? $_mm) . ' ' . substr($_my, 2) ?></div>
        <div style="flex:1;background:#f0f4ee;border-radius:4px;height:16px;position:relative">
          <div style="width:<?= $_pct ?>%;background:<?= $_mk === $thisMonthKey ? '#3d6b41' : '#8bc34a' ?>;height:100%;border-radius:4px;transition:width .3s"></div>
        </div>
        <div style="width:55px;font-size:0.72rem;color:#2d3e2d;font-weight:600;text-align:right"><?= number_format($_rev, 0, ',', '.') ?> €</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php elseif ($canSeeBookings): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:20px">
      <div class="section-title" style="margin-bottom:14px">📊 Auslastung</div>
      <div style="color:#8a9a8a;font-size:0.85rem">Noch keine bezahlten Buchungen vorhanden.</div>
    </div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- Geburtstage -->
    <?php if ($canSeeMembers): ?>
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:20px">
      <div class="section-title" style="margin-bottom:14px">🎂 Geburtstage (30 Tage)</div>
      <?php if (empty($dashBirthdays)): ?>
        <div style="color:#8a9a8a;font-size:0.85rem">Keine Geburtstage in den nächsten 30 Tagen.</div>
      <?php else: foreach ($dashBirthdays as $_bd): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f0f4ee">
          <div style="min-width:70px;font-size:0.78rem;font-weight:700;color:#ad1457;background:#fce4ec;padding:3px 7px;border-radius:6px;text-align:center"><?= date('d.m.', strtotime($_bd['date'])) ?></div>
          <div>
            <div style="font-size:0.88rem;font-weight:600"><?= htmlspecialchars($_bd['name']) ?></div>
            <div style="font-size:0.72rem;color:#5a6c5a"><?= $_bd['diff'] === 0 ? '🎉 Heute!' : 'in ' . $_bd['diff'] . ' Tag' . ($_bd['diff'] === 1 ? '' : 'en') ?><?= $_bd['parzelle'] !== '' ? ' · Parzelle ' . htmlspecialchars($_bd['parzelle']) : '' ?></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <!-- Aktivitätsprotokoll -->
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:20px">
      <div class="section-title" style="margin-bottom:14px">📋 Letzte Aktionen</div>
      <?php if (empty($dashActivity)): ?>
        <div style="color:#8a9a8a;font-size:0.85rem">Noch keine Aktionen protokolliert.</div>
      <?php else: foreach ($dashActivity as $_act): ?>
        <div style="padding:6px 0;border-bottom:1px solid #f0f4ee;font-size:0.8rem">
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
            <span style="font-weight:600;color:#2d3e2d"><?= htmlspecialchars($_act['action'] ?? '') ?></span>
            <span style="font-size:0.7rem;color:#9aaa9a;white-space:nowrap"><?= htmlspecialchars(substr($_act['ts'] ?? '', 0, 16)) ?></span>
          </div>
          <?php if (!empty($_act['detail'])): ?><div style="color:#5a6c5a;margin-top:1px"><?= htmlspecialchars($_act['detail']) ?></div><?php endif; ?>
          <div style="font-size:0.68rem;color:#8a9a8a"><?= htmlspecialchars($_act['user'] ?? '') ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div>

  </div><!-- /mtab-dashboard -->

  <!-- Tab: Buchungen -->
  <div id="mtab-bookings" class="mtab">

  <?php if ($finance['paid_count'] > 0): ?>
  <div class="section-title" style="margin-bottom:12px">💰 Finanzübersicht</div>
  <div class="finance-grid" style="grid-template-columns:repeat(2,1fr)">
    <div class="finance-card" style="border-left:4px solid #2e7d32">
      <div class="fc-num" style="color:#2e7d32"><?= number_format($finance['revenue'], 2, ',', '.') ?> €</div>
      <div class="fc-lbl">Gesamteinnahmen (Netto)</div>
      <div class="fc-sub" style="margin-top:8px;display:flex;flex-direction:column;gap:3px">
        <span>🏠 Raummiete: <strong><?= number_format($finance['revenue_miete'], 2, ',', '.') ?> €</strong></span>
        <span>➕ Extras: <strong style="color:<?= $finance['revenue_extras'] > 0 ? '#1565c0' : '#9e9e9e' ?>"><?= number_format($finance['revenue_extras'], 2, ',', '.') ?> €</strong></span>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="finance-card" style="border-left:4px solid #6a1b9a;padding:12px 20px">
        <div class="fc-num" style="color:#6a1b9a;font-size:1.4rem"><?= number_format($finance['kaution_open'], 2, ',', '.') ?> €</div>
        <div class="fc-lbl">Kautionen in Verwahrung</div>
      </div>
      <div class="finance-card" style="border-left:4px solid #9e9e9e;padding:12px 20px">
        <div class="fc-num" style="color:#9e9e9e;font-size:1.4rem"><?= number_format($finance['kaution_returned'], 2, ',', '.') ?> €</div>
        <div class="fc-lbl">Kautionen zurückgezahlt</div>
      </div>
    </div>
  </div>

  <?php if (!empty($finance['extras_stats'])): ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
    <!-- Extras-Statistik -->
    <div>
      <div class="section-title" style="margin-bottom:10px">📦 Zubehör-Statistik</div>
      <table class="finance-table">
        <tr><th>Zusatzleistung</th><th style="text-align:center">Buchungen</th><th style="text-align:right">Einnahmen</th></tr>
        <?php foreach ($finance['extras_stats'] as $label => $stat): ?>
        <tr>
          <td><?= htmlspecialchars($label) ?></td>
          <td style="text-align:center">
            <span style="background:#e8f5e9;color:#2e7d32;border-radius:12px;padding:2px 10px;font-size:0.82rem;font-weight:700"><?= $stat['count'] ?>×</span>
            <?php if ($stat['qty'] > $stat['count']): ?>
            <span style="color:#8a9a8a;font-size:0.78rem">(<?= $stat['qty'] ?> Stk.)</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;font-weight:600;color:#1565c0"><?= number_format($stat['revenue'], 2, ',', '.') ?> €</td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <!-- Monatliche Übersicht -->
    <?php if (!empty($finance['monthly'])): ?>
    <div>
      <div class="section-title" style="margin-bottom:10px">📅 Einnahmen nach Monat</div>
      <table class="finance-table">
        <tr><th>Monat</th><th style="text-align:center">Buchungen</th><th style="text-align:right">Einnahmen</th></tr>
        <?php
        $monthNames = ['01'=>'Jan','02'=>'Feb','03'=>'Mär','04'=>'Apr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Dez'];
        foreach (array_reverse($finance['monthly'], true) as $ym => $m):
          [$y, $mo] = explode('-', $ym);
        ?>
        <tr>
          <td><?= ($monthNames[$mo] ?? $mo) . ' ' . $y ?></td>
          <td style="text-align:center">
            <span style="background:#e8f5e9;color:#2e7d32;border-radius:12px;padding:2px 10px;font-size:0.82rem;font-weight:700"><?= $m['count'] ?></span>
          </td>
          <td style="text-align:right">
            <span style="font-weight:700;color:#2e7d32"><?= number_format($m['miete'] + $m['extras'], 2, ',', '.') ?> €</span>
            <?php if ($m['extras'] > 0): ?>
            <br><span style="font-size:0.76rem;color:#8a9a8a">+<?= number_format($m['extras'], 2, ',', '.') ?> € Extras</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <table class="finance-table" style="margin-bottom:32px">
    <tr>
      <th>Buchung</th><th>Datum</th><th>Bezahlt am</th><th style="text-align:right">Raummiete + Extras</th><th style="text-align:right">Kaution (200 €)</th>
    </tr>
    <?php foreach ($finance['paid_rows'] as $fr): ?>
    <tr>
      <td>
        <?= htmlspecialchars($fr['name']) ?>
        <?php if ($fr['is_member_tarif']): ?>
          <span style="display:inline-block;margin-left:6px;background:#e8f5e9;color:#2e7d32;font-size:0.7rem;font-weight:700;padding:1px 7px;border-radius:10px">Mitglied</span>
        <?php endif; ?>
      </td>
      <td style="color:#5a6c5a;font-size:0.82rem"><?= htmlspecialchars($fr['dates']) ?></td>
      <td style="color:#5a6c5a;font-size:0.82rem"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($fr['paid_at']))) ?></td>
      <td style="text-align:right;font-weight:700;color:#2e7d32">
        <?= number_format($fr['net'], 2, ',', '.') ?> €
        <?php if ($fr['is_member_tarif']): ?>
          <br><span style="font-size:0.72rem;color:#8a9a8a;font-weight:400">(<?= number_format($_mieteMemberCfg, 0, ',', '.') ?> € Mitgliedstarif)</span>
        <?php endif; ?>
      </td>
      <td style="text-align:right">
        <?php if ($fr['kaution_returned_at'] === ''): ?>
          <button class="btn btn-kaution" type="button" style="padding:5px 12px;font-size:0.78rem"
            onclick="openKautionModal('<?= htmlspecialchars($fr['id']) ?>')">
            Kaution abrechnen
          </button>
        <?php else:
          $settlement = $fr['kaution_settlement'] ?? null; ?>
          <?php if ($settlement): ?>
            <span style="color:#2e7d32;font-size:0.82rem;font-weight:600">
              ✓ <?= number_format($settlement['returned_amount'], 2, ',', '.') ?> €
            </span>
            <br><span style="color:#9e9e9e;font-size:0.75rem">
              zurück · <?= htmlspecialchars(date('d.m.Y', strtotime($fr['kaution_returned_at']))) ?>
              <?php if (($settlement['total_deductions'] ?? 0) > 0): ?>
                <br>−<?= number_format($settlement['total_deductions'], 2, ',', '.') ?> € Abzüge
              <?php endif; ?>
            </span>
          <?php else: ?>
            <span style="color:#9e9e9e;font-size:0.82rem">✓ <?= number_format($_kautionCfg, 2, ',', '.') ?> € · <?= htmlspecialchars(date('d.m.Y', strtotime($fr['kaution_returned_at']))) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f5f7f2">
      <td colspan="3" style="font-weight:700;color:#3d6b41">Gesamt</td>
      <td style="text-align:right;font-weight:700;color:#2e7d32"><?= number_format($finance['revenue'], 2, ',', '.') ?> €</td>
      <td></td>
    </tr>
  </table>
  <?php endif; ?>

  <div style="margin-bottom:20px">
    <a href="/intern/export.php" class="btn btn-note" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;padding:9px 20px;font-size:0.88rem">📥 CSV exportieren</a>
  </div>

  <?php if (empty($bookings)): ?>
  <div class="empty">Noch keine Buchungsanfragen vorhanden.</div>
  <?php else: ?>

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <div class="section-title" style="margin:0">Alle Buchungsanfragen (<?= count($bookings) ?>)</div>
    <button onclick="document.getElementById('createBookingModal').style.display='flex'" style="background:#3d6b41;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer">➕ Buchung manuell eintragen</button>
  </div>

  <!-- Modal: Buchung manuell anlegen -->
  <div id="createBookingModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:16px;overflow-y:auto">
    <div style="background:#fff;border-radius:14px;padding:28px;max-width:520px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <strong style="font-size:1rem;color:#2d3e2d">➕ Buchung manuell eintragen</strong>
        <button onclick="document.getElementById('createBookingModal').style.display='none'" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#8a9a8a">✕</button>
      </div>
      <form method="POST" action="/intern/action.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="create_booking">
        <input type="hidden" name="booking_id" value="">
        <input type="hidden" name="extras" value="[]">
        <input type="hidden" name="cb_is_member" id="cb_is_member" value="0">
        <datalist id="cb-member-list">
          <?php foreach ($allMembers as $_cm): if (empty($_cm['active'])) continue; ?>
          <option value="<?= htmlspecialchars($_cm['name'] ?? '') ?>" data-email="<?= htmlspecialchars($_cm['email'] ?? '') ?>">
          <?php endforeach; ?>
        </datalist>
        <div style="display:grid;gap:12px">
          <div>
            <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Mitglied auswählen <span style="font-weight:400;color:#8a9a8a">(optional — füllt Name &amp; E-Mail automatisch)</span></label>
            <input type="text" id="cb_member_picker" list="cb-member-list" placeholder="Name eintippen…" autocomplete="off"
              style="width:100%;padding:8px 12px;border:1.5px solid #a5d6a7;border-radius:8px;font-size:0.9rem;font-family:inherit;background:#f0f7f0"
              oninput="cbFillMember(this.value)">
          </div>
          <div>
            <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Name *</label>
            <input type="text" name="cb_name" id="cb_name" required placeholder="Vor- und Nachname" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">E-Mail</label>
              <input type="email" name="cb_email" id="cb_email" placeholder="name@beispiel.de" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
            </div>
            <div>
              <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Telefon</label>
              <input type="text" name="cb_phone" placeholder="040 …" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
            </div>
          </div>
          <div>
            <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:6px">Datum(e) auswählen *</label>
            <!-- Mini-Kalender -->
            <div style="border:1.5px solid #d4e6c3;border-radius:10px;padding:12px;background:#f9fbf7">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <button type="button" onclick="cbCalPrev()" style="background:none;border:1px solid #d4e6c3;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:1rem">‹</button>
                <span id="cbCalMonth" style="font-size:0.9rem;font-weight:700;color:#2d3e2d"></span>
                <button type="button" onclick="cbCalNext()" style="background:none;border:1px solid #d4e6c3;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:1rem">›</button>
              </div>
              <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center;margin-bottom:6px">
                <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $d): ?>
                <div style="font-size:0.68rem;font-weight:700;color:#5a6c5a;padding:2px"><?= $d ?></div>
                <?php endforeach; ?>
              </div>
              <div id="cbCalGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px"></div>
              <!-- Legende -->
              <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;font-size:0.68rem;color:#5a6c5a">
                <span><span style="display:inline-block;width:10px;height:10px;background:#3d6b41;border-radius:2px;margin-right:3px"></span>Ausgewählt</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:#ffebee;border:1px solid #ffcdd2;border-radius:2px;margin-right:3px"></span>Bestätigt</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:#fff8e1;border:1px solid #ffe082;border-radius:2px;margin-right:3px"></span>Anfrage</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:#f5f5f5;border:1px solid #ddd;border-radius:2px;margin-right:3px"></span>Sperrtag</span>
              </div>
            </div>
            <!-- Ausgewählte Tage als Chips -->
            <div id="cbDatesChips" style="display:flex;flex-wrap:wrap;gap:5px;min-height:24px;margin-top:8px"></div>
            <div id="cbDatesHint" style="font-size:0.75rem;color:#8a9a8a;margin-top:3px">Klicke auf Tage um sie auszuwählen/abzuwählen. Mehrfachauswahl möglich.</div>
            <input type="hidden" name="cb_dates" id="cbDatesInput" required>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Veranstaltungsart</label>
              <input type="text" name="cb_purpose" placeholder="Geburtstag, Vereinstreffen …" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
            </div>
            <div>
              <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Personen</label>
              <input type="number" name="cb_guests" value="1" min="1" max="120" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
            </div>
          </div>
          <!-- Tarif-Anzeige -->
          <div id="cb_tariff_box" style="display:none;background:#e8f5e9;border:1.5px solid #a5d6a7;border-radius:10px;padding:10px 14px;font-size:0.87rem;color:#2d6b31">
            👤 <strong>Mitgliedertarif wird angewendet</strong> — <?= number_format($_mieteMemberCfg, 0, ',', '.') ?> € / Tag (statt <?= number_format($_mieteCfg, 0, ',', '.') ?> €)
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center">
            <div>
              <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Status</label>
              <select name="cb_status" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
                <option value="confirmed">✅ Bestätigt</option>
                <option value="pending">⏳ Offen</option>
              </select>
            </div>
            <div style="padding-top:22px">
              <label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;cursor:pointer">
                <input type="checkbox" name="cb_paid" value="1"> Bereits bezahlt
              </label>
            </div>
          </div>
          <div>
            <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Interne Notiz</label>
            <textarea name="cb_note" rows="2" placeholder="Nur intern sichtbar …" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit;resize:vertical"></textarea>
          </div>
          <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer;width:100%">Buchung anlegen</button>
        </div>
      </form>
    </div>
  </div>

  <?php foreach ($bookings as $b):
    $st      = $b['status'] ?? 'pending';
    $id      = htmlspecialchars($b['id'] ?? '');
    $name    = htmlspecialchars($b['name'] ?? '—');
    $email   = htmlspecialchars($b['email'] ?? '');
    $phone   = htmlspecialchars($b['phone'] ?? '');
    $purp    = htmlspecialchars($b['purpose'] ?? '');
    $guests  = (int)($b['guests'] ?? 0);
    $created = htmlspecialchars($b['created_at'] ?? '');
    $datesFmt = htmlspecialchars(formatDates($b));
    $extras        = $b['extras'] ?? [];
    $isMemberTarif = !empty($b['is_member_tarif']);
    $bRaummiete    = (float)($b['raummiete'] ?? 300.0);
    $adminNote = htmlspecialchars($b['admin_note'] ?? '');
    $adminMsg  = htmlspecialchars($b['admin_message'] ?? '');
    $paidAt    = $b['paid_at'] ?? '';
  ?>
  <div class="booking-card <?= $st ?>">
    <div class="card-head" onclick="toggleCard('<?= $id ?>')">
      <span class="status-badge" style="background:<?= $statusBg[$st] ?? '#f5f5f5' ?>;color:<?= $statusColor[$st] ?? '#333' ?>">
        <?= $statusLabel[$st] ?? $st ?>
      </span>
      <?php if (!empty($b['cancel_requested'])): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:#fce8e6;color:#c62828;border:1px solid #f5c6c2;border-radius:6px;padding:2px 8px;font-size:0.75rem;font-weight:600;flex-shrink:0">🔄 Stornierung beantragt</span>
      <?php endif; ?>
      <?php if ($st === 'confirmed'): ?>
        <?php if ($paidAt !== ''): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;border-radius:6px;padding:2px 8px;font-size:0.75rem;font-weight:600;flex-shrink:0">💶 Bezahlt</span>
        <?php else: ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:#fff3e0;color:#e65100;border:1px solid #ffcc80;border-radius:6px;padding:2px 8px;font-size:0.75rem;font-weight:600;flex-shrink:0">⏳ Zahlung offen</span>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($isMemberTarif): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;border-radius:6px;padding:2px 8px;font-size:0.75rem;font-weight:600;flex-shrink:0">👤 Mitglied · <?= number_format($bRaummiete, 0, ',', '.') ?> €</span>
      <?php endif; ?>
      <span class="card-date"><?= $datesFmt ?></span>
      <span class="card-name"><?= $name ?></span>
      <span class="card-chevron" id="chev_<?= $id ?>">▼</span>
    </div>
    <div class="card-body" id="body_<?= $id ?>">
      <table class="info-table">
        <tr><td>Name</td><td><?= $name ?></td></tr>
        <tr><td>E-Mail</td><td>
          <span id="email_display_<?= $id ?>">
            <a href="mailto:<?= $email ?>" style="color:#3d6b41"><?= $email ?></a>
            <button onclick="showEmailEdit('<?= $id ?>','<?= $email ?>')"
                    style="background:none;border:none;cursor:pointer;font-size:0.8rem;color:#8a9a8a;padding:0 4px;vertical-align:middle"
                    title="E-Mail korrigieren">✏️</button>
          </span>
          <span id="email_edit_<?= $id ?>" style="display:none;gap:6px;align-items:center;flex-wrap:wrap">
            <input type="email" id="email_input_<?= $id ?>" value="<?= $email ?>" required
                   style="padding:4px 8px;border:1.5px solid #3d6b41;border-radius:6px;font-size:0.88rem;font-family:inherit;min-width:220px">
            <button class="btn btn-paid" style="font-size:0.82rem;padding:4px 12px"
                    onclick="saveEmailEdit('<?= $id ?>','<?= htmlspecialchars($csrf, ENT_QUOTES) ?>')">✓ Speichern</button>
            <button type="button" onclick="hideEmailEdit('<?= $id ?>')"
                    class="btn btn-note" style="font-size:0.82rem;padding:4px 10px">Abbrechen</button>
          </span>
        </td></tr>
        <tr><td>Telefon</td><td><?= $phone ?></td></tr>
        <?php if ($purp !== ''): ?><tr><td>Anlass</td><td><?= $purp ?></td></tr><?php endif; ?>
        <?php if ($guests > 0): ?><tr><td>Personen</td><td><?= $guests ?></td></tr><?php endif; ?>
        <tr><td>Eingegangen</td><td><?= $created ?></td></tr>
        <tr><td>Booking-ID</td><td style="font-family:monospace;font-size:0.8rem"><?= $id ?></td></tr>
      </table>

      <?php
      // Preisübersicht IMMER zeigen (auch ohne Extras) — damit Sandra die Endreinigung sehen kann
      $extrasTotal = $bRaummiete;
      foreach ($extras as $ex) {
          $ep = (float)($ex['price'] ?? 0);
          $eq = (int)($ex['qty'] ?? 1);
          $extrasTotal += $ep * $eq;
      }
      ?>
      <div class="extras-box">
        <strong><?= !empty($extras) ? 'Gebuchte Extras' : 'Preisübersicht' ?></strong>
        <?php foreach ($extras as $ex):
          $ep = (float)($ex['price'] ?? 0);
          $eq = (int)($ex['qty'] ?? 1);
        ?>
        <div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #e8f0e0">
          <span><?= htmlspecialchars($ex['label'] ?? '') ?><?= $eq > 1 ? ' × ' . $eq : '' ?></span>
          <span><?= number_format($ep * $eq, 2, ',', '.') ?> €</span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;font-weight:700;padding-top:6px;border-top:2px solid #d4e6c3;margin-top:4px">
          <span>Gesamt (inkl. <?= number_format($bRaummiete, 0, ',', '.') ?> € Miete<?= $isMemberTarif ? ' – Mitgliedertarif' : '' ?>)</span>
          <span><?= number_format($extrasTotal, 2, ',', '.') ?> €</span>
        </div>
        <?php
          $_bEndrein  = $isMemberTarif ? $_endreinigungMemberCfg : $_endreinigungCfg;
        ?>
        <?php if ($_bEndrein > 0): ?>
        <div style="display:flex;justify-content:space-between;padding-top:4px;color:#8a9a8a;font-size:0.82rem">
          <span>+ Endreinigung <span style="color:#5a6c5a">(verpflichtend, besenrein übergeben)</span></span>
          <span><?= number_format($_bEndrein, 2, ',', '.') ?> €</span>
        </div>
        <?php else: ?>
        <div style="display:flex;justify-content:space-between;padding-top:4px;color:#2e7d32;font-size:0.82rem;font-weight:700">
          <span>+ Endreinigung <span style="font-weight:400">(Mitglied — frei!)</span></span>
          <span>0,00 €</span>
        </div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;padding-top:4px;color:#8a9a8a;font-size:0.82rem">
          <span>+ Kaution (wird zurückerstattet)</span>
          <span><?= number_format($_kautionCfg, 2, ',', '.') ?> €</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-weight:700;padding-top:5px;border-top:1px solid #d4e6c3;margin-top:4px;color:#3d6b41">
          <span>Zu überweisen</span>
          <span><?= number_format($extrasTotal + $_bEndrein + $_kautionCfg, 2, ',', '.') ?> €</span>
        </div>
      </div>

      <div id="note-display-<?= $id ?>" <?= $adminNote === '' ? 'style="display:none"' : '' ?>>
        <div class="note-box">📝 <strong>Interne Notiz:</strong> <span id="note-text-<?= $id ?>"><?= $adminNote ?></span>
          <button type="button" onclick="toggleNoteEdit('<?= $id ?>')"
            style="background:none;border:none;color:#3d6b41;cursor:pointer;font-size:0.8rem;margin-left:8px;text-decoration:underline;padding:0">bearbeiten</button>
        </div>
      </div>
      <div id="note-edit-<?= $id ?>" style="display:none;margin-bottom:10px">
        <form method="POST" action="/intern/action.php">
          <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="booking_id" value="<?= $id ?>">
          <input type="hidden" name="action"     value="update_note">
          <textarea name="admin_note" rows="2"
            style="width:100%;box-sizing:border-box;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.85rem;resize:vertical"
            placeholder="Interne Notiz …"><?= $adminNote ?></textarea>
          <div style="display:flex;gap:8px;margin-top:6px">
            <button type="submit" class="btn btn-note" style="font-size:0.82rem;padding:6px 14px">💾 Speichern</button>
            <button type="button" onclick="toggleNoteEdit('<?= $id ?>')"
              style="background:none;border:none;color:#8a9a8a;cursor:pointer;font-size:0.82rem">Abbrechen</button>
          </div>
        </form>
      </div>
      <?php if ($adminMsg !== ''): ?>
      <div class="note-box" style="background:#e8f5e9;border-color:#3d6b41;color:#1b3a1d">✉️ <strong>Nachricht an Kunden:</strong> <?= $adminMsg ?></div>
      <?php endif; ?>

      <?php if ($st === 'pending'): ?>
      <div class="actions">
        <button class="btn btn-confirm" onclick="openConfirmModal(<?= htmlspecialchars(json_encode([
          'id'       => $b['id'] ?? '',
          'name'     => $b['name'] ?? '',
          'dates'    => $datesFmt,
          'purpose'  => $b['purpose'] ?? '',
          'guests'   => $guests,
          'isMember' => !empty($b['is_member_tarif']),
        ]), ENT_QUOTES) ?>)">
          ✓ Bestätigen & E-Mail senden
        </button>
        <form method="POST" action="/intern/action.php" style="display:inline">
          <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="booking_id" value="<?= $id ?>">
          <input type="hidden" name="action"     value="reject">
          <button class="btn btn-reject" type="submit"
            onclick="return confirm('Anfrage von <?= addslashes($name) ?> ablehnen?')">
            ✕ Ablehnen & E-Mail senden
          </button>
        </form>
      </div>
      <?php elseif ($st === 'confirmed'): ?>
      <div class="actions">
        <?php if ($paidAt === ''): ?>
        <form method="POST" action="/intern/action.php">
          <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="booking_id" value="<?= $id ?>">
          <input type="hidden" name="action"     value="mark_paid">
          <button class="btn btn-paid" type="submit">💶 Zahlung eingegangen</button>
        </form>
        <?php else: ?>
        <div>
          <div class="paid-badge">
            ✓ Bezahlt am <?= htmlspecialchars(date('d.m.Y \u\m H:i \U\h\r', strtotime($paidAt))) ?>
            <button type="button" onclick="toggleUnpaid('<?= $id ?>')"
              style="background:none;border:none;color:#1565c0;cursor:pointer;font-size:0.8rem;margin-left:8px;text-decoration:underline;padding:0">
              zurücksetzen
            </button>
          </div>
          <div id="unpaid_<?= $id ?>" style="display:none;margin-top:8px;background:#fff3e0;border:1px solid #ffb74d;border-radius:8px;padding:10px 14px;font-size:0.85rem">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#e65100">
              <input type="checkbox" id="unpaid_cb_<?= $id ?>" onchange="toggleUnpaidBtn('<?= $id ?>')">
              Ja, ich möchte die Zahlung zurücksetzen
            </label>
            <form method="POST" action="/intern/action.php" style="margin-top:8px">
              <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="booking_id" value="<?= $id ?>">
              <input type="hidden" name="action"     value="unmark_paid">
              <button id="unpaid_btn_<?= $id ?>" class="btn btn-note" type="submit" disabled
                style="font-size:0.82rem;padding:6px 14px;opacity:0.4">
                Zahlung entfernen
              </button>
              <button type="button" onclick="toggleUnpaid('<?= $id ?>')"
                style="background:none;border:none;color:#8a9a8a;cursor:pointer;font-size:0.82rem;margin-left:8px">
                Abbrechen
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-note" onclick="toggleNoteEdit('<?= $id ?>')"
          style="font-size:0.82rem;padding:6px 14px">✏️ Notiz</button>
        <form method="POST" action="/intern/action.php">
          <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="booking_id" value="<?= $id ?>">
          <input type="hidden" name="action"     value="reject">
          <button class="btn btn-note" type="submit"
            onclick="return confirm('Bestätigte Buchung stornieren und Absage senden?')">
            Stornieren & Absage senden
          </button>
        </form>
      </div>
      <?php endif; ?>
      <?php if ($canDeleteBooking): ?>
      <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e0ead6">
        <form method="POST" action="/intern/action.php" style="display:inline">
          <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="booking_id" value="<?= $id ?>">
          <input type="hidden" name="action"     value="delete_booking">
          <button type="submit" class="btn btn-delete"
            style="font-size:0.82rem;padding:6px 14px;background:#fdecea;color:#c62828;border:1px solid #f5c6c6"
            onclick="return confirm('Buchung von <?= addslashes($name) ?> endgültig löschen? Dies kann nicht rückgängig gemacht werden.')">
            🗑 Buchung löschen
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  </div><!-- /mtab-bookings -->

  <!-- Tab: Kontaktanfragen -->
  <div id="mtab-contacts" class="mtab">

  <!-- Posteingang-Toolbar -->
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <form method="POST" id="fetchIncomingForm">
      <input type="hidden" name="fetch_incoming" value="1">
      <input type="hidden" name="csrf_contact" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" class="btn btn-note" id="fetchIncomingBtn" style="font-size:0.85rem;padding:7px 16px">
        📥 Posteingang abrufen
      </button>
    </form>
    <?php
    $fetchedCount = isset($_GET['fetched']) ? (int)$_GET['fetched'] : -1;
    if ($fetchedCount >= 0):
    ?>
    <span style="font-size:0.82rem;color:<?= $fetchedCount > 0 ? '#2e7d32' : '#8a9a8a' ?>">
      <?= $fetchedCount > 0
          ? '✅ ' . $fetchedCount . ' neue Antwort' . ($fetchedCount !== 1 ? 'en' : '') . ' abgerufen'
          : '✓ Keine neuen Antworten' ?>
      <?php if (!empty($_GET['fetch_err'])): ?><span style="color:#c62828"> · Fehler beim Abrufen</span><?php endif; ?>
    </span>
    <?php endif; ?>
  </div>

  <?php if (empty($contacts)): ?>
    <div class="empty">Noch keine Kontaktanfragen vorhanden.</div>
  <?php else: ?>
    <div class="section-title" style="margin-bottom:12px">📬 Kontaktanfragen (<?= count($contacts) ?><?php if ($newContactsCount > 0): ?> · <span style="color:#3d6b41"><?= $newContactsCount ?> neu</span><?php endif; ?>)</div>
    <?php foreach ($contacts as $ci):
      $cid     = htmlspecialchars($ci['id'] ?? '');
      $cStatus = $ci['status'] ?? 'new';
      $cDate      = htmlspecialchars(isset($ci['date']) ? date('d.m.Y H:i', strtotime($ci['date'])) : '');
      $cName      = htmlspecialchars($ci['name'] ?? '');
      $cEmail     = htmlspecialchars($ci['email'] ?? '');
      $cSubj      = htmlspecialchars($ci['subject'] ?? '');
      $cMsg       = htmlspecialchars($ci['message'] ?? '');
      $cReplied   = $cStatus === 'replied';
      // Support both old single-reply and new replies-array format
      $cReplies = $ci['replies'] ?? [];
      if (empty($cReplies) && !empty($ci['reply'])) {
          $cReplies = [['body' => $ci['reply'], 'replied_at' => $ci['replied_at'] ?? '', 'admin_name' => '']];
      }
    ?>
    <div class="contact-card <?= $cStatus ?>">
      <div class="contact-head" onclick="toggleContact('<?= $cid ?>')">
        <?php if ($cStatus === 'new'): ?><div class="contact-new-dot" title="Neu"></div><?php endif; ?>
        <span style="font-size:0.78rem;color:#8a9a8a;white-space:nowrap"><?= $cDate ?></span>
        <span style="font-weight:700;flex:0 0 auto"><?= $cName ?></span>
        <span style="color:#5a6c5a;font-size:0.88rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $cSubj ?></span>
        <?php if ($cStatus === 'new'): ?>
          <span style="background:#e8f5e9;color:#2e7d32;font-size:0.72rem;font-weight:700;border-radius:12px;padding:2px 8px;flex-shrink:0">NEU</span>
        <?php elseif ($cReplied): ?>
          <span style="background:#e8f5e9;color:#2e7d32;font-size:0.72rem;font-weight:700;border-radius:12px;padding:2px 8px;flex-shrink:0">✓ Beantwortet</span>
        <?php endif; ?>
        <span style="color:#8a9a8a;font-size:0.8rem">▼</span>
      </div>
      <div class="contact-body" id="cb_<?= $cid ?>">
        <table class="info-table" style="margin-bottom:12px">
          <tr><td>Name</td><td><?= $cName ?></td></tr>
          <tr><td>E-Mail</td><td><a href="mailto:<?= $cEmail ?>" style="color:#3d6b41"><?= $cEmail ?></a></td></tr>
          <tr><td>Betreff</td><td><?= $cSubj ?></td></tr>
          <tr><td>Eingang</td><td><?= $cDate ?></td></tr>
        </table>
        <!-- Nachrichtenverlauf -->
        <div style="margin-bottom:14px">
          <div style="font-size:0.72rem;font-weight:700;color:#8a9a8a;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">💬 Nachrichtenverlauf</div>
          <div style="display:flex;flex-direction:column;gap:8px">

            <!-- Eingehende Nachricht -->
            <div style="display:flex;flex-direction:column;align-items:flex-start;max-width:85%">
              <div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px;padding-left:4px">
                📨 <?= $cName ?> · <?= $cDate ?>
              </div>
              <div style="background:#f0f2f0;border-radius:0 12px 12px 12px;padding:10px 14px;font-size:0.88rem;color:#2d3e2d;white-space:pre-wrap;line-height:1.5;border:1px solid #e0e8dc">
                <?= $cMsg ?>
              </div>
            </div>

            <!-- Antworten im Verlauf -->
            <?php foreach ($cReplies as $_cr):
              $_crType = $_cr['type'] ?? 'outgoing';
              $_crDate = (isset($_cr['replied_at']) && $_cr['replied_at'] !== '') ? date('d.m.Y H:i', strtotime($_cr['replied_at'])) : '';
              if ($_crType === 'incoming'):
            ?>
            <!-- Eingehende Antwort vom Kontakt (links, grau) -->
            <div style="display:flex;flex-direction:column;align-items:flex-start;max-width:85%">
              <div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px;padding-left:4px">
                📨 <?= htmlspecialchars($_cr['from_name'] ?? $cName) ?>
                <?= $_crDate !== '' ? '· ' . htmlspecialchars($_crDate) : '' ?>
              </div>
              <div style="background:#f0f2f0;border-radius:0 12px 12px 12px;padding:10px 14px;font-size:0.88rem;color:#2d3e2d;white-space:pre-wrap;line-height:1.5;border:1px solid #e0e8dc">
                <?= htmlspecialchars($_cr['body'] ?? '') ?>
              </div>
            </div>
            <?php else: ?>
            <!-- Gesendete Antwort (rechts, grün) -->
            <div style="display:flex;flex-direction:column;align-items:flex-end">
              <div style="font-size:0.7rem;color:#8a9a8a;margin-bottom:3px;padding-right:4px">
                ✉️ <?= htmlspecialchars($_cr['admin_name'] ?? 'Vorstand') ?>
                <?= $_crDate !== '' ? '· ' . htmlspecialchars($_crDate) : '' ?>
              </div>
              <div style="background:#e8f5e9;border-radius:12px 0 12px 12px;padding:10px 14px;font-size:0.88rem;color:#1b4d1e;white-space:pre-wrap;line-height:1.5;border:1px solid #c8e6c9;max-width:85%">
                <?= htmlspecialchars($_cr['body'] ?? '') ?>
              </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>

          </div>
        </div>

        <!-- Inline reply form -->
        <div id="replybox_<?= $cid ?>" style="<?= $cReplied ? 'display:none' : 'display:block' ?>;margin-top:4px">
          <form method="POST">
            <input type="hidden" name="reply_contact" value="1">
            <input type="hidden" name="contact_id"    value="<?= $cid ?>">
            <input type="hidden" name="csrf_contact"  value="<?= htmlspecialchars($csrf) ?>">
            <div style="font-size:0.75rem;font-weight:700;color:#3d6b41;margin-bottom:6px">↩ Antworten an <?= $cName ?> &lt;<?= $cEmail ?>&gt;</div>
            <textarea name="reply_body" placeholder="Ihre Antwort..." required
                      style="width:100%;box-sizing:border-box;min-height:90px;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-family:inherit;font-size:0.88rem;resize:vertical;background:#fff;margin-bottom:8px"></textarea>
            <button class="btn btn-paid" type="submit" style="font-size:0.85rem;padding:7px 18px">✉️ Antwort senden & E-Mail</button>
          </form>
          <?php if (!$cReplied && $cStatus === 'new'): ?>
          <form method="POST" style="display:inline;margin-left:8px">
            <input type="hidden" name="mark_read"    value="1">
            <input type="hidden" name="contact_id"   value="<?= $cid ?>">
            <input type="hidden" name="csrf_contact" value="<?= htmlspecialchars($csrf) ?>">
            <button class="btn btn-note" type="submit" style="font-size:0.85rem;padding:7px 16px">✓ Als gelesen</button>
          </form>
          <?php endif; ?>
        </div>
        <?php if ($cReplied): ?>
        <button onclick="document.getElementById('replybox_<?= $cid ?>').style.display='block';this.style.display='none'"
                class="btn btn-note" style="font-size:0.82rem;padding:6px 14px;margin-top:4px">↩ Erneut antworten</button>
        <?php endif; ?>
        <?php if ($canManageRoles): ?>
        <form method="POST" style="display:inline;float:right">
          <input type="hidden" name="delete_contact" value="1">
          <input type="hidden" name="contact_id"     value="<?= $cid ?>">
          <input type="hidden" name="csrf_contact"   value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn btn-delete"
                  style="font-size:0.82rem;padding:6px 14px;background:#fdecea;color:#c62828;border:1px solid #f5c6c6"
                  onclick="return confirm('Kontaktanfrage von <?= addslashes($cName) ?> endgültig löschen?')">
            🗑 Löschen
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div><!-- /mtab-contacts -->

  <!-- Tab: Kalender -->
  <div id="mtab-calendar" class="mtab">
    <div class="cal-nav">
      <button onclick="calPrev()">◀ Zurück</button>
      <div class="cal-month" id="cal-month-label"></div>
      <button onclick="calNext()">Weiter ▶</button>
    </div>
    <div class="cal-grid">
      <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $dn): ?>
      <div class="cal-day-name"><?= $dn ?></div>
      <?php endforeach; ?>
    </div>
    <div id="cal-body" class="cal-grid" style="margin-top:4px"></div>
    <div style="display:flex;gap:16px;margin-top:14px;font-size:0.82rem;flex-wrap:wrap">
      <span style="background:#fff3e0;color:#e65100;border-radius:4px;padding:3px 10px;font-weight:600">Offen</span>
      <span style="background:#e8f5e9;color:#2e7d32;border-radius:4px;padding:3px 10px;font-weight:600">Bestätigt</span>
      <span style="background:#f5f5f5;color:#9e9e9e;border-radius:4px;padding:3px 10px;font-weight:600">Abgelehnt</span>
      <span style="background:#fff0f0;color:#c62828;border-radius:4px;padding:3px 10px;font-weight:600">🚫 Gesperrt (Intern)</span>
    </div>
  </div><!-- /mtab-calendar -->

  <!-- ══ TAB: MITGLIEDER ══════════════════════════════════════════════════════ -->
  <div id="mtab-members" class="mtab">

    <!-- Registrierungsanfragen -->
    <div class="section-title" style="margin-bottom:12px">📩 Registrierungsanfragen
      <?php $pending = array_filter($memberRequests, fn($r) => ($r['status'] ?? '') === 'pending'); ?>
      (<?= count($pending) ?> offen)
    </div>
    <?php if (empty($pending)): ?>
      <div class="empty" style="margin-bottom:24px">Keine offenen Anfragen.</div>
    <?php else: foreach ($pending as $req): ?>
    <div class="booking-card" style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div>
          <strong><?= htmlspecialchars($req['name'] ?? '') ?></strong>
          <?php $rTyp = $req['rolle_typ'] ?? 'paechter'; ?>
          <?php if ($rTyp === 'paechterpartner'): ?>
            <span style="font-size:0.75rem;padding:2px 7px;border-radius:10px;background:#e8f5e9;color:#2e7d32;font-weight:600;margin-left:4px">🤝 Pächterpartner</span>
          <?php elseif ($rTyp === 'foerdermitglied'): ?>
            <span style="font-size:0.75rem;padding:2px 7px;border-radius:10px;background:#fff8e1;color:#f57f17;font-weight:600;margin-left:4px">💛 Fördermitglied</span>
          <?php endif; ?>
          &nbsp;<span style="color:#5a6c5a;font-size:0.82rem"><?= $rTyp !== 'foerdermitglied' ? 'Parzelle ' . htmlspecialchars($req['parzelle'] ?? '–') : '' ?></span>
          <div style="font-size:0.82rem;color:#5a6c5a;margin-top:4px">
            <?= htmlspecialchars($req['email'] ?? '') ?>
            <?php if (!empty($req['phone'])): ?> · <?= htmlspecialchars($req['phone']) ?><?php endif; ?>
          </div>
          <?php if (!empty($req['message'])): ?>
          <div style="font-size:0.82rem;color:#3d6b41;margin-top:6px;background:#f0f5ec;padding:6px 10px;border-radius:6px"><?= htmlspecialchars($req['message']) ?></div>
          <?php endif; ?>
          <div style="font-size:0.75rem;color:#5a6c5a;margin-top:6px">
            Zustimmungen:
            <?= !empty($req['consent_contact'])   ? '✅ Kontakt' : '❌ Kontakt' ?>
            &nbsp;<?= !empty($req['consent_phonelist']) ? '✅ Telefonliste' : '❌ Telefonliste' ?>
          </div>
          <div style="font-size:0.75rem;color:#5a6c5a"><?= htmlspecialchars($req['created_at'] ?? '') ?></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="member_action" value="approve_request">
            <input type="hidden" name="req_id" value="<?= htmlspecialchars($req['id'] ?? '') ?>">
            <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:0.82rem;font-family:inherit">✅ Genehmigen</button>
          </form>
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="member_action" value="reject_request">
            <input type="hidden" name="req_id" value="<?= htmlspecialchars($req['id'] ?? '') ?>">
            <button type="submit" style="background:#f5f5f5;color:#555;border:1px solid #ddd;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:0.82rem;font-family:inherit">❌ Ablehnen</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- Mitglieder-Liste -->
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:24px 0 12px">
      <div class="section-title" style="margin:0">👥 Aktive Mitglieder (<?= count($allMembers) ?>)</div>
      <a href="/intern/member_export.php" class="btn btn-note" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:7px 16px;font-size:0.82rem">📥 CSV</a>
      <a href="/intern/kassenbuch_export.php" class="btn btn-note" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:7px 16px;font-size:0.82rem">💶 Kassenbuch</a>
      <button onclick="printPhonelist()" class="btn btn-note" style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;font-size:0.82rem">🖨️ Telefonliste drucken</button>
      <?php if ($canSeeMembers): ?>
      <button onclick="document.getElementById('bulkMsgModal').style.display='flex'" style="background:#3d6b41;color:#fff;border:none;padding:7px 16px;border-radius:8px;font-size:0.82rem;font-weight:600;cursor:pointer">📢 Massen-Nachricht</button>
      <?php endif; ?>
      <input type="search" id="memberSearchInput" placeholder="🔍 Suchen (Name, E-Mail, Parzelle…)" oninput="filterMembers()"
        style="flex:1;min-width:220px;max-width:340px;padding:7px 14px;border:1px solid #d4e6c3;border-radius:8px;font-size:0.88rem;font-family:inherit">
    </div>
    <div id="memberNoResults" style="display:none;color:#888;font-size:0.88rem;padding:12px 0">Keine Mitglieder gefunden.</div>
    <?php if (empty($allMembers)): ?>
      <div class="empty">Noch keine Mitglieder vorhanden.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table id="membersTable" style="width:100%;border-collapse:collapse;font-size:0.85rem;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e0ead6">
      <thead>
        <tr style="background:#3d6b41;color:#fff">
          <th style="padding:10px 14px;text-align:left">Parzelle</th>
          <th style="padding:10px 14px;text-align:left">Name</th>
          <th style="padding:10px 14px;text-align:left">E-Mail</th>
          <th style="padding:10px 14px;text-align:left">Telefon</th>
          <th style="padding:10px 14px;text-align:center">Status</th>
          <th style="padding:10px 14px;text-align:center">Zustimmungen</th>
          <th style="padding:10px 14px;text-align:left">Letzter Login</th>
          <th style="padding:10px 14px;text-align:center">Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allMembers as $mem):
            $isActive = !empty($mem['active']);
            $cConsent = !empty($mem['consents']['contact_allowed']);
            $pConsent = !empty($mem['consents']['in_phonelist']);
            $memRolleTyp = $mem['rolle_typ'] ?? 'paechter';
        ?>
        <tr class="mem-row" data-search="<?= htmlspecialchars(strtolower(($mem['parzelle'] ?? '').' '.($mem['name'] ?? '').' '.($mem['email'] ?? '').' '.($mem['phone'] ?? '').' '.$memRolleTyp)) ?>" style="border-bottom:1px solid #e8f0e0;<?= !$isActive ? 'opacity:0.55' : '' ?>">
          <td style="padding:10px 14px"><?= htmlspecialchars($mem['parzelle'] ?? '–') ?></td>
          <td style="padding:10px 14px;font-weight:500">
            <?= htmlspecialchars($mem['name'] ?? '') ?>
            <?php if ($memRolleTyp === 'paechterpartner'): ?>
              <span style="font-size:0.7rem;padding:1px 6px;border-radius:8px;background:#e8f5e9;color:#2e7d32;font-weight:600;margin-left:4px" title="Pächterpartner">🤝</span>
            <?php elseif ($memRolleTyp === 'foerdermitglied'): ?>
              <span style="font-size:0.7rem;padding:1px 6px;border-radius:8px;background:#fff8e1;color:#f57f17;font-weight:600;margin-left:4px" title="Fördermitglied">💛</span>
            <?php endif; ?>
            <?php
              $_memRoleLabels = ['vorstand'=>'👑 Vorstand','buchung'=>'📋 Buchung','schriftfuehrer'=>'📝 Schriftf.','web'=>'🌐 Web','koppel'=>'🔨 Wegewart/in','kassier'=>'💶 Kassier/in'];
              $_memAdminRoles = array_intersect(array_keys($_memRoleLabels), $mem['roles'] ?? []);
              foreach ($_memAdminRoles as $_ar):
            ?>
              <span style="font-size:0.68rem;padding:1px 5px;border-radius:6px;background:#e8f0e0;color:#3d6b41;font-weight:600;margin-left:3px;white-space:nowrap"><?= $_memRoleLabels[$_ar] ?></span>
            <?php endforeach; ?>
          </td>
          <td style="padding:10px 14px"><?= htmlspecialchars($mem['email'] ?? '') ?></td>
          <td style="padding:10px 14px"><?= htmlspecialchars($mem['phone'] ?? '–') ?></td>
          <td style="padding:10px 14px;text-align:center">
            <span style="font-size:0.75rem;padding:3px 8px;border-radius:10px;font-weight:600;background:<?= $isActive ? '#e8f5e9' : '#fce8e6' ?>;color:<?= $isActive ? '#2e7d32' : '#c62828' ?>">
              <?= $isActive ? 'Aktiv' : 'Gesperrt' ?>
            </span>
          </td>
          <td style="padding:10px 14px;text-align:center;font-size:0.82rem">
            <?= $cConsent ? '✅' : '❌' ?> Kontakt &nbsp;
            <?= $pConsent ? '✅' : '❌' ?> Tel.liste
            <?php if (!$cConsent && !empty($mem['consents']['contact_revoked_at'])): ?>
              <div style="font-size:0.7rem;color:#888">Wid. <?= htmlspecialchars(substr($mem['consents']['contact_revoked_at'],0,10)) ?></div>
            <?php endif; ?>
          </td>
          <td style="padding:10px 14px;font-size:0.78rem;color:#5a6c5a"><?= htmlspecialchars($mem['last_login'] ? substr($mem['last_login'],0,10) : '–') ?></td>
          <td style="padding:10px 14px;text-align:center">
            <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="member_action" value="toggle_member">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($mem['id'] ?? '') ?>">
                <button type="submit" title="<?= $isActive ? 'Sperren' : 'Entsperren' ?>" style="background:none;border:none;cursor:pointer;font-size:1rem"><?= $isActive ? '🔒' : '🔓' ?></button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="member_action" value="reset_password">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($mem['id'] ?? '') ?>">
                <button type="submit" title="Passwort zurücksetzen" style="background:none;border:none;cursor:pointer;font-size:1rem" onclick="return confirm('Passwort zurücksetzen und per E-Mail senden?')">🔑</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="member_action" value="delete_member">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($mem['id'] ?? '') ?>">
                <button type="submit" title="Löschen" style="background:none;border:none;cursor:pointer;font-size:1rem" onclick="return confirm('Mitglied endgültig löschen?')">🗑️</button>
              </form>
              <?php if ($canManageRoles): ?>
              <button onclick="toggleRoleForm('rf-<?= htmlspecialchars($mem['id'] ?? '') ?>')" title="Rollen bearbeiten" style="background:none;border:none;cursor:pointer;font-size:1rem">🎭</button>
              <?php endif; ?>
            </div>
            <?php if ($canManageRoles): ?>
            <?php $memRoles = $mem['roles'] ?? ['mitglied']; ?>
            <div id="rf-<?= htmlspecialchars($mem['id'] ?? '') ?>" style="display:none;margin-top:8px;padding:10px;background:#f5f7f2;border-radius:8px;border:1px solid #d4e6c3">
              <form method="POST" style="display:flex;flex-direction:column;gap:6px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="member_action" value="save_member_roles">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($mem['id'] ?? '') ?>">
                <div style="font-size:0.75rem;font-weight:700;color:#3d6b41;margin-bottom:4px">Signatur-Titel (in E-Mails):</div>
                <input type="text" name="sig_rolle" value="<?= htmlspecialchars($mem['sig_rolle'] ?? '') ?>"
                       placeholder="z.B. 1. Vorsitzender, Schriftführerin & Kassenwärtin"
                       style="width:100%;box-sizing:border-box;padding:5px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;margin-bottom:6px">
                <div style="font-size:0.75rem;font-weight:700;color:#3d6b41;margin-bottom:4px">Rollen:</div>
                <?php foreach (['mitglied'=>'🌱 Mitglied','vorstand'=>'👑 Vorstand (alles)','buchung'=>'📋 Buchungsverwaltung','schriftfuehrer'=>'📝 Schriftführer','web'=>'🌐 Web-Betreuung','koppel'=>'🔨 Wegewart/in','kassier'=>'💶 Kassier/in (Finanzen)'] as $rk => $rl): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:0.82rem;cursor:pointer">
                  <input type="checkbox" name="roles[]" value="<?= $rk ?>" <?= in_array($rk, $memRoles, true) ? 'checked' : '' ?>>
                  <?= $rl ?>
                </label>
                <?php endforeach; ?>
                <button type="submit" style="margin-top:6px;background:#3d6b41;color:#fff;border:none;padding:5px 14px;border-radius:6px;cursor:pointer;font-size:0.8rem;align-self:flex-start">Speichern</button>
              </form>
              <!-- Geburtstag separat speichern -->
              <form method="POST" style="display:flex;align-items:center;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid #e8f0e0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="member_action" value="save_birthday">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($mem['id'] ?? '') ?>">
                <label style="font-size:0.75rem;font-weight:700;color:#3d6b41;white-space:nowrap">🎂 Geburtstag:</label>
                <input type="text" name="geburtstag" value="<?= htmlspecialchars($mem['geburtstag'] ?? '') ?>"
                       placeholder="MM-TT (z.B. 03-15)"
                       style="width:90px;padding:4px 7px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem">
                <button type="submit" style="background:#5a8f5e;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:0.78rem">💾</button>
              </form>
              <!-- Eintrittsdatum separat speichern (für Ehrungs-Radar) -->
              <form method="POST" action="/intern/action.php" style="display:flex;align-items:center;gap:8px;margin-top:6px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_member_since">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($mem['id'] ?? '') ?>">
                <label style="font-size:0.75rem;font-weight:700;color:#5e35b1;white-space:nowrap">🏅 Mitglied seit:</label>
                <input type="text" name="member_since" value="<?= htmlspecialchars($mem['member_since'] ?? '') ?>"
                       placeholder="YYYY-MM-TT"
                       style="width:120px;padding:4px 7px;border:1.5px solid #d4cfe8;border-radius:6px;font-size:0.82rem">
                <button type="submit" style="background:#7e57c2;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:0.78rem">💾</button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div id="membersPagination" style="display:flex;gap:6px;justify-content:center;align-items:center;padding:14px 0;flex-wrap:wrap"></div>
    <?php endif; ?>

    <!-- Nachrichten-Threads -->
    <!-- Nachrichten Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin:28px 0 14px;flex-wrap:wrap;gap:10px">
      <div style="display:flex;align-items:center;gap:12px">
        <span class="section-title" style="margin:0">💬 Nachrichten</span>
        <?php if ($openMessagesCount > 0): ?>
        <span style="background:#e53935;color:#fff;font-size:0.75rem;font-weight:700;padding:3px 10px;border-radius:20px"><?= $openMessagesCount ?> neu</span>
        <?php endif; ?>
        <span style="color:#5a6c5a;font-size:0.8rem"><?= count($memberMessages) ?> gesamt</span>
      </div>
      <button onclick="document.getElementById('newMsgModal').style.display='flex'"
        style="background:#3d6b41;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer">
        ✉️ Neue Nachricht
      </button>
    </div>

    <!-- Modal: Neue Nachricht an Mitglied -->
    <div id="newMsgModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:16px">
      <div style="background:#fff;border-radius:14px;padding:28px;max-width:500px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
          <strong style="font-size:1rem;color:#2d3e2d">✉️ Neue Nachricht an Mitglied</strong>
          <button onclick="document.getElementById('newMsgModal').style.display='none'"
            style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#8a9a8a;line-height:1">✕</button>
        </div>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="member_action" value="new_message_to_member">
          <div style="margin-bottom:14px">
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Empfänger</label>
            <select name="target_member_id" required
              style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.88rem;background:#fafcf8">
              <option value="">— Mitglied auswählen —</option>
              <?php foreach ($allMembers as $_tm): ?>
              <option value="<?= htmlspecialchars($_tm['id'] ?? '') ?>"
                data-consent="<?= !empty($_tm['consents']['contact_allowed']) ? '1' : '0' ?>">
                Parzelle <?= htmlspecialchars($_tm['parzelle'] ?? '') ?> – <?= htmlspecialchars($_tm['name'] ?? '') ?>
                <?= empty($_tm['consents']['contact_allowed']) ? ' (nur intern)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div id="nmConsentHint" style="font-size:0.75rem;margin-top:5px;min-height:16px"></div>
          </div>
          <div style="margin-bottom:14px">
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Betreff</label>
            <input type="text" name="nm_subject" required
              style="width:100%;box-sizing:border-box;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.88rem;background:#fafcf8"
              placeholder="Betreff der Nachricht">
          </div>
          <div style="margin-bottom:20px">
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Nachricht</label>
            <textarea name="nm_body" required rows="5"
              style="width:100%;box-sizing:border-box;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.88rem;background:#fafcf8;resize:vertical"
              placeholder="Nachricht …"></textarea>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" onclick="document.getElementById('newMsgModal').style.display='none'"
              style="background:none;border:1.5px solid #d4e6c3;padding:8px 18px;border-radius:8px;cursor:pointer;font-size:0.85rem;color:#5a6c5a">Abbrechen</button>
            <button type="submit"
              style="background:#3d6b41;color:#fff;border:none;padding:8px 20px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer">Senden</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    document.querySelector('select[name="target_member_id"]')?.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const hint = document.getElementById('nmConsentHint');
        if (!opt.value) { hint.textContent = ''; return; }
        if (opt.dataset.consent === '1') {
            hint.innerHTML = '<span style="color:#2e7d32">✅ Zustimmung vorhanden – Nachricht wird per E-Mail zugestellt.</span>';
        } else {
            hint.innerHTML = '<span style="color:#e65100">⚠️ Keine E-Mail-Zustimmung – Nachricht erscheint nur im Mitgliederbereich.</span>';
        }
    });
    </script>

    <?php if (empty($memberMessages)): ?>
      <div class="empty">Noch keine Nachrichten vorhanden.</div>
    <?php else: ?>
    <div id="msgList">
    <?php foreach ($memberMessages as $thread):
        $isOpen    = ($thread['status'] ?? '') === 'open';
        $lastMsg   = end($thread['messages']);
        $lastFrom  = $lastMsg['from'] ?? 'member';
        $isNew     = $isOpen && $lastFrom === 'member'; // new = member wrote last, waiting for reply
        $tid       = $thread['id'] ?? '';
        $msgCount  = count($thread['messages'] ?? []);
    ?>
    <div style="border-radius:10px;overflow:hidden;margin-bottom:10px;border:2px solid <?= $isNew ? '#e53935' : ($isOpen ? '#f9a825' : '#d4e6c3') ?>;background:#fff">

      <!-- Thread header -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;
                  background:<?= $isNew ? '#fff5f5' : ($isOpen ? '#fffdf0' : '#f5faf2') ?>;
                  cursor:pointer;gap:12px;border-bottom:1px solid <?= $isNew ? '#ffcdd2' : ($isOpen ? '#fff3cd' : '#e0ead6') ?>"
           onclick="toggleMsgThread('mt-<?= htmlspecialchars($tid) ?>')">

        <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
          <!-- Status icon -->
          <div style="width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem;
                      background:<?= $isNew ? '#ffebee' : ($isOpen ? '#fff8e1' : '#e8f5e9') ?>">
            <?= $isNew ? '🔴' : ($isOpen ? '🟡' : '✅') ?>
          </div>
          <div style="min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <strong style="font-size:0.9rem"><?= htmlspecialchars($thread['subject'] ?? '') ?></strong>
              <span style="font-size:0.7rem;padding:2px 9px;border-radius:20px;font-weight:700;
                           background:<?= $isNew ? '#ffebee' : ($isOpen ? '#fff8e1' : '#e8f5e9') ?>;
                           color:<?= $isNew ? '#c62828' : ($isOpen ? '#f57f17' : '#2e7d32') ?>">
                <?= $isNew ? '● Neue Nachricht' : ($isOpen ? '◐ Offen' : '✓ Beantwortet') ?>
              </span>
              <?php $_rRole = $thread['recipient_role'] ?? 'vorstand'; if ($_rRole !== 'vorstand'): ?>
              <span style="font-size:0.7rem;padding:2px 9px;border-radius:20px;font-weight:700;background:#e3f2fd;color:#1565c0">
                <?= $_rRole === 'kassier' ? '💶 Kassier/in' : '🔨 Wegewart/in' ?>
              </span>
              <?php endif; ?>
            </div>
            <div style="font-size:0.75rem;color:#5a6c5a;margin-top:3px">
              👤 <strong><?= htmlspecialchars($thread['member_name'] ?? '') ?></strong>
              &nbsp;·&nbsp; Parzelle <?= htmlspecialchars($thread['member_parzelle'] ?? '') ?>
              &nbsp;·&nbsp; <?= $msgCount ?> Nachricht<?= $msgCount !== 1 ? 'en' : '' ?>
              &nbsp;·&nbsp; <?= htmlspecialchars(substr($thread['updated_at'] ?? '', 0, 16)) ?>
            </div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0" onclick="event.stopPropagation()">
          <form method="POST" onsubmit="return confirm('Nachricht &quot;<?= addslashes(htmlspecialchars($thread['subject'] ?? '')) ?>&quot; wirklich löschen?')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="member_action" value="delete_message">
            <input type="hidden" name="thread_id" value="<?= htmlspecialchars($tid) ?>">
            <button type="submit" title="Thread löschen" style="background:none;border:1px solid #ffcdd2;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:0.8rem;color:#c62828;line-height:1">🗑</button>
          </form>
          <span id="mt-arr-<?= htmlspecialchars($tid) ?>" style="color:#5a6c5a;font-size:0.85rem;transition:transform .2s">▾</span>
        </div>
      </div>

      <!-- Thread body (collapsed by default unless new) -->
      <div id="mt-<?= htmlspecialchars($tid) ?>" style="display:<?= $isNew ? 'block' : 'none' ?>;padding:18px">

        <!-- Chat bubbles -->
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
        <?php foreach ($thread['messages'] as $tmsg):
            $fromAdmin = ($tmsg['from'] ?? '') === 'admin';
        ?>
        <div style="display:flex;gap:0;justify-content:<?= $fromAdmin ? 'flex-end' : 'flex-start' ?>">
          <div style="max-width:75%;
                      background:<?= $fromAdmin ? '#3d6b41' : '#f0f5ec' ?>;
                      color:<?= $fromAdmin ? '#fff' : '#2d3e2d' ?>;
                      border-radius:<?= $fromAdmin ? '16px 4px 16px 16px' : '4px 16px 16px 16px' ?>;
                      padding:11px 15px;box-shadow:0 1px 3px rgba(0,0,0,0.08)">
            <div style="font-size:0.68rem;font-weight:700;opacity:<?= $fromAdmin ? '0.75' : '1' ?>;color:<?= $fromAdmin ? '#fff' : '#5a6c5a' ?>;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em">
              <?= $fromAdmin ? '🌿 Vorstand' : '👤 '.htmlspecialchars($thread['member_name'] ?? '') ?>
              &ensp;<?= htmlspecialchars(substr($tmsg['created_at'] ?? '', 0, 16)) ?>
            </div>
            <div style="font-size:0.88rem;white-space:pre-wrap;line-height:1.55"><?= htmlspecialchars($tmsg['body'] ?? '') ?></div>
            <?php if (!empty($tmsg['file'])): $af = $tmsg['file']; $afUrl = '/member-api/msg_file.php?f='.urlencode($af['stored'] ?? ''); $afImg = str_starts_with($af['mime'] ?? '', 'image/'); ?>
            <div style="margin-top:8px">
              <?php if ($afImg): ?>
                <a href="<?= $afUrl ?>" target="_blank"><img src="<?= $afUrl ?>" style="max-width:180px;max-height:150px;border-radius:8px;display:block;object-fit:cover;cursor:pointer" alt="<?= htmlspecialchars($af['orig'] ?? '') ?>"></a>
              <?php else: ?>
                <a href="<?= $afUrl ?>" target="_blank" style="display:inline-flex;align-items:center;gap:5px;font-size:0.8rem;padding:5px 10px;background:rgba(255,255,255,0.15);border-radius:6px;text-decoration:none;color:<?= $fromAdmin ? '#fff' : '#2d3e2d' ?>;border:1px solid rgba(255,255,255,0.2)">📎 <?= htmlspecialchars($af['orig'] ?? '') ?></a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Reply box -->
        <div style="background:#f5f7f2;border-radius:10px;padding:14px 16px">
          <div style="font-size:0.75rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
            ↩ Antworten an <?= htmlspecialchars($thread['member_name'] ?? '') ?> &lt;<?= htmlspecialchars($thread['member_email'] ?? '') ?>&gt;
          </div>
          <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="member_action" value="reply_message">
            <input type="hidden" name="thread_id" value="<?= htmlspecialchars($tid) ?>">
            <textarea name="reply_body" placeholder="Ihre Antwort..." style="min-height:80px;padding:10px 14px;border:1.5px solid #d4e6c3;border-radius:8px;font-family:inherit;font-size:0.88rem;resize:vertical;background:#fff"></textarea>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.82rem;color:#5a6c5a;background:#fff;border:1.5px solid #d4e6c3;border-radius:8px;padding:7px 12px">
                📎 Anhang <span style="color:#3d6b41">(optional)</span>
                <input type="file" name="reply_file" accept="image/*,.pdf,.doc,.docx" style="display:none" onchange="adminFilePreview(this,'aprev-<?= htmlspecialchars($tid) ?>')">
              </label>
              <div id="aprev-<?= htmlspecialchars($tid) ?>" style="font-size:0.82rem;color:#5a6c5a"></div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:10px 22px;border-radius:8px;cursor:pointer;font-size:0.85rem;font-family:inherit;font-weight:600">
                ✉️ Antwort senden &amp; E-Mail
              </button>
              <?php if ($isOpen): ?>
              <span style="font-size:0.78rem;color:#8a9a8a">oder</span>
              <?php endif; ?>
            </div>
          </form>
          <?php if ($isOpen): ?>
          <form method="POST" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="member_action" value="mark_message_read">
            <input type="hidden" name="thread_id" value="<?= htmlspecialchars($tid) ?>">
            <button type="submit" style="background:#fff;color:#5a6c5a;border:1.5px solid #d4e6c3;padding:8px 18px;border-radius:8px;cursor:pointer;font-size:0.82rem;font-family:inherit;font-weight:600">
              ✓ Als gelesen markieren (ohne Antwort)
            </button>
          </form>
          <?php endif; ?>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
    </div><!-- /msgList -->
    <div id="msgPagination" style="display:flex;gap:6px;justify-content:center;align-items:center;padding:14px 0;flex-wrap:wrap"></div>
    <?php endif; ?>

  <!-- Massen-Nachricht Modal -->
  <div id="bulkMsgModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:14px;padding:28px;max-width:500px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <strong style="font-size:1rem;color:#2d3e2d">📢 Massen-Nachricht an alle Mitglieder</strong>
        <button onclick="document.getElementById('bulkMsgModal').style.display='none'" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#8a9a8a">✕</button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="member_action" value="bulk_message">
        <div style="display:grid;gap:12px">
          <div>
            <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Betreff *</label>
            <input type="text" name="bm_subject" required placeholder="z.B. Einladung zur Jahreshauptversammlung" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit">
          </div>
          <div>
            <label style="font-size:0.82rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:4px">Nachricht *</label>
            <textarea name="bm_body" required rows="5" placeholder="Nachrichtentext …" style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit;resize:vertical"></textarea>
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;cursor:pointer;background:#f0f5ec;padding:10px 14px;border-radius:8px">
            <input type="checkbox" name="bm_send_email" value="1" checked>
            <span>Zusätzlich per E-Mail senden <span style="color:#8a9a8a">(nur an Mitglieder mit Kontakt-Zustimmung)</span></span>
          </label>
          <div style="font-size:0.8rem;color:#5a6c5a;background:#f9fbf7;padding:10px 14px;border-radius:8px">
            📬 Nachricht wird an <strong><?= count(array_filter($allMembers, fn($m) => !empty($m['active']))) ?> aktive Mitglieder</strong> im Mitgliederbereich gestellt.
          </div>
          <button type="submit" onclick="return confirm('Massen-Nachricht an alle Mitglieder senden?')" style="background:#3d6b41;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">📢 Jetzt senden</button>
        </div>
      </form>
    </div>
  </div>

  </div><!-- /mtab-members -->

  <!-- ══════════════════════════════════════════════════════════════════════════ -->
  <!-- ══ GEMEINSCHAFTSARBEIT-TAB ══════════════════════════════════════════════ -->
  <!-- ══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($canManageRoles): ?>
  <div id="mtab-arbeit" class="mtab">
  <?php $arbeitYear = (int)($_GET['aj'] ?? date('Y')); $arbeitSoll = max(1, (int)($_adminCc['settings']['arbeit_soll_stunden'] ?? 4)); ?>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <div class="section-title" style="margin:0">🔨 Gemeinschaftsarbeit <?= $arbeitYear ?></div>
    <div style="display:flex;gap:4px">
      <a href="?tab=arbeit&aj=<?= $arbeitYear-1 ?>" class="btn btn-note" style="padding:4px 10px;font-size:0.8rem;text-decoration:none">◀</a>
      <a href="?tab=arbeit&aj=<?= $arbeitYear+1 ?>" class="btn btn-note" style="padding:4px 10px;font-size:0.8rem;text-decoration:none">▶</a>
    </div>
    <span style="font-size:0.82rem;color:#5a6c5a">Soll: <?= $arbeitSoll ?>h pro Mitglied</span>
    <?php
      $arbDoneCount = 0; $arbOpenCount = 0;
      foreach ($allMembers as $_am) {
          $_amId = $_am['id'] ?? '';
          $_amH = array_sum(array_column(array_filter($arbeitData[$_amId] ?? [], fn($e) => (int)($e['year'] ?? 0) === $arbeitYear), 'hours'));
          if ($_amH >= $arbeitSoll) $arbDoneCount++; else $arbOpenCount++;
      }
    ?>
    <span style="font-size:0.82rem;color:#2e7d32;font-weight:600">✅ <?= $arbDoneCount ?> erfüllt</span>
    <span style="font-size:0.82rem;color:#e65100;font-weight:600">⏳ <?= $arbOpenCount ?> offen</span>
  </div>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:0.85rem;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e0ead6">
    <thead>
      <tr style="background:#3d6b41;color:#fff">
        <th style="padding:9px 14px;text-align:left">Parzelle</th>
        <th style="padding:9px 14px;text-align:left">Name</th>
        <th style="padding:9px 14px;text-align:center">Geleistet</th>
        <th style="padding:9px 14px;text-align:center">Status</th>
        <th style="padding:9px 14px;text-align:left">Einträge</th>
        <th style="padding:9px 14px;text-align:center">Eintragen</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($allMembers as $_am):
          $_amId   = $_am['id'] ?? '';
          $_amEntr = array_values(array_filter($arbeitData[$_amId] ?? [], fn($e) => (int)($e['year'] ?? 0) === $arbeitYear));
          $_amH    = array_sum(array_column($_amEntr, 'hours'));
          $_amDone = $_amH >= $arbeitSoll;
      ?>
      <tr style="border-bottom:1px solid #e8f0e0">
        <td style="padding:8px 14px"><?= htmlspecialchars($_am['parzelle'] ?? '–') ?></td>
        <td style="padding:8px 14px;font-weight:500"><?= htmlspecialchars($_am['name'] ?? '') ?></td>
        <td style="padding:8px 14px;text-align:center;font-weight:700;color:<?= $_amDone ? '#2e7d32' : '#e65100' ?>"><?= number_format($_amH, 1, ',', '.') ?>h</td>
        <td style="padding:8px 14px;text-align:center">
          <div style="height:8px;background:#f0f4ee;border-radius:4px;width:80px;margin:0 auto">
            <div style="height:8px;background:<?= $_amDone ? '#4caf50' : '#ff9800' ?>;border-radius:4px;width:<?= min(100, round($_amH / $arbeitSoll * 100)) ?>%"></div>
          </div>
          <div style="font-size:0.7rem;color:#5a6c5a;margin-top:3px"><?= min(100, round($_amH / $arbeitSoll * 100)) ?>%</div>
        </td>
        <td style="padding:8px 14px;font-size:0.78rem;color:#5a6c5a">
          <?php foreach ($_amEntr as $_idx => $_ae): ?>
            <span style="display:inline-flex;align-items:center;gap:3px;background:#f0f5ec;border-radius:4px;padding:1px 5px;margin:1px">
              <?= htmlspecialchars(substr($_ae['date'] ?? '', 5)) ?> · <?= number_format((float)($_ae['hours'] ?? 0), 1, ',', '.') ?>h
              <?php if (!empty($_ae['note'])): ?><span title="<?= htmlspecialchars($_ae['note']) ?>">💬</span><?php endif; ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="member_action" value="delete_arbeit">
                <input type="hidden" name="mem_id" value="<?= htmlspecialchars($_amId) ?>">
                <input type="hidden" name="arbeit_idx" value="<?= $_idx ?>">
                <input type="hidden" name="arbeit_year" value="<?= $arbeitYear ?>">
                <button type="submit" style="background:none;border:none;cursor:pointer;font-size:0.75rem;color:#c62828;padding:0 2px" onclick="return confirm('Eintrag löschen?')" title="Löschen">✕</button>
              </form>
            </span>
          <?php endforeach; ?>
        </td>
        <td style="padding:8px 14px;text-align:center">
          <button onclick="toggleArbeitForm('af-<?= htmlspecialchars($_amId) ?>')" style="background:none;border:none;cursor:pointer;font-size:0.95rem" title="Stunden eintragen">➕</button>
        </td>
      </tr>
      <tr id="af-<?= htmlspecialchars($_amId) ?>" style="display:none;background:#f9fbf7">
        <td colspan="6" style="padding:10px 14px">
          <form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="member_action" value="save_arbeit">
            <input type="hidden" name="mem_id" value="<?= htmlspecialchars($_amId) ?>">
            <input type="hidden" name="arbeit_year" value="<?= $arbeitYear ?>">
            <div><label style="font-size:0.75rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:3px">Stunden</label>
              <input type="text" name="arbeit_hours" value="4" style="width:70px;padding:6px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit"></div>
            <div><label style="font-size:0.75rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:3px">Datum</label>
              <input type="date" name="arbeit_date" value="<?= date('Y-m-d') ?>" style="padding:6px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit"></div>
            <div><label style="font-size:0.75rem;font-weight:600;color:#3d6b41;display:block;margin-bottom:3px">Notiz</label>
              <input type="text" name="arbeit_note" placeholder="z.B. Frühjahrspflege" style="width:200px;padding:6px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit"></div>
            <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;cursor:pointer">Eintragen</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div><!-- /mtab-arbeit -->
  <?php endif; ?>

  <!-- ── TODOS ── -->
  <div id="mtab-todos" class="mtab">

    <?php if ($canWriteTodo): ?>
    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:20px;margin-bottom:20px">
      <div style="font-size:0.8rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">+ Neues Todo</div>
      <form method="POST" action="/intern/action.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="add_todo">
        <textarea name="todo_text" rows="3" required
          placeholder="Was ist aufgefallen oder gewünscht? Möglichst konkret beschreiben…"
          style="width:100%;box-sizing:border-box;padding:10px 14px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit;resize:vertical;min-height:80px"></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
          <label style="display:inline-flex;align-items:center;gap:6px;background:#f0f7f0;border:1.5px solid #a5d6a7;border-radius:8px;padding:7px 14px;cursor:pointer;font-size:0.85rem;color:#3d6b41;font-weight:500">
            📎 Bild(er) anhängen
            <input type="file" name="todo_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none"
              onchange="document.getElementById('todo-new-img-label').textContent=this.files.length?this.files.length+' Datei(en) gewählt':''">
          </label>
          <span id="todo-new-img-label" style="font-size:0.8rem;color:#5a6c5a"></span>
          <button type="submit" style="margin-left:auto;background:#3d6b41;color:#fff;border:none;padding:8px 20px;border-radius:8px;font-size:0.88rem;font-weight:600;cursor:pointer;font-family:inherit">
            + Todo erstellen
          </button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php
    $openTodos = array_values(array_filter($todos, fn($t) => empty($t['done'])));
    $doneTodos = array_values(array_filter($todos, fn($t) => !empty($t['done'])));
    ?>

    <?php if (empty($todos)): ?>
    <div style="text-align:center;padding:48px 20px;color:#8a9a8a;font-size:0.92rem">
      ✅ Keine Todos vorhanden<?= $canWriteTodo ? ' — oben eintragen!' : '.' ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($openTodos)): ?>
    <div style="font-size:0.78rem;font-weight:700;color:#3d6b41;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">
      📋 Offen (<?= count($openTodos) ?>)
    </div>
    <?php foreach ($openTodos as $td): ?>
    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:18px 20px;margin-bottom:12px;border-left:4px solid #4a7c4e">
      <div style="font-size:0.95rem;line-height:1.6;color:#1b2e1d;margin-bottom:10px;white-space:pre-wrap"><?= htmlspecialchars($td['text']) ?></div>
      <?php if (!empty($td['images'])): ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <?php foreach ($td['images'] as $_img): ?>
        <a href="/intern/todo-img.php?f=<?= urlencode($_img) ?>" target="_blank">
          <img src="/intern/todo-img.php?f=<?= urlencode($_img) ?>" alt=""
            style="height:90px;width:auto;max-width:160px;border-radius:7px;border:1px solid #d4e6c3;object-fit:cover;cursor:zoom-in;transition:transform .15s"
            onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform=''">
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div style="font-size:0.75rem;color:#8a9a8a;margin-bottom:10px">
        Erstellt von <strong><?= htmlspecialchars($td['created_by'] ?? '–') ?></strong>
        · <?= htmlspecialchars(date('d.m.Y H:i', strtotime($td['created_at'] ?? 'now'))) ?> Uhr
      </div>
      <?php if (!empty($td['notes'])): ?>
      <div style="margin-bottom:12px;display:flex;flex-direction:column;gap:6px">
        <?php foreach ($td['notes'] as $_note): ?>
        <div style="background:#f5f7f2;border-left:3px solid #a5d6a7;border-radius:0 7px 7px 0;padding:7px 12px">
          <div style="font-size:0.87rem;color:#2d3e2d;line-height:1.5;white-space:pre-wrap"><?= htmlspecialchars($_note['text']) ?></div>
          <div style="font-size:0.72rem;color:#9e9e9e;margin-top:3px">
            <strong><?= htmlspecialchars($_note['by'] ?? '–') ?></strong>
            · <?= htmlspecialchars(date('d.m.Y H:i', strtotime($_note['at'] ?? 'now'))) ?> Uhr
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($canWriteTodo): ?>
      <div id="note-form-<?= htmlspecialchars($td['id']) ?>" style="display:none;margin-bottom:10px">
        <form method="POST" action="/intern/action.php">
          <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action"    value="add_todo_note">
          <input type="hidden" name="todo_id"   value="<?= htmlspecialchars($td['id']) ?>">
          <textarea name="note_text" rows="2" required placeholder="Notiz eingeben…"
            style="width:100%;box-sizing:border-box;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:7px;font-size:0.87rem;font-family:inherit;resize:vertical;margin-bottom:6px"></textarea>
          <div style="display:flex;gap:8px">
            <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:6px 16px;border-radius:7px;font-size:0.82rem;font-weight:600;cursor:pointer;font-family:inherit">💾 Speichern</button>
            <button type="button" onclick="document.getElementById('note-form-<?= htmlspecialchars($td['id']) ?>').style.display='none'"
              style="background:#f5f7f2;color:#5a6c5a;border:1px solid #d4e6c3;padding:6px 12px;border-radius:7px;font-size:0.82rem;cursor:pointer;font-family:inherit">Abbrechen</button>
          </div>
        </form>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding-top:10px;border-top:1px solid #f0f5ec">
        <button type="button" onclick="var f=document.getElementById('note-form-<?= htmlspecialchars($td['id']) ?>');f.style.display=f.style.display==='none'?'block':'none'"
          style="background:#f5f7f2;color:#5a6c5a;border:1px solid #d4e6c3;border-radius:7px;padding:6px 12px;font-size:0.82rem;font-weight:500;cursor:pointer;font-family:inherit">
          💬 Notiz
        </button>
        <form method="POST" action="/intern/action.php" enctype="multipart/form-data" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action"  value="add_todo_img">
          <input type="hidden" name="todo_id" value="<?= htmlspecialchars($td['id']) ?>">
          <label style="display:inline-flex;align-items:center;gap:5px;background:#f5f7f2;border:1px solid #d4e6c3;border-radius:7px;padding:6px 12px;cursor:pointer;font-size:0.82rem;color:#3d6b41;font-weight:500">
            📎 Bild hinzufügen
            <input type="file" name="todo_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none" onchange="this.form.submit()">
          </label>
        </form>
        <form method="POST" action="/intern/action.php" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action"  value="toggle_todo">
          <input type="hidden" name="todo_id" value="<?= htmlspecialchars($td['id']) ?>">
          <button type="submit" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;border-radius:7px;padding:6px 14px;font-size:0.82rem;font-weight:600;cursor:pointer;font-family:inherit">
            ✓ Erledigt
          </button>
        </form>
        <form method="POST" action="/intern/action.php" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action"  value="delete_todo">
          <input type="hidden" name="todo_id" value="<?= htmlspecialchars($td['id']) ?>">
          <button type="submit" onclick="return confirm('Todo endgültig löschen?')"
            style="background:#fdecea;color:#c62828;border:1px solid #f5c6c6;border-radius:7px;padding:6px 12px;font-size:0.82rem;cursor:pointer;font-family:inherit">
            🗑 Löschen
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($doneTodos)): ?>
    <div style="font-size:0.78rem;font-weight:700;color:#8a9a8a;text-transform:uppercase;letter-spacing:.06em;margin:24px 0 10px">
      ✅ Erledigt (<?= count($doneTodos) ?>)
    </div>
    <?php foreach ($doneTodos as $td): ?>
    <div style="background:#f9fbf9;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.04);padding:16px 20px;margin-bottom:10px;border-left:4px solid #c8e6c9;opacity:.82">
      <div style="font-size:0.9rem;line-height:1.5;color:#5a6c5a;text-decoration:line-through;margin-bottom:6px;white-space:pre-wrap"><?= htmlspecialchars($td['text']) ?></div>
      <?php if (!empty($td['images'])): ?>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
        <?php foreach ($td['images'] as $_img): ?>
        <a href="/intern/todo-img.php?f=<?= urlencode($_img) ?>" target="_blank">
          <img src="/intern/todo-img.php?f=<?= urlencode($_img) ?>" alt=""
            style="height:60px;width:auto;max-width:100px;border-radius:6px;border:1px solid #e0e0e0;object-fit:cover;opacity:.75">
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div style="font-size:0.75rem;color:#9e9e9e;margin-bottom:8px">
        Erstellt von <strong><?= htmlspecialchars($td['created_by'] ?? '–') ?></strong>
        · <?= htmlspecialchars(date('d.m.Y', strtotime($td['created_at'] ?? 'now'))) ?>
        &nbsp;·&nbsp;
        ✅ Erledigt von <strong><?= htmlspecialchars($td['done_by'] ?? '–') ?></strong>
        am <?= htmlspecialchars(date('d.m.Y \u\m H:i \U\h\r', strtotime($td['done_at'] ?? 'now'))) ?>
      </div>
      <?php if (!empty($td['notes'])): ?>
      <div style="margin-bottom:10px;display:flex;flex-direction:column;gap:5px">
        <?php foreach ($td['notes'] as $_note): ?>
        <div style="background:#f0f4f0;border-left:3px solid #c8e6c9;border-radius:0 6px 6px 0;padding:6px 10px;opacity:.85">
          <div style="font-size:0.83rem;color:#5a6c5a;white-space:pre-wrap"><?= htmlspecialchars($_note['text']) ?></div>
          <div style="font-size:0.7rem;color:#b0b0b0;margin-top:2px">
            <strong><?= htmlspecialchars($_note['by'] ?? '–') ?></strong>
            · <?= htmlspecialchars(date('d.m.Y H:i', strtotime($_note['at'] ?? 'now'))) ?> Uhr
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($canWriteTodo): ?>
      <div style="display:flex;gap:8px;padding-top:8px;border-top:1px solid #efefef">
        <form method="POST" action="/intern/action.php" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action"  value="toggle_todo">
          <input type="hidden" name="todo_id" value="<?= htmlspecialchars($td['id']) ?>">
          <button type="submit" style="background:#fff8e1;color:#e65100;border:1px solid #ffe082;border-radius:7px;padding:5px 12px;font-size:0.8rem;cursor:pointer;font-family:inherit">
            ↩ Reaktivieren
          </button>
        </form>
        <form method="POST" action="/intern/action.php" style="display:inline">
          <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action"  value="delete_todo">
          <input type="hidden" name="todo_id" value="<?= htmlspecialchars($td['id']) ?>">
          <button type="submit" onclick="return confirm('Todo endgültig löschen?')"
            style="background:#fdecea;color:#c62828;border:1px solid #f5c6c6;border-radius:7px;padding:5px 10px;font-size:0.8rem;cursor:pointer;font-family:inherit">
            🗑
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div><!-- /mtab-todos -->

  <!-- ===== TAB: TERMINE ===== -->
  <?php if ($canEditContent): ?>
  <div class="mtab" id="mtab-termine">
    <?php
      $_tSaved = $_GET['saved'] ?? '';
      $_tTab   = $_GET['tab']   ?? '';
    ?>
    <?php if ($_tTab === 'termine' && $_tSaved === '1'): ?>
    <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:10px 16px;margin-bottom:16px;color:#2e7d32;font-size:0.88rem">✅ Vereinstermine gespeichert.</div>
    <?php elseif ($_tTab === 'termine' && $_tSaved === 'ga'): ?>
    <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:10px 16px;margin-bottom:16px;color:#2e7d32;font-size:0.88rem">✅ Gemeinschaftsarbeit-Termine gespeichert.</div>
    <?php endif; ?>

    <!-- ── Gemeinschaftsarbeit-Termine ── -->
    <form method="POST" id="ga-termine-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="save_ga_termine" value="1">
      <div class="card" style="margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
          <h3 style="margin:0">🔨 Gemeinschaftsarbeit-Termine</h3>
          <button type="button" onclick="addGaTermin()" style="background:#3d6b41;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer">＋ Termin hinzufügen</button>
        </div>
        <p style="font-size:0.82rem;color:#5a6c5a;margin-bottom:16px">Diese Einträge erscheinen im Mitgliederbereich unter „📅 Termine → Gemeinschaftsarbeit". Neue Zeile im Text = neue Zeile in der Anzeige.</p>
        <div id="ga-termine-list" style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px">
          <?php if (empty($_gaTermineAdmin)): ?>
          <p style="font-size:0.85rem;color:#9e9e9e;padding:8px 0" id="ga-termine-empty">Noch keine Einträge.</p>
          <?php endif; ?>
          <?php foreach ($_gaTermineAdmin as $_gi => $_ga): ?>
          <div class="ga-row" id="gar_<?= $_gi ?>" style="background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:14px;display:grid;grid-template-columns:150px 1fr 1fr auto;gap:10px;align-items:start">
            <div>
              <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Datum (erstes)</label>
              <input type="date" name="ga_date[]" value="<?= htmlspecialchars($_ga['date'] ?? '') ?>" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit">
              <input type="hidden" name="ga_id[]" value="<?= htmlspecialchars($_ga['id'] ?? '') ?>">
            </div>
            <div>
              <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Titel</label>
              <input type="text" name="ga_title[]" value="<?= htmlspecialchars($_ga['title'] ?? '') ?>" placeholder="z.B. Frühjahrspflege" maxlength="80" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit;box-sizing:border-box">
            </div>
            <div>
              <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Text (neue Zeile = neue Zeile)</label>
              <textarea name="ga_body[]" rows="3" placeholder="Sa, 11.04. · Ungerade Parzellen&#10;So, 12.04. · Gerade Parzellen&#10;10:00 – 13:00 Uhr" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit;resize:vertical;box-sizing:border-box"><?= htmlspecialchars($_ga['body'] ?? '') ?></textarea>
            </div>
            <div style="padding-top:22px">
              <button type="button" onclick="delGaTermin(<?= $_gi ?>)" title="Löschen" style="background:#fce8e6;border:none;color:#c62828;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center">✕</button>
              <input type="hidden" name="ga_delete[]" value="" id="gardel_<?= $_gi ?>" disabled>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:10px 28px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">💾 Speichern</button>
      </div>
    </form>

    <!-- ── Vereinstermine ── -->
    <form method="POST" id="termine-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="save_termine" value="1">
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
          <h3 style="margin:0">📅 Vereinstermine</h3>
          <button type="button" onclick="addTermin()" style="background:#3d6b41;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer">＋ Termin hinzufügen</button>
        </div>
        <p style="font-size:0.82rem;color:#5a6c5a;margin-bottom:16px">Sprechzeiten, Versammlungen usw. — erscheinen unter „Vereinstermine". Abgelaufene Termine werden automatisch ausgeblendet.</p>
        <div id="termine-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
          <?php if (empty($_termineAdmin)): ?>
          <p style="font-size:0.85rem;color:#9e9e9e;padding:12px 0" id="termine-empty">Noch keine Termine eingetragen.</p>
          <?php endif; ?>
          <?php foreach ($_termineAdmin as $_ti => $_te): ?>
          <div class="termin-row" id="ter_<?= $_ti ?>" style="display:grid;grid-template-columns:150px 1fr auto;gap:8px;align-items:start;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:12px">
            <div>
              <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Datum</label>
              <input type="date" name="te_date[]" value="<?= htmlspecialchars($_te['date'] ?? '') ?>" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit">
            </div>
            <div>
              <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Titel / Beschreibung</label>
              <input type="text" name="te_title[]" value="<?= htmlspecialchars($_te['title'] ?? '') ?>" placeholder="z.B. Sprechzeit Vorstand · 18:00–18:30 Uhr · Vereinshaus" maxlength="120" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit;box-sizing:border-box">
              <input type="text" name="te_desc[]" value="<?= htmlspecialchars($_te['desc'] ?? '') ?>" placeholder="Zusatzinfo (optional)" maxlength="120" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;margin-top:5px;color:#5a6c5a">
            </div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding-top:22px">
              <button type="button" onclick="delTermin(<?= $_ti ?>)" title="Löschen" style="background:#fce8e6;border:none;color:#c62828;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center">✕</button>
              <input type="hidden" name="te_delete[]" value="" id="terdel_<?= $_ti ?>" disabled>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:10px 28px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">💾 Speichern</button>
      </div>
    </form>
  </div><!-- /mtab-termine -->
  <?php endif; ?>

  <!-- ───────────────────────── VERANSTALTUNGEN ───────────────────────── -->
  <?php
  if ($_canManageEvents) {
      require_once dirname(__DIR__) . '/inc/events.php';
      $_eventsList   = kgv_events_all();
      $_editEventId  = (string)($_GET['id'] ?? '');
      $_editEvent    = null;
      foreach ($_eventsList as $_e) {
          if (($_e['id'] ?? '') === $_editEventId) { $_editEvent = $_e; break; }
      }
      $_evFlashSaved = (string)($_GET['saved']   ?? '');
      $_evFlashError = (string)($_GET['error']   ?? '');
  }
  ?>
  <?php if ($_canManageEvents): ?>
  <div id="mtab-events" class="mtab">
    <div style="background:#fff;border-radius:14px;border:1px solid #d4e6c3;padding:24px 26px;margin-bottom:20px">
      <h2 style="margin:0 0 6px;color:#2d3e2d">🎪 Veranstaltungen</h2>
      <p style="margin:0;color:#5a6c5a;font-size:0.92rem">Anmeldeseiten mit QR-Code für Veranstaltungen erstellen — öffentlich oder intern, mit oder ohne Buffet-Beitrag.</p>
    </div>

    <?php
    // Wenn gerade gespeichert UND zur Edit-Seite des frischen Events weitergeleitet
    if ($_evFlashSaved !== '' && $_editEvent && ($_editEvent['id'] ?? '') === $_evFlashSaved):
        $_savedHasFlyer = !empty($_editEvent['custom_flyer_file']) && !empty($_editEvent['use_custom_flyer']);
        $_savedQrUrl    = site_url() . '/event/' . rawurlencode($_editEvent['slug'] ?? '');
    ?>
    <?php if (!$_savedHasFlyer): ?>
    <!-- Onboarding-Wizard: nach dem Anlegen prominente Anleitung zum Flyer-Workflow -->
    <div style="background:linear-gradient(135deg,#e8f5e9 0%,#f5f7f2 100%);border:2px solid #2e7d32;border-radius:14px;padding:24px 28px;margin-bottom:22px;box-shadow:0 4px 14px rgba(46,125,50,0.12)">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <span style="font-size:1.5rem">🎉</span>
        <h3 style="margin:0;color:#1b5e20;font-size:1.15rem">Super, deine Veranstaltung steht!</h3>
      </div>
      <p style="margin:6px 0 16px;color:#2d3e2d;line-height:1.55">Die Anmeldeseite ist schon live unter <a href="<?= htmlspecialchars($_savedQrUrl) ?>" target="_blank" style="color:#3d6b41;font-weight:600;text-decoration:none"><?= htmlspecialchars($_savedQrUrl) ?></a>. Jetzt noch der letzte Schliff: ein hübscher Flyer für die Pinnwand.</p>

      <div style="display:grid;grid-template-columns:180px 1fr;gap:22px;align-items:center;background:#fff;border-radius:10px;padding:18px;margin-bottom:14px">
        <div style="text-align:center">
          <img src="/intern/qr.php?size=240&amp;data=<?= rawurlencode($_savedQrUrl) ?>" alt="QR-Code" style="width:160px;height:160px;border-radius:8px;border:1px solid #d4e6c3;background:#fff">
          <div style="margin-top:6px"><a href="/intern/qr.php?size=800&amp;data=<?= rawurlencode($_savedQrUrl) ?>" download="qr_<?= htmlspecialchars($_editEvent['slug']) ?>.png" target="_blank" rel="noopener" style="font-size:0.82rem;color:#3d6b41;text-decoration:none;font-weight:600">⬇ QR als PNG laden</a></div>
        </div>
        <div>
          <div style="font-weight:700;color:#2d3e2d;margin-bottom:8px;font-size:1rem">📱 Hier ist dein QR-Code für die Anmeldeseite</div>
          <p style="margin:0 0 12px;color:#5a6c5a;line-height:1.6;font-size:0.92rem">
            Jeder, der den QR scannt, landet direkt im Anmeldeformular. <strong>Bastel dir was Hübsches in Canva</strong> 🎨 (oder einem anderen Tool) und lade den fertigen Flyer unten hoch — wir hängen ihn als Aushang aus und zeigen ihn auch im Mitgliederbereich an.
          </p>
          <details style="font-size:0.84rem;color:#5a6c5a">
            <summary style="cursor:pointer;color:#3d6b41;font-weight:600">💡 So funktioniert's in Canva</summary>
            <ol style="margin:8px 0 0;padding-left:22px;line-height:1.7">
              <li>Canva öffnen, Vorlage wählen (Suchbegriff z.B. „Veranstaltung Flyer A4")</li>
              <li>Titel, Datum, Uhrzeit, Ort anpassen</li>
              <li>QR-Code-PNG (oben links) einfügen — Drag &amp; Drop oder Upload</li>
              <li><em>Datei → Herunterladen → PDF (Druck) oder PNG</em></li>
              <li>Unten hochladen → Häkchen → speichern → fertig!</li>
            </ol>
          </details>
        </div>
      </div>

      <!-- Direkter Upload-Block (Mini-Form, postet ans selbe Event) -->
      <form method="POST" action="/intern/" enctype="multipart/form-data" style="background:#fff;border:2px dashed #2e7d32;border-radius:10px;padding:16px 18px">
        <input type="hidden" name="csrf"  value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="ev_id" value="<?= htmlspecialchars($_editEvent['id']) ?>">
        <input type="hidden" name="save_event" value="1">
        <input type="hidden" name="ev_custom_flyer_existing" value="<?= htmlspecialchars($_editEvent['custom_flyer_file'] ?? '') ?>">
        <!-- Sandra muss die restlichen Pflichtfelder mitschicken, damit der Save-Handler nicht meckert -->
        <input type="hidden" name="ev_title"    value="<?= htmlspecialchars($_editEvent['title']    ?? '') ?>">
        <input type="hidden" name="ev_slug"     value="<?= htmlspecialchars($_editEvent['slug']     ?? '') ?>">
        <input type="hidden" name="ev_subtitle" value="<?= htmlspecialchars($_editEvent['subtitle'] ?? '') ?>">
        <input type="hidden" name="ev_description" value="<?= htmlspecialchars($_editEvent['description'] ?? '') ?>">
        <input type="hidden" name="ev_type"     value="<?= htmlspecialchars($_editEvent['type']     ?? 'public') ?>">
        <input type="hidden" name="ev_event_date" value="<?= htmlspecialchars($_editEvent['event_date'] ?? '') ?>">
        <input type="hidden" name="ev_deadline"   value="<?= htmlspecialchars($_editEvent['deadline']   ?? '') ?>">
        <?php if (!empty($_editEvent['active'])):              ?><input type="hidden" name="ev_active"              value="1"><?php endif; ?>
        <?php if (!empty($_editEvent['show_catering'])):       ?><input type="hidden" name="ev_show_catering"       value="1"><?php endif; ?>
        <?php if (!empty($_editEvent['show_in_member_area'])): ?><input type="hidden" name="ev_show_in_member_area" value="1"><?php endif; ?>
        <input type="hidden" name="ev_pdf_eyebrow"     value="<?= htmlspecialchars($_editEvent['pdf_eyebrow']     ?? '') ?>">
        <input type="hidden" name="ev_pdf_intro_text"  value="<?= htmlspecialchars($_editEvent['pdf_intro_text']  ?? '') ?>">
        <input type="hidden" name="ev_pdf_cta_main"    value="<?= htmlspecialchars($_editEvent['pdf_cta_main']    ?? '') ?>">
        <input type="hidden" name="ev_pdf_cta_sub"     value="<?= htmlspecialchars($_editEvent['pdf_cta_sub']     ?? '') ?>">
        <input type="hidden" name="ev_pdf_footer_note" value="<?= htmlspecialchars($_editEvent['pdf_footer_note'] ?? '') ?>">
        <?php if (!empty($_editEvent['pdf_show_description'])): ?><input type="hidden" name="ev_pdf_show_description" value="1"><?php endif; ?>
        <input type="hidden" name="ev_use_custom_flyer" value="1">

        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <label style="display:block;font-weight:700;color:#2d3e2d;font-size:0.95rem;flex-shrink:0">🎨 Flyer hochladen:</label>
          <input type="file" name="ev_custom_flyer" accept="application/pdf,image/jpeg,image/png" required style="flex:1;min-width:200px;padding:10px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;background:#fafbf8">
          <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:10px 22px;border-radius:7px;font-weight:700;cursor:pointer;font-size:0.92rem">📤 Hochladen + speichern</button>
        </div>
        <div style="font-size:0.78rem;color:#8a9a8a;margin-top:8px">PDF, PNG oder JPG · max. 12 MB · Häkchen „verwenden" wird automatisch gesetzt</div>
      </form>

      <div style="margin-top:12px;text-align:right">
        <a href="?tab=events" style="font-size:0.84rem;color:#8a9a8a;text-decoration:none">Flyer später hochladen — ich nehme erstmal die Standard-Vorlage ›</a>
      </div>
    </div>
    <?php else: ?>
    <div style="background:#e8f5e9;color:#2e7d32;border-left:4px solid #2e7d32;padding:12px 16px;border-radius:8px;margin-bottom:18px">✓ Veranstaltung gespeichert — Flyer ist aktiv.</div>
    <?php endif; ?>
    <?php elseif ($_evFlashSaved !== ''): ?>
    <div style="background:#e8f5e9;color:#2e7d32;border-left:4px solid #2e7d32;padding:12px 16px;border-radius:8px;margin-bottom:18px">✓ Veranstaltung gespeichert.</div>
    <?php endif; ?>
    <?php if ($_evFlashError === 'title_required'): ?>
    <div style="background:#ffebee;color:#b71c1c;border-left:4px solid #c62828;padding:12px 16px;border-radius:8px;margin-bottom:18px">⚠️ Titel ist Pflichtfeld.</div>
    <?php endif; ?>

    <!-- ─── Liste aller Events ─── -->
    <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:20px 22px;margin-bottom:18px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <h3 style="margin:0;color:#2d3e2d">Alle Veranstaltungen</h3>
        <a href="?tab=events&new=1" style="background:#3d6b41;color:#fff;text-decoration:none;padding:8px 18px;border-radius:8px;font-weight:600;font-size:0.88rem">＋ Neue Veranstaltung</a>
      </div>

      <?php if (empty($_eventsList)): ?>
      <p style="color:#8a9a8a;padding:18px;text-align:center">Noch keine Veranstaltungen angelegt. Klick oben rechts auf „＋ Neue Veranstaltung".</p>
      <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:0.9rem">
        <thead>
          <tr style="background:#f5f7f2;color:#5a8c5e;text-transform:uppercase;letter-spacing:.04em;font-size:0.72rem">
            <th style="text-align:left;padding:10px 12px">Veranstaltung</th>
            <th style="text-align:left;padding:10px 12px">Datum</th>
            <th style="text-align:left;padding:10px 12px">Anmeldungen</th>
            <th style="text-align:left;padding:10px 12px">Status</th>
            <th style="text-align:right;padding:10px 12px">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($_eventsList as $_ev): ?>
          <?php
            $_active = !empty($_ev['active']) && !kgv_event_deadline_passed($_ev);
            $_regCount = kgv_event_active_count($_ev);
            $_totalGuests = kgv_event_total_guests($_ev);
            $_evdate = '';
            if (!empty($_ev['event_date'])) {
                $_dt = DateTimeImmutable::createFromFormat('Y-m-d', $_ev['event_date']);
                $_evdate = $_dt ? $_dt->format('d.m.Y') : (string)$_ev['event_date'];
            }
            $_url = site_url() . '/event/' . htmlspecialchars($_ev['slug'] ?? '');
          ?>
          <tr style="border-top:1px solid #e8f0e0">
            <td style="padding:12px">
              <div style="font-weight:700;color:#2d3e2d"><?= htmlspecialchars($_ev['title'] ?? '?') ?></div>
              <div style="color:#8a9a8a;font-size:0.82rem;margin-top:2px"><?= htmlspecialchars($_ev['subtitle'] ?? '') ?></div>
              <div style="margin-top:4px"><a href="<?= $_url ?>" target="_blank" style="color:#3d6b41;font-size:0.78rem;text-decoration:none">🔗 /event/<?= htmlspecialchars($_ev['slug'] ?? '') ?></a></div>
            </td>
            <td style="padding:12px;color:#5a6c5a"><?= $_evdate ?: '<span style="color:#bbb">—</span>' ?></td>
            <td style="padding:12px;color:#2d3e2d">
              <strong><?= $_regCount ?></strong>
              <?php if ($_totalGuests !== $_regCount): ?><span style="color:#8a9a8a;font-size:0.82rem">(<?= $_totalGuests ?> Pers.)</span><?php endif; ?>
            </td>
            <td style="padding:12px">
              <?php if ($_active): ?>
                <span style="background:#e8f5e9;color:#2e7d32;font-size:0.72rem;padding:3px 10px;border-radius:12px;font-weight:600">Aktiv</span>
              <?php elseif (kgv_event_deadline_passed($_ev)): ?>
                <span style="background:#fff3e0;color:#e65100;font-size:0.72rem;padding:3px 10px;border-radius:12px;font-weight:600">Frist abgelaufen</span>
              <?php else: ?>
                <span style="background:#eeeeee;color:#666;font-size:0.72rem;padding:3px 10px;border-radius:12px;font-weight:600">Inaktiv</span>
              <?php endif; ?>
            </td>
            <td style="padding:12px;text-align:right;white-space:nowrap">
              <a href="?tab=events&id=<?= urlencode($_ev['id']) ?>" style="display:inline-block;background:#3d6b41;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600">Öffnen</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- ─── Edit-Modus ODER Neu-Modus ─── -->
    <?php
      $_showEdit = $_editEvent !== null || isset($_GET['new']);
      if ($_showEdit):
        $_ed = $_editEvent ?? [
            'id' => '', 'slug' => '', 'title' => '', 'subtitle' => '',
            'description' => '', 'type' => 'public', 'show_catering' => false,
            'active' => true, 'show_in_member_area' => false,
            'deadline' => '', 'event_date' => '', 'registrations' => [],
        ];
    ?>
    <div id="ev-edit" style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:22px 24px;margin-bottom:18px">
      <h3 style="margin:0 0 14px;color:#2d3e2d">
        <?= $_editEvent ? '✏️ Veranstaltung bearbeiten' : '＋ Neue Veranstaltung anlegen' ?>
      </h3>

      <form method="POST" action="/intern/" enctype="multipart/form-data">
        <input type="hidden" name="csrf"  value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="ev_id" value="<?= htmlspecialchars($_ed['id'] ?? '') ?>">
        <input type="hidden" name="save_event" value="1">
        <input type="hidden" name="ev_custom_flyer_existing" value="<?= htmlspecialchars($_ed['custom_flyer_file'] ?? '') ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div>
            <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">Titel <span style="color:#c62828">*</span></label>
            <input type="text" name="ev_title" required maxlength="120" value="<?= htmlspecialchars($_ed['title'] ?? '') ?>" placeholder="z.B. Frühshoppen 2026" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">URL-Slug <span class="hint" style="color:#8a9a8a;font-weight:400">(auto, überschreibbar)</span></label>
            <input type="text" name="ev_slug" maxlength="60" value="<?= htmlspecialchars($_ed['slug'] ?? '') ?>" placeholder="auto" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:monospace;box-sizing:border-box">
          </div>
        </div>

        <div style="margin-top:12px">
          <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">Untertitel <span style="color:#8a9a8a;font-weight:400">(z.B. Datum + Uhrzeit als Freitext)</span></label>
          <input type="text" name="ev_subtitle" maxlength="160" value="<?= htmlspecialchars($_ed['subtitle'] ?? '') ?>" placeholder="z.B. Sonntag, 12. Juli 2026, 11:00 Uhr" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
        </div>

        <div style="margin-top:12px">
          <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">Beschreibung</label>
          <textarea name="ev_description" maxlength="4000" rows="6" placeholder="Was findet statt? Was gibt es vor Ort? Was sollen die Anmelder wissen?" style="width:100%;padding:10px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box;resize:vertical"><?= htmlspecialchars($_ed['description'] ?? '') ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
          <div>
            <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">Veranstaltungsdatum</label>
            <input type="date" name="ev_event_date" value="<?= htmlspecialchars($_ed['event_date'] ?? '') ?>" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">Anmeldeschluss</label>
            <input type="date" name="ev_deadline" value="<?= htmlspecialchars($_ed['deadline'] ?? '') ?>" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
          </div>
        </div>

        <div style="background:#f5f7f2;border-radius:8px;padding:14px 18px;margin-top:16px">
          <div style="font-size:0.78rem;font-weight:700;color:#5a8c5e;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Einstellungen</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <label style="display:flex;gap:9px;align-items:flex-start;font-size:0.92rem;cursor:pointer">
              <input type="checkbox" name="ev_active" value="1" <?= !empty($_ed['active']) ? 'checked' : '' ?> style="margin-top:3px">
              <span><strong>Anmeldeseite aktiv</strong> — wenn deaktiviert, ist die Seite nicht erreichbar</span>
            </label>
            <label style="display:flex;gap:9px;align-items:flex-start;font-size:0.92rem;cursor:pointer">
              <input type="checkbox" name="ev_show_catering" value="1" <?= !empty($_ed['show_catering']) ? 'checked' : '' ?> style="margin-top:3px">
              <span><strong>Buffet-Feld anzeigen</strong> — Anmelder können einen Buffet-Beitrag angeben</span>
            </label>
            <label style="display:flex;gap:9px;align-items:flex-start;font-size:0.92rem;cursor:pointer">
              <input type="checkbox" name="ev_show_in_member_area" value="1" <?= !empty($_ed['show_in_member_area']) ? 'checked' : '' ?> style="margin-top:3px">
              <span><strong>Im Mitgliederbereich anzeigen</strong> — Mitglieder sehen die Veranstaltung dort + können sich mit vorausgefüllten Daten anmelden</span>
            </label>
            <div style="margin-top:8px">
              <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px">Veranstaltungstyp</label>
              <select name="ev_type" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box;background:#fff">
                <option value="public"   <?= ($_ed['type'] ?? '') === 'public'   ? 'selected' : '' ?>>🌿 Öffentlich — jeder kann anmelden (auch externe Vereine)</option>
                <option value="internal" <?= ($_ed['type'] ?? '') === 'internal' ? 'selected' : '' ?>>🌱 Intern — interne Veranstaltung des Vereins</option>
              </select>
            </div>
          </div>
        </div>

        <!-- ─── Eigener Flyer (z.B. aus Canva) hochladen ─── -->
        <details style="margin-top:18px;border:1px dashed #d4e6c3;border-radius:8px;padding:14px 18px;background:#fff8e1" <?= !empty($_ed['custom_flyer_file']) ? 'open' : '' ?>>
          <summary style="cursor:pointer;font-weight:700;color:#3d6b41;font-size:0.95rem">🎨 Eigenen Flyer hochladen <span style="color:#8a9a8a;font-weight:400;font-size:0.84rem">(z.B. aus Canva — wird statt der Standard-Vorlage als PDF-Aushang verwendet)</span></summary>
          <div style="margin-top:14px">
            <?php if (!empty($_ed['custom_flyer_file'])): ?>
              <div style="background:#fff;border:1px solid #d4e6c3;border-radius:8px;padding:14px 18px;margin-bottom:12px">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
                  <div>
                    <strong style="color:#2d3e2d">📎 Aktuell hochgeladen:</strong>
                    <code style="font-size:0.82rem;color:#5a6c5a;background:#f5f7f2;padding:3px 8px;border-radius:4px;margin-left:6px"><?= htmlspecialchars($_ed['custom_flyer_file']) ?></code>
                  </div>
                  <a href="/images/event_flyers/<?= rawurlencode($_ed['custom_flyer_file']) ?>" target="_blank" style="background:#e3f2fd;color:#1565c0;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.84rem">🔍 Anschauen</a>
                </div>
                <label style="display:flex;gap:9px;align-items:flex-start;font-size:0.92rem;cursor:pointer;color:#2d3e2d;margin-top:14px;background:#f5f7f2;padding:10px 14px;border-radius:6px">
                  <input type="checkbox" name="ev_use_custom_flyer" value="1" <?= !empty($_ed['use_custom_flyer']) ? 'checked' : '' ?> style="margin-top:3px">
                  <span><strong>Custom-Flyer als PDF-Aushang verwenden</strong> <span style="color:#8a9a8a;font-weight:400">(statt der Standard-Vorlage). Der Download-Button im Backoffice + die „QR-Aushang"-PDF liefern dann diesen Flyer aus.</span></span>
                </label>
                <label style="display:flex;gap:9px;align-items:flex-start;font-size:0.85rem;cursor:pointer;color:#c62828;margin-top:8px">
                  <input type="checkbox" name="ev_remove_custom_flyer" value="1">
                  <span>🗑 Hochgeladenen Flyer beim Speichern entfernen</span>
                </label>
              </div>
            <?php endif; ?>

            <div>
              <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:5px"><?= !empty($_ed['custom_flyer_file']) ? 'Neuen Flyer hochladen (ersetzt den aktuellen)' : 'Flyer auswählen' ?> <span style="color:#8a9a8a;font-weight:400">(PDF, PNG oder JPG · max. 12 MB)</span></label>
              <input type="file" name="ev_custom_flyer" accept="application/pdf,image/jpeg,image/png" style="width:100%;padding:8px;border:1px dashed #c8d3c4;border-radius:7px;font-family:inherit;background:#fff;box-sizing:border-box">
            </div>

            <div style="background:#f5f7f2;border-radius:6px;padding:10px 14px;margin-top:12px;font-size:0.84rem;color:#5a6c5a;line-height:1.55">
              <strong style="color:#3d6b41">💡 Tipp Canva-Workflow:</strong><br>
              1. Flyer in Canva fertigstellen<br>
              2. <em>Datei → Herunterladen → PDF</em> (oder PNG für Druck/Web)<br>
              3. Hier hochladen → Häkchen „als PDF-Aushang verwenden" → Speichern<br>
              4. Optional: Den QR-Code-PNG (rechte Spalte) in Canva einfügen, damit der QR direkt auf dem Flyer ist
            </div>
          </div>
        </details>

        <!-- ─── PDF-Aushang anpassen (collapsible — gilt nur für die Standard-Vorlage) ─── -->
        <details style="margin-top:18px;border:1px dashed #d4e6c3;border-radius:8px;padding:14px 18px;background:#fafbf8" <?= ($_editEvent && (!empty($_ed['pdf_intro_text']) || !empty($_ed['pdf_footer_note']) || !empty($_ed['pdf_eyebrow']))) ? 'open' : '' ?>>
          <summary style="cursor:pointer;font-weight:700;color:#3d6b41;font-size:0.95rem">📄 Standard-Aushang anpassen <span style="color:#8a9a8a;font-weight:400;font-size:0.84rem">(nur relevant, wenn KEIN eigener Flyer verwendet wird)</span></summary>
          <div style="margin-top:14px;display:flex;flex-direction:column;gap:12px">

            <div>
              <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:4px">Überzeile <span style="color:#8a9a8a;font-weight:400">(über dem Titel)</span></label>
              <input type="text" name="ev_pdf_eyebrow" maxlength="80" value="<?= htmlspecialchars($_ed['pdf_eyebrow'] ?? '') ?>" placeholder="🎪 EINLADUNG ZUR VERANSTALTUNG" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
              <div style="font-size:0.76rem;color:#8a9a8a;margin-top:3px">Default: „🎪 EINLADUNG ZUR VERANSTALTUNG" · Emojis möglich</div>
            </div>

            <div>
              <label style="display:flex;gap:9px;align-items:flex-start;font-size:0.92rem;cursor:pointer;color:#2d3e2d">
                <input type="checkbox" name="ev_pdf_show_description" value="1" <?= !empty($_ed['pdf_show_description']) ? 'checked' : '' ?> style="margin-top:3px">
                <span><strong>Veranstaltungs-Beschreibung im PDF anzeigen</strong> — die volle Beschreibung erscheint zwischen Untertitel und QR-Code</span>
              </label>
            </div>

            <div>
              <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:4px">Zusätzlicher Text im PDF <span style="color:#8a9a8a;font-weight:400">(z.B. „Bringt Familie und Freunde mit!")</span></label>
              <textarea name="ev_pdf_intro_text" maxlength="800" rows="3" placeholder="Optionaler Hinweis zwischen Untertitel und QR-Code" style="width:100%;padding:10px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box;resize:vertical"><?= htmlspecialchars($_ed['pdf_intro_text'] ?? '') ?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div>
                <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:4px">Aufruf zum QR-Scan <span style="color:#8a9a8a;font-weight:400">(direkt unter dem QR)</span></label>
                <input type="text" name="ev_pdf_cta_main" maxlength="80" value="<?= htmlspecialchars($_ed['pdf_cta_main'] ?? '') ?>" placeholder="📱 Jetzt mit dem Smartphone scannen" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
              </div>
              <div>
                <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:4px">Untertext zum QR-Scan</label>
                <input type="text" name="ev_pdf_cta_sub" maxlength="80" value="<?= htmlspecialchars($_ed['pdf_cta_sub'] ?? '') ?>" placeholder="… oder direkt im Browser besuchen:" style="width:100%;padding:9px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box">
              </div>
            </div>

            <div>
              <label style="display:block;font-size:0.84rem;font-weight:600;color:#2d3e2d;margin-bottom:4px">Fußzeilen-Hinweis <span style="color:#8a9a8a;font-weight:400">(über dem Standard-Footer, z.B. Kontakt für Rückfragen)</span></label>
              <textarea name="ev_pdf_footer_note" maxlength="400" rows="2" placeholder="z.B. Bei Fragen: events@example.org" style="width:100%;padding:10px 12px;border:1px solid #c8d3c4;border-radius:7px;font-family:inherit;box-sizing:border-box;resize:vertical"><?= htmlspecialchars($_ed['pdf_footer_note'] ?? '') ?></textarea>
            </div>

            <p style="margin:6px 0 0;font-size:0.78rem;color:#8a9a8a;background:#fff8e1;border-radius:6px;padding:8px 12px"><strong>Tipp:</strong> Lass die Felder leer, um die Standard-Vorlage zu nutzen. Nach dem Speichern oben rechts auf „📄 PDF-Aushang herunterladen" klicken, um die Vorschau zu sehen.</p>
          </div>
        </details>

        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:11px 26px;border-radius:8px;font-weight:700;cursor:pointer;font-size:0.95rem">💾 Speichern</button>
          <a href="?tab=events" style="background:#f0f4ee;color:#3d6b41;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.92rem">Abbrechen</a>
          <?php if ($_editEvent): ?>
            <span style="margin-left:auto"></span>
            <a href="<?= site_url() ?>/event/<?= htmlspecialchars($_ed['slug']) ?>" target="_blank" style="background:#e3f2fd;color:#1565c0;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600">🔗 Anmeldeseite öffnen</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($_editEvent): ?>
      <?php $_qrUrl = site_url() . '/event/' . rawurlencode($_ed['slug']); ?>

      <!-- QR-Code + PDF-Aushang -->
      <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:22px 24px;margin-bottom:18px">
        <h3 style="margin:0 0 14px;color:#2d3e2d">📱 QR-Code & PDF-Aushang</h3>
        <div style="display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:center">
          <img src="/intern/qr.php?size=300&amp;data=<?= rawurlencode($_qrUrl) ?>" alt="QR-Code Anmeldeseite" style="width:200px;height:200px;border:1px solid #d4e6c3;border-radius:10px;background:#fff">
          <div>
            <p style="margin:0 0 12px;color:#5a6c5a;line-height:1.6">Der QR-Code führt direkt zur Anmeldeseite:<br><a href="<?= htmlspecialchars($_qrUrl) ?>" target="_blank" style="color:#3d6b41;font-weight:600;text-decoration:none"><?= htmlspecialchars($_qrUrl) ?></a></p>
            <?php $_usesCustom = !empty($_ed['use_custom_flyer']) && !empty($_ed['custom_flyer_file']); ?>
            <?php if ($_usesCustom): ?>
            <div style="background:#e8f5e9;color:#1b5e20;border-left:4px solid #2e7d32;padding:8px 14px;border-radius:6px;margin-bottom:12px;font-size:0.86rem">
              🎨 <strong>Eigener Flyer aktiv</strong> — der Download liefert deine hochgeladene Datei (<code style="background:#fff;padding:2px 6px;border-radius:3px"><?= htmlspecialchars($_ed['custom_flyer_file']) ?></code>) statt der Standard-Vorlage.
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <a href="/intern/event-aushang.php?id=<?= urlencode($_ed['id']) ?>" target="_blank" style="background:#3d6b41;color:#fff;padding:10px 18px;border-radius:7px;text-decoration:none;font-weight:600;font-size:0.9rem">📄 <?= $_usesCustom ? 'Eigenen Flyer öffnen' : 'PDF-Aushang (DIN A4) herunterladen' ?></a>
              <?php if ($_usesCustom): ?>
              <a href="/intern/event-aushang.php?id=<?= urlencode($_ed['id']) ?>&standard=1" target="_blank" style="background:#f0f4ee;color:#3d6b41;padding:10px 16px;border-radius:7px;text-decoration:none;font-weight:600;font-size:0.88rem">📄 Standard-Vorlage ansehen</a>
              <?php endif; ?>
              <a href="/intern/qr.php?size=600&amp;data=<?= rawurlencode($_qrUrl) ?>" download="qr_<?= htmlspecialchars($_ed['slug']) ?>.png" target="_blank" rel="noopener" style="background:#f0f4ee;color:#3d6b41;padding:10px 16px;border-radius:7px;text-decoration:none;font-weight:600;font-size:0.88rem">⬇ QR als PNG</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Anmeldungen-Liste mit Filter (Sandras Mockup 1, KGV-styled) -->
      <?php
        $_regs = $_ed['registrations'] ?? [];
        $_regsActive    = array_values(array_filter($_regs, fn($r) => empty($r['cancelled_at'])));
        $_regsCancelled = array_values(array_filter($_regs, fn($r) => !empty($r['cancelled_at'])));
        $_sumGuests = array_sum(array_map(fn($r) => (int)($r['guests'] ?? 1), $_regsActive));
      ?>
      <div style="background:#fff;border-radius:12px;border:1px solid #d4e6c3;padding:22px 24px;margin-bottom:18px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px">
          <h3 style="margin:0;color:#2d3e2d">📋 Anmeldungen (<?= count($_regsActive) ?>)</h3>
          <div style="display:flex;gap:10px;align-items:center">
            <a href="/intern/event-csv.php?id=<?= urlencode($_ed['id']) ?>" style="background:#f0f4ee;color:#3d6b41;padding:8px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.84rem">⬇ CSV-Export</a>
          </div>
        </div>

        <div style="background:#e2efe0;border-left:5px solid #3d6b41;padding:14px 18px;margin-bottom:14px;font-size:1rem;border-radius:6px">
          <strong>Personen gesamt: <?= $_sumGuests ?></strong>
          <?php if ($_sumGuests !== count($_regsActive)): ?>
          <span style="color:#5a6c5a"> · in <?= count($_regsActive) ?> Anmeldungen</span>
          <?php endif; ?>
        </div>

        <?php if (empty($_regsActive)): ?>
        <p style="color:#8a9a8a;padding:18px;text-align:center">Noch keine Anmeldungen.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.88rem">
          <thead>
            <tr style="background:#f5f7f2;color:#5a8c5e;text-transform:uppercase;letter-spacing:.04em;font-size:0.72rem">
              <th style="text-align:left;padding:10px 12px">Name</th>
              <th style="text-align:left;padding:10px 12px">E-Mail</th>
              <th style="text-align:left;padding:10px 12px">Telefon</th>
              <th style="text-align:center;padding:10px 12px">Personen</th>
              <?php if (!empty($_ed['show_catering'])): ?>
              <th style="text-align:left;padding:10px 12px">Buffet-Beitrag</th>
              <?php endif; ?>
              <th style="text-align:left;padding:10px 12px">Anmeldung</th>
              <th style="text-align:right;padding:10px 12px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($_regsActive as $r): ?>
            <tr style="border-top:1px solid #e8f0e0">
              <td style="padding:10px 12px;color:#2d3e2d;font-weight:600"><?= htmlspecialchars($r['fullname'] ?? '?') ?></td>
              <td style="padding:10px 12px"><a href="mailto:<?= htmlspecialchars($r['email'] ?? '') ?>" style="color:#3d6b41;text-decoration:none"><?= htmlspecialchars($r['email'] ?? '') ?></a></td>
              <td style="padding:10px 12px"><a href="tel:<?= htmlspecialchars($r['phone'] ?? '') ?>" style="color:#3d6b41;text-decoration:none"><?= htmlspecialchars($r['phone'] ?? '') ?></a></td>
              <td style="padding:10px 12px;text-align:center;font-weight:700;color:#3d6b41"><?= (int)($r['guests'] ?? 1) ?></td>
              <?php if (!empty($_ed['show_catering'])): ?>
              <td style="padding:10px 12px;color:#5a6c5a"><?= htmlspecialchars($r['catering'] ?? '') ?></td>
              <?php endif; ?>
              <td style="padding:10px 12px;color:#8a9a8a;font-size:0.82rem"><?= htmlspecialchars($r['submitted_at'] ?? '') ?></td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                <form method="POST" action="/intern/" style="display:inline" onsubmit="return confirm('Anmeldung von <?= htmlspecialchars(addslashes($r['fullname'] ?? '?')) ?> stornieren?')">
                  <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="ev_id"  value="<?= htmlspecialchars($_ed['id']) ?>">
                  <input type="hidden" name="reg_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>">
                  <input type="hidden" name="reg_action" value="cancel">
                  <button type="submit" style="background:none;border:1px solid #c62828;color:#c62828;padding:4px 10px;border-radius:5px;font-size:0.78rem;font-weight:600;cursor:pointer">Storno</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($_regsCancelled)): ?>
        <details style="margin-top:18px">
          <summary style="cursor:pointer;color:#8a9a8a;font-size:0.88rem;padding:8px 0">🗂️ Stornierte Anmeldungen (<?= count($_regsCancelled) ?>) anzeigen</summary>
          <table style="width:100%;border-collapse:collapse;font-size:0.84rem;margin-top:10px;opacity:0.65">
            <?php foreach ($_regsCancelled as $r): ?>
            <tr style="border-top:1px solid #e8f0e0">
              <td style="padding:8px 12px;text-decoration:line-through"><?= htmlspecialchars($r['fullname'] ?? '?') ?></td>
              <td style="padding:8px 12px"><?= htmlspecialchars($r['email'] ?? '') ?></td>
              <td style="padding:8px 12px"><?= (int)($r['guests'] ?? 1) ?> Pers.</td>
              <td style="padding:8px 12px;color:#8a9a8a;font-size:0.78rem">storniert: <?= htmlspecialchars($r['cancelled_at'] ?? '') ?></td>
              <td style="padding:8px 12px;text-align:right">
                <form method="POST" action="/intern/" style="display:inline">
                  <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="ev_id"  value="<?= htmlspecialchars($_ed['id']) ?>">
                  <input type="hidden" name="reg_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>">
                  <input type="hidden" name="reg_action" value="reactivate">
                  <button type="submit" style="background:none;border:1px solid #2e7d32;color:#2e7d32;padding:3px 8px;border-radius:4px;font-size:0.75rem;cursor:pointer">↻ Reaktivieren</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </table>
        </details>
        <?php endif; ?>
      </div>

      <!-- Veranstaltung löschen -->
      <div style="background:#fff;border-radius:12px;border:1px solid #ffcdd2;padding:18px 22px;margin-bottom:18px">
        <h4 style="margin:0 0 10px;color:#c62828">⚠️ Veranstaltung löschen</h4>
        <p style="margin:0 0 12px;color:#5a6c5a;font-size:0.88rem">Löscht das Event komplett inkl. aller Anmeldungen. Diese Aktion kann nicht rückgängig gemacht werden.</p>
        <form method="POST" action="/intern/" onsubmit="return confirm('Veranstaltung „<?= htmlspecialchars(addslashes($_ed['title'])) ?>“ mit <?= count($_regs) ?> Anmeldungen wirklich endgültig löschen?')">
          <input type="hidden" name="csrf"  value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="ev_id" value="<?= htmlspecialchars($_ed['id']) ?>">
          <input type="hidden" name="delete_event" value="1">
          <button type="submit" style="background:#c62828;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-weight:600;cursor:pointer;font-size:0.88rem">🗑 Veranstaltung endgültig löschen</button>
        </form>
      </div>
    <?php endif; ?>

    <?php endif; ?>
  </div><!-- /mtab-events -->
  <?php endif; ?>

  <!-- Tab: Schriftführung -->
  <?php
  // Sichtbar für SuperAdmin + Schriftführer + Web-Rolle (gleiche Bedingung wie Tab-Button oben).
  $_canSchriftfuehrung = $isSuperAdmin
      || in_array('schriftfuehrer', $_memberRoles, true)
      || in_array('web', $_memberRoles, true);
  if ($_canSchriftfuehrung):
      require_once dirname(__DIR__) . '/inc/sf_render.php';
      $_sfSub = (string)($_GET['sub'] ?? 'inbox');
  ?>
  <div id="mtab-schriftfuehrung" class="mtab">
    <?php sf_render($csrf, $_sfSub); ?>
  </div>
  <?php endif; ?>

</div>

<!-- ===== KAUTION-ABRECHNUNG-MODAL ===== -->
<div class="modal-backdrop" id="kautionModal">
  <div class="modal" style="max-width:540px">
    <div class="modal-header" style="background:#6a1b9a">
      <h3>💜 Kaution abrechnen</h3>
      <button class="modal-close" onclick="closeKautionModal()">×</button>
    </div>
    <form method="POST" action="/intern/action.php">
      <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action"     value="settle_kaution">
      <input type="hidden" name="booking_id" id="km-booking-id">
    <div class="modal-body">

      <div id="km-booking-info" style="background:#f5f7f2;border-radius:8px;padding:10px 16px;margin-bottom:20px;font-size:0.9rem;line-height:1.8"></div>

      <!-- Stromverbrauch -->
      <div class="modal-section">
        <label>⚡ Stromverbrauch</label>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <div style="position:relative;display:flex;align-items:center">
            <input type="number" name="kwh" id="km-kwh" placeholder="0,00" min="0" step="0.1"
              style="width:120px;padding:9px 36px 9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.95rem;font-family:inherit"
              oninput="_calcKaution()">
            <span style="position:absolute;right:10px;font-size:0.82rem;color:#5a6c5a">kWh</span>
          </div>
          <span id="km-strom-preis" style="font-size:0.82rem;color:#5a6c5a"></span>
        </div>
        <p style="font-size:0.76rem;color:#9e9e9e;margin-top:6px;margin-bottom:0">
          Preis/kWh wird aus <a href="/intern/content.php?tab=prices" target="_blank" style="color:#6a1b9a">Preiseinstellungen</a> gezogen.
        </p>
      </div>

      <!-- Schäden / Abzüge -->
      <div class="modal-section">
        <label>🔧 Schäden / weitere Abzüge</label>
        <div id="km-damages-list"></div>
        <button type="button" onclick="addKautionDamage()"
          style="background:#f5f0ff;color:#6a1b9a;border:1.5px dashed #ce93d8;border-radius:7px;padding:7px 14px;font-size:0.82rem;font-weight:600;cursor:pointer;font-family:inherit;margin-top:4px">
          ＋ Posten hinzufügen
        </button>
      </div>

      <!-- Live-Berechnung -->
      <div class="modal-section">
        <label>Abrechnung</label>
        <div class="price-summary">
          <div class="price-row"><span>Kaution</span><span><?= number_format($_kautionCfg, 2, ',', '.') ?> €</span></div>
          <div class="price-row" id="km-elec-row" style="display:none;color:#c62828">
            <span>Strom <span id="km-elec-detail" style="font-size:0.8rem;color:#9e9e9e"></span></span>
            <span id="km-elec-amount"></span>
          </div>
          <div class="price-row" id="km-dmg-row" style="display:none;color:#c62828">
            <span>Schäden / Abzüge</span>
            <span id="km-dmg-amount"></span>
          </div>
          <div class="price-row total"><span>Gesamtabzüge</span><span id="km-deductions">–</span></div>
          <div class="price-row grand"><span>Rückzahlung an Mieter</span><span id="km-returned" style="font-size:1.1rem"><?= number_format($_kautionCfg, 2, ',', '.') ?> €</span></div>
        </div>
      </div>

      <!-- Persönliche Nachricht -->
      <div class="modal-section" style="margin-bottom:0">
        <label>💬 Persönliche Nachricht (optional)</label>
        <textarea name="settle_note" id="km-note" rows="3"
          style="width:100%;box-sizing:border-box;padding:10px 14px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.88rem;font-family:inherit;resize:vertical"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-modal-cancel" onclick="closeKautionModal()">Abbrechen</button>
      <button type="submit" style="background:#6a1b9a;color:#fff;border:none;padding:10px 24px;border-radius:8px;cursor:pointer;font-size:0.9rem;font-weight:700">
        ✓ Abrechnen &amp; E-Mail senden
      </button>
    </div>
    </form>
  </div>
</div>

<!-- ===== BESTÄTIGEN-MODAL ===== -->
<div class="modal-backdrop" id="confirmModal">
  <div class="modal">
    <div class="modal-header">
      <h3>✓ Buchung bestätigen</h3>
      <button class="modal-close" onclick="closeModal()">×</button>
    </div>
    <div class="modal-body">

      <!-- Buchungsinfo -->
      <div id="modal-booking-info" style="background:#f5f7f2;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:0.9rem;line-height:1.8"></div>

      <!-- Tarif -->
      <div class="modal-section">
        <label>Tarif</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label id="tarif-std-label" style="display:flex;align-items:center;gap:10px;padding:12px 16px;border:2px solid #3d6b41;border-radius:10px;cursor:pointer;background:#f0f7f0">
            <input type="radio" name="tarif" value="standard" checked onchange="updateTotal()">
            <div>
              <div style="font-weight:700;color:#2d3e2d;font-size:0.9rem">Standard-Tarif</div>
              <div style="font-size:0.8rem;color:#5a6c5a"><?= number_format($_mieteCfg, 2, ',', '.') ?> € · Externe Gäste</div>
            </div>
          </label>
          <label id="tarif-member-label" style="display:flex;align-items:center;gap:10px;padding:12px 16px;border:2px solid #d4e6c3;border-radius:10px;cursor:pointer;background:#fff">
            <input type="radio" name="tarif" value="member" onchange="updateTotal()">
            <div>
              <div style="font-weight:700;color:#2d3e2d;font-size:0.9rem">Mitgliedstarif</div>
              <div style="font-size:0.8rem;color:#5a6c5a"><?= number_format($_mieteMemberCfg, 2, ',', '.') ?> € · KGV-Mitglieder (50 % Rabatt)</div>
            </div>
          </label>
        </div>
        <div id="member-tarif-hint" style="display:none;margin-top:8px;padding:8px 12px;background:#e8f5e9;border-radius:7px;font-size:0.82rem;color:#2e7d32;font-weight:600">
          ✓ Mitgliedstarif aktiv — 50% Rabatt auf die Raummiete
        </div>
      </div>

      <!-- Extras -->
      <div class="modal-section">
        <label>Zusatzleistungen</label>
        <div id="extras-list">
          <!-- Vordefinierte Extras -->
          <div class="extra-row" data-preset>
            <input type="checkbox" class="extra-check" onchange="updateTotal()">
            <span class="extra-label">Bierzelt-Garnitur (1 Tisch, 2 Bänke)</span>
            <input type="number" class="qty-input" value="1" min="1" onchange="updateTotal()">
            <span class="unit">×</span>
            <input type="number" class="price-input" value="5" min="0" step="0.01" onchange="updateTotal()">
            <span class="unit">€/Set</span>
          </div>
          <div class="extra-row" data-preset>
            <input type="checkbox" class="extra-check" onchange="updateTotal()">
            <span class="extra-label">Fritteusennutzung</span>
            <input type="number" class="qty-input" value="1" min="1" onchange="updateTotal()" style="visibility:hidden">
            <span class="unit" style="visibility:hidden">×</span>
            <input type="number" class="price-input" value="10" min="0" step="0.01" onchange="updateTotal()">
            <span class="unit">€</span>
          </div>
          <div class="extra-row" data-preset>
            <input type="checkbox" class="extra-check" onchange="updateTotal()">
            <span class="extra-label">Grill Nutzung</span>
            <input type="number" class="qty-input" value="1" min="1" onchange="updateTotal()" style="visibility:hidden">
            <span class="unit" style="visibility:hidden">×</span>
            <input type="number" class="price-input" value="5" min="0" step="0.01" onchange="updateTotal()">
            <span class="unit">€</span>
          </div>
          <div class="extra-row" data-preset>
            <input type="checkbox" class="extra-check" onchange="updateTotal()">
            <span class="extra-label">Musikbox</span>
            <input type="number" class="qty-input" value="1" min="1" onchange="updateTotal()" style="visibility:hidden">
            <span class="unit" style="visibility:hidden">×</span>
            <input type="number" class="price-input" value="10" min="0" step="0.01" onchange="updateTotal()">
            <span class="unit">€</span>
          </div>
          <div class="extra-row" data-preset>
            <input type="checkbox" class="extra-check" onchange="updateTotal()">
            <span class="extra-label">Eiswürfel-Maschine</span>
            <input type="number" class="qty-input" value="1" min="1" onchange="updateTotal()" style="visibility:hidden">
            <span class="unit" style="visibility:hidden">×</span>
            <input type="number" class="price-input" value="10" min="0" step="0.01" onchange="updateTotal()">
            <span class="unit">€</span>
          </div>
        </div>
        <p style="margin:6px 2px 0;font-size:0.78rem;color:#8a9a8a;line-height:1.5">
          💡 Hinweis: <strong>Endreinigung</strong> wird automatisch unten zur Preisübersicht addiert — nicht als Extra anklicken.
          Externer Tarif: <?= number_format($_endreinigungCfg, 2, ',', '.') ?> € · Mitglied:
          <?= $_endreinigungMemberCfg > 0 ? number_format($_endreinigungMemberCfg, 2, ',', '.') . ' €' : '<strong style="color:#2e7d32">frei</strong>' ?>
        </p>
        <button class="add-extra-btn" onclick="addCustomExtra(); return false;">＋ Eigene Position hinzufügen</button>
      </div>

      <!-- Preisübersicht -->
      <div class="modal-section">
        <label>Preisübersicht</label>
        <div class="price-summary">
          <div class="price-row"><span id="raummiete-label">Raummiete</span><span id="raummiete-amount"><?= number_format($_mieteCfg, 2, ',', '.') ?> €</span></div>
          <div id="extras-price-rows"></div>
          <div class="price-row total"><span>Raummiete gesamt</span><span id="total-amount"><?= number_format($_mieteCfg, 2, ',', '.') ?> €</span></div>
          <div class="price-row endrein" style="color:#5a6c5a"><span id="endrein-label">+ Endreinigung <small style="color:#8a9a8a">(verpflichtend, besenrein übergeben)</small></span><span id="endrein-amount"><?= number_format($_endreinigungCfg, 2, ',', '.') ?> €</span></div>
          <div class="price-row kaution"><span>+ Kaution (wird zurückerstattet)</span><span id="kaution-amount"><?= number_format($_kautionCfg, 2, ',', '.') ?> €</span></div>
          <div class="price-row grand"><span>Zu überweisen</span><span id="grand-total"><?= number_format($_mieteCfg + $_endreinigungCfg + $_kautionCfg, 2, ',', '.') ?> €</span></div>
        </div>
      </div>

      <!-- Interne Notiz -->
      <div class="modal-section">
        <label>Interne Notiz <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#8a9a8a">(nur im Backoffice sichtbar)</span></label>
        <textarea class="modal-textarea" id="admin-note" placeholder="z.B. Parkplatz reserviert, Schlüssel übergeben am..."></textarea>
      </div>

      <!-- Nachricht an Kunden -->
      <div class="modal-section">
        <label>Persönliche Nachricht an Buchenden <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#8a9a8a">(optional, erscheint in der E-Mail)</span></label>
        <textarea class="modal-textarea" id="admin-message" placeholder="z.B. Herzlichen Glückwunsch zum Jubiläum! Wir freuen uns auf Sie."></textarea>
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn-modal-cancel" onclick="closeModal()">Abbrechen</button>
      <button class="btn-modal-confirm" onclick="submitConfirm()">✓ Jetzt bestätigen & E-Mail senden</button>
    </div>
  </div>
</div>

<!-- Hidden form for confirm submit -->
<form id="confirm-form" method="POST" action="/intern/action.php" style="display:none">
  <input type="hidden" name="csrf"        value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="booking_id"  id="cf-booking-id">
  <input type="hidden" name="action"      value="confirm">
  <input type="hidden" name="extras"      id="cf-extras">
  <input type="hidden" name="admin_note"  id="cf-note">
  <input type="hidden" name="admin_message"   id="cf-message">
  <input type="hidden" name="is_member_tarif" id="cf-member-tarif" value="0">
</form>

<script>
function toggleNoteEdit(id) {
    const display = document.getElementById('note-display-' + id);
    const edit    = document.getElementById('note-edit-' + id);
    const open    = edit.style.display === 'none';
    edit.style.display    = open ? 'block' : 'none';
    if (display) display.style.display = open ? 'none' : (document.getElementById('note-text-' + id)?.textContent.trim() ? 'block' : 'none');
}
function toggleUnpaid(id) {
    const box = document.getElementById('unpaid_' + id);
    const cb  = document.getElementById('unpaid_cb_' + id);
    const open = box.style.display === 'none';
    box.style.display = open ? 'block' : 'none';
    if (!open && cb) cb.checked = false;
    if (!open) toggleUnpaidBtn(id);
}
function toggleUnpaidBtn(id) {
    const cb  = document.getElementById('unpaid_cb_' + id);
    const btn = document.getElementById('unpaid_btn_' + id);
    if (btn) { btn.disabled = !cb.checked; btn.style.opacity = cb.checked ? '1' : '0.4'; }
}
function toggleCard(id) {
    const body = document.getElementById('body_' + id);
    const chev = document.getElementById('chev_' + id);
    const open = body.classList.toggle('open');
    chev.textContent = open ? '▲' : '▼';
}
document.querySelectorAll('.booking-card.pending .card-head').forEach(h => h.click());

let currentBookingId = '';

function openConfirmModal(booking) {
    currentBookingId = booking.id;
    document.getElementById('modal-booking-info').innerHTML =
        '<strong>' + escHtml(booking.name) + '</strong> · ' + escHtml(booking.dates) +
        (booking.purpose ? ' · ' + escHtml(booking.purpose) : '') +
        (booking.guests ? ' · ' + booking.guests + ' Personen' : '');
    // Tarif vorauswählen: Mitglied → member, sonst standard
    const tarifVal = booking.isMember ? 'member' : 'standard';
    document.querySelector('input[name="tarif"][value="' + tarifVal + '"]').checked = true;
    // Reset extras
    document.querySelectorAll('#extras-list .extra-check').forEach(cb => { cb.checked = false; });
    document.getElementById('admin-note').value = '';
    document.getElementById('admin-message').value = '';
    // Remove custom rows
    document.querySelectorAll('#extras-list .extra-row:not([data-preset])').forEach(r => r.remove());
    updateTotal();
    document.getElementById('confirmModal').classList.add('open');
}

function closeModal() {
    document.getElementById('confirmModal').classList.remove('open');
}

// ── Kaution-Abrechnung Modal ──────────────────────────────────────────────
const _stromKwh   = <?= json_encode($_stromKwhCfg) ?>;
const _kautionBtg = <?= json_encode($_kautionCfg) ?>;
const _kautionRows = <?= json_encode(
    array_column(
        array_filter($finance['paid_rows'], fn($r) => $r['kaution_returned_at'] === ''),
        null, 'id'
    ),
    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE
) ?>;
let _kautionDamageIdx = 0;

function openKautionModal(bookingId) {
    const booking = _kautionRows[bookingId];
    if (!booking) return;
    document.getElementById('km-booking-id').value  = booking.id;
    document.getElementById('km-booking-info').innerHTML =
        '<strong>' + _esc(booking.name) + '</strong>'
        + (booking.dates ? ' &nbsp;&middot;&nbsp; ' + _esc(booking.dates) : '');
    document.getElementById('km-kwh').value = '';
    document.getElementById('km-strom-preis').textContent =
        '× ' + _stromKwh.toFixed(2).replace('.', ',') + ' €/kWh (aus Preiseinstellungen)';
    document.getElementById('km-damages-list').innerHTML = '';
    _kautionDamageIdx = 0;
    document.getElementById('km-note').value =
        'Wir hoffen, Sie hatten einen schönen Abend in unserem Vereinshaus und freuen uns, Sie bald wieder begrüßen zu dürfen!';
    _calcKaution();
    document.getElementById('kautionModal').classList.add('open');
}
function closeKautionModal() {
    document.getElementById('kautionModal').classList.remove('open');
}
function addKautionDamage() {
    const i = _kautionDamageIdx++;
    const row = document.createElement('div');
    row.id = 'kdmg_' + i;
    row.style.cssText = 'display:grid;grid-template-columns:1fr 120px auto;gap:8px;align-items:center;margin-bottom:6px';
    row.innerHTML =
        '<input type="text" name="damage_desc[]" placeholder="Beschreibung (z.B. Stuhl beschädigt)" required'
        + ' style="padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.87rem;font-family:inherit;box-sizing:border-box"'
        + ' oninput="_calcKaution()">'
        + '<div style="position:relative;display:flex;align-items:center">'
        + '<input type="number" name="damage_amount[]" placeholder="0,00" min="0" step="0.01"'
        + ' style="width:100%;padding:8px 28px 8px 8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.87rem;font-family:inherit;box-sizing:border-box"'
        + ' oninput="_calcKaution()">'
        + '<span style="position:absolute;right:8px;font-size:0.82rem;color:#5a6c5a">€</span>'
        + '</div>'
        + '<button type="button" onclick="document.getElementById(\'kdmg_' + i + '\').remove();_calcKaution()"'
        + ' style="background:#fce8e6;border:none;color:#c62828;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:1rem">✕</button>';
    document.getElementById('km-damages-list').appendChild(row);
}
function _calcKaution() {
    const kwh  = parseFloat(document.getElementById('km-kwh').value) || 0;
    const elec = Math.round(kwh * _stromKwh * 100) / 100;
    document.getElementById('km-elec-row').style.display = kwh > 0 ? '' : 'none';
    document.getElementById('km-elec-amount').textContent = '−' + elec.toFixed(2).replace('.', ',') + ' €';
    document.getElementById('km-elec-detail').textContent =
        '(' + kwh.toFixed(2).replace('.', ',') + ' kWh × ' + _stromKwh.toFixed(2).replace('.', ',') + ' €)';

    let dmgTotal = 0;
    document.querySelectorAll('#km-damages-list input[name="damage_amount[]"]').forEach(function(inp) {
        dmgTotal += parseFloat(inp.value) || 0;
    });
    dmgTotal = Math.round(dmgTotal * 100) / 100;
    document.getElementById('km-dmg-row').style.display = dmgTotal > 0 ? '' : 'none';
    document.getElementById('km-dmg-amount').textContent = '−' + dmgTotal.toFixed(2).replace('.', ',') + ' €';

    const total     = Math.round((elec + dmgTotal) * 100) / 100;
    const returned  = Math.max(0, Math.round((_kautionBtg - total) * 100) / 100);
    document.getElementById('km-deductions').textContent = total > 0 ? '−' + total.toFixed(2).replace('.', ',') + ' €' : '–';
    document.getElementById('km-returned').textContent   = returned.toFixed(2).replace('.', ',') + ' €';
    document.getElementById('km-returned').style.color   = returned === _kautionBtg ? '#2e7d32' : (returned > 0 ? '#1565c0' : '#c62828');
}
function _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function addCustomExtra() {
    const row = document.createElement('div');
    row.className = 'extra-row';
    row.innerHTML = `
        <input type="checkbox" class="extra-check" checked onchange="updateTotal()">
        <input type="text" placeholder="Bezeichnung" style="flex:1;padding:6px;border:1px solid #d4e6c3;border-radius:6px;font-size:0.85rem;min-width:120px" oninput="updateTotal()">
        <span class="unit">×</span>
        <input type="number" class="qty-input" value="1" min="1" onchange="updateTotal()">
        <span class="unit">×</span>
        <input type="number" class="price-input" value="0" min="0" step="0.01" onchange="updateTotal()">
        <span class="unit">€</span>
        <button onclick="this.closest('.extra-row').remove();updateTotal();" style="background:none;border:none;color:#c62828;cursor:pointer;font-size:1rem;padding:0 4px;">✕</button>`;
    document.getElementById('extras-list').appendChild(row);
    updateTotal();
}

function getExtras() {
    const extras = [];
    // Endreinigung wird automatisch addiert (nicht als Extra)
    // Leinwand & Beamer entfernt (Andreas-Wunsch 06/2026)
    const presetLabels = ['Bierzelt-Garnitur (1 Tisch, 2 Bänke)','Fritteusennutzung','Grill Nutzung','Musikbox','Eiswürfel-Maschine'];
    let pi = 0;
    document.querySelectorAll('#extras-list .extra-row').forEach(row => {
        const cb = row.querySelector('.extra-check');
        if (!cb || !cb.checked) { if (row.dataset.preset !== undefined) pi++; return; }
        let label, qty, price;
        if (row.dataset.preset !== undefined) {
            label = presetLabels[pi++];
            qty   = parseInt(row.querySelector('.qty-input')?.value || '1');
            price = parseFloat(row.querySelector('.price-input')?.value || '0');
        } else {
            label = row.querySelector('input[type=text]')?.value?.trim() || 'Extra';
            qty   = parseInt(row.querySelector('.qty-input')?.value || '1');
            price = parseFloat(row.querySelector('.price-input')?.value || '0');
        }
        if (price > 0 || label) extras.push({label, qty, price});
    });
    return extras;
}

// Preis-Konstanten aus content.json (vom PHP injiziert — nicht mehr hardcoded)
const _MIETE_BASE      = <?= json_encode($_mieteCfg) ?>;
const _MIETE_MEMBER    = <?= json_encode($_mieteMemberCfg) ?>;
const _ENDREIN_EXT     = <?= json_encode($_endreinigungCfg) ?>;
const _ENDREIN_MEMBER  = <?= json_encode($_endreinigungMemberCfg) ?>;
const _KAUTION         = <?= json_encode($_kautionCfg) ?>;

function updateTotal() {
    const isMember  = document.querySelector('input[name="tarif"]:checked')?.value === 'member';
    const raummiete = isMember ? _MIETE_MEMBER    : _MIETE_BASE;
    const endrein   = isMember ? _ENDREIN_MEMBER  : _ENDREIN_EXT;

    // Tarif-Styling
    document.getElementById('tarif-std-label').style.borderColor    = isMember ? '#d4e6c3' : '#3d6b41';
    document.getElementById('tarif-std-label').style.background     = isMember ? '#fff' : '#f0f7f0';
    document.getElementById('tarif-member-label').style.borderColor = isMember ? '#3d6b41' : '#d4e6c3';
    document.getElementById('tarif-member-label').style.background  = isMember ? '#f0f7f0' : '#fff';
    document.getElementById('member-tarif-hint').style.display      = isMember ? '' : 'none';
    document.getElementById('raummiete-label').textContent  = isMember ? 'Raummiete (Mitgliedstarif ✓)' : 'Raummiete';
    document.getElementById('raummiete-amount').textContent = fmtEur(raummiete);

    const extras = getExtras();
    let extrasTotal = raummiete;
    let rows = '';
    extras.forEach(e => {
        const sub = e.price * e.qty;
        extrasTotal += sub;
        rows += `<div class="price-row"><span>${escHtml(e.label)}${e.qty > 1 ? ' × ' + e.qty : ''}</span><span>${fmtEur(sub)}</span></div>`;
    });
    document.getElementById('extras-price-rows').innerHTML = rows;
    document.getElementById('total-amount').textContent = fmtEur(extrasTotal);

    // Endreinigung-Zeile: Label + Betrag dynamisch
    const endreinEl    = document.getElementById('endrein-amount');
    const endreinLabel = document.getElementById('endrein-label');
    if (endrein > 0) {
        if (endreinLabel) endreinLabel.innerHTML = '+ Endreinigung <small style="color:#8a9a8a">(verpflichtend, besenrein übergeben)</small>';
        if (endreinEl)    endreinEl.textContent  = fmtEur(endrein);
    } else {
        if (endreinLabel) endreinLabel.innerHTML = '+ Endreinigung <small style="color:#2e7d32;font-weight:700">(Mitglied — frei!)</small>';
        if (endreinEl)    endreinEl.textContent  = fmtEur(0);
    }

    const kautEl = document.getElementById('kaution-amount');
    if (kautEl) kautEl.textContent = fmtEur(_KAUTION);
    document.getElementById('grand-total').textContent  = fmtEur(extrasTotal + endrein + _KAUTION);
}

function submitConfirm() {
    const extras    = getExtras();
    const isMember  = document.querySelector('input[name="tarif"]:checked')?.value === 'member';
    document.getElementById('cf-booking-id').value    = currentBookingId;
    document.getElementById('cf-extras').value        = JSON.stringify(extras);
    document.getElementById('cf-note').value          = document.getElementById('admin-note').value;
    document.getElementById('cf-message').value       = document.getElementById('admin-message').value;
    document.getElementById('cf-member-tarif').value  = isMember ? '1' : '0';
    document.getElementById('confirm-form').submit();
}

function fmtEur(n) {
    return n.toFixed(2).replace('.', ',') + ' €';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Close on backdrop click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ── Pagination ───────────────────────────────────────────────────────────
// Members table pagination (deactivated when search is active)
let membersPager = null;
function initMembersPagination() {
    const rows = Array.from(document.querySelectorAll('#membersTable tbody .mem-row'));
    const pagEl = document.getElementById('membersPagination');
    if (!pagEl || rows.length <= 20) { if (pagEl) pagEl.innerHTML = ''; return; }
    let renderFn;
    const pages = Math.max(1, Math.ceil(rows.length / 20));
    function render(page) {
        rows.forEach((el, i) => { el.style.display = (i >= (page-1)*20 && i < page*20) ? '' : 'none'; });
        pagEl.innerHTML = '';
        if (pages <= 1) return;
        const mkBtn = (label, p, active, disabled) => {
            const b = document.createElement('button');
            b.textContent = label;
            b.style.cssText = `padding:5px 12px;border-radius:6px;border:1px solid ${active?'#3d6b41':'#d4e6c3'};background:${active?'#3d6b41':'#fff'};color:${active?'#fff':'#3d6b41'};cursor:${disabled?'default':'pointer'};font-size:0.82rem;font-family:inherit;`;
            if (!disabled) b.onclick = () => render(p);
            pagEl.appendChild(b);
        };
        mkBtn('«',1,false,page===1); mkBtn('‹',page-1,false,page===1);
        for (let i=1;i<=pages;i++) { if(pages>7&&i>2&&i<pages-1&&Math.abs(i-page)>1){if(i===3||i===pages-2){const s=document.createElement('span');s.textContent='…';s.style.padding='5px 4px';pagEl.appendChild(s);}continue;} mkBtn(i,i,i===page,false); }
        mkBtn('›',page+1,false,page===pages); mkBtn('»',pages,false,page===pages);
    }
    membersPager = render;
    render(1);
}

// ── Members search + pagination integration ──────────────────────────────
function filterMembers() {
    const q = (document.getElementById('memberSearchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#membersTable .mem-row');
    let visible = 0;
    rows.forEach(row => {
        const match = !q || (row.dataset.search || '').includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const noRes = document.getElementById('memberNoResults');
    if (noRes) noRes.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
    // When searching: show all results, hide paginator; when cleared: restore pagination
    const pagEl = document.getElementById('membersPagination');
    if (q) {
        if (pagEl) pagEl.innerHTML = '';
    } else {
        initMembersPagination();
    }
}

// Messages pagination
function initMsgPagination() {
    const items = Array.from(document.querySelectorAll('#msgList > div'));
    const pagEl = document.getElementById('msgPagination');
    if (!pagEl || items.length <= 10) { if (pagEl) pagEl.innerHTML = ''; return; }
    const perPage = 10;
    const pages = Math.max(1, Math.ceil(items.length / perPage));
    function render(page) {
        items.forEach((el, i) => { el.style.display = (i >= (page-1)*perPage && i < page*perPage) ? '' : 'none'; });
        pagEl.innerHTML = '';
        if (pages <= 1) return;
        const mkBtn = (label, p, active, disabled) => {
            const b = document.createElement('button');
            b.textContent = label;
            b.style.cssText = `padding:5px 12px;border-radius:6px;border:1px solid ${active?'#3d6b41':'#d4e6c3'};background:${active?'#3d6b41':'#fff'};color:${active?'#fff':'#3d6b41'};cursor:${disabled?'default':'pointer'};font-size:0.82rem;font-family:inherit;`;
            if (!disabled) b.onclick = () => render(p);
            pagEl.appendChild(b);
        };
        mkBtn('«',1,false,page===1); mkBtn('‹',page-1,false,page===1);
        for (let i=1;i<=pages;i++) { if(pages>7&&i>2&&i<pages-1&&Math.abs(i-page)>1){if(i===3||i===pages-2){const s=document.createElement('span');s.textContent='…';s.style.padding='5px 4px';pagEl.appendChild(s);}continue;} mkBtn(i,i,i===page,false); }
        mkBtn('›',page+1,false,page===pages); mkBtn('»',pages,false,page===pages);
    }
    render(1);
}

document.addEventListener('DOMContentLoaded', () => {
    initMembersPagination();
    initMsgPagination();
});

// ── Print phone list ─────────────────────────────────────────────────────
const _allMembersData = <?= json_encode(array_map(fn($m) => [
    'parzelle' => $m['parzelle'] ?? '',
    'name'     => $m['name']     ?? '',
    'phone'    => $m['phone']    ?? '',
    'email'    => $m['email']    ?? '',
    'active'   => !empty($m['active']),
    'phonelist'=> !empty($m['consents']['in_phonelist']),
], $allMembers), JSON_UNESCAPED_UNICODE) ?>;

function printPhonelist() {
    const rows = _allMembersData
        .filter(m => m.active)
        .sort((a,b) => a.parzelle.localeCompare(b.parzelle, 'de', {numeric:true}));
    const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let html = `<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Mitglieder Telefonliste</title>
<style>
body{font-family:Arial,sans-serif;font-size:11pt;margin:20mm}
h1{font-size:14pt;margin-bottom:4px}
.sub{font-size:9pt;color:#555;margin-bottom:16px}
table{width:100%;border-collapse:collapse}
th{background:#3d6b41;color:#fff;padding:6px 10px;text-align:left;font-size:10pt}
td{padding:5px 10px;border-bottom:1px solid #ddd;font-size:10pt}
tr:nth-child(even) td{background:#f5f7f2}
.no-consent{color:#888;font-style:italic}
@media print{@page{margin:15mm}}
</style></head><body>
<h1>Mitgliederliste – KGV Musterstadt e.V.</h1>
<div class="sub">Stand: ${new Date().toLocaleDateString('de-DE')} · Intern – nicht für Dritte bestimmt</div>
<table>
<thead><tr><th>Parzelle</th><th>Name</th><th>Telefon</th><th>E-Mail</th><th>Telef.liste</th></tr></thead>
<tbody>`;
    rows.forEach(m => {
        const pl = m.phonelist ? '✅' : '<span class="no-consent">Nein</span>';
        html += `<tr><td>${esc(m.parzelle)}</td><td>${esc(m.name)}</td><td>${esc(m.phone||'–')}</td><td>${esc(m.email)}</td><td>${pl}</td></tr>`;
    });
    html += '</tbody></table></body></html>';
    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 300);
}

// ── Manuelle Buchung: Mitglied auswählen ─────────────────────────────────
const _cbMembers = <?= json_encode(array_values(array_map(fn($m) => [
    'name'  => $m['name']  ?? '',
    'email' => $m['email'] ?? '',
], array_filter($allMembers, fn($m) => !empty($m['active'])))), JSON_UNESCAPED_UNICODE) ?>;

function cbFillMember(val) {
    const found = _cbMembers.find(m => m.name === val);
    const tariffBox = document.getElementById('cb_tariff_box');
    if (found) {
        document.getElementById('cb_name').value  = found.name;
        document.getElementById('cb_email').value = found.email;
        document.getElementById('cb_is_member').value = '1';
        if (tariffBox) tariffBox.style.display = 'block';
    } else {
        document.getElementById('cb_is_member').value = '0';
        if (tariffBox) tariffBox.style.display = 'none';
    }
}

// ── Main tab switching ────────────────────────────────────────────────────
function switchMainTab(name) {
    document.querySelectorAll('.mtab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.main-tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('mtab-' + name);
    const btn   = document.getElementById('mtbtn-' + name);
    if (panel) panel.classList.add('active');
    if (btn)   btn.classList.add('active');
    history.replaceState(null, '', '?tab=' + name);
    if (name === 'calendar') renderCalendar();
}

// Init tab from URL
(function() {
    const t = new URLSearchParams(location.search).get('tab') || 'dashboard';
    switchMainTab(t);
})();

// ── Termine Editor ───────────────────────────────────────────────────────
let _gaTerminIdx = <?= count($_gaTermineAdmin) ?>;
function addGaTermin() {
    const list = document.getElementById('ga-termine-list');
    const empty = document.getElementById('ga-termine-empty');
    if (empty) empty.remove();
    const i = _gaTerminIdx++;
    const row = document.createElement('div');
    row.className = 'ga-row';
    row.id = 'gar_' + i;
    row.style.cssText = 'background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:14px;display:grid;grid-template-columns:150px 1fr 1fr auto;gap:10px;align-items:start';
    row.innerHTML = `
      <input type="hidden" name="ga_id[]" value="">
      <div>
        <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Datum</label>
        <input type="date" name="ga_date[]" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit">
      </div>
      <div>
        <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Titel</label>
        <input type="text" name="ga_title[]" placeholder="z.B. Frühjahrspflege" maxlength="80" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Text</label>
        <textarea name="ga_body[]" rows="3" placeholder="Sa, 11.04. · Ungerade Parzellen&#10;So, 12.04. · Gerade Parzellen&#10;10:00 – 13:00 Uhr" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit;resize:vertical;box-sizing:border-box"></textarea>
      </div>
      <div style="padding-top:22px">
        <button type="button" onclick="delGaTermin(${i})" title="Löschen" style="background:#fce8e6;border:none;color:#c62828;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center">✕</button>
        <input type="hidden" name="ga_delete[]" value="" id="gardel_${i}" disabled>
      </div>`;
    list.appendChild(row);
}
function delGaTermin(i) {
    const row = document.getElementById('gar_' + i);
    const del = document.getElementById('gardel_' + i);
    if (row) row.remove();
    if (del) { del.value = String(i); del.disabled = false; document.getElementById('ga-termine-form').appendChild(del); }
}

let _terminIdx = <?= count($_termineAdmin) ?>;
function addTermin() {
    const list = document.getElementById('termine-list');
    const empty = document.getElementById('termine-empty');
    if (empty) empty.remove();
    const i = _terminIdx++;
    const row = document.createElement('div');
    row.className = 'termin-row';
    row.id = 'ter_' + i;
    row.style.cssText = 'display:grid;grid-template-columns:150px 1fr auto;gap:8px;align-items:start;background:#f9fbf7;border:1px solid #e0ead6;border-radius:10px;padding:12px';
    row.innerHTML = `
      <div>
        <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Datum</label>
        <input type="date" name="te_date[]" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit">
      </div>
      <div>
        <label style="font-size:0.72rem;font-weight:700;color:#5a6c5a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Titel / Beschreibung</label>
        <input type="text" name="te_title[]" placeholder="z.B. Sprechzeit Vorstand · 18:00–18:30 Uhr · Vereinshaus" maxlength="120" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.88rem;font-family:inherit;box-sizing:border-box">
        <input type="text" name="te_desc[]" placeholder="Zusatzinfo (optional)" maxlength="120" style="width:100%;padding:8px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;margin-top:5px;color:#5a6c5a">
      </div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding-top:22px">
        <button type="button" onclick="delTermin(${i})" title="Löschen" style="background:#fce8e6;border:none;color:#c62828;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center">✕</button>
        <input type="hidden" name="te_delete[]" value="" id="terdel_${i}" disabled>
      </div>`;
    list.appendChild(row);
}
function delTermin(i) {
    const row = document.getElementById('ter_' + i);
    const del = document.getElementById('terdel_' + i);
    if (row) row.remove();
    // Mark for server-side deletion via hidden field if it was an existing entry
    if (del) { del.value = String(i); del.disabled = false; document.getElementById('termine-form').appendChild(del); }
}

// ── Contact card toggle ───────────────────────────────────────────────────
function toggleContact(id) {
    const body = document.getElementById('cb_' + id);
    if (body) body.classList.toggle('open');
}

// ── Calendar ─────────────────────────────────────────────────────────────
const calBlockedDatesFull = <?= json_encode($_blockedDatesFull,   JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
const calBlockedRanges    = <?= json_encode($_blockedRangesAdmin, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
const bookingsData = <?= json_encode(array_map(fn($b) => [
    'id'     => $b['id'] ?? '',
    'name'   => $b['name'] ?? '',
    'dates'  => $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []),
    'status' => $b['status'] ?? 'pending',
], $bookings), JSON_UNESCAPED_UNICODE) ?>;

let calYear, calMonth;
(function() {
    const now = new Date();
    calYear  = now.getFullYear();
    calMonth = now.getMonth();
})();

const monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

function formatBlockReason(reason) {
    if (!reason) return 'Gesperrt';
    // "Vorbereitung/Übergabe: Bastian (04.06.2026) [id]" → "Übergabe Bastian"
    // "Aufräumen/Übergabe: Bastian (04.06.2026) [id]" → "Aufräumen Bastian"
    const m = reason.match(/^(Vorbereitung|Aufräumen)\/Übergabe:\s*(.+?)\s*\(/);
    if (m) return (m[1] === 'Vorbereitung' ? 'Übergabe' : 'Aufräumen') + ' ' + m[2];
    // Buchungs-ID-Suffix `[…]` generell wegkürzen
    return reason.replace(/\s*\[[^\]]+\]\s*$/, '');
}
function getBlockInfo(ds) {
    for (const b of calBlockedDatesFull) {
        if (b.date === ds) return { type: 'single', label: formatBlockReason(b.reason) };
    }
    const d = new Date(ds + 'T00:00:00');
    for (const r of calBlockedRanges) {
        if (r.from && r.to && d >= new Date(r.from+'T00:00:00') && d <= new Date(r.to+'T00:00:00'))
            return { type: 'range', label: r.reason || 'Gesperrt' };
    }
    return null;
}

function calPrev() { calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; } renderCalendar(); }
function calNext() { calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; } renderCalendar(); }

function renderCalendar() {
    document.getElementById('cal-month-label').textContent = monthNames[calMonth] + ' ' + calYear;
    const first = new Date(calYear, calMonth, 1);
    let dow = first.getDay(); // 0=Sun
    dow = dow === 0 ? 6 : dow - 1; // Monday=0

    const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
    const daysInPrev  = new Date(calYear, calMonth, 0).getDate();
    const today = new Date();

    // Build date→bookings map
    const dateMap = {};
    bookingsData.filter(b => b.status !== 'rejected' && b.status !== 'expired').forEach(b => {
        b.dates.forEach(d => {
            if (!dateMap[d]) dateMap[d] = [];
            dateMap[d].push(b);
        });
    });

    let html = '';
    // Prev month days
    for (let i = dow - 1; i >= 0; i--) {
        const day = daysInPrev - i;
        html += `<div class="cal-day other-month"><div class="day-num">${day}</div></div>`;
    }
    // Current month
    for (let d = 1; d <= daysInMonth; d++) {
        const ym = calYear + '-' + String(calMonth + 1).padStart(2, '0');
        const dateStr = ym + '-' + String(d).padStart(2, '0');
        const isToday = d === today.getDate() && calMonth === today.getMonth() && calYear === today.getFullYear();
        const booksForDay = dateMap[dateStr] || [];
        const blockInfo = getBlockInfo(dateStr);
        let classes = 'cal-day';
        if (isToday) classes += ' today';
        if (blockInfo) classes += blockInfo.type === 'range' ? ' blocked-range' : ' blocked';
        let inner = `<div class="day-num">${d}</div>`;
        if (blockInfo) {
            const cls = blockInfo.type === 'range' ? 'cal-block-range' : 'cal-block-label';
            inner += `<div class="${cls}">🚫 ${escHtml(blockInfo.label)}</div>`;
        }
        booksForDay.forEach(b => {
            const name = escHtml(b.name.split(' ')[0]);
            inner += `<div class="cal-booking-dot ${b.status}" onclick="scrollToBooking('${escHtml(b.id)}')" title="${escHtml(b.name)}">${name}</div>`;
        });
        html += `<div class="${classes}">${inner}</div>`;
    }
    // Fill remaining cells
    const totalCells = Math.ceil((dow + daysInMonth) / 7) * 7;
    let nextDay = 1;
    for (let i = dow + daysInMonth; i < totalCells; i++, nextDay++) {
        html += `<div class="cal-day other-month"><div class="day-num">${nextDay}</div></div>`;
    }

    document.getElementById('cal-body').innerHTML = html;
}

function scrollToBooking(id) {
    switchMainTab('bookings');
    setTimeout(() => {
        const el = document.getElementById('body_' + id);
        if (el) {
            if (!el.classList.contains('open')) document.getElementById('body_' + id).closest('.booking-card').querySelector('.card-head').click();
            el.closest('.booking-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 100);
}

// ── Role form toggle ─────────────────────────────────────────────────────
function toggleRoleForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
// ── Buchung-Modal Kalender ────────────────────────────────────────────────
(function() {
const MONTH_NAMES = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
const blockedDates  = <?= json_encode($_blockedDatesAdmin,  JSON_UNESCAPED_UNICODE) ?>;
const blockedRanges = <?= json_encode($_blockedRangesAdmin, JSON_UNESCAPED_UNICODE) ?>;
let cbCurrent = new Date();
let cbSelected = new Set(); // selected YYYY-MM-DD strings

function isBlocked(ds) {
    if (blockedDates.includes(ds)) return true;
    const d = new Date(ds + 'T00:00:00');
    for (const r of blockedRanges) {
        if (r.from && r.to && d >= new Date(r.from+'T00:00:00') && d <= new Date(r.to+'T00:00:00')) return true;
    }
    return false;
}

function cbRender() {
    const year = cbCurrent.getFullYear(), month = cbCurrent.getMonth();
    document.getElementById('cbCalMonth').textContent = MONTH_NAMES[month] + ' ' + year;
    const grid = document.getElementById('cbCalGrid');
    grid.innerHTML = '';
    const startDay = (new Date(year, month, 1).getDay() || 7) - 1;
    for (let i = 0; i < startDay; i++) grid.appendChild(document.createElement('div'));

    // Get booking data from existing bookingsData variable
    const bookedSet  = new Set(typeof bookingsData !== 'undefined' ? bookingsData.filter(b => b.status === 'confirmed').flatMap(b => b.dates || []) : []);
    const pendingSet = new Set(typeof bookingsData !== 'undefined' ? bookingsData.filter(b => b.status === 'pending').flatMap(b => b.dates || []) : []);

    const days = new Date(year, month + 1, 0).getDate();
    for (let day = 1; day <= days; day++) {
        const ds = year + '-' + String(month+1).padStart(2,'0') + '-' + String(day).padStart(2,'0');
        const el = document.createElement('div');
        el.textContent = day;
        el.style.cssText = 'aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:6px;cursor:pointer;font-size:0.78rem;font-weight:500;border:1.5px solid #e0ead6;transition:all .15s;';
        const isSelected = cbSelected.has(ds);
        const isBooked   = bookedSet.has(ds);
        const isPending  = pendingSet.has(ds);
        const isSperr    = isBlocked(ds);
        if (isSelected) {
            el.style.background = '#3d6b41'; el.style.color = '#fff'; el.style.borderColor = '#3d6b41';
        } else if (isBooked) {
            el.style.background = '#ffebee'; el.style.color = '#c62828'; el.style.borderColor = '#ffcdd2';
            el.title = 'Bereits bestätigt (trotzdem wählbar)';
        } else if (isPending) {
            el.style.background = '#fff8e1'; el.style.color = '#e65100'; el.style.borderColor = '#ffe082';
            el.title = 'Offene Anfrage (trotzdem wählbar)';
        } else if (isSperr) {
            el.style.background = '#f5f5f5'; el.style.color = '#bbb'; el.style.textDecoration = 'line-through';
            el.title = 'Sperrtag (trotzdem wählbar)';
        } else {
            el.style.background = '#fff';
        }
        el.onmouseenter = () => { if (!isSelected) el.style.background = isBooked||isPending||isSperr ? el.style.background : '#e8f5e9'; };
        el.onmouseleave = () => { if (!cbSelected.has(ds)) el.style.background = isBooked ? '#ffebee' : isPending ? '#fff8e1' : isSperr ? '#f5f5f5' : '#fff'; };
        el.onclick = () => {
            if (cbSelected.has(ds)) cbSelected.delete(ds); else cbSelected.add(ds);
            cbUpdateInput(); cbRender();
        };
        grid.appendChild(el);
    }
}

function cbUpdateInput() {
    const sorted = [...cbSelected].sort();
    document.getElementById('cbDatesInput').value = sorted.join(',');
    const chips = document.getElementById('cbDatesChips');
    chips.innerHTML = '';
    sorted.forEach(ds => {
        const chip = document.createElement('span');
        const d = new Date(ds+'T00:00:00');
        chip.style.cssText = 'background:#3d6b41;color:#fff;border-radius:20px;padding:3px 10px;font-size:0.75rem;display:inline-flex;align-items:center;gap:5px;cursor:pointer';
        chip.innerHTML = d.toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit',year:'numeric'}) + ' <span style="opacity:.7">✕</span>';
        chip.onclick = () => { cbSelected.delete(ds); cbUpdateInput(); cbRender(); };
        chips.appendChild(chip);
    });
    const hint = document.getElementById('cbDatesHint');
    hint.textContent = sorted.length === 0 ? 'Klicke auf Tage um sie auszuwählen/abzuwählen. Mehrfachauswahl möglich.' : sorted.length + ' Tag' + (sorted.length > 1 ? 'e' : '') + ' ausgewählt';
}

window.cbCalPrev = function() { cbCurrent.setMonth(cbCurrent.getMonth()-1); cbRender(); };
window.cbCalNext = function() { cbCurrent.setMonth(cbCurrent.getMonth()+1); cbRender(); };

// Init on modal open
document.getElementById('createBookingModal').addEventListener('click', function init(e) {
    if (e.target === this) return; // only run once on first open
}, {once:false});

// Init calendar when modal first shown
const _origOpen = document.getElementById('createBookingModal');
const _observer = new MutationObserver(() => {
    if (_origOpen.style.display !== 'none') cbRender();
});
_observer.observe(_origOpen, {attributes:true, attributeFilter:['style']});

// Also init immediately in case already open
cbRender();
})();

function toggleBeitragForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}
function toggleArbeitForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}

// ── E-Mail inline edit (AJAX) ─────────────────────────────────────────────
function showEmailEdit(id, currentEmail) {
    document.getElementById('email_display_' + id).style.display = 'none';
    const edit = document.getElementById('email_edit_' + id);
    edit.style.display = 'inline-flex';
    document.getElementById('email_input_' + id).value = currentEmail;
    document.getElementById('email_input_' + id).focus();
}
function hideEmailEdit(id) {
    document.getElementById('email_display_' + id).style.display = '';
    document.getElementById('email_edit_' + id).style.display = 'none';
}
function saveEmailEdit(bookingId, csrf) {
    const newEmail = document.getElementById('email_input_' + bookingId).value.trim();
    if (!newEmail) return;
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('booking_id', bookingId);
    fd.append('action', 'update_email');
    fd.append('new_email', newEmail);
    fetch('/intern/action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                // Update displayed email in DOM
                const disp = document.getElementById('email_display_' + bookingId);
                const link = disp.querySelector('a');
                if (link) { link.href = 'mailto:' + data.email; link.textContent = data.email; }
                hideEmailEdit(bookingId);
            } else {
                alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
            }
        })
        .catch(() => alert('Verbindungsfehler beim Speichern.'));
}

// ── Posteingang fetch spinner ─────────────────────────────────────────────
document.getElementById('fetchIncomingForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('fetchIncomingBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Wird abgerufen…'; }
});

// ── Admin reply file preview ──────────────────────────────────────────────
function adminFilePreview(input, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    const file = input.files[0];
    if (!file) { preview.innerHTML = ''; return; }
    if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.style.cssText = 'max-width:80px;max-height:70px;border-radius:6px;object-fit:cover;vertical-align:middle';
        img.src = URL.createObjectURL(file);
        preview.innerHTML = '';
        preview.appendChild(img);
    } else {
        preview.textContent = '📎 ' + file.name;
    }
}

// ── Mitglieder Nachrichten-Thread Toggle ─────────────────────────────────
function toggleMsgThread(id) {
    const body = document.getElementById(id);
    const tid  = id.replace('mt-', '');
    const arr  = document.getElementById('mt-arr-' + tid);
    if (!body) return;
    const open = body.style.display === 'none';
    body.style.display = open ? 'block' : 'none';
    if (arr) arr.style.transform = open ? 'rotate(180deg)' : '';
}
</script>
<?php endif; ?>
</body>
</html>
