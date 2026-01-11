# S&F Guilds (sfguilds)

Kleine Web-App zum Verwalten und Anzeigen von Shakes & Fidget Gilden-Daten (Mitgliederlisten, Aktiv-Zahlen, Wappen/Crests) – mit Admin-Panel für CSV-Import, Notizen und Status-Felder.

**Ziel:** Schnell eine übersichtliche Gilden-Seite im S&F-Look, die man öffentlich teilen kann – und im Admin-Bereich komfortabel pflegt.

---

## Features

### Öffentlich

- Gildenübersicht: Server, Gilde, **Aktiv**, **Stand** (Datum des letzten Imports)
- Einzelne Gildenseite mit Mitgliedern
- Sortierung nach **Rang** (Anführer → Offizier → Mitglieder) und danach nach Aktivität/Level (siehe unten)
- S&F-Style Layout + eigene Fehlerseiten (400/401/403/404/413/500/502/503/504) mit Grafiken

### Admin

- Login/Logout
- Gilden verwalten
- Mitglieder verwalten
- CSV-Import pro Gilde (Insert/Update anhand `Name`)
- Manuelle Felder pflegen (z. B. Notizen, Entlassen/Verlassen)
- Upload von Wappen (Crests) mit Dateityp-Beschränkung

---

## Tech-Stack

- PHP (klassisch, ohne Framework)
- SQLite (Datei-Datenbank)
- NGINX + php-fpm
- Frontend: CSS (Dark UI, Buttons etc.)

---

## Datenmodell (SQLite)

### `guilds`

- `id`, `server`, `name`, `tag`, `notes`, `crest_file`
- `last_import_at` (ISO-8601 UTC Timestamp, z. B. `2026-01-11T20:33:06+00:00`)

### `members`

- `guild_id`, `name`
- `rank` (z. B. `Anführer`, `Offizier`, `Mitglied`)
- `level`, `last_online`, `joined_at`, `gold`, `mentor`, `knight_hall`, `guild_pet`
- `days_offline` (optional, kann live berechnet werden)
- `notes`, `fired_at`, `left_at`

> Hinweis: Die DB-Schemata werden beim Start automatisch angelegt/ergänzt (Schema/Migration via `app/db.php`).

---

## CSV-Import

Der Import läuft im Admin-Bereich und:

- erkennt `,` oder `;` als Trennzeichen automatisch
- mappt Header tolerant (UTF-8/BOM, Leerzeichen, Umlaute etc.)
- macht Insert/Update anhand **`Name`**
- überschreibt **manuelle Felder** (Notizen / Entlassen / Verlassen) **nur**, wenn die CSV dort Werte enthält
- liest zusätzlich `Rang` (bzw. `rank`) und schreibt in `members.rank`

### Erwartete CSV-Spalten (Beispiel)

- `Name`
- `Rang`
- `Level`
- `zul. Online`
- `Gildenbeitritt`
- `Goldschatz`
- `Lehrmeister`
- `Ritterhalle`
- `Gildenpet`
- `Entlassen`
- `Verlassen`
- `Sonstige Notizen`

Nicht alle müssen vorhanden sein – `Name` ist Pflicht.

---

## Sortierung der Mitglieder

Es gibt zwei Sortierbereiche:

- Admin: `/admin/members.php`
- Öffentlich: `/guild/<id>` (Routing über `public/index.php`)

Die Sortierung ist so gedacht:

1. **Rang**
   - `Anführer` zuerst
   - `Offizier` danach
   - Rest zuletzt
2. **Aktivität**
   - `last_online` (Datum) absteigend
3. **Level** absteigend
4. **Name** (fallback, case-insensitive)

> `days_offline` wird i. d. R. live berechnet. Wenn du `days_offline` NICHT mehr speicherst/importierst, sollte die Sortierung ausschließlich über `last_online` + `level` laufen.

---

## Letzte Aktualisierung / „Stand“-Spalte

`guilds.last_import_at` wird beim Import aktualisiert.

- Auf der **Gildenübersicht** wird pro Gilde der `Stand` (Datum) angezeigt
- Auf der **einzelnen Gilde** wird „Letzte Aktualisierung: …“ angezeigt

Konvertierung nach Europe/Berlin und Formatierung `d.m.Y` erfolgt im View.

---

## Fehlerseiten (S&F Style)

Es gibt statische Fehlerseiten unter:

- `public/errors/400.html`
- `public/errors/401.html`
- `public/errors/403.html`
- `public/errors/404.html`
- `public/errors/413.html`
- `public/errors/500.html`
- `public/errors/502.html`
- `public/errors/503.html`
- `public/errors/504.html`

Dazu passende Grafiken unter:

- `public/assets/errors/<code>.png`

Layout/Navigation/Logo werden im HTML der Fehlerseiten eingebunden (kein PHP nötig).

---

## Security

### XSS / CSV-Import

- CSV-Inhalte werden beim Rendern HTML-escaped ausgegeben (`htmlspecialchars`/`e()`).
- Eingebettetes `<script>` in CSV wird dadurch **nicht ausgeführt**, sondern als Text angezeigt.

### Upload-Sicherheit (Crests / Upload-Verzeichnis)

- Uploads werden serverseitig auf erlaubte Bildtypen eingeschränkt (z. B. `png`, `jpg`, `webp`).
- Zusätzlich sollte NGINX PHP-Ausführung in Upload-Pfaden blockieren (siehe NGINX Beispiel).

### Hidden Files

- Zugriff auf dotfiles (z. B. `.env`) wird unterbunden (außer `.well-known` für ACME).

---

## NGINX (Beispiel-Konfiguration)

> Die NGINX-Config hängt stark vom Setup ab (php-fpm Socket, Pfade etc.).  
> Wichtig sind:
>
> - PHP nur über `location ~ \.php$`
> - Upload-Verzeichnis **keine** PHP-Ausführung
> - Optional: eigene Error-Pages per `error_page`

### Upload-Verzeichnis absichern

Beispiel:

```nginx
location ^~ /uploads/ {
    if ($uri ~* \.(php|phtml|phar|php\d)$) { return 403; }
    try_files $uri =404;
}
```
