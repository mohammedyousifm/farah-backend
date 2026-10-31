# Deploying Farah (فرة) to production

Target: one Hostinger KVM VPS, Ubuntu 24.04, plain OS. Nginx + PHP 8.4-FPM + MySQL 8 + Redis,
Supervisor for the queue worker, cron for the scheduler, Let's Encrypt for HTTPS.

One server runs everything: the API for the mobile app (`/api/v1`), the admin dashboard (`/admin`),
the provider dashboard (`/provider`) and the public website (`/`). There is no Node/Vite build step:
Filament ships its own CSS/JS in `public/css|js|fonts/filament` (re-published by `composer install`).

Throughout this guide the domain is `farah.example.sa`, the code lives in `/var/www/farah`
(the root of the `farah` git repository) and the Linux user that deploys is `deploy`.
Replace them with the real values.

> **No secrets in git.** `.env` and the Firebase key file live only on the server. `.env.example`
> lists every key with safe defaults and placeholders. Keep a copy of the production `.env` in a
> password manager: losing `APP_KEY` makes encrypted data (two-step login secrets) unreadable.

---

## 1. Server packages (once)

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone UTC          # the app runs in UTC; Saudi time is handled in code
sudo apt -y install software-properties-common
sudo add-apt-repository -y ppa:ondrej/php   # PHP 8.4 builds for Ubuntu 24.04
sudo apt update
sudo apt -y install nginx mysql-server redis-server supervisor git unzip curl ufw \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-gd php8.4-intl php8.4-bcmath php8.4-redis php8.4-opcache
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

Extensions the code needs: `gd` (thumbnails, PDFs), `mbstring`, `intl` (Filament), `zip` (Excel
export), `pdo_mysql`, `redis` (cache and sessions), `opcache`.

### PHP settings

`/etc/php/8.4/fpm/conf.d/99-farah.ini` (and the same `upload_*`/`memory_limit` lines in
`/etc/php/8.4/cli/conf.d/99-farah.ini`):

```ini
expose_php = Off
memory_limit = 256M
upload_max_filesize = 64M     ; venue videos are accepted up to 50 MB
post_max_size = 64M
max_execution_time = 60
date.timezone = UTC

opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 30000
opcache.validate_timestamps = 0   ; code changes need "systemctl reload php8.4-fpm" (in the deploy steps)
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

### MySQL

```bash
sudo mysql_secure_installation
sudo mysql <<'SQL'
CREATE DATABASE farah CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'farah'@'localhost' IDENTIFIED BY '<long random password>';
GRANT ALL PRIVILEGES ON farah.* TO 'farah'@'localhost';
FLUSH PRIVILEGES;
SQL
```

MySQL and Redis listen on `127.0.0.1` only (the Ubuntu defaults); keep it that way.
Set `default-time-zone = '+00:00'` under `[mysqld]` if the server is not on UTC.

---

## 2. Code and `.env`

```bash
sudo adduser --disabled-password deploy && sudo usermod -aG www-data deploy
sudo mkdir -p /var/www/farah && sudo chown deploy:www-data /var/www/farah
sudo -u deploy git clone <repo-url> /var/www/farah
cd /var/www/farah
sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction
sudo -u deploy cp .env.example .env
sudo -u deploy php artisan key:generate
sudo -u deploy nano .env        # fill the checklist below
```

### `.env` production checklist

| Key | Value |
|---|---|
| `APP_ENV` | `production` (turns on the forced HTTPS, secure cookies and debug-off in `AppServiceProvider::harden()`) |
| `APP_DEBUG` | `false` |
| `APP_KEY` | from `key:generate`; back it up |
| `APP_URL` | `https://farah.example.sa` (no trailing slash) |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | the database and user created above (not root) |
| `CACHE_STORE`, `SESSION_DRIVER` | `redis` (or `database` if Redis is not installed) |
| `QUEUE_CONNECTION` | `database` |
| `LOG_STACK` / `LOG_LEVEL` / `LOG_DAILY_DAYS` | `daily` / `warning` / `14` |
| `FILESYSTEM_DISK` | `local` (private; public images name the `public` disk themselves) |
| `SESSION_SECURE_COOKIE` | `true` |
| `MAIL_*` | real SMTP mailbox (password resets, team invitations) |
| `CORS_ALLOWED_ORIGINS` | empty (only the site itself) unless another website must call the API from a browser |
| `PAYMENT_WEBHOOK_SECRET` | `openssl rand -hex 32` |
| `OTP_DEV_CODE` | **absent** (it is ignored outside local/testing anyway) |
| `PUSH_DRIVER` / `FIREBASE_CREDENTIALS` | `fcm` / `/etc/farah/firebase.json` once Firebase exists (see open items) |
| `PAYMENT_DRIVER`, `SMS_DRIVER` | only `fake` / `log` exist today (see open items) |
| `FARAH_TIMEZONE` | `Asia/Riyadh` |

