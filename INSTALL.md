# INSTALL.md — Einrichtung des Vereinsportals

Diese Anleitung beschreibt, wie ein Verein das Portal auf einem eigenen
Webserver aufsetzt. Dauer: ca. 30–60 Minuten.

---

## 1. Voraussetzungen

- Webserver mit **PHP 8.1+** (empfohlen: Apache mit `mod_rewrite` — die
  `.htaccess` ist enthalten; bei nginx die Rewrite-Regeln übertragen)
- PHP-Erweiterungen: `mbstring`, `gd` (QR-Codes, PDF-Aushänge), `dom`/`xml`, `json`
- Optional: `imap` für den Empfang eingehender E-Mails
- Keine Datenbank erforderlich

## 2. Dateien hochladen

Kopiere alle Dateien des Repos in das Webroot deiner Domain (z.B. `public_html/`).
Danach sollte `https://DEINE-DOMAIN/` die Startseite zeigen.

## 3. Domain-Platzhalter ersetzen

In folgenden Dateien steht der Platzhalter **`YOUR-DOMAIN.TLD`** — ersetze ihn
durch deine echte Domain (ohne `https://`):

- `.htaccess` (Redirect-Regeln: www → non-www, alte Pfade)
- `sitemap.xml` (Alle `<loc>`-URLs)
- `.well-known/security.txt` (Canonical, Policy)
- `robots.txt` (Sitemap-URL)
- `kgv461.css` (Kommentar)

```bash
# Beispiel (in allen Dateien)
sed -i 's/YOUR-DOMAIN\.TLD/mein-verein.de/g' .htaccess sitemap.xml .well-known/security.txt robots.txt kgv461.css
```

## 4. `.env` anlegen

Kopiere `.env.example` nach `.env` und fülle die Werte aus:

```bash
cp .env.example .env
```

| Variable | Bedeutung |
|---|---|
| `SITE_URL` | Basis-URL des Portals, z.B. `https://mein-verein.de` (wird für Links in E-Mails & QR-Codes genutzt) |
| `IMAP_HOST` / `IMAP_PORT` | Postfach-Server (z.B. `imap.strato.de:993`) |
| `IMAP_USER` | E-Mail-Adresse des Postfachs |
| `IMAP_PASSWORD` | Passwort des Postfachs (für `fetch_incoming_mail`) |
| `ANLEITUNG_PASSWORD` | Passwort für das Admin-Handbuch (`anleitung.php`) |

> `.env` ist in `.gitignore` — Secrets nie ins Repo!

## 5. Datenverzeichnis `data/` anlegen

```bash
mkdir data
cp data.example/settings.example.json data/settings.json
cp data.example/content.example.json data/content.json
```

- **`data/settings.json`**: Domain, Admin-Passwort-Hash, IMAP-Zugang,
  E-Mail-Empfänger (Kontakt- & Buchungs-Benachrichtigungen)
- **`data/content.json`**: alle sichtbaren Inhalte (Vereinsname, Vorstand,
  Impressum, Datenschutz, Termine, Preise, Galerie …)

> Der Admin-Bereich (`/intern`) bietet einen **Inhalts-Editor (CMS)** — die
> meisten Inhalte lassen sich dort bequem pflegen, ohne die JSON-Dateien manuell
> zu editieren. Die Beispiel-Dateien dienen als Startstruktur.

### Admin-Zugang

Setze in `data/settings.json` unter `password_hash` einen bcrypt-Hash deines
Admin-Passworts:

```bash
php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_BCRYPT), PHP_EOL;"
```

Dann ist der Admin-Bereich unter `https://DEINE-DOMAIN/intern/` erreichbar.

## 6. Cron-Jobs einrichten

Zwei Routinen sind über Cron aufzurufen (jeweils mit deinem Secret-Token):

| Job | Intervall | Zweck |
|---|---|---|
| `cron-sf-daily.php` | täglich | Schriftführungs-Routinen, Frist-Erinnerungen |
| `inc/booking_expire.php` | stündlich | Ablauf unbezahlter Buchungen |

Beispiel (crontab):

```cron
0 6 * * *  curl -s 'https://DEINE-DOMAIN/cron-sf-daily.php?t=DEIN_TOKEN' >/dev/null
15 * * * * curl -s 'https://DEINE-DOMAIN/inc/booking_expire.php' >/dev/null
```

Die exakten Token/Parameter entnimmst du den Kommentaren in den Dateien.

## 7. Sicherheits-Checkliste

- [ ] `.env` liegt außerhalb des Webroot oder ist per Webserver blockiert
- [ ] `data/` nicht öffentlich erreichbar (`.htaccess` in `data/` ist enthalten;
      prüfen!) — sie enthält echte Mitgliederdaten
- [ ] `/admin/` und `/intern/` werden in `robots.txt` ausgeschlossen
- [ ] Admin-Passwort-Hash gesetzt und starkes Passwort gewählt
- [ ] HTTPS aktiv (`.user.ini` erzwingt Secure-Cookies)
- [ ] Backup der `data/`-Dateien + `.env` einrichten

## 8. Erste Schritte nach der Installation

1. Admin-Bereich öffnen (`/intern/`) und einloggen
2. Vereinsname, Impressum und Datenschutz in `data/content.json` bzw. über den
   Editor pflegen
3. Vorstand eintragen, Termine anlegen, Vereinshaus-Buchungsoptionen setzen
4. E-Mail-Empfänger in `data/settings.json` prüfen
5. Ein Mitglied als Test registrieren und die Buchung einmal durchspielen

---

Bei Fragen: Nutze die Struktur der `data.example/`-Dateien als Referenz. Die
Namen der JSON-Keys entsprechen den Feldern im Inhalts-Editor.
