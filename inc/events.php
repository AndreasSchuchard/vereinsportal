<?php
declare(strict_types=1);

/**
 * Veranstaltungs-Anmeldungen: gemeinsame Funktionen für Frontend (event.php)
 * und Backoffice (intern/index.php Tab "events").
 *
 * Datenmodell: data/events.json — Array von Events.
 * Schema pro Event:
 *   id, slug, title, subtitle, description, type (internal|public),
 *   active, show_in_member_area, deadline, event_date,
 *   created_at, created_by, registrations[]
 *
 * Pro registration:
 *   id, submitted_at, fullname, email, phone, guests, catering,
 *   cancel_token, cancelled_at, source (public|member|backoffice)
 */

define('EVENTS_FILE', dirname(__DIR__) . '/data/events.json');

/** Slugify: ä→ae, ß→ss, lowercase, dashes */
function kgv_slugify(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ô'=>'o','î'=>'i','û'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') $s = 'event';
    return substr($s, 0, 60);
}

/** Lädt alle Events aus data/events.json */
function kgv_events_all(): array {
    if (!file_exists(EVENTS_FILE)) return [];
    $raw = (string)file_get_contents(EVENTS_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Speichert die Event-Liste */
function kgv_events_save(array $events): bool {
    if (!is_dir(dirname(EVENTS_FILE))) {
        mkdir(dirname(EVENTS_FILE), 0755, true);
    }
    return (bool)file_put_contents(EVENTS_FILE,
        json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/** Findet ein Event by slug (case-insensitive) */
function kgv_event_by_slug(string $slug): ?array {
    $slug = mb_strtolower(trim($slug), 'UTF-8');
    foreach (kgv_events_all() as $e) {
        if (mb_strtolower((string)($e['slug'] ?? ''), 'UTF-8') === $slug) return $e;
    }
    return null;
}

/** Findet Event-Index by id (für update) */
function kgv_event_index_by_id(array $events, string $id): int {
    foreach ($events as $i => $e) {
        if (($e['id'] ?? '') === $id) return $i;
    }
    return -1;
}

/** Frist abgelaufen? (deadline YYYY-MM-DD; alles ≤ heute ist noch gültig, ab morgen abgelaufen) */
function kgv_event_deadline_passed(array $event): bool {
    $deadline = trim((string)($event['deadline'] ?? ''));
    if ($deadline === '') return false; // keine Frist gesetzt
    try {
        $tz = new DateTimeZone('Europe/Berlin');
        $d  = DateTimeImmutable::createFromFormat('Y-m-d', $deadline, $tz);
        if (!$d || $d->format('Y-m-d') !== $deadline) return false;
        $today = new DateTimeImmutable('today', $tz);
        return $d < $today;
    } catch (\Throwable $e) { return false; }
}

/** Ist die Anmelde-Seite öffentlich abrufbar? */
function kgv_event_is_active(array $event): bool {
    return !empty($event['active']) && !kgv_event_deadline_passed($event);
}

/** Summe aller (nicht stornierten) Personen */
function kgv_event_total_guests(array $event): int {
    $sum = 0;
    foreach (($event['registrations'] ?? []) as $r) {
        if (!empty($r['cancelled_at'])) continue;
        $sum += max(1, (int)($r['guests'] ?? 1));
    }
    return $sum;
}

/** Anzahl aktiver Anmeldungen */
function kgv_event_active_count(array $event): int {
    $n = 0;
    foreach (($event['registrations'] ?? []) as $r) {
        if (!empty($r['cancelled_at'])) continue;
        $n++;
    }
    return $n;
}

/** Rate-Limit für Event-Anmeldungen pro IP — max 3 in 60 min */
function kgv_event_rate_check(string $eventId, string $ip): bool {
    $rlFile = dirname(__DIR__) . '/data/rl_ev_' . md5($eventId . '|' . $ip) . '.json';
    $now = time();
    $hits = [];
    if (file_exists($rlFile)) {
        $d = json_decode((string)file_get_contents($rlFile), true);
        if (is_array($d)) $hits = array_values(array_filter($d, fn($t) => is_int($t) && ($now - $t) < 3600));
    }
    if (count($hits) >= 3) return false;
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode($hits), LOCK_EX);
    return true;
}