Firebase key file: `sudo mkdir -p /etc/farah && sudo chown root:www-data /etc/farah && sudo chmod 750 /etc/farah`,
copy the JSON there, `chmod 640`, owner `root:www-data`. Never put it inside `/var/www/farah`.

### Permissions

```bash
cd /var/www/farah
sudo chown -R deploy:www-data .
sudo find . -type f -not -path './vendor/*' -exec chmod 644 {} \;
sudo find . -type d -not -path './vendor/*' -exec chmod 755 {} \;
sudo chmod -R ug+rwX storage bootstrap/cache
sudo chmod 640 .env
```

### First deploy only

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force   # roles, reference data, plans and the 5 admin accounts.
# Outside local/testing every admin gets a random password, printed once: save them, sign in, change them.
# The demo data seeder only runs when APP_ENV=local.
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan optimize
sudo -u www-data php artisan farah:doctor
```

Run artisan as `www-data` (or `deploy` with the group-writable folders above) so files it creates in
`storage/` stay writable by PHP-FPM.

---

## 3. Nginx

`/etc/nginx/sites-available/farah` (then `ln -s` into `sites-enabled`, remove `default`):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name farah.example.sa www.farah.example.sa;
    root /var/www/farah/public;
    # certbot replaces this block with a redirect to https (step 4)
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
}
```

After certbot (step 4), make the HTTPS server block look like this:

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name farah.example.sa;
    root /var/www/farah/public;
    index index.php;
    charset utf-8;

    ssl_certificate     /etc/letsencrypt/live/farah.example.sa/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/farah.example.sa/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    server_tokens off;
    client_max_body_size 64M;          # matches upload_max_filesize / post_max_size

    gzip on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_vary on;
    gzip_proxied any;
    gzip_types text/plain text/css text/xml application/json application/javascript application/xml image/svg+xml;

    # Pages answered by PHP already carry X-Frame-Options, CSP frame-ancestors, nosniff, Referrer-Policy,
    # Permissions-Policy and HSTS (app/Http/Middleware/SecurityHeaders.php). Do not repeat them at server
    # level or responses get duplicate headers; static files get their own copy below.

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    # robots.txt and sitemap.xml are generated by Laravel (SeoController): no static file, falls through to PHP.

    # Static assets: Filament css/js/fonts, images, uploaded public files.
    location ~* \.(?:css|js|mjs|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif|ico|mp4|webm)$ {
        try_files $uri /index.php?$query_string;
        expires 30d;
        access_log off;
        add_header Cache-Control "public, max-age=2592000";
        add_header X-Content-Type-Options "nosniff" always;
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    }

    # Uploaded public files (public/storage -> storage/app/public): never run as code.
    location ^~ /storage/ {
        try_files $uri /index.php?$query_string;   # unknown paths reach Laravel's signed-URL route
        location ~ \.php$ { return 404; }
        expires 7d;
        add_header X-Content-Type-Options "nosniff" always;
    }

    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60s;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # Only index.php is executable; no dotfiles (.env, .git, .htaccess) are ever served.
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known) { deny all; }

    access_log /var/log/nginx/farah.access.log;
    error_log  /var/log/nginx/farah.error.log warn;
}
```

`sudo nginx -t && sudo systemctl reload nginx`.

Notes:
- Nginx terminates HTTPS on the same machine and passes `HTTPS=on` to PHP, so Laravel sees secure
  requests and real client IPs without any trusted-proxy setting. If Cloudflare (or any proxy) is put
  in front later, add `$middleware->trustProxies(at: [...])` in `bootstrap/app.php` and Nginx
  `real_ip_header`, otherwise rate limits key on the proxy's IP.
- Responses are gzipped by Nginx; the app's own gzip (`CompressResponse`) steps aside when a
  `Content-Encoding` is already set and otherwise does no harm.
- `public/.htaccess` is only for Apache and is ignored by Nginx.
- Health check for uptime monitors: `GET https://farah.example.sa/up` (200 when the app boots).

---

## 4. HTTPS (Let's Encrypt)

```bash
sudo apt -y install certbot python3-certbot-nginx
sudo certbot --nginx -d farah.example.sa -d www.farah.example.sa --redirect -m <ops e-mail> --agree-tos
sudo systemctl status certbot.timer     # renewal runs twice a day
sudo certbot renew --dry-run
```

---

## 5. Queue worker (Supervisor)

Nothing is queued by the code today (notifications and SMS run after the response through
`defer()`), but run one worker so anything queued later (mail, Filament jobs) is not left behind.

`/etc/supervisor/conf.d/farah-worker.conf`:

```ini
[program:farah-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/farah/artisan queue:work database --sleep=3 --tries=3 --backoff=30 --timeout=60 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/var/www/farah/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
```

`--timeout` (60) stays below `DB_QUEUE_RETRY_AFTER` (90). Failed jobs land in the `failed_jobs`
table: `php artisan queue:failed`, `queue:retry all`.

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

