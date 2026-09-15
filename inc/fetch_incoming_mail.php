<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_loader.php';


/**
 * Fetches incoming replies from the KGV mailbox via IMAP and saves them
 * into the matching contact thread in contacts.json.
 *
 * Returns array: ['added' => int, 'errors' => string[]]
 */
function fetch_incoming_contact_replies(string $contactsFile): array
{
    $result = ['added' => 0, 'errors' => []];

    if (!function_exists('imap_open')) {
        $result['errors'][] = 'IMAP-Erweiterung nicht verfügbar.';
        return $result;
    }

    // ── IMAP credentials: prefer environment variables, fallback to data/settings.json
    $_settings     = load_settings();
    $imapHostName  = (string)($_settings['imap_host'] ?? 'imap.strato.de');
    $imapPort      = (int)($_settings['imap_port'] ?? 993);
    $imapUser      = (string)($_settings['imap_user'] ?? '');
    $imapPass      = (string)($_settings['imap_password'] ?? '');
    if ($imapUser === '' || $imapPass === '') {
        $result['errors'][] = 'IMAP-Zugangsdaten fehlen in data/settings.json (imap_user / imap_password).';
        return $result;
    }
    $imapHost = '{' . $imapHostName . ':' . $imapPort . '/imap/ssl/novalidate-cert}INBOX';

    // ── Connect ───────────────────────────────────────────────────────────────
    $mbox = @imap_open($imapHost, $imapUser, $imapPass, 0, 1);
    if (!$mbox) {
        $err = imap_last_error() ?: 'Verbindung fehlgeschlagen';
        $result['errors'][] = 'IMAP: ' . $err;
        return $result;
    }

    // ── Load contacts ─────────────────────────────────────────────────────────
    if (!file_exists($contactsFile)) {
        imap_close($mbox);
        $result['errors'][] = 'contacts.json nicht gefunden';
        return $result;
    }
    $allC = json_decode((string)file_get_contents($contactsFile), true);
    if (!is_array($allC)) {
        imap_close($mbox);
        $result['errors'][] = 'contacts.json nicht lesbar';
        return $result;
    }

    // Index contacts by normalised subject and by email for fast lookup
    // Key: normalised_subject|sender_email => contact index
    $index = [];
    foreach ($allC as $i => $c) {
        $normSubj = _imap_norm_subject((string)($c['subject'] ?? ''));
        $fromAddr  = strtolower(trim((string)($c['email'] ?? '')));
        if ($normSubj !== '' && $fromAddr !== '') {
            $index[$normSubj . '|' . $fromAddr] = $i;
        }
    }

    // Collect already-stored message-IDs to avoid duplicates
    $seenMsgIds = [];
    foreach ($allC as $c) {
        foreach ($c['replies'] ?? [] as $r) {
            if (!empty($r['message_id'])) {
                $seenMsgIds[$r['message_id']] = true;
            }
        }
    }

    // ── Search emails from last 60 days ───────────────────────────────────────
    $since    = date('d-M-Y', strtotime('-60 days'));
    $msgNums  = @imap_search($mbox, 'SINCE "' . $since . '"');
    if (!is_array($msgNums) || empty($msgNums)) {
        imap_close($mbox);
        return $result;
    }

    foreach ($msgNums as $msgNum) {
        $header = @imap_headerinfo($mbox, $msgNum);
        if (!$header) continue;

        // Skip emails sent by us (from kontakt@example.org)
        $senderAddr = '';
        if (!empty($header->from[0]->mailbox) && !empty($header->from[0]->host)) {
            $senderAddr = strtolower($header->from[0]->mailbox . '@' . $header->from[0]->host);
        }
        if ($senderAddr === 'kontakt@example.org') continue;

        // Decode subject
        $rawSubj = isset($header->subject) ? imap_utf8((string)$header->subject) : '';
        $normSubj = _imap_norm_subject($rawSubj);

        // Message-ID for dedup
        $msgId = trim((string)($header->message_id ?? ''));

        if ($msgId !== '' && isset($seenMsgIds[$msgId])) continue; // already stored

        // Date
        $dateStr = isset($header->date) ? (string)$header->date : '';
        $ts      = $dateStr !== '' ? (@strtotime($dateStr) ?: time()) : time();
        $repliedAt = date('Y-m-d H:i:s', $ts);

        // Sender display name
        $senderName = '';
        if (!empty($header->from[0]->personal)) {
            $senderName = imap_utf8((string)$header->from[0]->personal);
        }
        if ($senderName === '') $senderName = $senderAddr;

        // Match to contact thread
        $lookupKey = $normSubj . '|' . $senderAddr;
        if (!isset($index[$lookupKey])) continue; // no matching thread

        $ci = $index[$lookupKey];

        // Fetch body (plain text preferred)
        $body = _imap_get_body($mbox, $msgNum);

        // Build reply entry
        $newReply = [
            'type'        => 'incoming',
            'body'        => $body,
            'replied_at'  => $repliedAt,
            'from_name'   => $senderName,
            'from_email'  => $senderAddr,
        ];
        if ($msgId !== '') $newReply['message_id'] = $msgId;

        // Ensure replies array exists and migrate old format
        if (!isset($allC[$ci]['replies'])) {
            $allC[$ci]['replies'] = [];
            if (!empty($allC[$ci]['reply'])) {
                $allC[$ci]['replies'][] = [
                    'type'       => 'outgoing',
                    'body'       => $allC[$ci]['reply'],
                    'replied_at' => $allC[$ci]['replied_at'] ?? '',
                    'admin_name' => '',
                ];
            }
            unset($allC[$ci]['reply'], $allC[$ci]['replied_at']);
        }

        $allC[$ci]['replies'][]  = $newReply;
        $allC[$ci]['status']     = 'new'; // mark thread as needing attention
        if ($msgId !== '') $seenMsgIds[$msgId] = true;
        $result['added']++;
    }

    imap_close($mbox);

    if ($result['added'] > 0) {
        file_put_contents($contactsFile, json_encode($allC, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    return $result;
}

/** Strip Re:, AW:, Fwd:, WG: prefixes and normalise */
function _imap_norm_subject(string $s): string
{
    $s = trim($s);
    // Strip prefixes repeatedly
    do {
        $prev = $s;
        $s = preg_replace('/^(re|aw|fwd|wg|fw)\s*:\s*/iu', '', $s);
        $s = trim($s ?? $prev);
    } while ($s !== $prev);
    return strtolower($s);
}

/** Heuristic: looks the string like HTML? */
function _imap_looks_like_html(string $s): bool {
    return (bool) preg_match('/<(html|body|div|p|br|table|span|font|strong|em|h[1-6]|li)\b/i', substr($s, 0, 4000));
}

/** Safety net: if the supposedly plain body still contains HTML markup, convert. */
function _imap_safe_text(string $s): string {
    return _imap_looks_like_html($s) ? _imap_html_to_text($s) : $s;
}

/** Extract plain-text body from IMAP message */
function _imap_get_body($mbox, int $msgNum): string
{
    // Try to get plain text part
    $structure = @imap_fetchstructure($mbox, $msgNum);
    if (!$structure) {
        return _imap_safe_text(trim((string)@imap_body($mbox, $msgNum)));
    }

    // Simple message (no parts) — könnte trotzdem HTML sein (subtype=HTML oder Inhalt)
    if (empty($structure->parts)) {
        $body    = (string)@imap_fetchbody($mbox, $msgNum, '1');
        $decoded = _imap_decode_body($body, $structure->encoding ?? 0, $structure->parameters ?? []);
        $subtype = strtolower((string)($structure->subtype ?? ''));
        if ($subtype === 'html' || _imap_looks_like_html($decoded)) {
            return _imap_html_to_text($decoded);
        }
        return $decoded;
    }

    // Multipart: find first text/plain part
    foreach ($structure->parts as $partNum => $part) {
        $stype = strtolower((string)($part->subtype ?? ''));
        if (($part->type ?? 99) === 0) { // TEXT (type=0)
            if ($stype === 'plain') {
                $body    = (string)@imap_fetchbody($mbox, $msgNum, (string)($partNum + 1));
                $decoded = _imap_decode_body($body, $part->encoding ?? 0, $part->parameters ?? []);
                // Safety net: manche Mailer markieren HTML fälschlich als text/plain
                return _imap_safe_text($decoded);
            }
        }
        // Check sub-parts (e.g. multipart/alternative inside multipart/mixed)
        if (!empty($part->parts)) {
            foreach ($part->parts as $subNum => $subPart) {
                if (($subPart->type ?? 99) === 0 && strtolower((string)($subPart->subtype ?? '')) === 'plain') {
                    $body    = (string)@imap_fetchbody($mbox, $msgNum, ($partNum + 1) . '.' . ($subNum + 1));
                    $decoded = _imap_decode_body($body, $subPart->encoding ?? 0, $subPart->parameters ?? []);
                    return _imap_safe_text($decoded);
                }
            }
        }
    }

    // Fallback: HTML part → convert to clean plain text
    foreach ($structure->parts as $partNum => $part) {
        if (($part->type ?? 99) === 0) {
            $body = (string)@imap_fetchbody($mbox, $msgNum, (string)($partNum + 1));
            $decoded = _imap_decode_body($body, $part->encoding ?? 0, $part->parameters ?? [], true);
            return _imap_html_to_text($decoded);
        }
    }

    // Last resort: whole raw body
    return _imap_html_to_text((string)@imap_body($mbox, $msgNum));
}

/** Convert HTML email body to clean plain text */
function _imap_html_to_text(string $html): string
{
    // Remove <head>, <style>, <script> blocks completely
    $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

    // Remove quoted reply block: everything from the quote marker onward
    // Matches: "Am ... schrieb ...", "On ... wrote:", "Von: ..." blockquote start
    $html = preg_replace('/<(blockquote|div)[^>]*border-left[^>]*>.*$/is', '', $html) ?? $html;

    // Convert block elements to newlines before stripping tags
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/?(p|div|tr|li|h[1-6])[^>]*>/i', "\n", $html) ?? $html;

    // Strip remaining tags
    $text = strip_tags($html);

    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Remove quoted reply lines starting with ">"
    $lines = explode("\n", $text);
    $clean = [];
    $inQuote = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Stop at common quote separators
        if (preg_match('/^(Am |On |Von:|From:|Gesendet\s*mit|Sent from|>{3,}|-{3,})/u', $trimmed)) {
            $inQuote = true;
        }
        if ($inQuote) continue;
        if (preg_match('/^\s*>/', $line)) continue; // quoted line
        $clean[] = $line;
    }
    $text = implode("\n", $clean);

    // Collapse excessive whitespace
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

    return trim($text);
}

function _imap_decode_body(string $body, int $encoding, array $params, bool $skipQuoteStrip = false): string
{
    // Decode transfer encoding
    switch ($encoding) {
        case 3: $body = (string)base64_decode($body); break;  // BASE64
        case 4: $body = quoted_printable_decode($body); break; // QP
    }
    // Detect charset
    $charset = 'UTF-8';
    foreach ($params as $p) {
        if (strtolower((string)($p->attribute ?? '')) === 'charset') {
            $charset = strtoupper((string)($p->value ?? 'UTF-8'));
            break;
        }
    }
    if ($charset !== 'UTF-8') {
        $conv = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', (string)$body);
        if ($conv !== false) $body = $conv;
    }
    if ($skipQuoteStrip) return (string)$body;

    // Remove quoted reply lines (plain-text emails with "> " prefix)
    $lines = explode("\n", (string)$body);
    $clean = [];
    $inQuote = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(Am |On |Von:|From:|Gesendet\s*mit|Sent from|-{3,})/u', $trimmed)) {
            $inQuote = true;
        }
        if ($inQuote) continue;
        if (preg_match('/^\s*>/', $line)) continue;
        $clean[] = $line;
    }
    $body = implode("\n", $clean);
    $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;
    return trim((string)$body);
}
