# S&F Guilds

Kleines, schnelles PHP-Projekt zum Verwalten und Anzeigen von Shakes & Fidget Gilden- und Member-Daten – inkl. Admin-Panel, Gildenübersicht und Gildendetailseiten.

**Ziel:** Die bisherige Excel-Ansicht und Logik (Spalten + bedingte Formatierung) als Webansicht nachbauen und Daten per CSV/JSON (Export aus sftools) importieren.

---

## Features

- ✅ **Startseite** mit Status/Navigation
- ✅ **Gildenübersicht** (`/guilds`)
- ✅ **Gildenseite** (`/guild?server=…&name=…` oder intern via ID – je nach Routing)
- ✅ **Admin-Panel** (`/admin/`) zum Anlegen/Löschen von Gilden (per BasicAuth geschützt)
- ✅ **SQLite** als Datenbank (kein Docker, keine externen Services nötig)
- ✅ **Member-Felder wie in Excel**
  - `level`, `last_online`, `joined_at`, `gold`, `mentor`, `knight_hall`, `guild_pet`
  - `fired_at` (Entlassen-Datum), `left_at` (Verlassen-Datum), `notes`
- ✅ **Eindeutigkeit**
  - Gilde: `server + guild_name`
  - Member: `guild_id + member_name`
- 🟡 **Import (Roadmap)** CSV/JSON (sftools Export)
- 🟡 **Wappen-Upload pro Gilde** (Roadmap)

---

## Projektstruktur

```text
/var/www/sfguilds/
├── app
│   ├── bootstrap.php
│   ├── db.php
│   ├── helpers.php
│   └── views
│       ├── admin.php
│       ├── guild.php
│       ├── guilds.php
│       ├── home.php
│       ├── layout.php
│       └── login.php
├── cli
│   └── import_sftools.php
├── public
│   ├── admin
│   │   ├── crest.php
│   │   ├── index.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   └── members.php
│   ├── assets
│   │   ├── app.css
│   │   ├── errors
│   │   │   ├── 400.png
│   │   │   ├── 401.png
│   │   │   ├── 403.png
│   │   │   ├── 404.png
│   │   │   ├── 413.png
│   │   │   ├── 500.png
│   │   │   ├── 502.png
│   │   │   ├── 503.png
│   │   │   └── 504.png
│   │   └── sf-logo.png
│   ├── error.php
│   ├── errors
│   │   ├── 400.html
│   │   ├── 401.html
│   │   ├── 403.html
│   │   ├── 404.html
│   │   ├── 413.html
│   │   ├── 500.html
│   │   ├── 502.html
│   │   ├── 503.html
│   │   └── 504.html
│   ├── index.php
│   └── uploads
│       └── crests
└── storage
    ├── allow_setup
    ├── import
    │   ├── archive
    │   ├── failed
    │   ├── incoming
    │   └── processing
    └── sfguilds.sqlite
```
Wichtig: DocumentRoot zeigt auf /var/www/sfguilds/public.
