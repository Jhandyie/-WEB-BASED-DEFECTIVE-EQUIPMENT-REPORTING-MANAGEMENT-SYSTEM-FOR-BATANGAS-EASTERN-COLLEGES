#!/usr/bin/env bash
#
# provision_vm.sh - turn a bare Ubuntu VM into a host for this system.
#
# For the free-tier machines (Google Cloud e2-micro, Oracle Always Free) where
# you get nothing but an empty Ubuntu box. Installs Apache and PHP with the
# PostgreSQL driver Supabase needs, fixes the two settings that silently break
# the app, and checks the things that are painful to discover later.
#
#   curl -fsSL <raw-url>/provision_vm.sh -o provision_vm.sh
#   sudo bash provision_vm.sh --domain pmo.bec.edu.ph
#
# Safe to run twice: it changes only what is not already correct.
#
# What it deliberately does NOT do:
#   - create your secret files (.env, config/chat_secrets.php,
#     data/system_settings.json). Those are copied by hand, on purpose.
#   - request the HTTPS certificate unless --domain is given and already
#     resolves to this machine. certbot fails confusingly otherwise.

set -euo pipefail

APP_DIR="/var/www/bec-pmo"
DOMAIN=""
REPO=""
PHP_TARGET="8.2"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --dir)    APP_DIR="${2:-}"; shift 2 ;;
        --repo)   REPO="${2:-}";   shift 2 ;;
        -h|--help) grep '^#' "$0" | cut -c3-; exit 0 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then echo "Run with sudo."; exit 1; fi

say()  { printf '\n\033[1;35m==> %s\033[0m\n' "$1"; }
ok()   { printf '    \033[0;32m[ OK ]\033[0m  %s\n' "$1"; }
warn() { printf '    \033[0;33m[WARN]\033[0m  %s\n' "$1"; }
bad()  { printf '    \033[0;31m[FAIL]\033[0m  %s\n' "$1"; }

FAILED=0

say "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# php-pgsql is the one that matters: it supplies pdo_pgsql, without which the
# app cannot reach Supabase at all. The rest are what the code actually calls.
apt-get install -y -qq \
    apache2 php libapache2-mod-php \
    php-pgsql php-curl php-mbstring php-zip php-gd php-xml \
    certbot python3-certbot-apache \
    git unzip curl netcat-openbsd cron >/dev/null
ok "apache2, php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'), extensions"

say "Code"
if [[ -n "$REPO" && ! -d "$APP_DIR/.git" ]]; then
    mkdir -p "$(dirname "$APP_DIR")"
    git clone --depth 1 "$REPO" "$APP_DIR"
    ok "cloned into $APP_DIR"
elif [[ -d "$APP_DIR" ]]; then
    ok "using existing $APP_DIR"
else
    mkdir -p "$APP_DIR"
    warn "$APP_DIR is empty - upload the project here before going live"
fi

say "Apache"
# AllowOverride All is the setting people miss. Every .htaccess in this project
# is ignored without it, including the ones keeping data/ off the web - which
# is where the Gmail app password lives.
cat > /etc/apache2/sites-available/bec-pmo.conf <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN:-localhost}
    DocumentRoot $APP_DIR

    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  \${APACHE_LOG_DIR}/bec-pmo-error.log
    CustomLog \${APACHE_LOG_DIR}/bec-pmo-access.log combined
</VirtualHost>
EOF
a2enmod rewrite headers >/dev/null 2>&1 || true
a2ensite bec-pmo >/dev/null 2>&1 || true
a2dissite 000-default >/dev/null 2>&1 || true
apache2ctl configtest >/dev/null 2>&1 && ok "config valid, AllowOverride All set" || bad "apache config test failed"
systemctl reload apache2

say "PHP settings"
PHP_IN="/etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/apache2/php.ini"
if [[ -f "$PHP_IN" ]]; then
    # 40M matches the photo size the UI already enforces; display_errors off
    # keeps stack traces out of the JSON endpoints.
    sed -i 's/^upload_max_filesize.*/upload_max_filesize = 40M/' "$PHP_IN"
    sed -i 's/^post_max_size.*/post_max_size = 40M/'             "$PHP_IN"
    sed -i 's/^display_errors.*/display_errors = Off/'           "$PHP_IN"
    sed -i 's/^;\?date.timezone.*/date.timezone = Asia\/Manila/' "$PHP_IN"
    ok "upload 40M, display_errors off, timezone Asia/Manila"
    systemctl reload apache2
else
    warn "could not find php.ini at $PHP_IN"
fi

say "Writable folders"
for d in uploads uploads/reports uploads/completed_work data data/mail_outbox logs backups api/data; do
    mkdir -p "$APP_DIR/$d"
