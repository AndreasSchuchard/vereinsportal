<?php
declare(strict_types=1);

/** @return array{data: array<mixed>, removed: int} */
function kgv_cleanup_json_file(string $path, callable $transform, bool $dryRun): array {
    if (!is_file($path)) {
        return ['data' => [], 'removed' => 0];
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open privacy cleanup file: {$path}");
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("Unable to lock privacy cleanup file: {$path}");
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false) {
            throw new RuntimeException("Unable to read privacy cleanup file: {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException("Invalid JSON in privacy cleanup file: {$path}", 0, $e);
        }

        if (!is_array($decoded)) {
            throw new UnexpectedValueException("Unexpected JSON structure in privacy cleanup file: {$path}");
        }

        $result = $transform($decoded);
        $updated = $result['data'] ?? $decoded;
        $removed = (int)($result['removed'] ?? 0);

        if (!$dryRun && $removed > 0) {
            try {
                $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("Unable to encode privacy cleanup file: {$path}", 0, $e);
            }

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new RuntimeException("Unable to write privacy cleanup file: {$path}");
            }
        }

        flock($handle, LOCK_UN);
        return ['data' => $updated, 'removed' => $removed];
    } finally {
        fclose($handle);
    }
}

/**
 * Apply the published retention rules at most once per day.
 *
 * @return array<string, int>
 */
function kgv_run_privacy_cleanup(string $dataDir, bool $dryRun = false, bool $force = false): array {
    $marker = rtrim($dataDir, '/') . '/.privacy-cleanup-last';
    $now = time();
    if (!$dryRun && !$force && is_file($marker) && ($now - (int)filemtime($marker)) < 82_800) {
        return ['skipped' => 1];
    }

    $summary = [
        'rate_limit_files' => 0,
        'expired_reset_tokens' => 0,
        'completed_contacts' => 0,
        'expired_or_rejected_bookings' => 0,
        'rejected_member_requests' => 0,
        'past_event_registrations' => 0,
    ];

    $rateCutoff = $now - 86_400;
    foreach (glob(rtrim($dataDir, '/') . '/rl_*.json') ?: [] as $rateFile) {
        $mtime = filemtime($rateFile);
        if ($mtime === false || $mtime >= $rateCutoff) {
            continue;
        }
        $summary['rate_limit_files']++;
        if (!$dryRun && !unlink($rateFile)) {
            throw new RuntimeException("Unable to remove expired rate-limit file: {$rateFile}");
        }
    }

    $membersResult = kgv_cleanup_json_file(rtrim($dataDir, '/') . '/members.json',
        static function (array $members) use ($now): array {
            $removed = 0;
            foreach ($members as &$member) {
                $expires = (int)($member['reset_token_expires'] ?? 0);
                if ($expires > 0 && $expires < $now) {
                    $member['reset_token'] = '';
                    $member['reset_token_expires'] = 0;
                    $removed++;
                }
            }
            unset($member);
            return ['data' => $members, 'removed' => $removed];
        }, $dryRun);
    $summary['expired_reset_tokens'] = $membersResult['removed'];

    $sixMonthsAgo = $now - (180 * 86_400);
    $contactResult = kgv_cleanup_json_file(rtrim($dataDir, '/') . '/contacts.json',
        static function (array $contacts) use ($sixMonthsAgo): array {
            $kept = array_values(array_filter($contacts, static function (array $contact) use ($sixMonthsAgo): bool {
                if (($contact['status'] ?? '') !== 'replied') {
                    return true;
                }
                $created = strtotime((string)($contact['date'] ?? ''));
                return $created === false || $created >= $sixMonthsAgo;
            }));
            return ['data' => $kept, 'removed' => count($contacts) - count($kept)];
        }, $dryRun);
    $summary['completed_contacts'] = $contactResult['removed'];

    $bookingResult = kgv_cleanup_json_file(rtrim($dataDir, '/') . '/bookings.json',
        static function (array $bookings) use ($sixMonthsAgo): array {
            $kept = array_values(array_filter($bookings, static function (array $booking) use ($sixMonthsAgo): bool {
                if (!in_array(($booking['status'] ?? ''), ['expired', 'rejected'], true)) {
                    return true;
                }
                $created = strtotime((string)($booking['created_at'] ?? ''));
                return $created === false || $created >= $sixMonthsAgo;
            }));
            return ['data' => $kept, 'removed' => count($bookings) - count($kept)];
        }, $dryRun);
    $summary['expired_or_rejected_bookings'] = $bookingResult['removed'];

    $threeMonthsAgo = $now - (90 * 86_400);
    $requestResult = kgv_cleanup_json_file(rtrim($dataDir, '/') . '/member_requests.json',
        static function (array $requests) use ($threeMonthsAgo): array {
            $kept = array_values(array_filter($requests, static function (array $request) use ($threeMonthsAgo): bool {
                if (($request['status'] ?? '') !== 'rejected') {
                    return true;
                }
                $created = strtotime((string)($request['created_at'] ?? ''));
                return $created === false || $created >= $threeMonthsAgo;
            }));
            return ['data' => $kept, 'removed' => count($requests) - count($kept)];
        }, $dryRun);
    $summary['rejected_member_requests'] = $requestResult['removed'];

    $eventResult = kgv_cleanup_json_file(rtrim($dataDir, '/') . '/events.json',
        static function (array $events) use ($threeMonthsAgo): array {
            $removed = 0;
            foreach ($events as &$event) {
                $eventDate = strtotime((string)($event['event_date'] ?? ''));
                if ($eventDate !== false && $eventDate < $threeMonthsAgo && !empty($event['registrations'])) {
                    $removed += count($event['registrations']);
                    $event['registrations'] = [];
                }
            }
            unset($event);
            return ['data' => $events, 'removed' => $removed];
        }, $dryRun);
    $summary['past_event_registrations'] = $eventResult['removed'];

    if (!$dryRun && file_put_contents($marker, date(DATE_ATOM), LOCK_EX) === false) {
        throw new RuntimeException("Unable to update privacy cleanup marker: {$marker}");
    }

    return $summary;
}

