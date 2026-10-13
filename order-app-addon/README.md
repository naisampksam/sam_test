# Embroidery → DST add-on for Looma Orders (order.loomaapparels.com)

Adds the image → DST embroidery converter as a page inside the order system (nothing is saved
on the server: the image is deleted right after reading and the DST is downloaded with a button):
same login, same menu, per-staff permission.

## Files
| File | What |
|---|---|
| `embroidery.php` | the converter page (More → Embroidery → DST) |
| `inc/digitizer.php` | the converter engine (blocked from direct web access by `inc/.htaccess`) |
| `inc/header.php` | menu: adds "Embroidery → DST" under More → Work |
| `inc/fields.php` | staff permission: adds "Embroidery → DST" switch |
| `assets/vendor/pdfjs/` | PDF.js 3.11.174 (Mozilla, Apache-2.0) – turns PDF artwork into an image in the browser |

## Using it
- Upload PNG, JPG, GIF, WEBP, BMP or **PDF** (multi-page PDFs: pick the page). Width = width of the design itself
  (empty margins are cut off automatically).
- After the first **Convert**, every change (width, colors, presets, advanced settings, PDF page) updates the
  result by itself – no new upload needed.
- Thread list: click a color to change the thread color (preview updates instantly); untick **Sew** to leave
  a color out of the file (it can be put back).
- Press **Download** to save the .dst. Nothing is stored on the server.

`inc/header.php` and `inc/fields.php` are the latest versions from the order app
(branch `claude/nifty-cannon-6tpv2n`, "Order date on the order form") with only these lines added.

## Install
Upload `looma-embroidery-update.zip` into the order app folder in File Manager and Extract (overwrite).
No database changes. Admins see the page right away; for staff, tick
**Embroidery → DST** under More → Staff → (person) → "What can they do?".
