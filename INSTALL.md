# Installation – S&F Guilds

Diese Anleitung geht von einem Debian/Ubuntu-Server mit Nginx aus.
Ziel: Projekt unter `/var/www/sfguilds` betreiben, HTTPS via Certbot, PHP-FPM (8.3), SQLite.

---

## 1) Voraussetzungen

Pakete installieren (Debian/Ubuntu, als root):

```bash
apt update
apt install -y nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl unzip sqlite3
```

Optional (wenn du Certbot nutzt):

```bash
apt install -y certbot python3-certbot-nginx
```

---

## 2) Projekt ablegen

Beispiel: Repo nach `/var/www/sfguilds` klonen oder Dateien kopieren.

```bash
mkdir -p /var/www/sfguilds
# Dateien hier hinein kopieren / git clone ...
```

Wichtige Verzeichnisse anlegen:

```bash
mkdir -p /var/www/sfguilds/storage
mkdir -p /var/www/sfguilds/public/uploads/crests
mkdir -p /var/www/sfguilds/storage/import/{incoming,processing,archive,failed}
```

---

## 3) Rechte setzen (wichtig)

Nginx/PHP laufen typischerweise als `www-data`. Storage/Uploads müssen schreibbar sein:

```bash
chown -R www-data:www-data /var/www/sfguilds/storage /var/www/sfguilds/public/uploads
chmod -R 775 /var/www/sfguilds/storage /var/www/sfguilds/public/uploads
```

---

## 4) Nginx vHost (HTTPS)

Beispiel-Serverblock für `your.tld`.
Datei z. B.: `/etc/nginx/sites-available/your.tld`

> Wichtig: `root` muss auf `.../public` zeigen.

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    server_name your.tld;
    root /var/www/sfguilds/public;
    index index.php index.html;

    access_log /var/log/nginx/sfguilds_access.log;
    error_log  /var/log/nginx/sfguilds_error.log;

    client_max_body_size 20m;

    ssl_certificate /etc/letsencrypt/live/your.tld/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your.tld/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location = /admin {
        return 308 /admin/;
    }

    location ~ ^/admin/.*\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /admin/ {
        try_files $uri $uri/ /admin/index.php?$query_string;
        rewrite ^ /admin/index.php last;
    }

    location ^~ /.well-known/acme-challenge/ {
        allow all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;

        fastcgi_read_timeout 60s;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* ^/(uploads|cache|tmp)/.*\.php$ {
        deny all;
    }

    location ^~ /uploads/ {
        if ($uri ~* \.(php|phtml|phar|php\d)$) { return 403; }
        try_files $uri =404;
    }

    error_page 400 /errors/400.html;
    error_page 401 /errors/401.html;
    error_page 403 /errors/403.html;
    error_page 404 /errors/404.html;
    error_page 413 /errors/413.html;
    error_page 500 /errors/500.html;
    error_page 502 /errors/502.html;
    error_page 503 /errors/503.html;
    error_page 504 /errors/504.html;

    location ^~ /errors/ {
        internal;
    }
}

server {
    listen 80;
    listen [::]:80;

    server_name your.tld;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/sfguilds;
        allow all;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

Aktivieren & reload:

```bash
ln -s /etc/nginx/sites-available/your.tld /etc/nginx/sites-enabled/your.tld
nginx -t
systemctl reload nginx
```

---

## 5) PHP-FPM prüfen

```bash
systemctl status php8.3-fpm --no-pager
```

---

## 6) Initiales Setup (Admin User anlegen)

Das Projekt schützt das Setup über eine Flag-Datei:

- Datei: `/var/www/sfguilds/storage/allow_setup`
- Wenn **keine User existieren** und diese Datei vorhanden ist, wirst du beim Aufruf von `/` auf `/admin/` geleitet.

### Setup freischalten:

```bash
touch /var/www/sfguilds/storage/allow_setup
chown www-data:www-data /var/www/sfguilds/storage/allow_setup
```

Dann im Browser:

- `https://your.tld/`
- Admin anlegen
- Danach kannst du (optional) das Flag wieder entfernen:

```bash
rm -f /var/www/sfguilds/storage/allow_setup
```

---

## 7) CSV Import

Im Adminbereich:

- **Admin → Mitglieder**
- Gilde auswählen
- CSV hochladen und importieren

Hinweise:

- Update/Insert anhand `Name`
- Spalte **Rang** (`Anführer`, `Offizier`, `Mitglied`) wird importiert und für Sortierung genutzt
- `days_offline` wird nicht mehr importiert (wird live berechnet)

---

## 8) Wappen (Crests)

Uploads landen unter:

- `/var/www/sfguilds/public/uploads/crests`

Achte darauf, dass Upload-Verzeichnis `www-data` gehört und beschreibbar ist.

---

## 9) Fehlerseiten

Statische Fehlerseiten liegen unter:

- `/var/www/sfguilds/public/errors/*.html`

Grafiken dazu unter:

- `/var/www/sfguilds/public/assets/errors/*.png`

---

## 10) Troubleshooting

### DB/Storage Probleme
- Check Rechte:
  - `/var/www/sfguilds/storage`
  - `/var/www/sfguilds/public/uploads`

### Logs
- Nginx:
  - `/var/log/nginx/sfguilds_error.log`
  - `/var/log/nginx/sfguilds_access.log`
- PHP-FPM:
  - `journalctl -u php8.3-fpm -e`

---

## 11) Projektpfad

```text
/var/www/sfguilds/
```
