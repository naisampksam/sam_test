# Image → DST Embroidery Converter (PHP)

Upload an image, adjust settings, and download a Tajima `.dst` file ready for the embroidery machine.

## Install on your web host (e.g. embr.loomaapparels.com)
Needs PHP 8.0+ with the GD extension (standard on almost every host).

1. In your hosting panel (cPanel / hPanel / Plesk) create the subdomain **embr.loomaapparels.com**.
   Note its folder (usually `public_html/embr.loomaapparels.com` or `embr.loomaapparels.com`).
2. Turn on SSL for the subdomain (free Let's Encrypt / AutoSSL in the panel). The site forces HTTPS.
3. Open the File Manager, go into that folder, upload the ZIP and **Extract** it there
   (`index.php` must be directly in the subdomain folder).
4. Make sure the `data` folder is writable (permission 755; 775 on some hosts).
5. Open https://embr.loomaapparels.com and log in. Change the password via "Change password".

Security: login with hashed passwords, lock-out after 5 wrong attempts (15 min), CSRF protection,
HTTPS only, hidden from search engines, engine/data files blocked from direct access.
To add another user, log in with a new name is not possible – add it by copying a line in
`data/users.php` (use "Change password" to generate hashes) or ask your developer.

## Run locally
```
cd dst-converter
php -S localhost:8000
```

## How it digitizes (like a digitizer would)
Every color is split into separate shapes and each shape gets the right stitch type:

| Shape | Stitch | Underlay |
|---|---|---|
| Wide areas | Tatami fill (4 mm stitches, 0.25 mm rows, angle follows the shape) | Edge run + crossed tatami |
| Narrow shapes – letters, borders, strokes | Satin column (0.4 mm per edge) that turns with the shape, mitred corners, split above 7 mm | Centre run + zigzag |
| Hair-thin lines | Bold triple (bean) running stitch on the centre line | – |

Default densities, stitch lengths and lock stitches were measured from professional DST files.
Between shapes it travels with running stitches hidden under areas sewn later, so it needs far fewer trims.

Plus: Lab color matching, removal of specks and blurry-edge slivers, color overlap (no gaps),
pull compensation, nearest-first sewing order, hidden travel stitches inside shapes, lock stitches,
trims, no stitch under 0.3 mm or over 7 mm, valid DST header.

## Best results
* Use a **clean logo** with flat colors (PNG with transparent background is ideal). Photos give noisy results with many trims.
* Pick **Coverage → "Safe – no thread breaks"** for smooth sewing. Use **Heavy/Maximum** only for full coverage on stable fabric (sew slower, use firm backing).
* Set **Thread colors** to the real number of colors in the design, or type your own thread colors as hex.
* Text should be at least **5 mm tall**. Smaller text can't be sewn cleanly by anyone.
* Read any ⚠ warnings shown after converting. They point out things that can cause thread breaks or poor results.
* Always do a test sew on the same fabric before production.

Note: DST stores only stitches and color stops, not thread colors. Use the thread table on the result page.
