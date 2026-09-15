<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_loader.php';


require_once __DIR__ . '/schriftfuehrung.php';

/* ───────────────────────────────────────────────────────────────────────── */
/*  Haupt-Render-Funktion: rendert den Schriftführung-Tab mit Sub-Tabs       */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render(string $csrf, string $sub = 'inbox'): void {
    $valid = ['inbox','birthdays','letters','newsletter','protocols','beschluesse','notes','correspondence','documents','druckstudio','canva','applications','stats','cards','settings','schaeden'];
    $sub = in_array($sub, $valid, true) ? $sub : 'inbox';
    $counts = sf_inbox_counts();
    $appsOpen = 0;
    foreach (sf_load_json(SF_APPLICATIONS) as $a) {
        if (in_array($a['status'] ?? '', ['eingegangen', 'offen'], true)) $appsOpen++;
    }
    $schadenOpen = 0;
    foreach (sf_load_json(SF_SCHAEDEN) as $s) {
        if (($s['status'] ?? '') === 'gemeldet') $schadenOpen++;
    }
    ?>
    <div style="background:linear-gradient(135deg,#7e57c2,#5e35b1);border-radius:14px;padding:24px 28px;margin-bottom:18px;color:#fff;box-shadow:0 4px 18px rgba(0,0,0,0.08)">
      <h2 style="margin:0 0 6px;font-size:1.4rem">📋 Schriftführung-Cockpit</h2>
      <p style="margin:0;font-size:0.92rem;opacity:0.9">Sandras Werkzeugkasten — 15 Module für alle Aspekte der Schriftführung.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:4px;background:#fff;padding:5px;border-radius:12px;margin-bottom:18px;border:1px solid #d4e6c3">
      <?php
      $tabs = [
        'inbox'          => ['📥', 'Inbox', array_sum(array_intersect_key($counts, array_flip(['birthdays','anniversaries','contacts','bookings_pending','reminders_due']))) + $appsOpen + $schadenOpen],
        'applications'   => ['📩', 'Anträge', $appsOpen],
        'schaeden'       => ['🔧', 'Schäden', $schadenOpen],
        'birthdays'      => ['🎂', 'Geburtstage', $counts['birthdays'] + $counts['anniversaries']],
        'letters'        => ['📝', 'Briefe', null],
        'newsletter'     => ['📨', 'Rundbrief', null],
        'protocols'      => ['📜', 'Protokolle', count(sf_load_json(SF_PROTOCOLS))],
        'beschluesse'    => ['🔍', 'Beschlüsse', count(sf_all_beschluesse())],
        'notes'          => ['🗒', 'Notizen', $counts['reminders_due']],
        'correspondence' => ['👥', 'Korrespondenz', null],
        'documents'      => ['📚', 'Dokumente', null],
        'druckstudio'    => ['🖨', 'Druck-Studio', null],
        'cards'          => ['💌', 'Karten-Studio', null],
        'canva'          => ['🎨', 'Canva', null],
        'stats'          => ['📊', 'Statistik', null],
        'settings'       => ['⚙', 'Einstellungen', null],
      ];
      foreach ($tabs as $key => [$icon, $label, $badge]):
        $active = ($key === $sub);
      ?>
        <a href="?tab=schriftfuehrung&sub=<?= $key ?>"
           style="text-decoration:none;text-align:center;padding:9px 6px;border-radius:8px;font-size:0.78rem;font-weight:600;color:<?= $active ? '#fff' : '#5a6c5a' ?>;background:<?= $active ? '#5e35b1' : 'transparent' ?>;transition:.15s">
           <?= $icon ?> <?= htmlspecialchars($label) ?>
           <?php if ($badge !== null && $badge > 0): ?>
             <span style="display:inline-block;background:<?= $active ? 'rgba(255,255,255,0.3)' : '#e65100' ?>;color:#fff;font-size:0.7rem;padding:1px 7px;border-radius:10px;margin-left:3px"><?= $badge ?></span>
           <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php
    switch ($sub) {
        case 'inbox':          sf_render_inbox($csrf, $counts);    break;
        case 'birthdays':      sf_render_birthdays($csrf);         break;
        case 'letters':        sf_render_letters($csrf);           break;
        case 'newsletter':     sf_render_newsletter($csrf);        break;
        case 'canva':          sf_render_canva($csrf);             break;
        case 'protocols':      sf_render_protocols($csrf);         break;
        case 'documents':      sf_render_documents($csrf);         break;
        case 'notes':          sf_render_notes($csrf);             break;
        case 'correspondence': sf_render_correspondence($csrf);    break;
        case 'beschluesse':    sf_render_beschluesse($csrf);       break;
        case 'druckstudio':    sf_render_druckstudio($csrf);       break;
        case 'applications':   sf_render_applications($csrf);      break;
        case 'stats':          sf_render_stats($csrf);             break;
        case 'cards':          sf_render_cards($csrf);             break;
        case 'settings':       sf_render_settings($csrf);          break;
        case 'schaeden':       sf_render_schaeden($csrf);          break;
    }
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Inbox                                                             */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_inbox(string $csrf, array $counts): void {
    $bdays  = sf_upcoming_birthdays(14);
    $annivs = sf_upcoming_anniversaries(90);
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:22px">
      <a href="?tab=schriftfuehrung&sub=birthdays" style="background:#fff;border-radius:12px;padding:18px;text-decoration:none;color:inherit;border-left:4px solid #ec407a;box-shadow:0 1px 4px rgba(0,0,0,0.04)">
        <div style="font-size:0.78rem;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Geburtstage 14 Tage</div>
        <div style="font-size:1.8rem;font-weight:700;color:#ec407a;margin:4px 0">🎂 <?= $counts['birthdays'] ?></div>
        <div style="font-size:0.82rem;color:#5a6c5a">anstehend</div>
      </a>
      <a href="?tab=schriftfuehrung&sub=birthdays" style="background:#fff;border-radius:12px;padding:18px;text-decoration:none;color:inherit;border-left:4px solid #f9a825;box-shadow:0 1px 4px rgba(0,0,0,0.04)">
        <div style="font-size:0.78rem;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Ehrungen 90 Tage</div>
        <div style="font-size:1.8rem;font-weight:700;color:#f9a825;margin:4px 0">🏅 <?= $counts['anniversaries'] ?></div>
        <div style="font-size:0.82rem;color:#5a6c5a">Jubiläen</div>
      </a>
      <a href="?tab=contacts" style="background:#fff;border-radius:12px;padding:18px;text-decoration:none;color:inherit;border-left:4px solid #1565c0;box-shadow:0 1px 4px rgba(0,0,0,0.04)">
        <div style="font-size:0.78rem;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Kontaktanfragen</div>
        <div style="font-size:1.8rem;font-weight:700;color:#1565c0;margin:4px 0">📬 <?= $counts['contacts'] ?></div>
        <div style="font-size:0.82rem;color:#5a6c5a">unbearbeitet</div>
      </a>
      <a href="?tab=bookings" style="background:#fff;border-radius:12px;padding:18px;text-decoration:none;color:inherit;border-left:4px solid #e65100;box-shadow:0 1px 4px rgba(0,0,0,0.04)">
        <div style="font-size:0.78rem;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Buchungen</div>
        <div style="font-size:1.8rem;font-weight:700;color:#e65100;margin:4px 0">📋 <?= $counts['bookings_pending'] ?></div>
        <div style="font-size:0.82rem;color:#5a6c5a">offen</div>
      </a>
    </div>

    <?php if (!empty($bdays) || !empty($annivs)): ?>
    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h3 style="margin:0 0 14px;color:#2d3e2d">🎉 Schnellaktionen</h3>
      <?php if (!empty($bdays)): ?>
        <p style="margin:0 0 8px;color:#5a6c5a;font-size:0.9rem">Diese Mitglieder haben demnächst Geburtstag:</p>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px">
          <?php foreach (array_slice($bdays, 0, 5) as $bd): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#fce4ec;border-radius:8px;border-left:3px solid #ec407a">
              <div style="font-size:1.4rem">🎂</div>
              <div style="flex:1">
                <strong><?= htmlspecialchars($bd['name']) ?></strong>
                <span style="color:#8a9a8a;font-size:0.85rem">
                  · <?= (new DateTime($bd['date']))->format('d.m.') ?> · in <?= $bd['days_until'] ?> Tagen
                  <?= $bd['age'] !== null ? ' · wird ' . $bd['age'] : '' ?>
                </span>
              </div>
              <a href="?tab=schriftfuehrung&sub=letters&tpl=tpl_birthday&mid=<?= urlencode($bd['member_id']) ?>" style="background:#ec407a;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600">Glückwunsch →</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($annivs)): ?>
        <p style="margin:14px 0 8px;color:#5a6c5a;font-size:0.9rem">Anstehende Vereinsjubiläen:</p>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach (array_slice($annivs, 0, 5) as $an): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#fff8e1;border-radius:8px;border-left:3px solid #f9a825">
              <div style="font-size:1.4rem">🏅</div>
              <div style="flex:1">
                <strong><?= htmlspecialchars($an['name']) ?></strong>
                <span style="color:#8a9a8a;font-size:0.85rem">
                  · <?= $an['years'] ?> Jahre · <?= (new DateTime($an['date']))->format('d.m.Y') ?> · in <?= $an['days_until'] ?> Tagen
                </span>
              </div>
              <a href="?tab=schriftfuehrung&sub=letters&tpl=tpl_anniversary&mid=<?= urlencode($an['member_id']) ?>&jahre=<?= $an['years'] ?>" style="background:#f9a825;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600">Ehrungs-Brief →</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="background:#f5f0fa;border-radius:12px;padding:18px 22px;border-left:4px solid #5e35b1">
      <h4 style="margin:0 0 8px;color:#5e35b1">💡 Tipp</h4>
      <p style="margin:0;font-size:0.88rem;color:#5a6c5a;line-height:1.6">
        Pflege die <strong>Geburtstage</strong> und <strong>Eintrittsdaten</strong> der Mitglieder im Tab „👥 Mitglieder" — dann zeigt der Radar automatisch was ansteht.
        Du kannst eigene <strong>Briefvorlagen</strong> ergänzen und schöne <strong>Canva-Designs</strong> als Briefkopf in PDFs einsetzen.
      </p>
    </div>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Birthdays & Anniversaries                                         */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_birthdays(string $csrf): void {
    $bdays  = sf_upcoming_birthdays(60);
    $annivs = sf_upcoming_anniversaries(180);
    ?>
    <div style="background:#fff;border-radius:14px;padding:24px;margin-bottom:18px;border:1px solid #d4e6c3">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0;color:#ec407a">🎂 Geburtstage (60 Tage)</h3>
        <span style="font-size:0.85rem;color:#8a9a8a"><?= count($bdays) ?> Treffer</span>
      </div>
      <?php if (empty($bdays)): ?>
        <p style="color:#8a9a8a;font-style:italic">Keine Geburtstage in den nächsten 60 Tagen — oder die Geburtstage sind noch nicht in den Mitgliederdaten gepflegt.</p>
        <p style="color:#8a9a8a;font-size:0.85rem;margin-top:8px">→ Tab „👥 Mitglieder" → bei jedem Mitglied das 🎂 Geburtstag-Feld füllen (Format <code>MM-TT</code> oder <code>YYYY-MM-TT</code>)</p>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
          <?php foreach ($bdays as $bd): ?>
            <div style="background:#fce4ec;border-radius:10px;padding:14px;border-left:4px solid #ec407a">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <strong style="color:#2d3e2d;font-size:1rem"><?= htmlspecialchars($bd['name']) ?></strong>
                <span style="font-size:0.72rem;background:#ec407a;color:#fff;padding:3px 8px;border-radius:10px;font-weight:700">in <?= $bd['days_until'] ?> Tg</span>
              </div>
              <div style="color:#5a6c5a;font-size:0.85rem;margin-bottom:10px">
                📅 <?= (new DateTime($bd['date']))->format('d.m.Y') ?>
                <?= $bd['age'] !== null ? ' · wird ' . $bd['age'] . ' Jahre' : '' ?>
              </div>
              <a href="?tab=schriftfuehrung&sub=letters&tpl=tpl_birthday&mid=<?= urlencode($bd['member_id']) ?>" style="display:inline-block;background:#ec407a;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600">📝 Glückwunsch schreiben</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div style="background:#fff;border-radius:14px;padding:24px;border:1px solid #d4e6c3">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0;color:#f9a825">🏅 Vereinsjubiläen (180 Tage)</h3>
        <span style="font-size:0.85rem;color:#8a9a8a"><?= count($annivs) ?> Treffer</span>
      </div>
      <?php if (empty($annivs)): ?>
        <p style="color:#8a9a8a;font-style:italic">Keine anstehenden Jubiläen — oder die Eintrittsdaten sind noch nicht erfasst.</p>
        <p style="color:#8a9a8a;font-size:0.85rem;margin-top:8px">→ Tab „👥 Mitglieder" → Feld „Mitglied seit" (Format <code>YYYY-MM-TT</code>)</p>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
          <?php foreach ($annivs as $an): ?>
            <div style="background:#fff8e1;border-radius:10px;padding:14px;border-left:4px solid #f9a825">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <strong style="color:#2d3e2d;font-size:1rem"><?= htmlspecialchars($an['name']) ?></strong>
                <span style="font-size:0.72rem;background:#f9a825;color:#fff;padding:3px 8px;border-radius:10px;font-weight:700"><?= $an['years'] ?> Jahre</span>
              </div>
              <div style="color:#5a6c5a;font-size:0.85rem;margin-bottom:10px">
                📅 <?= (new DateTime($an['date']))->format('d.m.Y') ?> · in <?= $an['days_until'] ?> Tagen
              </div>
              <a href="?tab=schriftfuehrung&sub=letters&tpl=tpl_anniversary&mid=<?= urlencode($an['member_id']) ?>&jahre=<?= $an['years'] ?>" style="display:inline-block;background:#f9a825;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600">🏅 Ehrungs-Brief</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Standardbriefe                                                    */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_letters(string $csrf): void {
    $templates  = sf_get_letter_templates();
    $members    = sf_member_list();
    $selectedId = $_GET['tpl'] ?? '';
    $memberId   = $_GET['mid'] ?? '';
    $extraJahre = $_GET['jahre'] ?? '';
    $log        = sf_load_json(SF_LETTERS_LOG);

    $selected = null;
    foreach ($templates as $t) if (($t['id'] ?? '') === $selectedId) { $selected = $t; break; }
    $selectedMember = null;
    foreach ($members as $m) if (($m['id'] ?? '') === $memberId) { $selectedMember = $m; break; }
    ?>
    <div style="display:grid;grid-template-columns:280px 1fr;gap:18px;align-items:start">

      <div style="background:#fff;border-radius:12px;padding:18px;border:1px solid #d4e6c3">
        <h3 style="margin:0 0 14px;color:#2d3e2d;font-size:1rem">📚 Vorlagen</h3>
        <div style="display:flex;flex-direction:column;gap:6px">
          <?php foreach ($templates as $t): ?>
            <a href="?tab=schriftfuehrung&sub=letters&tpl=<?= urlencode($t['id']) ?><?= $memberId ? '&mid=' . urlencode($memberId) : '' ?>"
               style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;text-decoration:none;color:#2d3e2d;background:<?= ($selectedId === $t['id']) ? '#f5f0fa' : 'transparent' ?>;border:1px solid <?= ($selectedId === $t['id']) ? '#5e35b1' : 'transparent' ?>;transition:.15s">
              <span style="font-size:1.2rem"><?= htmlspecialchars($t['icon'] ?? '✉') ?></span>
              <span style="font-size:0.85rem;font-weight:600"><?= htmlspecialchars($t['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e8f0e0">
          <p style="margin:0 0 8px;font-size:0.78rem;color:#8a9a8a;line-height:1.5">Eigene Vorlagen kannst du in der JSON-Datei <code>data/schriftfuehrung/letter_templates.json</code> ergänzen oder hier mit dem Plus-Button anlegen.</p>
        </div>
      </div>

      <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
        <?php if (!$selected): ?>
          <p style="color:#8a9a8a;font-style:italic;text-align:center;padding:40px 20px">← Wähle links eine Vorlage aus.</p>
        <?php else: ?>
          <form method="POST" action="/intern/action.php" target="_blank">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="sf_generate_letter">
            <input type="hidden" name="tpl_id" value="<?= htmlspecialchars($selected['id']) ?>">

            <h3 style="margin:0 0 14px;color:#2d3e2d"><?= htmlspecialchars($selected['icon'] ?? '') ?> <?= htmlspecialchars($selected['title']) ?></h3>

            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Empfänger</label>
            <select name="member_id" required style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:14px;font-size:0.92rem;background:#fff">
              <option value="">-- Mitglied wählen --</option>
              <?php foreach ($members as $m): if (empty($m['active'])) continue; ?>
                <option value="<?= htmlspecialchars($m['id'] ?? '') ?>" <?= ($selectedMember && $m['id'] === $selectedMember['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($m['name'] ?? '') ?><?= !empty($m['parzelle']) ? ' (Parzelle ' . htmlspecialchars($m['parzelle']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Betreff <span style="color:#8a9a8a;font-weight:400">(änderbar)</span></label>
            <input type="text" name="subject" value="<?= htmlspecialchars($selected['subject'] ?? '') ?>"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:14px;font-size:0.92rem;font-family:inherit;box-sizing:border-box">

            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Briefinhalt</label>
            <textarea name="body" rows="14"
                   style="width:100%;padding:12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:6px;font-size:0.9rem;font-family:inherit;line-height:1.6;box-sizing:border-box;resize:vertical"><?= htmlspecialchars($selected['body'] ?? '') ?></textarea>
            <p style="margin:0 0 14px;font-size:0.78rem;color:#8a9a8a">
              Variablen: <code>{name}</code> · <code>{parzelle}</code> · <code>{year}</code> · <code>{datum}</code> · <code>{jahre}</code>
            </p>

            <?php if ($selected['id'] === 'tpl_anniversary'): ?>
              <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Jahre Mitgliedschaft</label>
              <input type="number" name="extra_jahre" value="<?= htmlspecialchars((string)($extraJahre !== '' ? $extraJahre : 25)) ?>" min="1" max="100"
                     style="width:100px;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:14px;font-size:0.92rem">
            <?php endif; ?>

            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button type="submit" name="output" value="pdf"   style="background:#5e35b1;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">📄 PDF erzeugen</button>
              <button type="submit" name="output" value="email" style="background:#3d6b41;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">📧 Per E-Mail senden</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($log)): ?>
      <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;margin-top:18px">
        <h4 style="margin:0 0 12px;color:#2d3e2d">📋 Versand-Verlauf (letzte 10)</h4>
        <div style="display:flex;flex-direction:column;gap:6px">
          <?php foreach (array_slice(array_reverse($log), 0, 10) as $entry): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#f9fbf7;border-radius:6px;font-size:0.82rem">
              <span>
                <strong><?= htmlspecialchars($entry['template_title'] ?? '') ?></strong>
                an <?= htmlspecialchars($entry['member_name'] ?? '') ?>
              </span>
              <span style="color:#8a9a8a"><?= htmlspecialchars($entry['ts'] ?? '') ?> · <?= htmlspecialchars($entry['output'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Canva-Bibliothek                                                  */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_canva(string $csrf): void {
    $files = sf_load_json(SF_CANVA);
    usort($files, fn($a,$b) => strcmp($b['uploaded_at'] ?? '', $a['uploaded_at'] ?? ''));
    $quick = sf_canva_quick_templates();
    ?>
    <div style="background:linear-gradient(135deg,#00c4cc,#9b59b6);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">🎨 Canva-Studio</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Lade deine Canva-Designs als PNG, JPG oder PDF hoch — Briefköpfe, Glückwunsch-Karten, Flyer, Newsletter-Banner.
        Sie stehen in den anderen Modulen zur Verwendung bereit.
      </p>
      <p style="margin:8px 0 0;font-size:0.85rem;opacity:0.85">
        Tipp: In Canva → <em>Datei → Herunterladen → PNG (oder PDF, hochauflösend)</em>.
        <a href="https://www.canva.com/" target="_blank" rel="noopener" style="color:#fff;font-weight:700;text-decoration:underline">Canva öffnen ↗</a>
      </p>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">⚡ Schnellstart-Vorlagen für KGV-Aufgaben</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <?php foreach ($quick as $q): ?>
          <a href="<?= htmlspecialchars($q['url']) ?>" target="_blank" rel="noopener" style="background:#f9fbf7;border-radius:10px;padding:14px;text-decoration:none;color:inherit;border:1px solid #e0ead6;transition:.15s;display:block">
            <div style="font-size:1.8rem;margin-bottom:5px"><?= htmlspecialchars($q['icon']) ?></div>
            <div style="font-weight:700;color:#2d3e2d;font-size:0.92rem;margin-bottom:3px"><?= htmlspecialchars($q['title']) ?></div>
            <div style="color:#5a6c5a;font-size:0.78rem;line-height:1.4"><?= htmlspecialchars($q['desc']) ?></div>
            <div style="margin-top:8px;color:#00c4cc;font-size:0.78rem;font-weight:700">In Canva öffnen ↗</div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">⬆ Design hochladen</h4>
      <form method="POST" action="/intern/action.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_canva_upload">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Titel</label>
            <input type="text" name="title" required maxlength="80" placeholder="z.B. Briefkopf Vereinsausschuss" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Kategorie</label>
            <select name="category" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;background:#fff">
              <option value="briefkopf">Briefkopf</option>
              <option value="glueckwunsch">Glückwunsch-Karte</option>
              <option value="flyer">Flyer</option>
              <option value="banner">Newsletter-Banner</option>
              <option value="ehrung">Ehrungs-Urkunde</option>
              <option value="sonstige">Sonstige</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Datei (PNG, JPG oder PDF · max 10 MB)</label>
          <input type="file" name="canva_file" required accept="image/png,image/jpeg,application/pdf" style="width:100%;padding:7px 0;font-size:0.9rem">
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Notiz <span style="color:#8a9a8a;font-weight:400">(optional)</span></label>
          <input type="text" name="note" maxlength="160" placeholder="Wofür ist das gedacht?" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;box-sizing:border-box">
        </div>
        <button type="submit" style="background:#00c4cc;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">⬆ Hochladen</button>
      </form>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
      <h4 style="margin:0 0 14px;color:#2d3e2d">📁 Deine Designs (<?= count($files) ?>)</h4>
      <?php if (empty($files)): ?>
        <p style="color:#8a9a8a;font-style:italic">Noch keine Designs hochgeladen.</p>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
          <?php foreach ($files as $f): ?>
            <div style="background:#f9fbf7;border-radius:10px;padding:12px;border:1px solid #e0ead6">
              <?php $isImg = in_array(strtolower(pathinfo($f['filename'] ?? '', PATHINFO_EXTENSION)), ['png','jpg','jpeg'], true); ?>
              <div style="width:100%;height:140px;background:#fff;border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:10px;border:1px solid #e0ead6">
                <?php if ($isImg): ?>
                  <img src="/intern/sf-canva-file.php?id=<?= urlencode($f['id'] ?? '') ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
                <?php else: ?>
                  <div style="text-align:center;color:#8a9a8a">
                    <div style="font-size:2.5rem">📄</div>
                    <div style="font-size:0.78rem">PDF</div>
                  </div>
                <?php endif; ?>
              </div>
              <div style="font-size:0.85rem;font-weight:700;color:#2d3e2d;margin-bottom:4px"><?= htmlspecialchars($f['title'] ?? '') ?></div>
              <div style="font-size:0.74rem;color:#8a9a8a;margin-bottom:6px">
                <?= htmlspecialchars($f['category'] ?? '') ?> · <?= htmlspecialchars($f['uploaded_at'] ?? '') ?>
              </div>
              <?php if (!empty($f['note'])): ?>
                <div style="font-size:0.78rem;color:#5a6c5a;font-style:italic;margin-bottom:8px"><?= htmlspecialchars($f['note']) ?></div>
              <?php endif; ?>
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <a href="/intern/sf-canva-file.php?id=<?= urlencode($f['id'] ?? '') ?>&dl=1"
                   style="flex:1;text-align:center;background:#5e35b1;color:#fff;padding:5px 10px;border-radius:5px;text-decoration:none;font-size:0.76rem;font-weight:600">⬇ Download</a>
                <form method="POST" action="/intern/action.php" style="flex:1;margin:0"
                      onsubmit="return confirm('Design \'<?= htmlspecialchars($f['title'] ?? '', ENT_QUOTES) ?>\' wirklich löschen?')">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="sf_canva_delete">
                  <input type="hidden" name="canva_id" value="<?= htmlspecialchars($f['id'] ?? '') ?>">
                  <button type="submit" style="width:100%;background:#c62828;color:#fff;border:none;padding:5px 10px;border-radius:5px;font-size:0.76rem;font-weight:600;cursor:pointer">🗑 Löschen</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
}


/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Newsletter / Rundbrief                                            */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_newsletter(string $csrf): void {
    $newsletters = sf_load_json(SF_NEWSLETTERS);
    usort($newsletters, fn($a,$b) => strcmp($b['sent_at'] ?? '', $a['sent_at'] ?? ''));
    $members = array_values(array_filter(sf_member_list(), fn($m) => !empty($m['active'])));
    $canvaFiles = sf_load_json(SF_CANVA);
    ?>
    <div style="background:linear-gradient(135deg,#1565c0,#0d47a1);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">📨 Rundbrief / Newsletter</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Versende einen Rundbrief an alle Mitglieder. Empfänger werden als BCC eingetragen (DSGVO-konform — keine Adress-Weitergabe).
        Optional kannst du einen Canva-Banner oben einbinden.
      </p>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">✉ Neuer Rundbrief</h4>
      <form method="POST" action="/intern/action.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_newsletter_send">

        <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Empfänger</label>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:6px;background:#f9fbf7;padding:8px 14px;border-radius:8px;border:1px solid #d4e6c3;cursor:pointer">
            <input type="radio" name="audience" value="all" checked> Alle aktiven Mitglieder (<?= count($members) ?>)
          </label>
          <label style="display:flex;align-items:center;gap:6px;background:#f9fbf7;padding:8px 14px;border-radius:8px;border:1px solid #d4e6c3;cursor:pointer">
            <input type="radio" name="audience" value="vorstand"> Nur Vorstand
          </label>
          <label style="display:flex;align-items:center;gap:6px;background:#f9fbf7;padding:8px 14px;border-radius:8px;border:1px solid #d4e6c3;cursor:pointer">
            <input type="radio" name="audience" value="custom"> Manuelle Auswahl unten
          </label>
        </div>

        <details style="margin-bottom:14px">
          <summary style="cursor:pointer;color:#5a6c5a;font-size:0.85rem">Manuelle Empfänger-Auswahl anzeigen</summary>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;max-height:200px;overflow-y:auto;padding:10px;background:#f9fbf7;border-radius:8px">
            <?php foreach ($members as $m): ?>
              <label style="display:flex;align-items:center;gap:5px;background:#fff;padding:5px 10px;border-radius:14px;border:1px solid #d4e6c3;font-size:0.82rem">
                <input type="checkbox" name="recipients[]" value="<?= htmlspecialchars($m['email'] ?? '') ?>">
                <?= htmlspecialchars($m['name'] ?? '') ?>
              </label>
            <?php endforeach; ?>
          </div>
        </details>

        <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Betreff</label>
        <input type="text" name="subject" required maxlength="200" placeholder="z.B. KGV Musterstadt — Aktuelle Vereinsinformationen Mai 2026"
               style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:14px;font-size:0.92rem;box-sizing:border-box">

        <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Canva-Banner (optional, oben in der Mail)</label>
        <select name="banner_canva_id" style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:14px;background:#fff">
          <option value="">— Kein Banner —</option>
          <?php foreach ($canvaFiles as $cf): if (!in_array(strtolower(pathinfo($cf['filename'] ?? '', PATHINFO_EXTENSION)), ['png','jpg','jpeg'], true)) continue; ?>
            <option value="<?= htmlspecialchars($cf['id'] ?? '') ?>"><?= htmlspecialchars($cf['title'] ?? '') ?> (<?= htmlspecialchars($cf['category'] ?? '') ?>)</option>
          <?php endforeach; ?>
        </select>

        <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Anrede</label>
        <input type="text" name="greeting" value="Liebe Mitglieder," maxlength="120"
               style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:14px;font-size:0.92rem;box-sizing:border-box">

        <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Inhalt</label>
        <textarea name="body" rows="14" required placeholder="Schreibe hier den Inhalt deines Rundbriefs…"
                  style="width:100%;padding:12px;border:1.5px solid #d4e6c3;border-radius:8px;margin-bottom:6px;font-size:0.9rem;font-family:inherit;line-height:1.6;box-sizing:border-box;resize:vertical"></textarea>
        <p style="margin:0 0 14px;font-size:0.78rem;color:#8a9a8a">Du kannst HTML verwenden (z.B. &lt;strong&gt;, &lt;br&gt;, &lt;a href="…"&gt;). Zeilenumbrüche werden automatisch übernommen.</p>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit" name="mode" value="preview" formtarget="_blank" style="background:#90a4ae;color:#fff;border:none;padding:11px 22px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">👁 Vorschau</button>
          <button type="submit" name="mode" value="send" onclick="return confirm('Rundbrief wirklich versenden?')" style="background:#1565c0;color:#fff;border:none;padding:11px 22px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">📨 Versenden</button>
        </div>
      </form>
    </div>

    <?php if (!empty($newsletters)): ?>
      <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 12px;color:#2d3e2d">📋 Versendet-Verlauf</h4>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach (array_slice($newsletters, 0, 15) as $n): ?>
            <div style="padding:10px 14px;background:#f9fbf7;border-radius:8px;border-left:3px solid #1565c0">
              <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:5px">
                <strong style="color:#2d3e2d;font-size:0.92rem"><?= htmlspecialchars($n['subject'] ?? '') ?></strong>
                <span style="color:#8a9a8a;font-size:0.78rem"><?= htmlspecialchars($n['sent_at'] ?? '') ?></span>
              </div>
              <div style="color:#5a6c5a;font-size:0.82rem;margin-top:3px">
                an <?= (int)($n['recipient_count'] ?? 0) ?> Empfänger · Audience: <?= htmlspecialchars($n['audience'] ?? '') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Dokumenten-Tresor                                                 */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_documents(string $csrf): void {
    $docs = sf_load_json(SF_DOCUMENTS);
    usort($docs, fn($a,$b) => strcmp($b['uploaded_at'] ?? '', $a['uploaded_at'] ?? ''));
    // Group by category
    $byCat = [];
    foreach ($docs as $d) {
        $cat = $d['category'] ?? 'sonstige';
        $byCat[$cat] = $byCat[$cat] ?? [];
        $byCat[$cat][] = $d;
    }
    ?>
    <div style="background:linear-gradient(135deg,#558b2f,#33691e);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">📚 Dokumenten-Tresor</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Zentraler Ablageort für Satzung, Geschäftsordnung, Vorlagen, alte Protokolle, Verträge — alles griffbereit.
      </p>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">⬆ Dokument hochladen</h4>
      <form method="POST" action="/intern/action.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_document_upload">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Titel</label>
            <input type="text" name="title" required maxlength="100" placeholder="z.B. Satzung 2023" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Kategorie</label>
            <select name="category" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;background:#fff">
              <option value="satzung">Satzung & Statuten</option>
              <option value="geschaeftsordnung">Geschäftsordnung</option>
              <option value="protokoll_archiv">Protokoll-Archiv</option>
              <option value="vertrag">Verträge</option>
              <option value="formular">Formulare & Vorlagen</option>
              <option value="behoerde">Behörden / Anträge</option>
              <option value="sonstige">Sonstige</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Datei (PDF, DOCX, JPG, PNG · max 20 MB)</label>
          <input type="file" name="doc_file" required accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg" style="width:100%;padding:7px 0">
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Beschreibung (optional)</label>
          <input type="text" name="description" maxlength="200" placeholder="Worum geht's?" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;box-sizing:border-box">
        </div>
        <button type="submit" style="background:#558b2f;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">⬆ Hochladen</button>
      </form>
    </div>

    <?php if (empty($docs)): ?>
      <div style="background:#fff;border-radius:12px;padding:50px 22px;border:1px solid #d4e6c3;text-align:center">
        <div style="font-size:3rem;margin-bottom:10px">📚</div>
        <h4 style="margin:0 0 6px;color:#2d3e2d">Noch keine Dokumente</h4>
        <p style="margin:0;color:#8a9a8a;font-size:0.9rem">Lade Satzung, Geschäftsordnung und andere wichtige Vereinsdokumente hoch.</p>
      </div>
    <?php else: ?>
      <?php
      $catLabels = [
          'satzung' => '📜 Satzung & Statuten',
          'geschaeftsordnung' => '⚖ Geschäftsordnung',
          'protokoll_archiv' => '📋 Protokoll-Archiv',
          'vertrag' => '📝 Verträge',
          'formular' => '📄 Formulare & Vorlagen',
          'behoerde' => '🏛 Behörden / Anträge',
          'sonstige' => '📁 Sonstige',
      ];
      foreach ($catLabels as $catKey => $catLabel):
        if (empty($byCat[$catKey])) continue;
      ?>
        <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;margin-bottom:14px">
          <h4 style="margin:0 0 12px;color:#2d3e2d"><?= $catLabel ?> <span style="color:#8a9a8a;font-weight:400;font-size:0.85rem">(<?= count($byCat[$catKey]) ?>)</span></h4>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px">
            <?php foreach ($byCat[$catKey] as $d): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f9fbf7;border-radius:8px;border:1px solid #e0ead6">
                <div style="font-size:1.8rem">📄</div>
                <div style="flex:1;min-width:0">
                  <strong style="display:block;color:#2d3e2d;font-size:0.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($d['title'] ?? '') ?></strong>
                  <div style="color:#8a9a8a;font-size:0.74rem"><?= htmlspecialchars($d['uploaded_at'] ?? '') ?></div>
                  <?php if (!empty($d['description'])): ?>
                    <div style="color:#5a6c5a;font-size:0.78rem;margin-top:2px"><?= htmlspecialchars($d['description']) ?></div>
                  <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:5px">
                  <a href="/intern/sf-doc-file.php?id=<?= urlencode($d['id'] ?? '') ?>&dl=1" style="background:#558b2f;color:#fff;padding:5px 10px;border-radius:5px;text-decoration:none;font-size:0.74rem;font-weight:600;text-align:center">⬇</a>
                  <form method="POST" action="/intern/action.php" style="margin:0" onsubmit="return confirm('Dokument wirklich löschen?')">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="sf_document_delete">
                    <input type="hidden" name="doc_id" value="<?= htmlspecialchars($d['id'] ?? '') ?>">
                    <button type="submit" style="width:100%;background:#c62828;color:#fff;border:none;padding:5px 10px;border-radius:5px;font-size:0.74rem;font-weight:600;cursor:pointer">🗑</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Notizen & Wiedervorlage                                           */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_notes(string $csrf): void {
    $notes = sf_load_json(SF_NOTES);
    $today = date('Y-m-d');
    $due  = array_filter($notes, fn($n) => empty($n['done']) && !empty($n['reminder']) && $n['reminder'] <= $today);
    $future = array_filter($notes, fn($n) => empty($n['done']) && !empty($n['reminder']) && $n['reminder'] > $today);
    $undated = array_filter($notes, fn($n) => empty($n['done']) && empty($n['reminder']));
    $done  = array_filter($notes, fn($n) => !empty($n['done']));

    usort($due,    fn($a,$b) => strcmp($a['reminder'] ?? '', $b['reminder'] ?? ''));
    usort($future, fn($a,$b) => strcmp($a['reminder'] ?? '', $b['reminder'] ?? ''));
    usort($undated,fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    usort($done,   fn($a,$b) => strcmp($b['done_at']   ?? '', $a['done_at']   ?? ''));
    ?>
    <div style="background:linear-gradient(135deg,#f57c00,#e65100);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">🗒 Notizen & Wiedervorlage</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Schnellnotizen mit optionalem Wiedervorlage-Datum. Perfekt für „Bei Karin am 15. nochmal nachfragen" oder „Mahnung verschicken am 1.7.".
      </p>
    </div>

    <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <form method="POST" action="/intern/action.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_note_create">
        <div style="display:grid;grid-template-columns:1fr 160px auto;gap:10px;align-items:end">
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Notiz</label>
            <input type="text" name="text" required maxlength="300" placeholder="Worüber willst du dich erinnern lassen?"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Wiedervorlage (optional)</label>
            <input type="date" name="reminder" style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
          </div>
          <button type="submit" style="background:#e65100;color:#fff;border:none;padding:10px 18px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer;white-space:nowrap">➕ Anlegen</button>
        </div>
      </form>
    </div>

    <?php
    $sections = [
        ['🔥 Heute / überfällig', $due,     '#c62828', '#ffebee'],
        ['📅 Geplant',            $future,  '#1565c0', '#e3f2fd'],
        ['🗒 Ohne Termin',         $undated, '#616161', '#f5f5f5'],
    ];
    foreach ($sections as [$label, $list, $col, $bg]):
      if (empty($list)) continue;
    ?>
      <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;margin-bottom:14px">
        <h4 style="margin:0 0 12px;color:<?= $col ?>"><?= $label ?> (<?= count($list) ?>)</h4>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ($list as $n): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:<?= $bg ?>;border-radius:8px;border-left:3px solid <?= $col ?>">
              <div style="flex:1">
                <div style="color:#2d3e2d;font-size:0.92rem;line-height:1.4"><?= htmlspecialchars($n['text'] ?? '') ?></div>
                <?php if (!empty($n['reminder'])): ?>
                  <div style="color:#8a9a8a;font-size:0.76rem;margin-top:2px">📅 <?= htmlspecialchars($n['reminder']) ?></div>
                <?php endif; ?>
              </div>
              <form method="POST" action="/intern/action.php" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="sf_note_toggle">
                <input type="hidden" name="note_id" value="<?= htmlspecialchars($n['id'] ?? '') ?>">
                <button type="submit" style="background:#3d6b41;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:0.78rem;font-weight:600;cursor:pointer">✓ Erledigt</button>
              </form>
              <form method="POST" action="/intern/action.php" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="sf_note_delete">
                <input type="hidden" name="note_id" value="<?= htmlspecialchars($n['id'] ?? '') ?>">
                <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;padding:6px 12px;border-radius:6px;font-size:0.78rem;font-weight:600;cursor:pointer">🗑</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($done)): ?>
      <details>
        <summary style="cursor:pointer;color:#8a9a8a;font-size:0.88rem;padding:10px;background:#f5f5f5;border-radius:8px">✓ Erledigte Notizen (<?= count($done) ?>)</summary>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px">
          <?php foreach (array_slice($done, 0, 30) as $n): ?>
            <div style="padding:8px 14px;background:#f9fbf7;border-radius:6px;opacity:0.7;font-size:0.85rem">
              <span style="text-decoration:line-through"><?= htmlspecialchars($n['text'] ?? '') ?></span>
              <span style="color:#8a9a8a;font-size:0.76rem"> · ✓ <?= htmlspecialchars($n['done_at'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Korrespondenz pro Mitglied                                        */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_correspondence(string $csrf): void {
    $members = array_values(array_filter(sf_member_list(), fn($m) => !empty($m['active'])));
    $selectedId = $_GET['mid'] ?? '';
    $selected   = null;
    foreach ($members as $m) if (($m['id'] ?? '') === $selectedId) { $selected = $m; break; }

    $history = [];
    if ($selected) {
        $history = sf_member_correspondence(
            (string)($selected['id'] ?? ''),
            (string)($selected['email'] ?? ''),
            (string)($selected['name'] ?? '')
        );
    }
    ?>
    <div style="background:linear-gradient(135deg,#37474f,#263238);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">👥 Korrespondenz pro Mitglied</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Alle Briefe, Mails, Kontaktanfragen und Buchungen zu einem Mitglied auf einer Seite — perfekt vor einem Telefonat oder einer Antwort.
      </p>
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:18px;align-items:start">
      <div style="background:#fff;border-radius:12px;padding:14px;border:1px solid #d4e6c3;max-height:600px;overflow-y:auto">
        <h4 style="margin:0 0 10px;color:#2d3e2d;font-size:0.95rem">Mitglied wählen</h4>
        <input type="text" id="sf-corresp-filter" placeholder="🔍 Filter…" oninput="sfFilterMembers(this.value)"
               style="width:100%;padding:8px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.86rem;box-sizing:border-box;margin-bottom:10px">
        <div style="display:flex;flex-direction:column;gap:3px">
          <?php foreach ($members as $m): ?>
            <a href="?tab=schriftfuehrung&sub=correspondence&mid=<?= urlencode($m['id'] ?? '') ?>"
               data-name="<?= htmlspecialchars(strtolower($m['name'] ?? '')) ?>"
               class="sf-corresp-member"
               style="display:flex;align-items:center;gap:8px;padding:7px 10px;text-decoration:none;color:<?= ($selectedId === ($m['id'] ?? '')) ? '#fff' : '#2d3e2d' ?>;background:<?= ($selectedId === ($m['id'] ?? '')) ? '#37474f' : 'transparent' ?>;border-radius:6px;font-size:0.85rem">
              <?= htmlspecialchars($m['name'] ?? '') ?>
              <?php if (!empty($m['parzelle'])): ?>
                <span style="color:<?= ($selectedId === ($m['id'] ?? '')) ? '#b0bec5' : '#8a9a8a' ?>;font-size:0.74rem">· P.<?= htmlspecialchars((string)$m['parzelle']) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <?php if (!$selected): ?>
          <div style="background:#fff;border-radius:12px;padding:60px 22px;border:1px solid #d4e6c3;text-align:center">
            <div style="font-size:3rem;margin-bottom:14px">👈</div>
            <h4 style="margin:0 0 6px;color:#2d3e2d">Wähle links ein Mitglied</h4>
            <p style="margin:0;color:#8a9a8a;font-size:0.9rem">Du siehst dann den vollständigen Korrespondenz-Verlauf.</p>
          </div>
        <?php else: ?>
          <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px">
              <div>
                <h3 style="margin:0 0 4px;color:#2d3e2d"><?= htmlspecialchars($selected['name'] ?? '') ?></h3>
                <div style="font-size:0.85rem;color:#5a6c5a;line-height:1.6">
                  <?php if (!empty($selected['parzelle'])): ?>📍 Parzelle <?= htmlspecialchars((string)$selected['parzelle']) ?> · <?php endif; ?>
                  <?php if (!empty($selected['email'])): ?>✉ <?= htmlspecialchars($selected['email']) ?> · <?php endif; ?>
                  <?php if (!empty($selected['phone'])): ?>📞 <?= htmlspecialchars($selected['phone']) ?><?php endif; ?>
                </div>
                <?php if (!empty($selected['geburtstag']) || !empty($selected['member_since'])): ?>
                  <div style="font-size:0.78rem;color:#8a9a8a;margin-top:4px">
                    <?php if (!empty($selected['geburtstag'])): ?>🎂 <?= htmlspecialchars($selected['geburtstag']) ?><?php endif; ?>
                    <?php if (!empty($selected['member_since'])): ?> · 🏅 Mitglied seit <?= htmlspecialchars($selected['member_since']) ?><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <a href="?tab=schriftfuehrung&sub=letters&mid=<?= urlencode($selected['id'] ?? '') ?>" style="background:#5e35b1;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600;white-space:nowrap">📝 Brief schreiben</a>
            </div>
          </div>

          <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
            <h4 style="margin:0 0 14px;color:#2d3e2d">📜 Verlauf (<?= count($history) ?>)</h4>
            <?php if (empty($history)): ?>
              <p style="color:#8a9a8a;font-style:italic">Noch keine Interaktionen erfasst.</p>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:8px">
                <?php
                $colorMap = [
                  'letter'     => '#5e35b1',
                  'newsletter' => '#1565c0',
                  'contact_in' => '#0277bd',
                  'booking'    => '#e65100',
                ];
                foreach ($history as $h): $col = $colorMap[$h['type']] ?? '#616161'; ?>
                  <div style="display:flex;gap:12px;padding:10px 14px;background:#f9fbf7;border-radius:8px;border-left:3px solid <?= $col ?>">
                    <div style="font-size:1.4rem"><?= $h['icon'] ?></div>
                    <div style="flex:1;min-width:0">
                      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:3px">
                        <strong style="color:#2d3e2d;font-size:0.9rem"><?= htmlspecialchars($h['title']) ?></strong>
                        <span style="color:#8a9a8a;font-size:0.78rem"><?= htmlspecialchars($h['when']) ?></span>
                      </div>
                      <?php if (!empty($h['detail'])): ?>
                        <div style="color:#5a6c5a;font-size:0.82rem;line-height:1.4"><?= htmlspecialchars($h['detail']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <script>
    function sfFilterMembers(q) {
      q = q.toLowerCase();
      document.querySelectorAll('.sf-corresp-member').forEach(a => {
        const name = a.getAttribute('data-name') || '';
        a.style.display = (q === '' || name.includes(q)) ? '' : 'none';
      });
    }
    </script>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Beschluss-Datenbank                                               */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_beschluesse(string $csrf): void {
    $filter = trim((string)($_GET['q'] ?? ''));
    $list   = sf_all_beschluesse($filter);
    $total  = count(sf_all_beschluesse());
    ?>
    <div style="background:linear-gradient(135deg,#ad1457,#880e4f);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">🔍 Beschluss-Datenbank</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Alle Beschlüsse aus allen Sitzungsprotokollen — durchsuchbar nach Stichwort.
        Hilfreich bei Streitfällen oder wenn jemand fragt: „Wann haben wir das nochmal entschieden?"
      </p>
    </div>

    <form method="GET" style="background:#fff;border-radius:12px;padding:16px 22px;border:1px solid #d4e6c3;margin-bottom:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="tab" value="schriftfuehrung">
      <input type="hidden" name="sub" value="beschluesse">
      <input type="text" name="q" value="<?= htmlspecialchars($filter) ?>" placeholder="🔎 Volltext-Suche in Beschlüssen, TOPs, Notizen…"
             style="flex:1;min-width:200px;padding:10px 14px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem">
      <button type="submit" style="background:#ad1457;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer">Suchen</button>
      <?php if ($filter !== ''): ?>
        <a href="?tab=schriftfuehrung&sub=beschluesse" style="background:#fff;color:#5a6c5a;border:1px solid #d4e6c3;padding:9px 18px;border-radius:8px;text-decoration:none;font-size:0.88rem">✕ Zurücksetzen</a>
      <?php endif; ?>
    </form>

    <?php if (empty($list)): ?>
      <div style="background:#fff;border-radius:12px;padding:50px 22px;border:1px solid #d4e6c3;text-align:center">
        <div style="font-size:3rem;margin-bottom:10px">📭</div>
        <h4 style="margin:0 0 6px;color:#2d3e2d">
          <?= $filter === '' ? 'Noch keine Beschlüsse erfasst' : 'Keine Treffer für „' . htmlspecialchars($filter) . '"' ?>
        </h4>
        <p style="margin:0;color:#8a9a8a;font-size:0.9rem">
          <?= $filter === '' ? 'Beschlüsse erscheinen hier automatisch, sobald du Protokolle mit gefülltem Beschluss-Feld anlegst.' : 'Insgesamt sind ' . $total . ' Beschlüsse erfasst.' ?>
        </p>
      </div>
    <?php else: ?>
      <p style="color:#5a6c5a;font-size:0.85rem;margin:0 0 14px">
        <strong><?= count($list) ?></strong> Treffer<?= $filter !== '' ? ' für „' . htmlspecialchars($filter) . '"' : '' ?>
        <?= count($list) !== $total ? ' (von ' . $total . ' insgesamt)' : '' ?>
      </p>

      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($list as $b): ?>
          <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;border-left:4px solid #ad1457">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
              <div>
                <strong style="color:#ad1457;font-size:0.88rem"><?= htmlspecialchars($b['top_label']) ?>: <?= htmlspecialchars($b['top_title']) ?></strong>
                <div style="color:#8a9a8a;font-size:0.78rem;margin-top:2px">
                  📅 <?= htmlspecialchars($b['protocol_date']) ?>
                  · <?= htmlspecialchars($b['protocol_type']) ?>
                  · <a href="?tab=schriftfuehrung&sub=protocols&pid=<?= urlencode($b['protocol_id']) ?>" style="color:#5e35b1;text-decoration:none">→ <?= htmlspecialchars($b['protocol_title']) ?></a>
                </div>
              </div>
              <?php if ($b['ja'] !== null || $b['nein'] !== null || $b['enth'] !== null): ?>
                <div style="background:#f5f0fa;color:#5e35b1;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:700;white-space:nowrap">
                  ✅ <?= (int)$b['ja'] ?> · ❌ <?= (int)$b['nein'] ?> · ➖ <?= (int)$b['enth'] ?>
                </div>
              <?php endif; ?>
            </div>
            <div style="background:#fff8e1;border-radius:8px;padding:12px 14px;color:#2d3e2d;line-height:1.55;font-size:0.92rem">
              <?= nl2br(htmlspecialchars($b['beschluss'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Druck-Studio (Urkunden + Adressetiketten + Aushänge)              */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_druckstudio(string $csrf): void {
    $members = array_values(array_filter(sf_member_list(), fn($m) => !empty($m['active'])));
    $tool = $_GET['tool'] ?? 'urkunde';
    ?>
    <div style="background:linear-gradient(135deg,#00838f,#006064);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">🖨 Druck-Studio</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Drei PDF-Generatoren für deinen Drucker: Ehrungs-Urkunden im A4-Querformat, Adressetiketten für Postversand, und Aushänge für die Vereinspinnwand.
      </p>
    </div>

    <div style="display:flex;gap:8px;background:#fff;padding:5px;border-radius:10px;margin-bottom:18px;border:1px solid #d4e6c3;flex-wrap:wrap">
      <?php
      $tools = [
        'urkunde' => ['🏅', 'Ehrungs-Urkunde'],
        'labels'  => ['📮', 'Adressetiketten'],
        'aushang' => ['📌', 'Aushang'],
      ];
      foreach ($tools as $k => [$ic, $lab]):
        $act = ($k === $tool);
      ?>
        <a href="?tab=schriftfuehrung&sub=druckstudio&tool=<?= $k ?>"
           style="flex:1;min-width:130px;text-align:center;padding:10px;text-decoration:none;border-radius:7px;font-size:0.9rem;font-weight:600;color:<?= $act ? '#fff' : '#5a6c5a' ?>;background:<?= $act ? '#00838f' : 'transparent' ?>">
           <?= $ic ?> <?= htmlspecialchars($lab) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($tool === 'urkunde'): ?>
      <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 14px;color:#2d3e2d">🏅 Ehrungs-Urkunde generieren</h4>
        <form method="POST" action="/intern/action.php" target="_blank">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="sf_print_urkunde">

          <div style="display:grid;grid-template-columns:1fr 120px;gap:12px;margin-bottom:14px">
            <div>
              <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Mitglied</label>
              <select name="member_id" required style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;background:#fff">
                <option value="">-- wählen --</option>
                <?php foreach ($members as $m): ?>
                  <option value="<?= htmlspecialchars($m['id'] ?? '') ?>"><?= htmlspecialchars($m['name'] ?? '') ?><?= !empty($m['parzelle']) ? ' (Parzelle ' . htmlspecialchars($m['parzelle']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Jahre</label>
              <input type="number" name="jahre" value="25" min="1" max="100" required style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
            </div>
          </div>

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Datum (auf der Urkunde)</label>
          <input type="text" name="datum" placeholder="z.B. 12. Juli 2026" maxlength="40" style="width:240px;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;margin-bottom:14px;box-sizing:border-box">

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Eigener Text <span style="color:#8a9a8a;font-weight:400">(optional, überschreibt den Standardtext)</span></label>
          <textarea name="text_override" rows="3" placeholder="Leer lassen für Standard: „… diese Urkunde in dankbarer Anerkennung der XX-jährigen Mitgliedschaft …""
                    style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit;box-sizing:border-box;resize:vertical;margin-bottom:14px"></textarea>

          <button type="submit" style="background:#00838f;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">🏅 Urkunde als PDF</button>
        </form>
      </div>

    <?php elseif ($tool === 'labels'): ?>
      <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 6px;color:#2d3e2d">📮 Adressetiketten drucken</h4>
        <p style="margin:0 0 14px;color:#5a6c5a;font-size:0.85rem">3 × 7 = 21 Etiketten pro A4-Seite. Wähle die Empfänger.</p>
        <form method="POST" action="/intern/action.php" target="_blank">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="sf_print_labels">

          <div style="margin-bottom:14px">
            <div style="display:flex;gap:8px;margin-bottom:8px">
              <button type="button" onclick="document.querySelectorAll('.sf-lbl-cb').forEach(c=>c.checked=true)" style="background:#90a4ae;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.8rem;cursor:pointer">Alle</button>
              <button type="button" onclick="document.querySelectorAll('.sf-lbl-cb').forEach(c=>c.checked=false)" style="background:#fff;color:#5a6c5a;border:1px solid #d4e6c3;padding:6px 14px;border-radius:6px;font-size:0.8rem;cursor:pointer">Keine</button>
            </div>
            <div style="max-height:340px;overflow-y:auto;padding:12px;background:#f9fbf7;border-radius:8px;display:flex;flex-wrap:wrap;gap:8px">
              <?php foreach ($members as $m): ?>
                <label style="display:flex;align-items:center;gap:6px;background:#fff;padding:6px 12px;border-radius:14px;border:1px solid #d4e6c3;font-size:0.82rem;cursor:pointer">
                  <input type="checkbox" class="sf-lbl-cb" name="member_ids[]" value="<?= htmlspecialchars($m['id'] ?? '') ?>">
                  <?= htmlspecialchars($m['name'] ?? '') ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <button type="submit" style="background:#00838f;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">📮 Etiketten-PDF generieren</button>
        </form>
      </div>

    <?php else: /* aushang */ ?>
      <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 14px;color:#2d3e2d">📌 Aushang für die Vereinspinnwand</h4>
        <form method="POST" action="/intern/action.php" target="_blank">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="sf_print_aushang">

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Überschrift</label>
          <input type="text" name="title" required maxlength="80" placeholder="z.B. Vereinshaus heute geschlossen"
                 style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;margin-bottom:14px;box-sizing:border-box">

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Text</label>
          <textarea name="body" required rows="8" placeholder="Inhalt des Aushangs, mehrere Absätze möglich…"
                    style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;font-family:inherit;box-sizing:border-box;resize:vertical;margin-bottom:14px"></textarea>

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Akzentfarbe</label>
          <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
            <?php foreach (['#3d6b41'=>'Vereinsgrün', '#c62828'=>'Achtung-Rot', '#1565c0'=>'Info-Blau', '#f57c00'=>'Hinweis-Orange'] as $c => $lab): ?>
              <label style="display:flex;align-items:center;gap:6px;background:#f9fbf7;padding:7px 12px;border-radius:8px;cursor:pointer;border:1px solid #d4e6c3">
                <input type="radio" name="accent" value="<?= $c ?>" <?= $c === '#3d6b41' ? 'checked' : '' ?>>
                <span style="display:inline-block;width:14px;height:14px;background:<?= $c ?>;border-radius:3px"></span>
                <?= $lab ?>
              </label>
            <?php endforeach; ?>
          </div>

          <button type="submit" style="background:#00838f;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">📌 Aushang-PDF</button>
        </form>
      </div>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Antrags-Eingang                                                   */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_applications(string $csrf): void {
    $apps     = sf_load_json(SF_APPLICATIONS);
    $statuses = sf_application_statuses();
    $arten    = sf_application_arten();
    $decisionStatuses = sf_application_decision_statuses();

    // Auto: "eingegangen" -> "offen", sobald Sandra die Liste öffnet (= gelesen)
    $flipped = false;
    foreach ($apps as &$_af) {
        if (($_af['status'] ?? '') === 'eingegangen') {
            $_af['status']    = 'offen';
            $_af['read_at']   = date('Y-m-d H:i:s');
            $_af['history']   = (array)($_af['history'] ?? []);
            $_af['history'][] = ['status' => 'offen', 'at' => date('Y-m-d H:i:s'), 'by' => 'System (gelesen)'];
            $flipped = true;
        }
    }
    unset($_af);
    if ($flipped) sf_save_json(SF_APPLICATIONS, $apps);

    $filter    = $_GET['st']  ?? 'all';
    $artFilter = $_GET['art'] ?? 'all';

    $view = $apps;
    if ($filter !== 'all')    $view = array_values(array_filter($view, fn($a) => ($a['status'] ?? '') === $filter));
    if ($artFilter !== 'all') $view = array_values(array_filter($view, fn($a) => ($a['art'] ?? '') === $artFilter));
    usort($view, fn($a, $b) => strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? ''));

    $base = '?tab=schriftfuehrung&sub=applications';
    ?>
    <div style="background:linear-gradient(135deg,#01579b,#002f6c);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">📩 Antrags-Eingang</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Mitglieder reichen auf der Mitgliederseite Anträge ein (u.a. Bauanträge mit Anhang). Hier laufen sie auf.
        Beim Öffnen dieser Liste werden neue Anträge automatisch auf „Offen (gelesen)" gesetzt.
      </p>
      <p style="margin:8px 0 0;font-size:0.85rem;opacity:0.88">
        Workflow: <strong>Eingegangen</strong> → <strong>Offen</strong> → <strong>In Bearbeitung</strong> → <strong>Genehmigt</strong> / <strong>Abgelehnt</strong> / <strong>Erledigt</strong>
      </p>
    </div>

    <?php if (isset($_GET['sent'])): ?>
      <div style="background:#e8f5e9;border-left:4px solid #2e7d32;border-radius:8px;padding:12px 16px;margin-bottom:14px;color:#1b5e20;font-size:0.88rem">✅ Mitteilung an das Mitglied wurde per E-Mail gesendet.</div>
    <?php elseif (isset($_GET['saved'])): ?>
      <div style="background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;padding:12px 16px;margin-bottom:14px;color:#0d47a1;font-size:0.88rem">💾 Gespeichert.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
      <div style="background:#eceff1;border-left:4px solid #607d8b;border-radius:8px;padding:12px 16px;margin-bottom:14px;color:#37474f;font-size:0.88rem">Antrag gelöscht.</div>
    <?php elseif (($_GET['err'] ?? '') === 'begruendung'): ?>
      <div style="background:#ffebee;border-left:4px solid #c62828;border-radius:8px;padding:12px 16px;margin-bottom:14px;color:#b71c1c;font-size:0.88rem">⚠ Eine Ablehnung braucht eine Begründung im Feld „Mitteilung an das Mitglied", bevor sie gesendet werden kann. Text eingeben, „Mitteilung speichern", dann senden.</div>
    <?php endif; ?>

    <div style="background:#fff;border-radius:12px;padding:14px 18px;border:1px solid #d4e6c3;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <strong style="color:#5a6c5a;font-size:0.85rem">Status:</strong>
      <a href="<?= $base ?>&art=<?= htmlspecialchars($artFilter) ?>&st=all"
         style="padding:5px 12px;border-radius:14px;text-decoration:none;font-size:0.78rem;font-weight:600;background:<?= $filter === 'all' ? '#5e35b1' : '#f5f5f5' ?>;color:<?= $filter === 'all' ? '#fff' : '#5a6c5a' ?>">Alle</a>
      <?php foreach ($statuses as $k => [$col, $lab]): $active = $filter === $k; ?>
        <a href="<?= $base ?>&art=<?= htmlspecialchars($artFilter) ?>&st=<?= $k ?>"
           style="padding:5px 12px;border-radius:14px;text-decoration:none;font-size:0.78rem;font-weight:600;background:<?= $active ? $col : '#f5f5f5' ?>;color:<?= $active ? '#fff' : '#5a6c5a' ?>"><?= htmlspecialchars($lab) ?></a>
      <?php endforeach; ?>
    </div>
    <div style="background:#fff;border-radius:12px;padding:14px 18px;border:1px solid #d4e6c3;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <strong style="color:#5a6c5a;font-size:0.85rem">Art:</strong>
      <a href="<?= $base ?>&st=<?= htmlspecialchars($filter) ?>&art=all"
         style="padding:5px 12px;border-radius:14px;text-decoration:none;font-size:0.78rem;font-weight:600;background:<?= $artFilter === 'all' ? '#5e35b1' : '#f5f5f5' ?>;color:<?= $artFilter === 'all' ? '#fff' : '#5a6c5a' ?>">Alle</a>
      <?php foreach ($arten as $ak => $al): $active = $artFilter === $ak; ?>
        <a href="<?= $base ?>&st=<?= htmlspecialchars($filter) ?>&art=<?= htmlspecialchars($ak) ?>"
           style="padding:5px 12px;border-radius:14px;text-decoration:none;font-size:0.78rem;font-weight:600;background:<?= $active ? '#455a64' : '#f5f5f5' ?>;color:<?= $active ? '#fff' : '#5a6c5a' ?>"><?= htmlspecialchars($al) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($view)): ?>
      <div style="background:#fff;border-radius:12px;padding:60px 22px;border:1px solid #d4e6c3;text-align:center">
        <div style="font-size:3rem;margin-bottom:10px">📭</div>
        <h4 style="margin:0 0 6px;color:#2d3e2d">Keine Anträge für diese Filter</h4>
        <p style="margin:0;color:#8a9a8a;font-size:0.9rem;line-height:1.55">Sobald ein Mitglied über „Mitgliederbereich → Antrag" einen Antrag stellt, erscheint er hier.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($view as $a):
          $st   = (string)($a['status'] ?? 'eingegangen');
          $col  = $statuses[$st][0] ?? '#5a6c5a';
          $lab  = $statuses[$st][1] ?? 'Eingegangen';
          $artL = $arten[$a['art'] ?? ''] ?? 'Antrag';
          $aid  = (string)($a['id'] ?? '');
          $att  = (array)($a['attachments'] ?? []);
          $decF = (array)($a['decision_files'] ?? []);
          $isDecision = in_array($st, $decisionStatuses, true);
          $sentAt = (string)($a['decision_sent_at'] ?? '');
        ?>
          <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;border-left:4px solid <?= $col ?>">
            <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
              <div style="flex:1;min-width:0">
                <span style="display:inline-block;background:#eceff1;color:#455a64;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-bottom:4px"><?= htmlspecialchars($artL) ?></span>
                <div style="font-weight:700;color:#2d3e2d;font-size:1rem"><?= htmlspecialchars($a['title'] ?? '(Ohne Titel)') ?></div>
                <div style="color:#8a9a8a;font-size:0.82rem;margin-top:3px">
                  Von <strong style="color:#5a6c5a"><?= htmlspecialchars($a['from_name'] ?? '?') ?></strong>
                  <?php if (!empty($a['from_parzelle'])): ?> · <strong style="color:#455a64">Parzelle <?= htmlspecialchars((string)$a['from_parzelle']) ?></strong><?php endif; ?>
                  <?php if (!empty($a['from_email'])): ?> · <?= htmlspecialchars($a['from_email']) ?><?php endif; ?>
                  · eingegangen <?= htmlspecialchars($a['submitted_at'] ?? '') ?>
                </div>
              </div>
              <span style="background:<?= $col ?>;color:#fff;padding:4px 12px;border-radius:14px;font-size:0.76rem;font-weight:700;white-space:nowrap"><?= htmlspecialchars($lab) ?></span>
            </div>

            <div style="background:#f9fbf7;border-radius:8px;padding:12px 14px;margin:10px 0;color:#2d3e2d;line-height:1.55;font-size:0.92rem;white-space:pre-wrap"><?= htmlspecialchars($a['message'] ?? '') ?></div>

            <?php if (!empty($att)): ?>
              <div style="margin:8px 0;font-size:0.85rem">
                <strong style="color:#5a6c5a">📎 Anhänge:</strong>
                <?php foreach ($att as $f): ?>
                  <a href="/intern/sf-antrag-file.php?app=<?= urlencode($aid) ?>&id=<?= urlencode((string)($f['id'] ?? '')) ?>" target="_blank" style="display:inline-block;margin:2px 4px;padding:3px 10px;background:#eceff1;border-radius:6px;color:#37474f;text-decoration:none"><?= htmlspecialchars((string)($f['title'] ?? 'Anhang')) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($a['admin_note'])): ?>
              <div style="background:#fff8e1;border-left:3px solid #f9a825;border-radius:6px;padding:10px 14px;margin:8px 0;font-size:0.85rem;color:#5a6c5a">
                <strong style="color:#f57f17">📝 Interne Notiz:</strong> <?= htmlspecialchars($a['admin_note']) ?>
              </div>
            <?php endif; ?>

            <form method="POST" action="/intern/action.php" enctype="multipart/form-data" style="margin-top:10px">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="sf_application_update">
              <input type="hidden" name="app_id" value="<?= htmlspecialchars($aid) ?>">

              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select name="status" style="padding:6px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem">
                  <?php foreach ($statuses as $k => [$_c, $l]): ?>
                    <option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="admin_note" placeholder="Interne Notiz (nur Cockpit)" value="<?= htmlspecialchars($a['admin_note'] ?? '') ?>" style="flex:1;min-width:180px;padding:6px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem">
                <button type="submit" name="op" value="update" style="background:#5e35b1;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer">💾 Speichern</button>
                <button type="submit" name="op" value="delete" onclick="return confirm('Antrag samt Anhängen wirklich löschen?')" style="background:#fff;color:#c62828;border:1px solid #c62828;padding:6px 12px;border-radius:6px;font-size:0.78rem;cursor:pointer">🗑</button>
              </div>

              <?php if ($isDecision): ?>
                <div style="background:#f1f8e9;border:1px solid #c5e1a5;border-radius:8px;padding:12px 14px;margin-top:10px">
                  <div style="font-size:0.8rem;font-weight:700;color:#33691e;margin-bottom:6px">✉ Mitteilung an das Mitglied <span style="font-weight:400;color:#7cb342">(wird NICHT automatisch versendet)</span></div>
                  <textarea name="decision_note" rows="4" placeholder="<?= $st === 'abgelehnt' ? 'Begründung der Ablehnung (Pflicht) …' : 'Text an das Mitglied (optional, z.B. Auflagen) …' ?>" style="width:100%;padding:8px 10px;border:1.5px solid #c5e1a5;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;line-height:1.5"><?= htmlspecialchars((string)($a['decision_note'] ?? '')) ?></textarea>
                  <div style="margin-top:6px">
                    <label style="font-size:0.76rem;color:#5a6c5a">PDF anhängen (z.B. Genehmigung):</label>
                    <input type="file" name="decision_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*" style="font-size:0.8rem">
                  </div>
                  <?php if (!empty($decF)): ?>
                    <div style="margin-top:6px;font-size:0.82rem">
                      <strong style="color:#5a6c5a">Angehängt:</strong>
                      <?php foreach ($decF as $f): ?>
                        <a href="/intern/sf-antrag-file.php?app=<?= urlencode($aid) ?>&id=<?= urlencode((string)($f['id'] ?? '')) ?>" target="_blank" style="display:inline-block;margin:2px 4px;padding:3px 10px;background:#dcedc8;border-radius:6px;color:#33691e;text-decoration:none"><?= htmlspecialchars((string)($f['title'] ?? 'Datei')) ?></a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button type="submit" name="op" value="decision_save" style="background:#7cb342;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer">💾 Mitteilung speichern</button>
                    <button type="submit" name="op" value="decision_send" onclick="return confirm('Mitteilung jetzt per E-Mail an das Mitglied senden?')" style="background:#2e7d32;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;font-weight:700;cursor:pointer">📧 Mitteilung an Mitglied senden</button>
                    <?php if ($sentAt !== ''): ?>
                      <span style="font-size:0.78rem;color:#2e7d32;font-weight:600">✅ gesendet am <?= htmlspecialchars(substr($sentAt, 0, 16)) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php elseif ($sentAt !== ''): ?>
                <div style="margin-top:8px;font-size:0.8rem;color:#c62828">⚠ Es wurde bereits eine Mitteilung an das Mitglied gesendet (<?= htmlspecialchars(substr($sentAt, 0, 16)) ?>). Statuswechsel weg von der Entscheidung ist möglich, das Mitglied wurde aber schon informiert.</div>
              <?php endif; ?>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Statistik-Dashboard                                               */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_stats(string $csrf): void {
    $s = sf_stats_overview();
    ?>
    <div style="background:linear-gradient(135deg,#4527a0,#311b92);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">📊 Vereins-Statistik</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">Live-Übersicht aller Datenpunkte aus Mitgliederliste, Buchungen, Kontaktanfragen, Events, Briefen und Newslettern.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px">
      <?php
      $kpis = [
        ['👥', 'Mitglieder gesamt',  $s['members']['total'],          $s['members']['active'] . ' aktiv · ' . $s['members']['inactive'] . ' inaktiv', '#3d6b41'],
        ['🎂', 'Geburtstage gepflegt', $s['members']['with_birthday'], 'von ' . $s['members']['total'] . ' Mitgliedern',                            '#ec407a'],
        ['🏅', 'Eintrittsdaten gepflegt', $s['members']['with_since'], 'von ' . $s['members']['total'] . ' Mitgliedern',                            '#f9a825'],
        ['🏠', 'Buchungen gesamt', $s['bookings_total'], $s['bookings_status']['confirmed'] . ' bestätigt · ' . $s['bookings_status']['pending'] . ' offen', '#1565c0'],
        ['📨', 'Kontaktanfragen',  $s['contacts_total'], 'kumuliert',                                                                                '#0277bd'],
        ['🎪', 'Events',           $s['events_total'],   $s['event_regs_total'] . ' Anmeldungen insgesamt',                                         '#7e57c2'],
        ['📩', 'Anträge',          $s['applications_total'], (($s['applications_status']['eingegangen'] ?? 0) + ($s['applications_status']['offen'] ?? 0)) . ' offen',                                    '#01579b'],
        ['📬', 'Rundbriefe versendet', $s['newsletters_sent'], 'kumuliert',                                                                          '#558b2f'],
        ['📝', 'Standardbriefe',   $s['letters_sent'], 'kumuliert',                                                                                  '#ad1457'],
      ];
      foreach ($kpis as [$icon, $label, $val, $sub, $col]):
      ?>
        <div style="background:#fff;border-radius:12px;padding:18px;border-left:4px solid <?= $col ?>;box-shadow:0 1px 4px rgba(0,0,0,0.04)">
          <div style="font-size:0.74rem;color:#8a9a8a;text-transform:uppercase;letter-spacing:.05em;font-weight:700"><?= htmlspecialchars($label) ?></div>
          <div style="font-size:2rem;font-weight:700;color:<?= $col ?>;margin:4px 0"><?= $icon ?> <?= $val ?></div>
          <div style="font-size:0.82rem;color:#5a6c5a"><?= htmlspecialchars($sub) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">📈 Aktivität letzte 12 Monate</h4>
      <?php
      // Max-Wert finden für Balken-Skala
      $maxVal = 0;
      foreach ($s['monthly'] as $m) {
        $maxVal = max($maxVal, $m['bookings'] + $m['contacts'] + $m['event_regs'] + $m['letters']);
      }
      $maxVal = max(1, $maxVal);
      ?>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:6px;height:200px;border-bottom:2px solid #d4e6c3;padding:0 5px">
        <?php foreach ($s['monthly'] as $key => $m):
          $total = $m['bookings'] + $m['contacts'] + $m['event_regs'] + $m['letters'];
          $hPct  = ($total / $maxVal) * 100;
        ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
            <div style="width:100%;display:flex;flex-direction:column-reverse;height:<?= max(2, (int)$hPct) ?>%;border-radius:4px 4px 0 0;overflow:hidden" title="<?= htmlspecialchars($key) ?>: <?= $total ?> Events">
              <?php if ($m['bookings'] > 0): ?>
                <div style="background:#1565c0;flex:<?= $m['bookings'] ?>" title="<?= $m['bookings'] ?> Buchungen"></div>
              <?php endif; ?>
              <?php if ($m['contacts'] > 0): ?>
                <div style="background:#0277bd;flex:<?= $m['contacts'] ?>" title="<?= $m['contacts'] ?> Kontaktanfragen"></div>
              <?php endif; ?>
              <?php if ($m['event_regs'] > 0): ?>
                <div style="background:#7e57c2;flex:<?= $m['event_regs'] ?>" title="<?= $m['event_regs'] ?> Event-Anmeldungen"></div>
              <?php endif; ?>
              <?php if ($m['letters'] > 0): ?>
                <div style="background:#ad1457;flex:<?= $m['letters'] ?>" title="<?= $m['letters'] ?> Briefe"></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:space-between;gap:6px;padding:0 5px;margin-top:4px;font-size:0.66rem;color:#8a9a8a">
        <?php foreach (array_keys($s['monthly']) as $key): ?>
          <div style="flex:1;text-align:center;transform:rotate(-45deg);transform-origin:center;margin-top:6px"><?= htmlspecialchars(substr($key, 2)) ?></div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:14px;margin-top:20px;flex-wrap:wrap;justify-content:center;font-size:0.78rem;color:#5a6c5a">
        <span style="display:flex;align-items:center;gap:5px"><span style="width:10px;height:10px;background:#1565c0;border-radius:2px"></span> Buchungen</span>
        <span style="display:flex;align-items:center;gap:5px"><span style="width:10px;height:10px;background:#0277bd;border-radius:2px"></span> Kontaktanfragen</span>
        <span style="display:flex;align-items:center;gap:5px"><span style="width:10px;height:10px;background:#7e57c2;border-radius:2px"></span> Event-Anmeldungen</span>
        <span style="display:flex;align-items:center;gap:5px"><span style="width:10px;height:10px;background:#ad1457;border-radius:2px"></span> Briefe</span>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div style="background:#fff;border-radius:12px;padding:18px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 12px;color:#2d3e2d">🏠 Buchungs-Status</h4>
        <?php foreach ($s['bookings_status'] as $st => $cnt): if ($cnt === 0) continue; $stCol = ['pending'=>'#e65100','confirmed'=>'#2e7d32','rejected'=>'#616161','expired'=>'#8a8a8a'][$st] ?? '#5a6c5a'; ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f4ee;font-size:0.88rem">
            <span style="color:<?= $stCol ?>;font-weight:600">●</span>
            <span style="flex:1;margin-left:8px;color:#2d3e2d;text-transform:capitalize"><?= htmlspecialchars($st) ?></span>
            <strong style="color:<?= $stCol ?>"><?= $cnt ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="background:#fff;border-radius:12px;padding:18px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 12px;color:#2d3e2d">📩 Anträge nach Status</h4>
        <?php foreach (sf_application_statuses() as $st => [$col, $lab]): $cnt = $s['applications_status'][$st] ?? 0; if ($cnt === 0) continue; ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f4ee;font-size:0.88rem">
            <span style="color:<?= $col ?>;font-weight:600">●</span>
            <span style="flex:1;margin-left:8px;color:#2d3e2d"><?= htmlspecialchars($lab) ?></span>
            <strong style="color:<?= $col ?>"><?= $cnt ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Karten-Studio (Canva-Hintergrund + Glückwunschtext → PDF)         */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_cards(string $csrf): void {
    $canva = sf_load_json(SF_CANVA);
    $imageOnly = array_values(array_filter($canva, fn($c) => in_array(strtolower(pathinfo($c['filename'] ?? '', PATHINFO_EXTENSION)), ['png','jpg','jpeg'], true)));
    $members = array_values(array_filter(sf_member_list(), fn($m) => !empty($m['active'])));
    ?>
    <div style="background:linear-gradient(135deg,#d81b60,#ad1457);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">💌 Karten-Studio</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Glückwunsch- und Ehrungskarten mit einem deiner Canva-Hintergründe:
        Canva-Bild + Name + Glückwunschtext → fertige A5-PDF-Karte zum Drucken.
      </p>
    </div>

    <?php if (empty($imageOnly)): ?>
      <div style="background:#fff;border-radius:12px;padding:60px 22px;border:1px solid #d4e6c3;text-align:center">
        <div style="font-size:3rem;margin-bottom:10px">🎨</div>
        <h4 style="margin:0 0 6px;color:#2d3e2d">Keine Canva-Bilder verfügbar</h4>
        <p style="margin:0;color:#8a9a8a;font-size:0.9rem">Lade zuerst im Tab „🎨 Canva" ein PNG oder JPG hoch (z.B. eine schöne Garten-Aufnahme oder Vereins-Grafik) — dann kannst du es hier als Karten-Hintergrund nutzen.</p>
        <p style="margin:14px 0 0"><a href="?tab=schriftfuehrung&sub=canva" style="display:inline-block;background:#d81b60;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem">→ Zum Canva-Studio</a></p>
      </div>
    <?php else: ?>
      <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3">
        <h4 style="margin:0 0 14px;color:#2d3e2d">💌 Karte erstellen</h4>
        <form method="POST" action="/intern/action.php" target="_blank">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="sf_card_print">

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:6px">Hintergrund-Bild aus Canva-Bibliothek</label>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;max-height:300px;overflow-y:auto;padding:8px;background:#f9fbf7;border-radius:8px">
            <?php foreach ($imageOnly as $i => $c): ?>
              <label style="display:block;cursor:pointer;border-radius:8px;overflow:hidden;border:2px solid #e0ead6;background:#fff;transition:.15s" onclick="document.querySelectorAll('.sf-card-img-lbl').forEach(l => l.style.borderColor = '#e0ead6'); this.style.borderColor = '#d81b60';" class="sf-card-img-lbl">
                <input type="radio" name="canva_id" value="<?= htmlspecialchars($c['id'] ?? '') ?>" <?= $i === 0 ? 'checked' : '' ?> style="display:none">
                <img src="/intern/sf-canva-file.php?id=<?= urlencode($c['id'] ?? '') ?>" alt="" style="display:block;width:100%;height:90px;object-fit:cover">
                <div style="padding:6px 8px;font-size:0.74rem;color:#5a6c5a;font-weight:600"><?= htmlspecialchars($c['title'] ?? '') ?></div>
              </label>
            <?php endforeach; ?>
          </div>

          <div style="display:grid;grid-template-columns:1fr 200px;gap:12px;margin-bottom:14px">
            <div>
              <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Empfänger</label>
              <select name="member_id" required style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;background:#fff">
                <option value="">-- Mitglied wählen --</option>
                <?php foreach ($members as $m): ?>
                  <option value="<?= htmlspecialchars($m['id'] ?? '') ?>"><?= htmlspecialchars($m['name'] ?? '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Text-Position</label>
              <select name="text_pos" style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;background:#fff">
                <option value="bottom">Unten</option>
                <option value="center">Mitte</option>
              </select>
            </div>
          </div>

          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Glückwunschtext (mehrzeilig)</label>
          <textarea name="message" rows="6" required placeholder="Liebe Erika,&#10;&#10;zu deinem Geburtstag wünschen wir dir alles Liebe und Gute …"
                    style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit;box-sizing:border-box;resize:vertical;margin-bottom:14px">Herzliche Glückwünsche zum Geburtstag!

Wir wünschen dir ein wunderbares Lebensjahr voller Sonnenschein, Gesundheit und vieler entspannter Stunden im Garten.

Der Vorstand der KGV Musterstadt e.V.</textarea>

          <button type="submit" style="background:#d81b60;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">💌 Karte als PDF generieren (A5)</button>
        </form>
      </div>
    <?php endif; ?>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Einstellungen (Daily-Reminder Konfig etc.)                        */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_settings(string $csrf): void {
    $cfg = sf_config();
    $cronToken = sf_load_json(SF_CONFIG)['cron_token'] ?? '';
    if ($cronToken === '') {
        $cronToken = bin2hex(random_bytes(16));
        sf_config_save(['cron_token' => $cronToken]);
    }
    $cronUrl = site_url() . '/cron-sf-daily.php?t=' . urlencode($cronToken);
    ?>
    <div style="background:linear-gradient(135deg,#37474f,#263238);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">⚙ Einstellungen Schriftführung-Cockpit</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">Daily-Briefing aktivieren, Empfänger-Mails konfigurieren, Cron-URL für Strato.</p>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">⏰ Tägliches Briefing</h4>
      <form method="POST" action="/intern/action.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_config_save">

        <label style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f9fbf7;border-radius:8px;border:1px solid #e0ead6;cursor:pointer">
          <input type="checkbox" name="daily_enabled" value="1" <?= !empty($cfg['daily_enabled']) ? 'checked' : '' ?>>
          <span style="font-weight:600">Daily-Briefing aktivieren</span>
        </label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Hauptempfänger</label>
            <input type="email" name="daily_to" value="<?= htmlspecialchars($cfg['daily_to']) ?>" placeholder="schriftfuehrer@example.org"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">CC (optional)</label>
            <input type="email" name="daily_to_cc" value="<?= htmlspecialchars($cfg['daily_to_cc']) ?>" placeholder="andreas@wolf-hamburg.net"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
          </div>
        </div>

        <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Empfänger Antrags-Benachrichtigungen</label>
        <input type="email" name="antrag_to" value="<?= htmlspecialchars($cfg['antrag_to']) ?>" placeholder="schriftfuehrer@example.org"
               style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box;margin-bottom:14px">

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit" style="background:#37474f;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">💾 Speichern</button>
          <button type="submit" name="test_briefing" value="1" formtarget="_blank" style="background:#5e35b1;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">👁 Briefing jetzt testen</button>
        </div>
      </form>
    </div>

    <div style="background:#fff;border-radius:12px;padding:22px;border:1px solid #d4e6c3;margin-bottom:18px">
      <h4 style="margin:0 0 14px;color:#2d3e2d">🤖 Strato-Cronjob einrichten</h4>
      <p style="margin:0 0 10px;font-size:0.88rem;color:#5a6c5a;line-height:1.6">
        Damit das Briefing jeden Morgen automatisch rausgeht, muss ein Cronjob im Strato-Kunden-Center angelegt werden.
        Empfehlung: <strong>täglich 07:00 Uhr</strong>.
      </p>
      <ol style="margin:0;padding-left:22px;font-size:0.88rem;color:#5a6c5a;line-height:1.7">
        <li>Strato Kundenservice-Center → Hosting → Cronjobs</li>
        <li>„Neuen Cronjob anlegen" · Befehl: <code style="background:#f5f5f5;padding:2px 6px;border-radius:4px;font-size:0.82rem">wget -O - "<?= htmlspecialchars($cronUrl) ?>" > /dev/null</code></li>
        <li>Zeit: <code style="background:#f5f5f5;padding:2px 6px;border-radius:4px">0 7 * * *</code> (täglich 07:00)</li>
      </ol>
      <div style="margin-top:14px;padding:14px;background:#f9fbf7;border-radius:8px;border:1px solid #e0ead6">
        <div style="font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Deine eindeutige Cron-URL (Token-geschützt)</div>
        <code style="display:block;background:#fff;padding:10px 14px;border-radius:6px;font-size:0.78rem;word-break:break-all;color:#37474f"><?= htmlspecialchars($cronUrl) ?></code>
        <p style="margin:8px 0 0;font-size:0.78rem;color:#8a9a8a">Halte diese URL geheim — jeder, der sie kennt, kann den Cron triggern.</p>
      </div>
    </div>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Protokolle (Welle 5 Power-Up — alle Features)                     */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_protocols(string $csrf): void {
    $protocols = sf_load_json(SF_PROTOCOLS);
    usort($protocols, fn($a,$b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    $editId = $_GET['pid'] ?? '';
    $editing = null;
    foreach ($protocols as $p) if (($p['id'] ?? '') === $editId) { $editing = $p; break; }
    $isNew = ($_GET['new'] ?? '') === '1';
    $copyFromId = $_GET['copy'] ?? '';

    // 5.2 — „aus letzter Sitzung kopieren": Vorlage aus Quell-Protokoll laden
    if ($isNew && $copyFromId !== '') {
        foreach ($protocols as $p) {
            if (($p['id'] ?? '') === $copyFromId) {
                $editing = [
                    'id'         => '',
                    'title'      => '',
                    'date'       => date('Y-m-d'),
                    'type'       => $p['type'] ?? 'Vorstandssitzung',
                    'attendees'  => $p['attendees'] ?? [],
                    'tops'       => array_map(fn($t) => [
                        'top'   => $t['top']   ?? '',
                        'title' => $t['title'] ?? '',
                    ], $p['tops'] ?? []),
                    'status'     => 'draft',
                ];
                $isNew = true;
                break;
            }
        }
    }
    ?>
    <?php if ($editing || $isNew): ?>
      <?php sf_render_protocol_editor($csrf, $editing); ?>
    <?php else: ?>
      <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0;color:#2d3e2d">📜 Sitzungsprotokolle</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if (!empty($protocols)): ?>
            <details style="position:relative">
              <summary style="background:#90a4ae;color:#fff;padding:9px 18px;border-radius:8px;font-size:0.88rem;font-weight:600;cursor:pointer;list-style:none">📋 Aus letzter Sitzung kopieren ▾</summary>
              <div style="position:absolute;top:100%;right:0;background:#fff;border:1px solid #d4e6c3;border-radius:10px;padding:8px;margin-top:5px;box-shadow:0 4px 16px rgba(0,0,0,0.1);min-width:280px;z-index:5">
                <?php
                $byType = [];
                foreach ($protocols as $p) {
                    $t = $p['type'] ?? '?';
                    if (!isset($byType[$t])) $byType[$t] = $p; // erste = neueste (sortiert)
                }
                foreach ($byType as $type => $p):
                ?>
                  <a href="?tab=schriftfuehrung&sub=protocols&new=1&copy=<?= urlencode($p['id']) ?>"
                     style="display:block;padding:8px 12px;text-decoration:none;color:#2d3e2d;border-radius:6px;font-size:0.85rem"
                     onmouseover="this.style.background='#f5f0fa'" onmouseout="this.style.background='transparent'">
                    <strong><?= htmlspecialchars($type) ?></strong><br>
                    <span style="color:#8a9a8a;font-size:0.78rem"><?= htmlspecialchars($p['title'] ?? '') ?> · <?= htmlspecialchars($p['date'] ?? '') ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endif; ?>
          <a href="?tab=schriftfuehrung&sub=protocols&new=1" style="background:#5e35b1;color:#fff;padding:9px 18px;border-radius:8px;text-decoration:none;font-size:0.88rem;font-weight:600">➕ Neues Protokoll</a>
        </div>
      </div>

      <?php if (empty($protocols)): ?>
        <div style="background:#fff;border-radius:12px;padding:60px 22px;border:1px solid #d4e6c3;text-align:center">
          <div style="font-size:3rem;margin-bottom:14px">📜</div>
          <h4 style="margin:0 0 6px;color:#2d3e2d">Noch keine Protokolle</h4>
          <p style="margin:0;color:#8a9a8a;font-size:0.9rem">Lege das erste Sitzungsprotokoll an — Tagesordnungspunkte, Beschlüsse, Anwesenheit, alles dabei.</p>
        </div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
          <?php
          $statuses = sf_protocol_statuses();
          foreach ($protocols as $p):
            $st  = $p['status'] ?? 'draft';
            $col = $statuses[$st][0] ?? '#5e35b1';
            $lab = $statuses[$st][1] ?? 'Entwurf';
          ?>
            <div style="background:#fff;border-radius:12px;padding:18px;border:1px solid #d4e6c3;border-left:4px solid <?= $col ?>">
              <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px;gap:8px">
                <strong style="color:#2d3e2d;font-size:1rem;line-height:1.3"><?= htmlspecialchars($p['title'] ?? '') ?></strong>
                <span style="background:<?= $col ?>;color:#fff;font-size:0.7rem;padding:3px 8px;border-radius:8px;font-weight:700;white-space:nowrap"><?= htmlspecialchars($lab) ?></span>
              </div>
              <div style="color:#8a9a8a;font-size:0.82rem;margin-bottom:6px">
                📅 <?= htmlspecialchars($p['date'] ?? '') ?>
                · 👥 <?= count($p['attendees'] ?? []) ?>
                · 📋 <?= count($p['tops'] ?? []) ?> TOPs
                <?php
                $besCount = 0;
                foreach (($p['tops'] ?? []) as $t) if (!empty($t['beschluss'])) $besCount++;
                if ($besCount > 0): ?>
                  · 🏛 <?= $besCount ?>
                <?php endif; ?>
              </div>
              <div style="font-size:0.74rem;color:#8a9a8a;margin-bottom:10px">
                <span style="display:inline-block;background:#f5f0fa;color:#5e35b1;padding:2px 7px;border-radius:5px;font-weight:600"><?= htmlspecialchars($p['type'] ?? '') ?></span>
                <?php if (!empty($p['invitation_sent_at'])): ?>
                  <span style="display:inline-block;background:#e3f2fd;color:#1565c0;padding:2px 7px;border-radius:5px;margin-left:3px">📨 Einladung versandt</span>
                <?php endif; ?>
                <?php if (!empty($p['sent_at'])): ?>
                  <span style="display:inline-block;background:#e8f5e9;color:#2e7d32;padding:2px 7px;border-radius:5px;margin-left:3px">📧 Protokoll versandt</span>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="?tab=schriftfuehrung&sub=protocols&pid=<?= urlencode($p['id']) ?>" style="flex:1;text-align:center;background:#5e35b1;color:#fff;padding:6px 10px;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:600">✏ Bearbeiten</a>
                <a href="/intern/action.php?action=sf_protocol_pdf&csrf=<?= urlencode($csrf) ?>&pid=<?= urlencode($p['id']) ?>" target="_blank" style="flex:1;text-align:center;background:#3d6b41;color:#fff;padding:6px 10px;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:600">📄 PDF</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php
}

function sf_render_protocol_editor(string $csrf, ?array $p): void {
    $members = sf_member_list();
    $isNew   = (empty($p['id'] ?? ''));
    $defType = $p['type'] ?? 'Vorstandssitzung';
    $tops    = $p['tops'] ?? sf_default_tops_by_type($defType);
    $att     = $p['attendees'] ?? [];
    $status  = $p['status'] ?? 'draft';
    $statuses = sf_protocol_statuses();
    $att_list = [];
    $allByM   = [];
    foreach ($members as $m) {
        $allByM[$m['id'] ?? ''] = $m;
        if (in_array($m['id'] ?? '', $att, true)) $att_list[] = $m;
    }
    $attachments = (array)($p['attachments'] ?? []);
    ?>
    <div style="background:#fff;border-radius:14px;padding:24px;border:1px solid #d4e6c3">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div>
          <h3 style="margin:0;color:#2d3e2d">📜 <?= $isNew ? 'Neues Protokoll' : 'Protokoll bearbeiten' ?></h3>
          <?php if (!$isNew): ?>
            <span style="background:<?= $statuses[$status][0] ?? '#5e35b1' ?>;color:#fff;font-size:0.72rem;padding:3px 10px;border-radius:8px;font-weight:700"><?= htmlspecialchars($statuses[$status][1] ?? '') ?></span>
          <?php endif; ?>
        </div>
        <a href="?tab=schriftfuehrung&sub=protocols" style="color:#8a9a8a;text-decoration:none;font-size:0.85rem">← Zurück zur Übersicht</a>
      </div>

      <form method="POST" action="/intern/action.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="sf_protocol_save">
        <input type="hidden" name="pid" value="<?= htmlspecialchars($p['id'] ?? '') ?>">

        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:14px;margin-bottom:16px">
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Titel der Sitzung</label>
            <input type="text" name="p_title" required value="<?= htmlspecialchars($p['title'] ?? '') ?>"
                   placeholder="z.B. Vorstandssitzung Juni" style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Datum</label>
            <input type="date" name="p_date" required value="<?= htmlspecialchars($p['date'] ?? date('Y-m-d')) ?>"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;box-sizing:border-box">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Typ</label>
            <select name="p_type" style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;background:#fff">
              <?php foreach (['Vorstandssitzung','Mitgliederversammlung','Außerordentliche Sitzung','Ausschuss-Sitzung'] as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= ($p['type'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Status</label>
            <select name="p_status" style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.92rem;background:#fff">
              <?php foreach ($statuses as $k => [$_c, $lab]): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="background:#f9fbf7;border-radius:10px;padding:16px;margin-bottom:16px">
          <h4 style="margin:0 0 10px;color:#2d3e2d;font-size:0.95rem">👥 Anwesenheit (<span id="sf-att-count">0</span>)</h4>
          <div style="display:flex;flex-wrap:wrap;gap:10px">
            <?php foreach ($members as $m): if (empty($m['active'])) continue; ?>
              <label style="display:flex;align-items:center;gap:6px;background:#fff;padding:7px 12px;border-radius:20px;cursor:pointer;border:1px solid <?= in_array($m['id'] ?? '', $att, true) ? '#5e35b1' : '#d4e6c3' ?>">
                <input type="checkbox" name="p_attendees[]" class="sf-att-cb" value="<?= htmlspecialchars($m['id'] ?? '') ?>" <?= in_array($m['id'] ?? '', $att, true) ? 'checked' : '' ?> onchange="sfUpdateAttCount()">
                <span style="font-size:0.82rem;color:#2d3e2d"><?= htmlspecialchars($m['name'] ?? '') ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:6px">
            <h4 style="margin:0;color:#2d3e2d;font-size:0.95rem">📋 Tagesordnungspunkte</h4>
            <div style="display:flex;gap:6px;align-items:center">
              <span style="font-size:0.74rem;color:#8a9a8a">💡 Per Drag&Drop sortierbar (Griff rechts)</span>
              <button type="button" onclick="sfAddTop()" style="background:#5e35b1;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer">+ TOP hinzufügen</button>
            </div>
          </div>
          <div id="sf-tops">
            <?php foreach ($tops as $i => $top): ?>
              <div class="sf-top" draggable="true" style="background:#fff;border:1px solid #d4e6c3;border-radius:10px;padding:14px;margin-bottom:10px;cursor:default">
                <div style="display:grid;grid-template-columns:90px 1fr auto auto;gap:10px;margin-bottom:8px;align-items:center">
                  <input type="text" name="p_top_label[]" value="<?= htmlspecialchars($top['top'] ?? 'TOP ' . ($i+1)) ?>" placeholder="TOP 1" style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-weight:700">
                  <input type="text" name="p_top_title[]" required value="<?= htmlspecialchars($top['title'] ?? '') ?>" placeholder="Titel des TOP" style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem">
                  <span class="sf-drag-handle" style="cursor:grab;color:#90a4ae;font-size:1.2rem;padding:5px 8px;user-select:none" title="Ziehen zum Sortieren">⠿</span>
                  <button type="button" onclick="this.closest('.sf-top').remove()" style="background:#fff;color:#c62828;border:1px solid #c62828;border-radius:6px;padding:6px 12px;font-size:0.78rem;cursor:pointer">🗑</button>
                </div>
                <textarea name="p_top_notes[]" rows="3" placeholder="Besprechung / Notizen / Beschluss …"
                          style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit;line-height:1.5;box-sizing:border-box;resize:vertical"><?= htmlspecialchars($top['notes'] ?? '') ?></textarea>
                <div style="display:grid;grid-template-columns:1fr 80px 80px 80px;gap:8px;margin-top:8px">
                  <input type="text" name="p_top_beschluss[]" value="<?= htmlspecialchars($top['beschluss'] ?? '') ?>" placeholder="Beschluss-Wortlaut (optional) — wird auto-nummeriert"
                         style="padding:7px 10px;border:1.5px solid #f9a825;background:#fff8e1;border-radius:6px;font-size:0.82rem">
                  <input type="number" name="p_top_ja[]"  value="<?= htmlspecialchars((string)($top['ja'] ?? '')) ?>" placeholder="Ja"
                         style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;text-align:center">
                  <input type="number" name="p_top_nein[]" value="<?= htmlspecialchars((string)($top['nein'] ?? '')) ?>" placeholder="Nein"
                         style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;text-align:center">
                  <input type="number" name="p_top_enth[]" value="<?= htmlspecialchars((string)($top['enth'] ?? '')) ?>" placeholder="Enth."
                         style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;text-align:center">
                </div>
                <?php if (!empty($top['beschluss_nr'])): ?>
                  <div style="margin-top:6px;font-size:0.74rem;color:#5e35b1;font-weight:700">🏛 <?= htmlspecialchars($top['beschluss_nr']) ?></div>
                <?php endif; ?>
                <div style="margin-top:8px;display:grid;grid-template-columns:1fr 140px;gap:8px;align-items:center">
                  <input type="text" name="p_top_action[]" value="<?= htmlspecialchars($top['action'] ?? '') ?>" placeholder="📌 Action-Item für nächste Sitzung (z.B. Andreas prüft Endreinigung)"
                         style="padding:7px 10px;border:1.5px solid #e1bee7;border-radius:6px;font-size:0.82rem;background:#faf5ff">
                  <input type="date" name="p_top_action_due[]" value="<?= htmlspecialchars($top['action_due'] ?? '') ?>"
                         style="padding:7px 10px;border:1.5px solid #e1bee7;border-radius:6px;font-size:0.82rem;background:#faf5ff">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="margin-bottom:16px">
          <label style="display:block;font-size:0.78rem;font-weight:700;color:#5a6c5a;margin-bottom:5px">Sonstige Bemerkungen</label>
          <textarea name="p_notes" rows="3"
                    style="width:100%;padding:10px 12px;border:1.5px solid #d4e6c3;border-radius:8px;font-size:0.9rem;font-family:inherit;box-sizing:border-box;resize:vertical"><?= htmlspecialchars($p['notes'] ?? '') ?></textarea>
        </div>

        <!-- 5.7 Anhänge -->
        <div style="background:#f9fbf7;border-radius:10px;padding:16px;margin-bottom:16px">
          <h4 style="margin:0 0 10px;color:#2d3e2d;font-size:0.95rem">📎 Anhänge</h4>
          <?php if (!empty($attachments)): ?>
            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
              <?php foreach ($attachments as $att_): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;padding:8px 12px;border-radius:6px;border:1px solid #e0ead6">
                  <span style="font-size:0.85rem;color:#2d3e2d">📄 <?= htmlspecialchars($att_['title'] ?? $att_['filename'] ?? '') ?></span>
                  <a href="/intern/sf-protocol-att.php?pid=<?= urlencode($p['id'] ?? '') ?>&id=<?= urlencode($att_['id'] ?? '') ?>" target="_blank" style="color:#5e35b1;font-size:0.78rem;text-decoration:none">⬇ Download</a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <input type="file" name="protocol_attachment" accept="application/pdf,image/png,image/jpeg" style="font-size:0.85rem">
          <span style="font-size:0.74rem;color:#8a9a8a;margin-left:10px">PDF / JPG / PNG bis 10 MB</span>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <button type="submit" name="save_action" value="save" style="background:#5e35b1;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">💾 Speichern</button>
          <?php if (!$isNew): ?>
            <button type="submit" name="save_action" value="save_pdf" style="background:#3d6b41;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">💾 + 📄 PDF</button>
            <button type="submit" name="save_action" value="mail_protocol" formtarget="_blank" style="background:#1565c0;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">💾 + 📧 An Anwesende mailen</button>
            <button type="submit" name="save_action" value="send_invitation" formtarget="_blank" style="background:#7e57c2;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer">📨 Einladung versenden</button>
            <button type="submit" name="save_action" value="delete" onclick="return confirm('Protokoll wirklich löschen? Aktion kann nicht rückgängig gemacht werden.')" style="background:#c62828;color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:0.92rem;font-weight:600;cursor:pointer;margin-left:auto">🗑 Löschen</button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <script>
    function sfUpdateAttCount() {
      const n = document.querySelectorAll('.sf-att-cb:checked').length;
      const el = document.getElementById('sf-att-count');
      if (el) el.textContent = n;
    }
    sfUpdateAttCount();

    function sfAddTop() {
      const cnt = document.querySelectorAll('.sf-top').length + 1;
      const html = `
        <div class="sf-top" draggable="true" style="background:#fff;border:1px solid #d4e6c3;border-radius:10px;padding:14px;margin-bottom:10px;cursor:default">
          <div style="display:grid;grid-template-columns:90px 1fr auto auto;gap:10px;margin-bottom:8px;align-items:center">
            <input type="text" name="p_top_label[]" value="TOP ${cnt}" placeholder="TOP 1" style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-weight:700">
            <input type="text" name="p_top_title[]" required placeholder="Titel des TOP" style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem">
            <span class="sf-drag-handle" style="cursor:grab;color:#90a4ae;font-size:1.2rem;padding:5px 8px;user-select:none" title="Ziehen zum Sortieren">⠿</span>
            <button type="button" onclick="this.closest('.sf-top').remove()" style="background:#fff;color:#c62828;border:1px solid #c62828;border-radius:6px;padding:6px 12px;font-size:0.78rem;cursor:pointer">🗑</button>
          </div>
          <textarea name="p_top_notes[]" rows="3" placeholder="Besprechung / Notizen / Beschluss …" style="width:100%;padding:9px 12px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.85rem;font-family:inherit;line-height:1.5;box-sizing:border-box;resize:vertical"></textarea>
          <div style="display:grid;grid-template-columns:1fr 80px 80px 80px;gap:8px;margin-top:8px">
            <input type="text" name="p_top_beschluss[]" placeholder="Beschluss-Wortlaut (optional) — wird auto-nummeriert" style="padding:7px 10px;border:1.5px solid #f9a825;background:#fff8e1;border-radius:6px;font-size:0.82rem">
            <input type="number" name="p_top_ja[]" placeholder="Ja" style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;text-align:center">
            <input type="number" name="p_top_nein[]" placeholder="Nein" style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;text-align:center">
            <input type="number" name="p_top_enth[]" placeholder="Enth." style="padding:7px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem;text-align:center">
          </div>
          <div style="margin-top:8px;display:grid;grid-template-columns:1fr 140px;gap:8px;align-items:center">
            <input type="text" name="p_top_action[]" placeholder="📌 Action-Item für nächste Sitzung" style="padding:7px 10px;border:1.5px solid #e1bee7;border-radius:6px;font-size:0.82rem;background:#faf5ff">
            <input type="date" name="p_top_action_due[]" style="padding:7px 10px;border:1.5px solid #e1bee7;border-radius:6px;font-size:0.82rem;background:#faf5ff">
          </div>
        </div>`;
      document.getElementById('sf-tops').insertAdjacentHTML('beforeend', html);
      sfInitDragDrop();
    }

    // 5.9 Drag & Drop für TOP-Reihenfolge
    function sfInitDragDrop() {
      const container = document.getElementById('sf-tops');
      if (!container) return;
      let dragged = null;
      container.querySelectorAll('.sf-top').forEach(el => {
        el.ondragstart = (e) => { dragged = el; el.style.opacity = '0.4'; e.dataTransfer.effectAllowed = 'move'; };
        el.ondragend   = ()  => { el.style.opacity = '1'; };
        el.ondragover  = (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; };
        el.ondrop      = (e) => {
          e.preventDefault();
          if (dragged && dragged !== el) {
            const rect = el.getBoundingClientRect();
            const after = (e.clientY - rect.top) > (rect.height / 2);
            container.insertBefore(dragged, after ? el.nextSibling : el);
          }
        };
      });
    }
    sfInitDragDrop();
    </script>
    <?php
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Modul: Schadenmeldungen (Welle 6.5)                                      */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_render_schaeden(string $csrf): void {
    $items = sf_load_json(SF_SCHAEDEN);
    $statuses   = sf_schaden_statuses();
    $categories = sf_schaden_categories();
    $filter = $_GET['st'] ?? 'all';
    if ($filter !== 'all') {
        $items = array_values(array_filter($items, fn($s) => ($s['status'] ?? '') === $filter));
    }
    usort($items, fn($a,$b) => strcmp($b['reported_at'] ?? '', $a['reported_at'] ?? ''));
    ?>
    <div style="background:linear-gradient(135deg,#b71c1c,#7b1c1c);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px">
      <h3 style="margin:0 0 8px">🔧 Schadenmeldungen</h3>
      <p style="margin:0;font-size:0.92rem;opacity:0.92;line-height:1.55">
        Mitglieder melden Schäden am Vereinshaus/Anlage über den Mitgliederbereich („Schaden melden"-Tab).
        Hier laufen sie auf — kategorisiert, mit optionalen Fotos.
      </p>
    </div>

    <div style="background:#fff;border-radius:12px;padding:14px 18px;border:1px solid #d4e6c3;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <strong style="color:#5a6c5a;font-size:0.85rem">Filter:</strong>
      <a href="?tab=schriftfuehrung&sub=schaeden&st=all"
         style="padding:5px 12px;border-radius:14px;text-decoration:none;font-size:0.78rem;font-weight:600;background:<?= $filter === 'all' ? '#5e35b1' : '#f5f5f5' ?>;color:<?= $filter === 'all' ? '#fff' : '#5a6c5a' ?>">Alle</a>
      <?php foreach ($statuses as $k => [$col, $lab]):
        $active = $filter === $k; ?>
        <a href="?tab=schriftfuehrung&sub=schaeden&st=<?= $k ?>"
           style="padding:5px 12px;border-radius:14px;text-decoration:none;font-size:0.78rem;font-weight:600;background:<?= $active ? $col : '#f5f5f5' ?>;color:<?= $active ? '#fff' : '#5a6c5a' ?>">
          <?= htmlspecialchars($lab) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($items)): ?>
      <div style="background:#fff;border-radius:12px;padding:50px 22px;border:1px solid #d4e6c3;text-align:center">
        <div style="font-size:3rem;margin-bottom:10px">🔧</div>
        <h4 style="margin:0 0 6px;color:#2d3e2d"><?= $filter === 'all' ? 'Keine Schadenmeldungen' : 'Keine Meldungen mit Status „' . htmlspecialchars($statuses[$filter][1] ?? '?') . '"' ?></h4>
        <p style="margin:0;color:#8a9a8a;font-size:0.9rem">Mitglieder können Schäden über ihren Mitgliederbereich melden („🔧 Schaden")</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($items as $s):
          $col = $statuses[$s['status'] ?? '']['0'] ?? '#5a6c5a';
          $lab = $statuses[$s['status'] ?? '']['1'] ?? 'Gemeldet';
          $catLabel = $categories[$s['category'] ?? ''] ?? '🔧';
        ?>
          <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #d4e6c3;border-left:4px solid <?= $col ?>">
            <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
              <div style="flex:1;min-width:0">
                <strong style="color:#2d3e2d;font-size:1rem"><?= htmlspecialchars($catLabel) ?> · <?= htmlspecialchars($s['title'] ?? '(Ohne Titel)') ?></strong>
                <div style="color:#8a9a8a;font-size:0.82rem;margin-top:3px">
                  Von <strong style="color:#5a6c5a"><?= htmlspecialchars($s['from_name'] ?? '?') ?></strong>
                  <?php if (!empty($s['from_email'])): ?> · <?= htmlspecialchars($s['from_email']) ?><?php endif; ?>
                  · gemeldet <?= htmlspecialchars($s['reported_at'] ?? '') ?>
                </div>
              </div>
              <span style="background:<?= $col ?>;color:#fff;padding:4px 12px;border-radius:14px;font-size:0.76rem;font-weight:700;white-space:nowrap"><?= htmlspecialchars($lab) ?></span>
            </div>

            <div style="background:#f9fbf7;border-radius:8px;padding:12px 14px;margin:10px 0;color:#2d3e2d;line-height:1.55;font-size:0.92rem;white-space:pre-wrap"><?= htmlspecialchars($s['description'] ?? '') ?></div>

            <?php if (!empty($s['photo_filename'])): ?>
              <div style="margin:10px 0">
                <a href="/intern/sf-schaden-foto.php?id=<?= urlencode($s['id'] ?? '') ?>" target="_blank">
                  <img src="/intern/sf-schaden-foto.php?id=<?= urlencode($s['id'] ?? '') ?>" alt="Schaden-Foto" style="max-width:280px;max-height:200px;border-radius:8px;border:1px solid #d4e6c3">
                </a>
              </div>
            <?php endif; ?>

            <?php if (!empty($s['admin_note'])): ?>
              <div style="background:#fff8e1;border-left:3px solid #f9a825;border-radius:6px;padding:10px 14px;margin:8px 0;font-size:0.85rem;color:#5a6c5a">
                <strong style="color:#f57f17">📝 Interne Notiz:</strong> <?= htmlspecialchars($s['admin_note']) ?>
              </div>
            <?php endif; ?>

            <form method="POST" action="/intern/action.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="sf_schaden_update">
              <input type="hidden" name="schaden_id" value="<?= htmlspecialchars($s['id'] ?? '') ?>">
              <select name="status" style="padding:6px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem">
                <?php foreach ($statuses as $k => [$_c, $l]): ?>
                  <option value="<?= $k ?>" <?= ($s['status'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="admin_note" placeholder="Interne Notiz" value="<?= htmlspecialchars($s['admin_note'] ?? '') ?>" style="flex:1;min-width:180px;padding:6px 10px;border:1.5px solid #d4e6c3;border-radius:6px;font-size:0.82rem">
              <button type="submit" style="background:#b71c1c;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:0.82rem;font-weight:600;cursor:pointer">💾 Aktualisieren</button>
              <button type="submit" name="op" value="delete" onclick="return confirm('Schadenmeldung wirklich löschen?')" style="background:#fff;color:#c62828;border:1px solid #c62828;padding:6px 12px;border-radius:6px;font-size:0.78rem;cursor:pointer">🗑</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
}
