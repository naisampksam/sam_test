#!/bin/sh
# Builds dist/looma-attendance.zip, the WordPress plugin to upload in
# WordPress → Plugins → Add New → Upload Plugin.
# The web pages in public/ are shared with the Node.js version and copied in.
set -e
cd "$(dirname "$0")/.."

PLUGIN=wordpress/looma-attendance
rm -rf "$PLUGIN/assets"
mkdir -p "$PLUGIN/assets" dist
cp public/index.html public/admin.html public/app.css public/common.js public/kiosk.js public/admin.js public/logo.svg "$PLUGIN/assets/"

rm -f dist/looma-attendance.zip
python3 - <<'PY'
import os, zipfile
root = 'wordpress'
with zipfile.ZipFile('dist/looma-attendance.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(os.path.join(root, 'looma-attendance')):
        dirs.sort()
        for f in sorted(files):
            p = os.path.join(base, f)
            z.write(p, os.path.relpath(p, root))
PY
echo "Built dist/looma-attendance.zip"
