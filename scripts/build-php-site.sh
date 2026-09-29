#!/bin/sh
# Builds dist/looma-attendance-site.zip: the standalone PHP version (no
# WordPress) to extract into the folder of a (sub)domain such as
# attendance.loomaapparels.com. Shares the PHP core with the WordPress plugin
# and the web pages with the Node.js version.
set -e
cd "$(dirname "$0")/.."

OUT=build/php-site
rm -rf build/php-site
mkdir -p "$OUT/assets" "$OUT/includes" "$OUT/seed" "$OUT/data" dist
cp php-site/index.php php-site/.htaccess php-site/config.sample.php "$OUT/"
cp -r php-site/lib "$OUT/"
cp wordpress/looma-attendance/includes/*.php "$OUT/includes/"
cp wordpress/looma-attendance/seed/looma-staff.php "$OUT/seed/"
cp public/index.html public/admin.html public/app.css public/common.js public/kiosk.js public/admin.js public/logo.svg "$OUT/assets/"
printf 'Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n' > "$OUT/data/.htaccess"
: > "$OUT/data/index.html"
for d in includes lib seed; do : > "$OUT/$d/index.html"; done

rm -f dist/looma-attendance-site.zip
python3 - <<'PY'
import os, zipfile
root = 'build/php-site'
with zipfile.ZipFile('dist/looma-attendance-site.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(root):
        dirs.sort()
        for f in sorted(files):
            p = os.path.join(base, f)
            z.write(p, os.path.relpath(p, root))
PY
echo "Built dist/looma-attendance-site.zip"
