<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_loader.php';


require_once __DIR__ . '/schriftfuehrung.php';

/* ───────────────────────────────────────────────────────────────────────── */
/*  Action Dispatcher                                                        */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_handle_action(string $action, string $csrf): bool {
    switch ($action) {
        case 'sf_generate_letter':  sf_action_generate_letter();  return true;
        case 'sf_canva_upload':     sf_action_canva_upload();     return true;
        case 'sf_canva_delete':     sf_action_canva_delete();     return true;
        case 'sf_protocol_save':    sf_action_protocol_save();    return true;
        case 'sf_protocol_pdf':     sf_action_protocol_pdf();     return true;
        case 'save_member_since':   sf_action_save_member_since();return true;
        case 'sf_newsletter_send':  sf_action_newsletter();       return true;
        case 'sf_document_upload':  sf_action_document_upload();  return true;
        case 'sf_document_delete':  sf_action_document_delete();  return true;
        case 'sf_note_create':      sf_action_note_create();      return true;
        case 'sf_note_toggle':      sf_action_note_toggle();      return true;
        case 'sf_note_delete':      sf_action_note_delete();      return true;
        case 'sf_print_urkunde':    sf_action_print_urkunde();    return true;
        case 'sf_print_labels':     sf_action_print_labels();     return true;
        case 'sf_print_aushang':    sf_action_print_aushang();    return true;
        case 'sf_application_update': sf_action_application_update(); return true;
        case 'sf_card_print':       sf_action_card_print();       return true;
        case 'sf_config_save':      sf_action_config_save();      return true;
        case 'sf_schaden_update':   sf_action_schaden_update();   return true;
        case 'sf_protocol_invitation': sf_action_protocol_invitation(); return true;
    }
    return false;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Letter generation                                                        */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_generate_letter(): void {
    $tplId    = trim((string)($_POST['tpl_id']    ?? ''));
    $memberId = trim((string)($_POST['member_id'] ?? ''));
    $subject  = trim((string)($_POST['subject']   ?? ''));
    $body     = (string)($_POST['body']           ?? '');
    $jahre    = trim((string)($_POST['extra_jahre'] ?? ''));
    $output   = $_POST['output'] ?? 'pdf';

    if ($tplId === '' || $memberId === '') { http_response_code(400); exit('Vorlage oder Mitglied fehlt'); }

    $member = null;
    foreach (sf_member_list() as $m) if (($m['id'] ?? '') === $memberId) { $member = $m; break; }
    if (!$member) { http_response_code(404); exit('Mitglied nicht gefunden'); }

    $vars = [
        'name'     => $member['name']     ?? '',
        'parzelle' => $member['parzelle'] ?? '',
        'year'     => date('Y'),
        'datum'    => date('d.m.Y'),
        'jahre'    => $jahre !== '' ? $jahre : '',
        'uhrzeit'  => '',
    ];

    $tplOverride = ['id' => $tplId, 'subject' => $subject, 'body' => $body];

    // Settings für Footer
    $contentFile = dirname(__DIR__) . '/data/content.json';
    $content     = sf_load_json($contentFile);
    $settings    = $content['settings'] ?? [];

    if ($output === 'email') {
        $email = trim((string)($member['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400); exit('Mitglied hat keine gültige E-Mail.');
        }
        $finalSubject = sf_apply_letter_variables($subject, $vars);
        $finalText    = sf_apply_letter_variables($body, $vars);
        $finalHtml    = kgv_email_html(
            'Hallo ' . htmlspecialchars($member['name'] ?? '') . ' 👋,',
            '<div style="white-space:pre-wrap;line-height:1.7;color:#5a6c5a">' . nl2br(htmlspecialchars($finalText)) . '</div>',
            'Nachricht vom Vorstand',
            (string)($settings['kontakt_name']  ?? 'Vorstand'),
            (string)($settings['telefon']        ?? ''),
            (string)($settings['email']          ?? 'vorstand@example.org'),
            (string)($settings['kontakt_rolle']  ?? '1. Vorsitzender')
        );

        $boundary = 'b_' . md5(uniqid('', true));
        $mailBody  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$finalText}\r\n\r\n";
        $mailBody .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$finalHtml}\r\n--{$boundary}--";
        $headers   = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers  .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: " . ($settings['email'] ?? 'kontakt@example.org') . "\r\nReturn-Path: kontakt@example.org\r\n";
        $ok = @mail($email, $finalSubject, $mailBody, $headers, "-fkontakt@example.org");

        // Log
        sf_log_letter([
            'id'              => sf_uniq_id('let'),
            'ts'              => date('Y-m-d H:i:s'),
            'template_id'     => $tplId,
            'template_title'  => '',
            'member_id'       => $memberId,
            'member_name'     => $member['name'] ?? '',
            'output'          => 'email',
            'success'         => $ok,
        ]);

        header('Location: /intern/?tab=schriftfuehrung&sub=letters&sent=' . ($ok ? 'ok' : 'fail'));
        exit;
    }

    // PDF generieren
    $pdf = sf_letter_pdf($tplOverride, $member, $vars, $settings);

    sf_log_letter([
        'id'              => sf_uniq_id('let'),
        'ts'              => date('Y-m-d H:i:s'),
        'template_id'     => $tplId,
        'template_title'  => '',
        'member_id'       => $memberId,
        'member_name'     => $member['name'] ?? '',
        'output'          => 'pdf',
        'success'         => true,
    ]);

    $safeName = preg_replace('/[^a-z0-9_-]/i', '_', (string)($member['name'] ?? 'Mitglied'));
    $safeTpl  = preg_replace('/[^a-z0-9_-]/i', '_', $tplId);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="VEREIN_' . $safeTpl . '_' . $safeName . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function sf_log_letter(array $entry): void {
    $log = sf_load_json(SF_LETTERS_LOG);
    $log[] = $entry;
    if (count($log) > 200) $log = array_slice($log, -200);
    sf_save_json(SF_LETTERS_LOG, $log);
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Canva uploads                                                            */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_canva_upload(): void {
    sf_ensure_dirs();
    if (!isset($_FILES['canva_file']) || $_FILES['canva_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /intern/?tab=schriftfuehrung&sub=canva&error=upload'); exit;
    }
    $tmp  = $_FILES['canva_file']['tmp_name'];
    $size = (int)$_FILES['canva_file']['size'];
    if ($size > 10 * 1024 * 1024) { header('Location: /intern/?tab=schriftfuehrung&sub=canva&error=too_large'); exit; }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = (string)finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg','application/pdf'=>'pdf'];
    if (!isset($allowed[$mime])) { header('Location: /intern/?tab=schriftfuehrung&sub=canva&error=mime'); exit; }

    $id    = sf_uniq_id('canva');
    $ext   = $allowed[$mime];
    $fname = $id . '.' . $ext;
    $dest  = SF_UPLOAD_CANVA . '/' . $fname;
    if (!@move_uploaded_file($tmp, $dest)) {
        header('Location: /intern/?tab=schriftfuehrung&sub=canva&error=save'); exit;
    }

    $files   = sf_load_json(SF_CANVA);
    $files[] = [
        'id'          => $id,
        'title'       => mb_substr(trim(strip_tags((string)($_POST['title']    ?? ''))), 0, 80),
        'category'    => trim(strip_tags((string)($_POST['category'] ?? 'sonstige'))),
        'note'        => mb_substr(trim(strip_tags((string)($_POST['note']     ?? ''))), 0, 160),
        'filename'    => $fname,
        'mime'        => $mime,
        'size'        => $size,
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
    sf_save_json(SF_CANVA, $files);

    header('Location: /intern/?tab=schriftfuehrung&sub=canva&uploaded=1');
    exit;
}

function sf_action_canva_delete(): void {
    $id = trim((string)($_POST['canva_id'] ?? ''));
    if ($id === '') { header('Location: /intern/?tab=schriftfuehrung&sub=canva'); exit; }
    $files = sf_load_json(SF_CANVA);
    $kept  = [];
    foreach ($files as $f) {
        if (($f['id'] ?? '') === $id) {
            $p = SF_UPLOAD_CANVA . '/' . ($f['filename'] ?? '');
            if (file_exists($p)) @unlink($p);
            continue;
        }
        $kept[] = $f;
    }
    sf_save_json(SF_CANVA, $kept);
    header('Location: /intern/?tab=schriftfuehrung&sub=canva&deleted=1');
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Protocols save                                                           */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_protocol_save(): void {
    $saveAction = $_POST['save_action'] ?? 'save';
    $pid        = trim((string)($_POST['pid'] ?? ''));

    if ($saveAction === 'delete' && $pid !== '') {
        $list = sf_load_json(SF_PROTOCOLS);
        $list = array_values(array_filter($list, fn($x) => ($x['id'] ?? '') !== $pid));
        sf_save_json(SF_PROTOCOLS, $list);
        header('Location: /intern/?tab=schriftfuehrung&sub=protocols&deleted=1');
        exit;
    }

    // Bestehendes Protokoll für Anhänge/Beschluss-Nrn merken
    $list = sf_load_json(SF_PROTOCOLS);
    $existing = null;
    foreach ($list as $x) if (($x['id'] ?? '') === $pid) { $existing = $x; break; }
    $existingTopsByTitle = [];
    foreach (($existing['tops'] ?? []) as $t) {
        $existingTopsByTitle[trim((string)$t['title'])] = $t;
    }

    $valid_status = array_keys(sf_protocol_statuses());
    $newStatus = $_POST['p_status'] ?? 'draft';
    if (!in_array($newStatus, $valid_status, true)) $newStatus = 'draft';

    $protocol = [
        'id'         => $pid !== '' ? $pid : sf_uniq_id('prot'),
        'title'      => trim((string)($_POST['p_title'] ?? '')),
        'date'       => trim((string)($_POST['p_date'] ?? date('Y-m-d'))),
        'type'       => trim((string)($_POST['p_type'] ?? 'Vorstandssitzung')),
        'status'     => $newStatus,
        'attendees'  => array_values(array_filter((array)($_POST['p_attendees'] ?? []))),
        'tops'       => [],
        'notes'      => trim((string)($_POST['p_notes'] ?? '')),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $labels = (array)($_POST['p_top_label'] ?? []);
    $titles = (array)($_POST['p_top_title'] ?? []);
    $notes  = (array)($_POST['p_top_notes'] ?? []);
    $bes    = (array)($_POST['p_top_beschluss'] ?? []);
    $ja     = (array)($_POST['p_top_ja'] ?? []);
    $nein   = (array)($_POST['p_top_nein'] ?? []);
    $enth   = (array)($_POST['p_top_enth'] ?? []);
    $acts   = (array)($_POST['p_top_action'] ?? []);
    $actDue = (array)($_POST['p_top_action_due'] ?? []);
    $n = max(count($labels), count($titles), count($notes));

    $newActionItems = [];

    for ($i = 0; $i < $n; $i++) {
        $title = trim((string)($titles[$i] ?? ''));
        if ($title === '') continue;
        $beschluss = trim((string)($bes[$i] ?? ''));
        $action    = trim((string)($acts[$i] ?? ''));
        $actDueDate = trim((string)($actDue[$i] ?? ''));

        // 5.4 — Beschluss-Nr beibehalten oder neu vergeben
        $existingBeschlussNr = '';
        if (isset($existingTopsByTitle[$title]['beschluss_nr'])) {
            $existingBeschlussNr = (string)$existingTopsByTitle[$title]['beschluss_nr'];
        }
        $beschlussNr = '';
        if ($beschluss !== '') {
            $beschlussNr = $existingBeschlussNr !== '' ? $existingBeschlussNr : sf_next_beschluss_nr($protocol['date']);
        }

        $top = [
            'top'           => trim((string)($labels[$i] ?? 'TOP ' . ($i+1))),
            'title'         => $title,
            'notes'         => trim((string)($notes[$i] ?? '')),
            'beschluss'     => $beschluss,
            'beschluss_nr'  => $beschlussNr,
            'ja'            => ($ja[$i]   ?? '') !== '' ? (int)$ja[$i]   : null,
            'nein'          => ($nein[$i] ?? '') !== '' ? (int)$nein[$i] : null,
            'enth'          => ($enth[$i] ?? '') !== '' ? (int)$enth[$i] : null,
            'action'        => $action,
            'action_due'    => $actDueDate,
        ];

        // 5.6 — Neue Action-Items erkennen (waren vorher nicht da)
        if ($action !== '') {
            $wasThere = isset($existingTopsByTitle[$title]['action']) && trim((string)$existingTopsByTitle[$title]['action']) === $action;
            if (!$wasThere) {
                $newActionItems[] = [
                    'text'     => '[' . $top['top'] . '] ' . $action,
                    'reminder' => $actDueDate !== '' ? $actDueDate : '',
                ];
            }
        }

        $protocol['tops'][] = $top;
    }

    // 5.7 — Anhang-Upload (1 pro Save)
    $protocol['attachments'] = (array)($existing['attachments'] ?? []);
    if (isset($_FILES['protocol_attachment']) && $_FILES['protocol_attachment']['error'] === UPLOAD_ERR_OK) {
        sf_ensure_dirs();
        $size = (int)$_FILES['protocol_attachment']['size'];
        if ($size <= 10 * 1024 * 1024) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = (string)finfo_file($finfo, $_FILES['protocol_attachment']['tmp_name']);
            finfo_close($finfo);
            $extMap = ['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'];
            if (isset($extMap[$mime])) {
                $attId = sf_uniq_id('att');
                $attFname = $attId . '.' . $extMap[$mime];
                if (@move_uploaded_file($_FILES['protocol_attachment']['tmp_name'], SF_UPLOAD_PROTOCOLS . '/' . $attFname)) {
                    $protocol['attachments'][] = [
                        'id'          => $attId,
                        'title'       => basename((string)$_FILES['protocol_attachment']['name']),
                        'filename'    => $attFname,
                        'mime'        => $mime,
                        'uploaded_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }
        }
    }

    // Existing-Metadaten beibehalten
    if ($existing) {
        $protocol['created_at']         = $existing['created_at']         ?? date('Y-m-d H:i:s');
        $protocol['invitation_sent_at'] = $existing['invitation_sent_at'] ?? null;
        $protocol['invitation_sent_to'] = $existing['invitation_sent_to'] ?? [];
        $protocol['sent_at']            = $existing['sent_at']            ?? null;
        $protocol['sent_to']            = $existing['sent_to']            ?? [];
    } else {
        $protocol['created_at'] = date('Y-m-d H:i:s');
    }

    $found = false;
    foreach ($list as &$x) {
        if (($x['id'] ?? '') === $protocol['id']) {
            $x = array_merge($x, $protocol);
            $found = true;
            break;
        }
    }
    unset($x);
    if (!$found) $list[] = $protocol;
    sf_save_json(SF_PROTOCOLS, $list);

    // Action-Items als Notizen anlegen (5.6)
    if (!empty($newActionItems)) {
        $notesAll = sf_load_json(SF_NOTES);
        foreach ($newActionItems as $ai) {
            $notesAll[] = [
                'id'         => sf_uniq_id('note'),
                'text'       => mb_substr($ai['text'], 0, 300),
                'reminder'   => $ai['reminder'],
                'done'       => false,
                'created_at' => date('Y-m-d H:i:s'),
                'from_protocol' => $protocol['id'],
            ];
        }
        sf_save_json(SF_NOTES, $notesAll);
    }

    // 5.1 — Protokoll an Anwesende mailen
    if ($saveAction === 'mail_protocol') {
        sf_mail_protocol_to_attendees($protocol);
        // Re-Load + Update sent_at
        $list2 = sf_load_json(SF_PROTOCOLS);
        foreach ($list2 as &$x) {
            if (($x['id'] ?? '') === $protocol['id']) {
                $x['sent_at'] = date('Y-m-d H:i:s');
                $x['sent_to'] = $protocol['attendees'];
                break;
            }
        }
        unset($x);
        sf_save_json(SF_PROTOCOLS, $list2);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:30px;text-align:center;background:#e8f5e9'>";
        echo "<h2 style='color:#2e7d32'>✓ Protokoll wurde an Anwesende versendet</h2>";
        echo "<p style='color:#5a6c5a'>" . count($protocol['attendees']) . " Empfänger</p>";
        echo "<p><a href='/intern/?tab=schriftfuehrung&sub=protocols&pid=" . urlencode($protocol['id']) . "' style='background:#5e35b1;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none'>← Zurück zum Protokoll</a></p>";
        echo "</body></html>";
        exit;
    }

    // 5.3 — Einladung versenden (Dialog)
    if ($saveAction === 'send_invitation') {
        // Inline-Form rendern
        header('Content-Type: text/html; charset=UTF-8');
        sf_render_invitation_dialog($protocol);
        exit;
    }

    if ($saveAction === 'save_pdf') {
        header('Location: /intern/action.php?action=sf_protocol_pdf&csrf=' . urlencode((string)($_POST['csrf'] ?? '')) . '&pid=' . urlencode($protocol['id']));
        exit;
    }

    header('Location: /intern/?tab=schriftfuehrung&sub=protocols&pid=' . urlencode($protocol['id']) . '&saved=1');
    exit;
}

// Helper: Protokoll als E-Mail an Anwesende
function sf_mail_protocol_to_attendees(array $protocol): bool {
    $members = sf_member_list();
    $byId = [];
    foreach ($members as $m) $byId[$m['id'] ?? ''] = $m;

    $recipients = [];
    foreach ($protocol['attendees'] ?? [] as $id) {
        $m = $byId[$id] ?? null;
        if ($m && !empty($m['email']) && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $m['email'];
        }
    }
    if (empty($recipients)) return false;

    // PDF generieren
    require_once __DIR__ . '/fpdf.php';
    $_POST['pid'] = $protocol['id']; // für sf_action_protocol_pdf
    ob_start();
    // Wir generieren das PDF direkt inline (gleiche Logik wie sf_action_protocol_pdf, aber ohne Header/Exit)
    $pdfBin = sf_protocol_pdf_binary($protocol);
    ob_end_clean();

    $subject = '[KGV Musterstadt] Protokoll: ' . ($protocol['title'] ?? '');
    $bodyTxt = "Liebe/r Sitzungsteilnehmer/in,\n\nim Anhang findest du das Protokoll der Sitzung vom " . ($protocol['date'] ?? '') . ".\n\nViele Grüße\nDeine Schriftführung";

    $contentArr = sf_load_json(dirname(__DIR__) . '/data/content.json');
    $settings   = $contentArr['settings'] ?? [];

    $bodyHtml = kgv_email_html(
        'Liebe/r Sitzungsteilnehmer/in 👋,',
        '<p>im Anhang findest du das Protokoll der Sitzung vom <strong>' . htmlspecialchars($protocol['date'] ?? '') . '</strong>.</p>'
        . '<p>Bitte schau es dir zeitnah an. Anmerkungen oder Korrekturwünsche bitte bis zur nächsten Sitzung melden.</p>',
        'Protokoll · ' . ($protocol['title'] ?? ''),
        (string)($settings['kontakt_name']  ?? 'Schriftführung'),
        (string)($settings['telefon']        ?? ''),
        (string)($settings['email']          ?? 'kontakt@example.org'),
        'Schriftführerin'
    );

    $bMix = 'm_' . md5(uniqid('', true));
    $bAlt = 'a_' . md5(uniqid('', true));
    $alt  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$bodyTxt}\r\n\r\n";
    $alt .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$bodyHtml}\r\n--{$bAlt}--";

    $b64 = chunk_split(base64_encode($pdfBin));
    $fname = 'VEREIN_Protokoll_' . preg_replace('/[^a-z0-9_-]/i', '_', (string)($protocol['date'] ?? '')) . '.pdf';

    $body  = "--{$bMix}\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n\r\n{$alt}\r\n\r\n";
    $body .= "--{$bMix}\r\nContent-Type: application/pdf; name=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n{$b64}\r\n";
    $body .= "--{$bMix}--";

    $hdr  = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$bMix}\"\r\n";
    $hdr .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: " . ($settings['email'] ?? 'kontakt@example.org') . "\r\nReturn-Path: kontakt@example.org\r\n";
    $hdr .= "Bcc: " . implode(', ', $recipients) . "\r\n";

    return @mail((string)($settings['email'] ?? 'kontakt@example.org'), $subject, $body, $hdr, "-fkontakt@example.org");
}

// PDF-Binary ohne Headers (für Wiederverwendung)
function sf_protocol_pdf_binary(array $p): string {
    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    // Settings für Briefkopf
    $cf = sf_load_json(dirname(__DIR__) . '/data/content.json');
    $settings = $cf['settings'] ?? [];

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 12, 20);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // ── Briefkopf ──────────────────────────────────────────────────────
    sf_briefkopf_top($pdf, $settings, 12);

    // ── Titel: "Protokoll" + Untertitel "[Type] vom [Date]" ─────────────
    try {
        $dDate = (new DateTime($p['date'] ?? 'today'))->format('d. F Y');
        $dDateDe = strtr($dDate, [
            'January'=>'Januar','February'=>'Februar','March'=>'März','April'=>'April',
            'May'=>'Mai','June'=>'Juni','July'=>'Juli','August'=>'August',
            'September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Dezember',
        ]);
    } catch (\Throwable $err) { $dDateDe = $p['date'] ?? ''; }

    $titleLine = ($p['type'] ?? 'Sitzung') . ' vom ' . $dDateDe;
    sf_doc_title($pdf, 'Protokoll', $titleLine);

    // ── Meta-Block (Ort, Datum, Anwesenheit) ───────────────────────────
    $pdf->Ln(4);
    sf_meta_row($pdf, 'Ort',   "Vereinshaus KGV Musterstadt e.V.\nMusterstraße 1/Ecke Beckermannweg\n12345 Musterstadt");
    sf_meta_row($pdf, 'Datum', $dDateDe);
    sf_meta_row($pdf, 'Art',   (string)($p['type'] ?? 'Sitzung'));

    // Anwesenheit
    $members = sf_member_list();
    $byId = [];
    foreach ($members as $m) $byId[$m['id'] ?? ''] = $m['name'] ?? '';
    $names = [];
    foreach (($p['attendees'] ?? []) as $aid) if (!empty($byId[$aid])) $names[] = $byId[$aid];
    $attCount = count($names);
    $attText  = $attCount === 0
        ? '(keine Teilnehmer erfasst)'
        : $attCount . ' Teilnehmer/in' . ($attCount === 1 ? '' : 'nen') . ': ' . implode(', ', $names);
    sf_meta_row($pdf, 'Anwesende', $attText);

    // ── TOPs (Body) ────────────────────────────────────────────────────
    foreach (($p['tops'] ?? []) as $top) {
        $topLabel = trim((string)($top['top'] ?? ''));
        $topTitle = trim((string)($top['title'] ?? ''));
        // Format: "Zu TOP 1) Titel" - matches user's template
        $sectionTitle = ($topLabel !== '' ? 'Zu ' . $topLabel . ') ' : '') . $topTitle;
        sf_section_underline($pdf, $sectionTitle);

        if (!empty($top['notes'])) {
            sf_body_paragraph($pdf, $top['notes']);
        }

        if (!empty($top['beschluss'])) {
            $pdf->Ln(2);
            $pdf->SetFont('Helvetica', 'B', 10.5);
            $pdf->SetTextColor(0, 0, 0);
            $besHeader = 'Beschluss';
            if (!empty($top['beschluss_nr'])) $besHeader .= ' ' . $top['beschluss_nr'];
            $besHeader .= ':';
            $pdf->Cell(170, 5.5, $e($besHeader), 0, 1);

            $pdf->SetFont('Helvetica', '', 10.5);
            $pdf->MultiCell(170, 5.5, $e($top['beschluss']), 0, 'J');

            sf_abstimmung_box($pdf, $top['ja'] ?? null, $top['nein'] ?? null, $top['enth'] ?? null, 'Abstimmung');
        }

        if (!empty($top['action'])) {
            $pdf->SetFont('Helvetica', 'I', 9.5);
            $pdf->SetTextColor(80, 80, 80);
            $line = 'Aufgabe: ' . $top['action'];
            if (!empty($top['action_due'])) $line .= ' (bis ' . $top['action_due'] . ')';
            $pdf->MultiCell(170, 5, $e($line), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(1);
        }
    }

    if (!empty($p['notes'])) {
        sf_section_underline($pdf, 'Sonstige Bemerkungen');
        sf_body_paragraph($pdf, (string)$p['notes']);
    }

    if (!empty($p['attachments'])) {
        sf_section_underline($pdf, 'Anhänge');
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($p['attachments'] as $att) {
            $pdf->Cell(170, 5, $e('• ' . ($att['title'] ?? $att['filename'] ?? '')), 0, 1);
        }
    }

    // ── Unterschriften ─────────────────────────────────────────────────
    $pdf->Ln(14);
    $sigY = $pdf->GetY();
    if ($sigY > 250) { $pdf->AddPage(); $sigY = $pdf->GetY(); }
    $pdf->SetDrawColor(80, 80, 80);
    $pdf->Line(20, $sigY, 95, $sigY);
    $pdf->Line(110, $sigY, 190, $sigY);
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(110, 110, 110);
    // Unterschriftslinien-Labels: links IMMER "1. Vorsitzender" (fest, kein settings),
    // rechts "Schriftführer/in" (auch fest — Rolle, kein Name)
    $pdf->Cell(75, 4, $e('1. Vorsitzender'), 0, 0);
    $pdf->Cell(35, 4, '', 0, 0);
    $pdf->Cell(80, 4, $e('Schriftführer/in'), 0, 1);

    // ── Footer ─────────────────────────────────────────────────────────
    $pdf->SetY(-12);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell(170, 4, $e('Erstellt am ' . date('d.m.Y H:i') . ' · Muster-Kleingartenverein e.V.'), 0, 1, 'C');

    return $pdf->Output('S');
}

// 5.3 — Einladungs-Dialog rendern
function sf_render_invitation_dialog(array $protocol): void {
    $csrf = $_SESSION['csrf'] ?? '';
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Einladung versenden</title>
    <style>body{font-family:sans-serif;background:#f2f6f0;margin:0;padding:30px}
    .box{max-width:600px;margin:0 auto;background:#fff;border-radius:14px;padding:30px;box-shadow:0 4px 18px rgba(0,0,0,0.08)}
    label{display:block;font-size:0.82rem;font-weight:700;color:#5a6c5a;margin:14px 0 5px}
    input,textarea{width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box;font-family:inherit}
    button{background:#7e57c2;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer}
    </style></head><body>
    <div class="box">
      <h2 style="margin:0 0 6px;color:#5e35b1">📨 Einladung zur Sitzung versenden</h2>
      <p style="color:#5a6c5a;font-size:0.9rem"><?= htmlspecialchars($protocol['title'] ?? '') ?> · <?= htmlspecialchars($protocol['date'] ?? '') ?></p>
      <form method="POST" action="/intern/action.php" target="_blank">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_protocol_invitation">
        <input type="hidden" name="pid" value="<?= htmlspecialchars($protocol['id'] ?? '') ?>">
        <label>Uhrzeit</label>
        <input type="text" name="time" placeholder="z.B. 19:30 Uhr" value="19:30 Uhr">
        <label>Ort</label>
        <input type="text" name="location" placeholder="Vereinshaus KGV Musterstadt" value="Vereinshaus KGV Musterstadt">
        <label>Zusatz-Hinweis (optional)</label>
        <textarea name="note" rows="4" placeholder="Bitte Tagesordnungs-Anträge bis 7 Tage vorher einreichen…"></textarea>
        <div style="margin-top:18px;display:flex;gap:10px">
          <button type="submit" name="output" value="preview">👁 PDF-Vorschau</button>
          <button type="submit" name="output" value="send" style="background:#5e35b1" onclick="return confirm('Einladung jetzt an alle Anwesenheits-Mitglieder versenden?')">📨 Versenden</button>
        </div>
      </form>
    </div></body></html>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Schadenmeldungen-Handler                                                  */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_schaden_update(): void {
    $id     = trim((string)($_POST['schaden_id'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));
    $note   = trim((string)($_POST['admin_note'] ?? ''));
    $op     = $_POST['op'] ?? 'update';

    if ($id === '') { header('Location: /intern/?tab=schriftfuehrung&sub=schaeden'); exit; }

    $items = sf_load_json(SF_SCHAEDEN);
    if ($op === 'delete') {
        // Foto auch löschen
        foreach ($items as $s) {
            if (($s['id'] ?? '') === $id && !empty($s['photo_filename'])) {
                $p = SF_UPLOAD_SCHAEDEN . '/' . $s['photo_filename'];
                if (file_exists($p)) @unlink($p);
                break;
            }
        }
        $items = array_values(array_filter($items, fn($s) => ($s['id'] ?? '') !== $id));
        sf_save_json(SF_SCHAEDEN, $items);
        header('Location: /intern/?tab=schriftfuehrung&sub=schaeden&deleted=1');
        exit;
    }

    $valid = array_keys(sf_schaden_statuses());
    foreach ($items as &$s) {
        if (($s['id'] ?? '') === $id) {
            if (in_array($status, $valid, true)) $s['status'] = $status;
            $s['admin_note'] = mb_substr($note, 0, 500);
            $s['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    unset($s);
    sf_save_json(SF_SCHAEDEN, $items);
    header('Location: /intern/?tab=schriftfuehrung&sub=schaeden&saved=1');
    exit;
}

function sf_action_protocol_pdf(): void {
    $pid = trim((string)($_GET['pid'] ?? $_POST['pid'] ?? ''));
    if ($pid === '') { http_response_code(400); exit('Keine ID'); }
    $list = sf_load_json(SF_PROTOCOLS);
    $p = null;
    foreach ($list as $x) if (($x['id'] ?? '') === $pid) { $p = $x; break; }
    if (!$p) { http_response_code(404); exit('Nicht gefunden'); }

    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 18, 20);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $W = 170;

    // Logo
    $logoPath = dirname(__DIR__) . '/logo.png';
    if (file_exists($logoPath)) $pdf->Image($logoPath, 165, 12, 22);

    // Header
    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->SetTextColor(61, 107, 65);
    $pdf->Cell(140, 5, $e('Muster-Kleingartenverein e.V.'), 0, 1);
    $pdf->SetFont('Helvetica', '', 8); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(140, 4, $e('Musterstraße 1/Ecke Beckermannweg · 12345 Musterstadt'), 0, 1);
    $pdf->Ln(8);

    // Title
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($W, 8, $e('Sitzungsprotokoll'), 0, 1);
    $pdf->SetFont('Helvetica', '', 11.5);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell($W, 6, $e($p['title'] ?? ''), 0, 1);
    $pdf->Ln(2);

    // Meta
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(0, 0, 0);
    try {
        $dDate = (new DateTime($p['date'] ?? 'today'))->format('d.m.Y');
    } catch (\Throwable $err) { $dDate = $p['date'] ?? ''; }
    $pdf->Cell(60, 5, $e('Datum: ') . $e($dDate), 0, 0);
    $pdf->Cell(110, 5, $e('Art: ') . $e($p['type'] ?? ''), 0, 1);
    $pdf->Ln(4);

    // Attendees
    $members = sf_member_list();
    $byId = [];
    foreach ($members as $m) $byId[$m['id'] ?? ''] = $m['name'] ?? '';
    $attendeeNames = [];
    foreach (($p['attendees'] ?? []) as $aid) {
        if (!empty($byId[$aid])) $attendeeNames[] = $byId[$aid];
    }
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($W, 5, $e('Anwesenheit (' . count($attendeeNames) . '):'), 0, 1);
    $pdf->SetFont('Helvetica', '', 9.5);
    $attTxt = $attendeeNames ? implode(', ', $attendeeNames) : '(keine Teilnehmer erfasst)';
    $pdf->MultiCell($W, 5, $e($attTxt));
    $pdf->Ln(4);

    // TOPs
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(4);

    $tops = $p['tops'] ?? [];
    foreach ($tops as $top) {
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(94, 53, 177);
        $pdf->Cell($W, 6, $e(($top['top'] ?? '') . ' — ' . ($top['title'] ?? '')), 0, 1);
        $pdf->SetTextColor(0, 0, 0);

        if (!empty($top['notes'])) {
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->MultiCell($W, 5, $e($top['notes']));
            $pdf->Ln(1);
        }

        if (!empty($top['beschluss'])) {
            $pdf->SetFillColor(255, 248, 225);
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell($W, 5, $e('Beschluss:'), 0, 1, '', true);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->SetFillColor(255, 251, 230);
            $pdf->MultiCell($W, 5, $e($top['beschluss']), 0, 'L', true);
            $j = $top['ja']; $n = $top['nein']; $en = $top['enth'];
            if ($j !== null || $n !== null || $en !== null) {
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->SetTextColor(94, 53, 177);
                $resultLine = 'Abstimmung: Ja: ' . (int)$j . ' · Nein: ' . (int)$n . ' · Enth.: ' . (int)$en;
                $pdf->Cell($W, 5, $e($resultLine), 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }
        }
        $pdf->Ln(3);
    }

    if (!empty($p['notes'])) {
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($W, 5, $e('Sonstige Bemerkungen:'), 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->MultiCell($W, 5, $e($p['notes']));
    }

    // Signatur
    $pdf->Ln(14);
    $sigY = $pdf->GetY();
    if ($sigY > 250) { $pdf->AddPage(); $sigY = $pdf->GetY(); }
    $pdf->SetDrawColor(80, 80, 80);
    $pdf->Line(20, $sigY, 95, $sigY);
    $pdf->Line(110, $sigY, 190, $sigY);
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 8); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(75, 4, $e('Vorsitz'), 0, 0);
    $pdf->Cell(35, 4, '', 0, 0);
    $pdf->Cell(80, 4, $e('Schriftführer/in'), 0, 1);

    // Footer
    $pdf->SetY(-12);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell($W, 4, $e('Erstellt am ' . date('d.m.Y H:i') . ' · KGV Musterstadt e.V.'), 0, 1, 'C');

    $out = $pdf->Output('S');
    $safeName = preg_replace('/[^a-z0-9_-]/i', '_', (string)($p['title'] ?? 'Protokoll'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="VEREIN_Protokoll_' . ($p['date'] ?? '') . '_' . $safeName . '.pdf"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Member member_since field                                                */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_save_member_since(): void {
    $memId = trim((string)($_POST['mem_id'] ?? ''));
    $val   = trim((string)($_POST['member_since'] ?? ''));
    if ($memId === '') { http_response_code(400); exit('Kein Mitglied'); }
    if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        header('Location: /intern/?tab=members&err=member_since_format'); exit;
    }
    $mFile = dirname(__DIR__) . '/data/members.json';
    $list = sf_load_json($mFile);
    foreach ($list as &$m) {
        if (($m['id'] ?? '') === $memId) { $m['member_since'] = $val; break; }
    }
    unset($m);
    sf_save_json($mFile, $list);
    header('Location: /intern/?tab=members&saved=member_since');
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Newsletter                                                               */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_newsletter(): void {
    $audience = trim((string)($_POST['audience'] ?? 'all'));
    $subject  = trim((string)($_POST['subject']  ?? ''));
    $greeting = trim((string)($_POST['greeting'] ?? 'Liebe Mitglieder,'));
    $body     = (string)($_POST['body']          ?? '');
    $mode     = $_POST['mode'] ?? 'preview';
    $banner   = trim((string)($_POST['banner_canva_id'] ?? ''));
    $custom   = (array)($_POST['recipients'] ?? []);

    if ($subject === '' || $body === '') {
        header('Location: /intern/?tab=schriftfuehrung&sub=newsletter&err=empty'); exit;
    }

    // Empfänger ermitteln
    $members = sf_member_list();
    $recipients = [];
    if ($audience === 'all') {
        foreach ($members as $m) {
            $em = trim((string)($m['email'] ?? ''));
            if (!empty($m['active']) && $em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $recipients[] = $em;
        }
    } elseif ($audience === 'vorstand') {
        foreach ($members as $m) {
            $em = trim((string)($m['email'] ?? ''));
            $roles = (array)($m['roles'] ?? []);
            if (!empty($m['active']) && $em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && in_array('vorstand', $roles, true)) $recipients[] = $em;
        }
    } else { // custom
        foreach ($custom as $em) {
            $em = trim((string)$em);
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $recipients[] = $em;
        }
    }
    $recipients = array_values(array_unique($recipients));

    // Banner-Bild (Canva)
    $bannerHtml = '';
    if ($banner !== '') {
        foreach (sf_load_json(SF_CANVA) as $cf) {
            if (($cf['id'] ?? '') === $banner) {
                $bannerHtml = "<div style='margin:-28px -32px 22px;border-radius:12px 12px 0 0;overflow:hidden'><img src='" . site_url() . "/intern/sf-canva-file.php?id=" . urlencode($banner) . "' alt='' style='display:block;width:100%;max-height:240px;object-fit:cover'></div>";
                break;
            }
        }
    }

    // Body-HTML
    $content = $bannerHtml
             . "<div style='color:#5a6c5a;line-height:1.7;font-size:0.95rem'>"
             . nl2br($body)
             . "</div>";

    // Settings für Signatur
    $content_settings = sf_load_json(dirname(__DIR__) . '/data/content.json');
    $settings = $content_settings['settings'] ?? [];

    $html = kgv_email_html(
        htmlspecialchars($greeting),
        $content,
        'Mitgliederinformation',
        (string)($settings['kontakt_name']  ?? 'Vorstand'),
        (string)($settings['telefon']        ?? ''),
        (string)($settings['email']          ?? 'vorstand@example.org'),
        (string)($settings['kontakt_rolle']  ?? '1. Vorsitzender')
    );

    if ($mode === 'preview') {
        header('Content-Type: text/html; charset=UTF-8');
        echo "<!DOCTYPE html><html><head><title>Vorschau Rundbrief</title></head><body style='margin:0;background:#f2f6f0'>";
        echo "<div style='background:#90a4ae;color:#fff;padding:14px 22px;text-align:center;font-size:0.92rem'>👁 VORSCHAU — Empfänger: " . count($recipients) . " · Audience: <strong>" . htmlspecialchars($audience) . "</strong></div>";
        echo $html;
        echo "</body></html>";
        exit;
    }

    // Versenden
    $fromEmail = 'kontakt@example.org';
    $fromName  = 'KGV Musterstadt e.V.';
    $sentCount = 0; $failed = 0;
    $textBody  = strip_tags($greeting . "\n\n" . $body);
    foreach (array_chunk($recipients, 30) as $chunk) {
        // To: Festausschuss/Vorstand-Adresse, BCC: Empfänger-Chunk (DSGVO)
        $to  = (string)($settings['email'] ?? 'vorstand@example.org');
        $bcc = implode(', ', $chunk);
        $boundary = 'b_' . md5(uniqid('', true));
        $bodyMime  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$textBody}\r\n\r\n";
        $bodyMime .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--";
        $headers   = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers  .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\nReturn-Path: {$fromEmail}\r\n";
        $headers  .= "Bcc: {$bcc}\r\n";
        $ok = @mail($to, $subject, $bodyMime, $headers, "-f{$fromEmail}");
        if ($ok) $sentCount += count($chunk); else $failed += count($chunk);
    }

    $log = sf_load_json(SF_NEWSLETTERS);
    $log[] = [
        'id'              => sf_uniq_id('nl'),
        'sent_at'         => date('Y-m-d H:i:s'),
        'subject'         => $subject,
        'audience'        => $audience,
        'recipient_count' => $sentCount,
        'failed_count'    => $failed,
        'banner_canva_id' => $banner,
    ];
    sf_save_json(SF_NEWSLETTERS, $log);

    header('Location: /intern/?tab=schriftfuehrung&sub=newsletter&sent=' . $sentCount);
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Document upload + delete                                                 */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_document_upload(): void {
    sf_ensure_dirs();
    if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /intern/?tab=schriftfuehrung&sub=documents&err=upload'); exit;
    }
    $tmp  = $_FILES['doc_file']['tmp_name'];
    $size = (int)$_FILES['doc_file']['size'];
    if ($size > 20 * 1024 * 1024) { header('Location: /intern/?tab=schriftfuehrung&sub=documents&err=size'); exit; }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = (string)finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];
    if (!isset($allowed[$mime])) { header('Location: /intern/?tab=schriftfuehrung&sub=documents&err=mime'); exit; }

    $id    = sf_uniq_id('doc');
    $ext   = $allowed[$mime];
    $fname = $id . '.' . $ext;
    if (!@move_uploaded_file($tmp, SF_UPLOAD_DOCS . '/' . $fname)) {
        header('Location: /intern/?tab=schriftfuehrung&sub=documents&err=save'); exit;
    }

    $docs   = sf_load_json(SF_DOCUMENTS);
    $docs[] = [
        'id'          => $id,
        'title'       => mb_substr(trim(strip_tags((string)($_POST['title']       ?? ''))), 0, 100),
        'category'    => trim(strip_tags((string)($_POST['category']    ?? 'sonstige'))),
        'description' => mb_substr(trim(strip_tags((string)($_POST['description'] ?? ''))), 0, 200),
        'filename'    => $fname,
        'mime'        => $mime,
        'size'        => $size,
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
    sf_save_json(SF_DOCUMENTS, $docs);
    header('Location: /intern/?tab=schriftfuehrung&sub=documents&uploaded=1');
    exit;
}

function sf_action_document_delete(): void {
    $id = trim((string)($_POST['doc_id'] ?? ''));
    if ($id === '') { header('Location: /intern/?tab=schriftfuehrung&sub=documents'); exit; }
    $docs = sf_load_json(SF_DOCUMENTS);
    $kept = [];
    foreach ($docs as $d) {
        if (($d['id'] ?? '') === $id) {
            $p = SF_UPLOAD_DOCS . '/' . ($d['filename'] ?? '');
            if (file_exists($p)) @unlink($p);
            continue;
        }
        $kept[] = $d;
    }
    sf_save_json(SF_DOCUMENTS, $kept);
    header('Location: /intern/?tab=schriftfuehrung&sub=documents&deleted=1');
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Notes                                                                    */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_note_create(): void {
    $txt = trim((string)($_POST['text']     ?? ''));
    $rem = trim((string)($_POST['reminder'] ?? ''));
    if ($txt === '') { header('Location: /intern/?tab=schriftfuehrung&sub=notes'); exit; }
    if ($rem !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rem)) $rem = '';

    $notes   = sf_load_json(SF_NOTES);
    $notes[] = [
        'id'         => sf_uniq_id('note'),
        'text'       => mb_substr($txt, 0, 300),
        'reminder'   => $rem,
        'done'       => false,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    sf_save_json(SF_NOTES, $notes);
    header('Location: /intern/?tab=schriftfuehrung&sub=notes');
    exit;
}

function sf_action_note_toggle(): void {
    $id = trim((string)($_POST['note_id'] ?? ''));
    if ($id === '') { header('Location: /intern/?tab=schriftfuehrung&sub=notes'); exit; }
    $notes = sf_load_json(SF_NOTES);
    foreach ($notes as &$n) {
        if (($n['id'] ?? '') === $id) {
            $n['done']    = !($n['done'] ?? false);
            $n['done_at'] = $n['done'] ? date('Y-m-d H:i:s') : '';
            break;
        }
    }
    unset($n);
    sf_save_json(SF_NOTES, $notes);
    header('Location: /intern/?tab=schriftfuehrung&sub=notes');
    exit;
}

function sf_action_note_delete(): void {
    $id = trim((string)($_POST['note_id'] ?? ''));
    if ($id === '') { header('Location: /intern/?tab=schriftfuehrung&sub=notes'); exit; }
    $notes = sf_load_json(SF_NOTES);
    $notes = array_values(array_filter($notes, fn($n) => ($n['id'] ?? '') !== $id));
    sf_save_json(SF_NOTES, $notes);
    header('Location: /intern/?tab=schriftfuehrung&sub=notes');
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Druck-Studio: Urkunde / Etiketten / Aushang                              */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_print_urkunde(): void {
    $memberId = trim((string)($_POST['member_id'] ?? ''));
    $jahre    = max(1, min(100, (int)($_POST['jahre'] ?? 25)));
    $datum    = trim((string)($_POST['datum'] ?? ''));
    $override = trim((string)($_POST['text_override'] ?? ''));

    $member = null;
    foreach (sf_member_list() as $m) if (($m['id'] ?? '') === $memberId) { $member = $m; break; }
    if (!$member) { http_response_code(404); exit('Mitglied nicht gefunden'); }

    $pdf = sf_certificate_pdf((string)($member['name'] ?? ''), $jahre, $datum, $override);
    $safeName = preg_replace('/[^a-z0-9_-]/i', '_', (string)($member['name'] ?? 'Mitglied'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="VEREIN_Ehrenurkunde_' . $jahre . 'J_' . $safeName . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function sf_action_print_labels(): void {
    $ids = (array)($_POST['member_ids'] ?? []);
    if (empty($ids)) {
        header('Location: /intern/?tab=schriftfuehrung&sub=druckstudio&tool=labels&err=no_selection');
        exit;
    }
    $all = sf_member_list();
    $byId = [];
    foreach ($all as $m) $byId[$m['id'] ?? ''] = $m;
    $chosen = [];
    foreach ($ids as $id) if (isset($byId[$id])) $chosen[] = $byId[$id];

    $pdf = sf_address_labels_pdf($chosen);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="VEREIN_Adressetiketten_' . count($chosen) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function sf_action_print_aushang(): void {
    $title  = trim((string)($_POST['title']  ?? ''));
    $body   = trim((string)($_POST['body']   ?? ''));
    $accent = trim((string)($_POST['accent'] ?? '#3d6b41'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#3d6b41';
    if ($title === '' || $body === '') { http_response_code(400); exit('Titel und Text erforderlich'); }

    $pdf = sf_aushang_pdf($title, $body, $accent);
    $safe = preg_replace('/[^a-z0-9_-]/i', '_', $title);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="VEREIN_Aushang_' . $safe . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Antrags-Inbox: Status updaten / Notiz / löschen                          */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_application_update(): void {
    $id       = trim((string)($_POST['app_id'] ?? ''));
    $status   = trim((string)($_POST['status'] ?? ''));
    $note     = trim((string)($_POST['admin_note'] ?? ''));
    $decNote  = trim((string)($_POST['decision_note'] ?? ''));
    $op       = (string)($_POST['op'] ?? 'update');
    $redir    = '/intern/?tab=schriftfuehrung&sub=applications';

    if ($id === '') { header("Location: {$redir}"); exit; }

    $actor = trim((string)($_SESSION['kgv_member']['name'] ?? ''));
    if ($actor === '') $actor = !empty($_SESSION['kgv_admin']) ? 'Vorstand' : 'Schriftführung';

    $apps = sf_load_json(SF_APPLICATIONS);

    if ($op === 'delete') {
        foreach ($apps as $a) {
            if (($a['id'] ?? '') === $id) {
                sf_delete_antrag_files((array)($a['attachments'] ?? []));
                sf_delete_antrag_files((array)($a['decision_files'] ?? []));
                break;
            }
        }
        $apps = array_values(array_filter($apps, fn($a) => ($a['id'] ?? '') !== $id));
        sf_save_json(SF_APPLICATIONS, $apps);
        header("Location: {$redir}&deleted=1");
        exit;
    }

    $valid    = array_keys(sf_application_statuses());
    $decStat  = sf_application_decision_statuses();
    $now      = date('Y-m-d H:i:s');
    $doSend   = ($op === 'decision_send');
    $isDecOp  = ($op === 'decision_save' || $doSend);
    $sentApp  = null;

    // Ablehnung ohne Begründung darf nicht an das Mitglied gesendet werden.
    if ($doSend) {
        $effStatus = in_array($status, $valid, true) ? $status : '';
        foreach ($apps as $x) { if (($x['id'] ?? '') === $id && $effStatus === '') { $effStatus = (string)($x['status'] ?? ''); break; } }
        if ($effStatus === 'abgelehnt' && $decNote === '') {
            header("Location: {$redir}&err=begruendung");
            exit;
        }
    }

    foreach ($apps as &$a) {
        if (($a['id'] ?? '') !== $id) continue;

        $oldStatus = (string)($a['status'] ?? '');
        if (in_array($status, $valid, true) && $status !== $oldStatus) {
            $a['status']    = $status;
            $a['history']   = (array)($a['history'] ?? []);
            $a['history'][] = ['status' => $status, 'at' => $now, 'by' => $actor];
        }
        $a['admin_note'] = mb_substr($note, 0, 500);
        $a['updated_at'] = $now;

        if ($isDecOp) {
            $a['decision_note'] = mb_substr($decNote, 0, 4000);
            $newFiles = sf_store_antrag_uploads('decision_files', 6);
            if ($newFiles) {
                $a['decision_files'] = array_merge((array)($a['decision_files'] ?? []), $newFiles);
            }
            if (empty($a['decided_at'])) $a['decided_at'] = $now;
            $a['decided_by'] = $actor;

            if ($doSend) {
                $a['decision_sent_at'] = $now;
                $sentApp = $a;
            }
        }
        break;
    }
    unset($a);
    sf_save_json(SF_APPLICATIONS, $apps);

    if ($doSend && $sentApp !== null) {
        sf_mail_application_decision($sentApp);
        header("Location: {$redir}&sent=1");
        exit;
    }
    header("Location: {$redir}&saved=1");
    exit;
}

/** Sendet die Entscheidungs-Mitteilung an das antragstellende Mitglied (Text + PDF-Anhänge). */
function sf_mail_application_decision(array $a): bool {
    $to = trim((string)($a['from_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $statuses  = sf_application_statuses();
    $st        = (string)($a['status'] ?? '');
    $stLabel   = $statuses[$st][1] ?? 'bearbeitet';
    $title     = (string)($a['title'] ?? 'Dein Antrag');
    $decNote   = trim((string)($a['decision_note'] ?? ''));
    $name      = (string)($a['from_name'] ?? '');
    $firstName = $name !== '' ? explode(' ', trim($name))[0] : '';

    $contentArr = sf_load_json(dirname(__DIR__) . '/data/content.json');
    $settings   = $contentArr['settings'] ?? [];

    $subject = 'Dein Antrag „' . $title . '" — ' . $stLabel;
    $txt  = "Hallo " . ($firstName !== '' ? $firstName : 'lieber Gartenfreund') . ",\n\n"
          . "dein Antrag \"{$title}\" wurde vom Vorstand bearbeitet.\n"
          . "Status: {$stLabel}\n\n"
          . ($decNote !== '' ? $decNote . "\n\n" : '')
          . "Den vollständigen Stand und alle Dokumente findest du im Mitgliederbereich unter „Meine Anträge\":\n"
          . site_url() . "/mitglieder.php?tab=meine-antraege\n\n"
          . "Viele Grüße\nDein Vorstand · KGV Musterstadt e.V.";

    $html = kgv_email_html(
        'Hallo ' . htmlspecialchars($firstName !== '' ? $firstName : 'lieber Gartenfreund') . ' 👋,',
        "<p>Dein Antrag „<strong>" . htmlspecialchars($title) . "</strong>\" wurde vom Vorstand bearbeitet.</p>"
        . "<p style='margin:10px 0'><span style='display:inline-block;background:" . ($statuses[$st][0] ?? '#5a6c5a') . ";color:#fff;padding:4px 12px;border-radius:14px;font-size:0.82rem;font-weight:700'>" . htmlspecialchars($stLabel) . "</span></p>"
        . ($decNote !== '' ? "<div style='background:#f5f7f2;border-left:4px solid #3d6b41;border-radius:8px;padding:14px 18px;margin:14px 0;white-space:pre-wrap;line-height:1.6'>" . htmlspecialchars($decNote) . "</div>" : '')
        . "<p>Den vollständigen Stand und alle Dokumente findest du im Mitgliederbereich unter „Meine Anträge\".</p>"
        . "<p style='text-align:center;margin-top:18px'><a href='" . site_url() . "/mitglieder.php?tab=meine-antraege' style='display:inline-block;background:#3d6b41;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700'>→ Zu meinen Anträgen</a></p>",
        'Antwort zu deinem Antrag',
        (string)($settings['kontakt_name'] ?? 'Der Vorstand'),
        (string)($settings['telefon'] ?? ''),
        (string)($settings['email'] ?? 'kontakt@example.org'),
        (string)($settings['kontakt_rolle'] ?? 'Vorstand')
    );

    // PDF-/Bild-Anhänge einsammeln (Gesamt-Anhanggröße auf ~10 MB begrenzen)
    $attach = [];
    $totalBytes = 0;
    foreach ((array)($a['decision_files'] ?? []) as $f) {
        $fn = (string)($f['filename'] ?? '');
        if ($fn === '' || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $fn)) continue;
        $path = SF_UPLOAD_ANTRAEGE . '/' . $fn;
        if (!is_file($path)) continue;
        $sz = (int)filesize($path);
        if ($totalBytes + $sz > 10 * 1024 * 1024) continue; // zu groß -> nur Portal-Link
        $totalBytes += $sz;
        $attach[] = [
            'name' => preg_replace('/[^a-zA-Z0-9_.\- ]/', '_', (string)($f['title'] ?? $fn)),
            'mime' => (string)($f['mime'] ?? 'application/octet-stream'),
            'data' => (string)@file_get_contents($path),
        ];
    }

    $bMix = 'm_' . md5(uniqid('', true));
    $bAlt = 'a_' . md5(uniqid('', true));
    $alt  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$txt}\r\n\r\n";
    $alt .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$bAlt}--";

    if (empty($attach)) {
        $body = $alt;
        $hdr  = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n";
    } else {
        $body = "--{$bMix}\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n\r\n{$alt}\r\n\r\n";
        foreach ($attach as $at) {
            $b64 = chunk_split(base64_encode($at['data']));
            $body .= "--{$bMix}\r\nContent-Type: " . $at['mime'] . "; name=\"" . $at['name'] . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $at['name'] . "\"\r\n\r\n{$b64}\r\n";
        }
        $body .= "--{$bMix}--";
        $hdr   = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$bMix}\"\r\n";
    }
    $hdr .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: kontakt@example.org\r\nReturn-Path: kontakt@example.org\r\n";

    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $hdr, '-fkontakt@example.org');
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Karten-Drucker                                                           */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_card_print(): void {
    $canvaId  = trim((string)($_POST['canva_id'] ?? ''));
    $memberId = trim((string)($_POST['member_id'] ?? ''));
    $message  = (string)($_POST['message'] ?? '');
    $textPos  = $_POST['text_pos'] === 'center' ? 'center' : 'bottom';

    $member = null;
    foreach (sf_member_list() as $m) if (($m['id'] ?? '') === $memberId) { $member = $m; break; }
    if (!$member) { http_response_code(404); exit('Mitglied nicht gefunden'); }

    $canvaFile = null;
    foreach (sf_load_json(SF_CANVA) as $c) if (($c['id'] ?? '') === $canvaId) { $canvaFile = $c; break; }
    if (!$canvaFile) { http_response_code(404); exit('Canva-Bild nicht gefunden'); }
    $imgPath = SF_UPLOAD_CANVA . '/' . ($canvaFile['filename'] ?? '');
    if (!file_exists($imgPath)) { http_response_code(404); exit('Hintergrund-Datei fehlt'); }

    $pdf = sf_card_pdf($imgPath, (string)($member['name'] ?? ''), $message, $textPos);
    $safeName = preg_replace('/[^a-z0-9_-]/i', '_', (string)($member['name'] ?? 'Mitglied'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="VEREIN_Karte_' . $safeName . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Config Save                                                              */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_config_save(): void {
    $patch = [
        'daily_enabled' => !empty($_POST['daily_enabled']),
        'daily_to'      => filter_var(trim((string)($_POST['daily_to'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'daily_to_cc'   => filter_var(trim((string)($_POST['daily_to_cc'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'antrag_to'     => filter_var(trim((string)($_POST['antrag_to'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
    ];
    sf_config_save($patch);

    if (!empty($_POST['test_briefing'])) {
        // Inline preview rendern
        $briefing = sf_daily_briefing();
        $html = kgv_email_html(
            'Hallo Sandra 👋,',
            sf_daily_briefing_render_html($briefing),
            'Daily-Briefing · ' . date('d.m.Y'),
            'Schriftführung-Cockpit', '', 'kontakt@example.org', 'Automatisches Briefing'
        );
        header('Content-Type: text/html; charset=UTF-8');
        echo "<!DOCTYPE html><html><body style='margin:0;background:#f2f6f0'>";
        echo "<div style='background:#90a4ae;color:#fff;padding:14px 22px;text-align:center;font-size:0.92rem'>👁 VORSCHAU Daily-Briefing</div>";
        echo $html;
        echo "</body></html>";
        exit;
    }

    header('Location: /intern/?tab=schriftfuehrung&sub=settings&saved=1');
    exit;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Einladungs-Versand (Welle 5.3)                                            */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_action_protocol_invitation(): void {
    $pid     = trim((string)($_POST['pid'] ?? ''));
    $time    = trim((string)($_POST['time'] ?? ''));
    $location= trim((string)($_POST['location'] ?? ''));
    $note    = trim((string)($_POST['note'] ?? ''));
    $output  = $_POST['output'] ?? 'preview';

    $list = sf_load_json(SF_PROTOCOLS);
    $p = null;
    foreach ($list as $x) if (($x['id'] ?? '') === $pid) { $p = $x; break; }
    if (!$p) { http_response_code(404); exit('Nicht gefunden'); }

    $contentArr = sf_load_json(dirname(__DIR__) . '/data/content.json');
    $settings   = $contentArr['settings'] ?? [];

    $pdfBin = sf_protocol_invitation_pdf($p, $settings, $time, $location, $note);

    if ($output === 'preview') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="VEREIN_Einladung_' . preg_replace('/[^a-z0-9_-]/i','_',(string)$p['date']) . '.pdf"');
        header('Content-Length: ' . strlen($pdfBin));
        echo $pdfBin;
        exit;
    }

    // Versenden an Anwesenheits-Empfänger
    $members = sf_member_list();
    $byId = []; foreach ($members as $m) $byId[$m['id'] ?? ''] = $m;
    $recipients = [];
    foreach ($p['attendees'] ?? [] as $id) {
        $m = $byId[$id] ?? null;
        if ($m && !empty($m['email']) && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) $recipients[] = $m['email'];
    }
    // Fallback: wenn keine Anwesenheit definiert → alle aktiven Mitglieder mit Vorstand/Schriftführer-Rolle
    if (empty($recipients)) {
        foreach ($members as $m) {
            if (empty($m['active'])) continue;
            $em = (string)($m['email'] ?? '');
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
            $roles = (array)($m['roles'] ?? []);
            if (array_intersect($roles, ['vorstand','schriftfuehrer','buchung','web'])) $recipients[] = $em;
        }
    }

    if (empty($recipients)) {
        echo "<p style='font-family:sans-serif;padding:20px'>⚠ Keine Empfänger gefunden — Anwesenheits-Liste leer und kein Vorstand mit E-Mail.</p>";
        exit;
    }

    $subject = 'Einladung: ' . ($p['title'] ?? '') . ' am ' . ($p['date'] ?? '');
    $txt = "Einladung zur Sitzung\n\n" . ($p['title'] ?? '') . "\nDatum: " . ($p['date'] ?? '') . "\nUhrzeit: {$time}\nOrt: {$location}\n\n";
    foreach ($p['tops'] ?? [] as $top) $txt .= '• ' . ($top['top'] ?? '') . ' — ' . ($top['title'] ?? '') . "\n";
    if ($note !== '') $txt .= "\n{$note}\n";
    $txt .= "\nFreundliche Grüße\nDer Vorstand";

    $html = kgv_email_html(
        'Liebe Sitzungsteilnehmer/innen 👋,',
        "<p>hiermit lade ich euch herzlich zur " . htmlspecialchars($p['type'] ?? 'Sitzung') . " ein.</p>"
        . "<table style='width:100%;border-collapse:collapse;margin:14px 0'>"
        . "<tr><td style='padding:6px 12px;background:#f5f0fa;font-weight:700;color:#5e35b1'>📅 Datum</td><td style='padding:6px 12px;background:#f9fbf7'>" . htmlspecialchars($p['date'] ?? '') . "</td></tr>"
        . ($time !== '' ? "<tr><td style='padding:6px 12px;background:#f5f0fa;font-weight:700;color:#5e35b1'>🕐 Uhrzeit</td><td style='padding:6px 12px;background:#f9fbf7'>" . htmlspecialchars($time) . "</td></tr>" : '')
        . ($location !== '' ? "<tr><td style='padding:6px 12px;background:#f5f0fa;font-weight:700;color:#5e35b1'>📍 Ort</td><td style='padding:6px 12px;background:#f9fbf7'>" . htmlspecialchars($location) . "</td></tr>" : '')
        . "</table>"
        . "<p>Im Anhang findest du die Einladung als PDF mit der geplanten Tagesordnung.</p>"
        . ($note !== '' ? "<div style='background:#fff8e1;border-left:4px solid #f9a825;border-radius:8px;padding:12px 16px;margin:14px 0'>" . nl2br(htmlspecialchars($note)) . "</div>" : '')
        . "<p>Bei Verhinderung bitte kurz Bescheid geben.</p>",
        'Sitzungseinladung',
        (string)($settings['kontakt_name']  ?? 'Schriftführung'),
        (string)($settings['telefon']        ?? ''),
        (string)($settings['email']          ?? 'kontakt@example.org'),
        'Schriftführerin'
    );

    $bMix = 'm_' . md5(uniqid('', true));
    $bAlt = 'a_' . md5(uniqid('', true));
    $alt  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$txt}\r\n\r\n";
    $alt .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$bAlt}--";

    $b64 = chunk_split(base64_encode($pdfBin));
    $fname = 'VEREIN_Einladung_' . preg_replace('/[^a-z0-9_-]/i','_',(string)$p['date']) . '.pdf';
    $body  = "--{$bMix}\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n\r\n{$alt}\r\n\r\n";
    $body .= "--{$bMix}\r\nContent-Type: application/pdf; name=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n{$b64}\r\n";
    $body .= "--{$bMix}--";

    $hdr  = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$bMix}\"\r\n";
    $hdr .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: " . ($settings['email'] ?? 'kontakt@example.org') . "\r\nReturn-Path: kontakt@example.org\r\n";
    $hdr .= "Bcc: " . implode(', ', $recipients) . "\r\n";

    $ok = @mail((string)($settings['email'] ?? 'kontakt@example.org'), $subject, $body, $hdr, "-fkontakt@example.org");

    // Status updaten
    foreach ($list as &$x) {
        if (($x['id'] ?? '') === $pid) {
            $x['invitation_sent_at'] = date('Y-m-d H:i:s');
            $x['invitation_sent_to'] = $recipients;
            break;
        }
    }
    unset($x);
    sf_save_json(SF_PROTOCOLS, $list);

    header('Content-Type: text/html; charset=UTF-8');
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:30px;text-align:center;background:#f5f0fa'>";
    echo "<h2 style='color:#5e35b1'>" . ($ok ? '✓ Einladung verschickt' : '⚠ Versand fehlgeschlagen') . "</h2>";
    echo "<p style='color:#5a6c5a'>" . count($recipients) . " Empfänger</p>";
    echo "<p><a href='/intern/?tab=schriftfuehrung&sub=protocols&pid=" . urlencode($pid) . "' style='background:#5e35b1;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none'>← Zurück zum Protokoll</a></p>";
    echo "</body></html>";
    exit;
}