done
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod -R 775 "$APP_DIR"/{uploads,data,logs,backups} 2>/dev/null || true
ok "uploads, data, logs, backups, api/data writable by www-data"

say "Swap"
# The 1 GB free tiers run out of memory under Apache + PHP and the kernel starts
# killing processes. Cheap insurance.
TOTAL_MB=$(free -m | awk '/^Mem:/{print $2}')
if [[ "$TOTAL_MB" -lt 2048 ]] && ! swapon --show | grep -q .; then
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    ok "2 GB swap added (${TOTAL_MB} MB RAM)"
else
    ok "swap not needed or already present"
fi

say "Firewall"
if command -v ufw >/dev/null; then
    ufw allow OpenSSH >/dev/null 2>&1 || true
    ufw allow 80/tcp  >/dev/null 2>&1 || true
    ufw allow 443/tcp >/dev/null 2>&1 || true
    ufw --force enable >/dev/null 2>&1 || true
    ok "ports 22, 80, 443 open on the machine"
fi
warn "your CLOUD firewall is separate - open 80 and 443 there too, or the site stays unreachable"

say "Nightly backup"
CRON="0 18 * * * www-data /usr/bin/php $APP_DIR/scripts/backup_db.php >/dev/null 2>&1"
echo "$CRON" > /etc/cron.d/bec-pmo-backup
chmod 644 /etc/cron.d/bec-pmo-backup
ok "backup_db.php scheduled daily at 18:00"

# ---------------------------------------------------------------- checks ----
say "Checks that matter"

if php -m | grep -q '^pdo_pgsql$'; then ok "pdo_pgsql present - Supabase reachable in principle"
else bad "pdo_pgsql MISSING - the app cannot reach its database"; FAILED=1; fi

# The whole login is an emailed code. A machine that cannot open 587 cannot log
# anyone in, and Oracle blocks it by default.
if timeout 12 nc -z smtp.gmail.com 587 2>/dev/null; then
    ok "outbound SMTP 587 open - verification codes can be sent"
else
    bad "outbound SMTP 587 BLOCKED - nobody will be able to sign in"
    warn "common on Oracle Cloud. Ask support to lift it, or move the mailer to an HTTP email API."
    FAILED=1
fi

if [[ -f "$APP_DIR/.env" ]]; then
    PGH=$(grep -E '^PGHOST=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
    PGP=$(grep -E '^PGPORT=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'"'"' \r'); PGP=${PGP:-5432}
    if [[ -n "$PGH" ]] && timeout 12 nc -z "$PGH" "$PGP" 2>/dev/null; then
        ok "reached Supabase at $PGH:$PGP"
    else
        bad "could not reach Supabase at ${PGH:-<unset>}:${PGP}"; FAILED=1
    fi
else
    warn ".env not present yet - copy your secret files, then re-run this script"
fi

if [[ -n "$DOMAIN" ]]; then
    RESOLVED=$(getent hosts "$DOMAIN" | awk '{print $1}' | head -1)
    PUBLIC=$(curl -fsS --max-time 8 https://api.ipify.org 2>/dev/null || echo "")
    if [[ -n "$RESOLVED" && "$RESOLVED" == "$PUBLIC" ]]; then
        say "HTTPS"
        certbot --apache -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect || \
            warn "certbot did not complete - run 'sudo certbot --apache' by hand"
    else
        warn "$DOMAIN resolves to '${RESOLVED:-nothing}' but this machine is '${PUBLIC:-unknown}'"
        warn "point the DNS record here first, then: sudo certbot --apache -d $DOMAIN"
    fi
fi

# ---------------------------------------------------------------- wrap up ---
IP=$(curl -fsS --max-time 8 https://api.ipify.org 2>/dev/null || echo "this machine")
say "Done"
echo "    Site root : $APP_DIR"
echo "    Reachable : http://${DOMAIN:-$IP}/"
echo ""
echo "    Still yours to do:"
echo "      1. Copy the three secret files (.env, config/chat_secrets.php,"
echo "         data/system_settings.json) - they are not in the repo."
echo "      2. Copy the uploads/ photos across (~73 MB)."
echo "      3. Confirm data/ is not public:"
echo "           curl -I http://${DOMAIN:-$IP}/data/system_settings.json"
echo "         Anything other than 403 or 404 means your Gmail app password is"
echo "         downloadable. Fix that before telling anyone the address."
echo "      4. php $APP_DIR/scripts/demo_preflight.php"
echo ""

if [[ "$FAILED" -ne 0 ]]; then
    bad "One or more checks failed - read them above before going further."
    exit 1
fi
ok "All checks passed."
