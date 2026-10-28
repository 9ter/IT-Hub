#!/usr/bin/env bash
# เพิ่ม IT Hub (/it-hub) เข้า vhost 8443/8445 ของ ERP
# ใช้: sudo bash /var/www/IT-Hub/deploy/install-apache.sh
set -Eeuo pipefail

vhost=/etc/apache2/sites-available/web-erp-ssl.conf
line='    Include /var/www/IT-Hub/deploy/apache-it-hub.conf'

[[ ${EUID} -eq 0 ]] || { echo 'ต้องรันด้วย sudo' >&2; exit 1; }

if grep -qF 'IT-Hub/deploy/apache-it-hub.conf' "$vhost"; then
    echo 'มี Include ของ IT Hub อยู่แล้ว — ข้ามการแก้ไฟล์'
else
    count=$(grep -cE '^\s*DocumentRoot /var/www/web-erp/public\s*$' "$vhost" || true)
    [[ $count == 2 ]] || { echo "คาดว่าจะเจอ DocumentRoot 2 ที่ แต่เจอ $count — ไม่แก้ไฟล์" >&2; exit 1; }

    backup="$vhost.bak.ithub-$(date +%Y%m%d%H%M%S)"
    cp -a "$vhost" "$backup"
    echo "สำรองไฟล์เดิมไว้ที่ $backup"

    sed -i -E "s#^(\s*DocumentRoot /var/www/web-erp/public\s*)\$#$line\n\n\1#" "$vhost"

    if ! apache2ctl configtest; then
        cp -a "$backup" "$vhost"
        echo 'configtest ไม่ผ่าน — คืนไฟล์เดิมแล้ว ERP ไม่ได้รับผลกระทบ' >&2
        exit 1
    fi
fi

systemctl reload apache2
sleep 1

check() {
    local url=$1 expect=$2 code
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$url")
    printf '%-50s %s %s\n' "$url" "$code" "$([[ $code == "$expect" ]] && echo OK || echo "FAIL (ต้องได้ $expect)")"
}
check https://127.0.0.1:8445/login 200
check https://127.0.0.1:8443/login 200
check https://127.0.0.1:8445/it-hub/ 200
check https://127.0.0.1:8445/it-hub/api/meta 200
check https://127.0.0.1:8443/it-hub/api/meta 200
