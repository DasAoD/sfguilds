# S&F Guilds

Kleine, schlanke PHP-Webapp zur Verwaltung und Anzeige von **Shakes & Fidget**-Gilden-Daten.
Gedacht als Self-Hosted Projekt: Gilden anlegen, CSVs importieren, Mitglieder anzeigen – im passenden dunklen “S&F”-Look inkl. eigener Fehlerseiten.
Als Datenbasis dienen die Exporte von https://sftools.mar21.eu.

## Features

- **Gildenübersicht** (`/`) mit:
  - Server, Gilde, aktive Mitglieder
  - **Stand** (Datum der letzten Aktualisierung pro Gilde)
  - optionalem Wappen (Crest)
- **Gildenseite** (`/guild/<id>`) mit Mitgliederliste
  - Sortierung nach Rang (Anführer/Offizier/Mitglied) + Aktivität/Level/Name
- **Adminbereich** (`/admin/`)
  - Login/Logout
  - Gilden verwalten
  - **CSV-Import** (sftools Export)
  - manuelle Felder wie Notizen, Entlassen/Verlassen pflegbar
  - Upload/Löschen von Wappen
- **Custom Error Pages** (400, 401, 403, 404, 413, 500, 502, 503, 504)
  - inklusive passender Grafiken unter `/public/assets/errors/`

## CSV Import

- Import erfolgt über den Adminbereich (CSV Upload)
- Insert/Update anhand **Name**
- Es wird **nichts gelöscht**
- Manuelle Felder (Notizen, Entlassen/Verlassen) werden nur überschrieben, wenn die CSV dafür Werte enthält
- Zusätzlich unterstützt: Spalte **Rang** (`Anführer`, `Offizier`, `Mitglied`) zur Sortierung

## Setup / Installation

Siehe: **INSTALL.md**

## Sicherheit / Hinweise

- PHP-Ausführung in `uploads/`, `cache/`, `tmp/` wird per Nginx blockiert (harte Sicherheitsmaßnahme)
- Hidden Files sind gesperrt (außer `.well-known`)
- Errorpages sind `noindex,nofollow`
- Login-Redirect unterstützt `next=` (Weiterleitung zur zuletzt besuchten Seite)

## Projektstruktur (Pfad)

Standard-Installationspfad (Beispiel):

```text
/var/www/sfguilds/
├── app
│   ├── bootstrap.php
│   ├── db.php
│   ├── helpers.php
│   └── views
│       ├── admin.php
│       ├── guild.php
│       ├── guilds.php
│       ├── home.php
│       ├── layout.php
│       └── login.php
├── cli
│   └── import_sftools.php
├── public
│   ├── admin
│   │   ├── crest.php
│   │   ├── index.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   └── members.php
│   ├── assets
│   │   ├── app.css
│   │   ├── errors
│   │   │   ├── 400.png
│   │   │   ├── 401.png
│   │   │   ├── 403.png
│   │   │   ├── 404.png
│   │   │   ├── 413.png
│   │   │   ├── 500.png
│   │   │   ├── 502.png
│   │   │   ├── 503.png
│   │   │   └── 504.png
│   │   └── sf-logo.png
│   ├── error.php
│   ├── errors
│   │   ├── 400.html
│   │   ├── 401.html
│   │   ├── 403.html
│   │   ├── 404.html
│   │   ├── 413.html
│   │   ├── 500.html
│   │   ├── 502.html
│   │   ├── 503.html
│   │   └── 504.html
│   ├── index.php
│   └── uploads
│       └── crests
└── storage
    ├── allow_setup
    ├── import
    │   ├── archive
    │   ├── failed
    │   ├── incoming
    │   └── processing
    └── sfguilds.sqlite
```
---

wip:
- Auswertung für Gildenangriffe und -verteidigung pro Gilde.

## Lizenz

Privates Projekt / interne Nutzung.
