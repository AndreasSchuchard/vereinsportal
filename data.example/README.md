# data.example/ — Beispieldaten für die Einrichtung

Dieser Ordner enthält anonymisierte Beispiel-Dateien. **Kopiere sie nicht direkt** —
sie dienen nur als Vorlage für die Struktur, die dein Verein in `data/` anlegt.

## Vorgehen

1. Erstelle im Projektordner das Verzeichnis `data/` (wird nicht mit ausgeliefert).
2. Kopiere `settings.example.json` → `data/settings.json` und befülle die Werte.
3. Kopiere `content.example.json` → `data/content.json` und ersetze die
   Beispiel-Inhalte durch die echten Daten eures Vereins.
4. Wichtig: `data/` enthält echte Vereins-/Mitgliederdaten und wird von
   `.gitignore` ausgeschlossen — niemals hochladen!

## Struktur

- `settings.json` — nicht-sekretäre Konfiguration (Domain, E-Mail-Empfänger,
  Wartungsmodus). Sekrete wie IMAP-Passwort gehören in `.env`.
- `content.json` — alle redaktionellen Inhalte (Vereinsname, Vorstand,
  Impressum, Datenschutz, Termine, Preise, Galerie …) die im Portal angezeigt werden.

> Beide Dateien werden vom `inc/settings_loader.php` (bzw. dem Seiten-Code) zur
> Laufzeit geladen. Die `site_url` in `settings.json` überschreibt den
> `SITE_URL`-Fallback aus `.env` — setze hier deine echte Domain.