---

## 6. Scheduler (cron)

```bash
sudo crontab -u www-data -e
```

```
* * * * * cd /var/www/farah && php artisan schedule:run >> /dev/null 2>&1
```

Without it unpaid orders never expire, finished bookings never complete, and campaigns never send.
`php artisan schedule:list` shows the jobs (times shown in UTC). Daily jobs are set in Saudi time in
`routes/console.php`: prune 03:30, settlements 05:00 (weekly on Sunday by default), document-expiry
warnings 08:00. `farah:doctor` and the admin dashboard warn when the scheduler stops.

---

## 7. Firewall and SSH

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'     # 80 + 443
sudo ufw enable && sudo ufw status
```

Also: SSH keys only (`PasswordAuthentication no`, `PermitRootLogin prohibit-password` in
`/etc/ssh/sshd_config`), and `sudo apt -y install unattended-upgrades`.
Hostinger's panel firewall can mirror the same rules.

Optional, fail2ban:

```bash
sudo apt -y install fail2ban
sudo tee /etc/fail2ban/jail.local >/dev/null <<'EOF'
[sshd]
enabled = true
maxretry = 5
bantime = 1h

[nginx-limit-req]
enabled = false   ; enable after adding limit_req zones to nginx
EOF
sudo systemctl enable --now fail2ban
```

The app already rate-limits sign-in codes, the API and dashboard logins itself.

---

## 8. Backups (daily)

What to keep: the database, `storage/app` (provider documents, payout receipts, venue photos) and
the `.env` + Firebase key (in a password manager, not with the backups).

`/usr/local/bin/farah-backup`:

```bash
#!/bin/bash
set -euo pipefail
DEST=/var/backups/farah
STAMP=$(date +%F)
mkdir -p "$DEST"
# credentials in /root/.my.cnf ([client] user=farah password=...), chmod 600
mysqldump --single-transaction --quick --routines --triggers --no-tablespaces farah | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/storage-$STAMP.tar.gz" -C /var/www/farah/storage app
find "$DEST" -type f -mtime +14 -delete
```

```bash
sudo chmod 700 /usr/local/bin/farah-backup
echo '30 1 * * * root /usr/local/bin/farah-backup' | sudo tee /etc/cron.d/farah-backup
```

Copy the folder off the server (Hostinger weekly snapshots alone are not enough): e.g. `rclone` or
`rsync` to another host / object storage, encrypted. Test a restore once:
`gunzip < db-YYYY-MM-DD.sql.gz | mysql farah_restore_test`.

---

## 9. Updating (every release)

```bash
cd /var/www/farah
sudo -u www-data php artisan down --retry=60
sudo -u deploy git pull --ff-only
sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize          # config, routes, views, events, Filament + icons
sudo -u www-data php artisan queue:restart
sudo systemctl reload php8.4-fpm               # opcache.validate_timestamps=0
sudo -u www-data php artisan up
sudo -u www-data php artisan farah:doctor
```

`optimize` caches the configuration: after any `.env` change run `php artisan optimize` again
(`env()` is only read while caching). Roll back by checking out the previous tag and repeating
the steps (migrations are additive; restore the backup if one must be undone).

Before a risky migration, take a backup first (`/usr/local/bin/farah-backup`).

---

## 10. Files that must not be on the server's public folder

`public/` in git holds only `index.php`, `.htaccess`, `favicon.ico`, `images/`; `composer install`
adds `css|js|fonts/filament`, and `storage:link` adds `public/storage`. These local-only items are
git-ignored and must not be copied by hand:

- `public/farah.apk` (a development test build of the Android app),
- `public/app/` (a development Flutter-web build).

---

## 11. Open items before real customers

`php artisan farah:doctor` fails on each of these until they are done:

1. **Payment gateway**: only the `fake` sandbox driver exists (`PAYMENT_DRIVER=fake`, nobody is
   charged). A real driver (e.g. Moyasar / HyperPay / Tap) has to be written and registered in
   `AppServiceProvider::DRIVERS`, with its webhook secret in `PAYMENT_WEBHOOK_SECRET`.
2. **SMS provider**: only `SMS_DRIVER=log` exists; sign-in codes are written to the log, not sent.
   A real driver (e.g. Unifonic / Taqnyat / Msegat, with a registered sender name in `SMS_SENDER`)
   is needed before customers can sign in.
3. **Firebase**: no service-account key yet. Create the Firebase project, set `PUSH_DRIVER=fcm` and
   `FIREBASE_CREDENTIALS=/etc/farah/firebase.json`, and add the app's `google-services.json` /
   iOS plist to the Flutter build.
4. **Mail**: a real mailbox for `MAIL_*`.
5. **Mobile app**: release builds must point `API_BASE` at `https://farah.example.sa` and be signed
   with the release key.

See also [SECURITY.md](../SECURITY.md) (what the code already does) and [API.md](API.md).
