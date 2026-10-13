# Embroidery → DST add-on for Looma Orders (order.loomaapparels.com)

Adds the image → DST embroidery converter as a page inside the order system (nothing is saved
on the server: the image is deleted right after reading and the DST is downloaded with a button):
same login, same menu, per-staff permission.

## Files
| File | What |
|---|---|
| `embroidery.php` | the converter page (More → Embroidery → DST) |
| `inc/digitizer.php` | the converter engine (blocked from direct web access by `inc/.htaccess`) |
| `inc/dstedit.php` | opens and changes existing .DST files |
| `inc/header.php` | menu: adds "Embroidery → DST" under More → Work |
| `inc/fields.php` | staff permission: adds "Embroidery → DST" switch |
| `assets/vendor/pdfjs/` | PDF.js 3.11.174 (Mozilla, Apache-2.0) – turns PDF artwork into an image in the browser |

## Using it
- Upload PNG, JPG, GIF, WEBP, BMP, **PDF** (multi-page PDFs: pick the page) or **SVG** (Illustrator, Inkscape, CorelDRAW exports). PDF and SVG are turned into a sharp image in the browser at the chosen size. Width = width of the design itself
  (empty margins are cut off automatically).
- After the first **Convert**, every change (width, colors, presets, advanced settings, PDF page) updates the
  result by itself – no new upload needed.
- Thread list: click a color to change the thread color (preview updates instantly); untick **Sew** to leave
  a color out of the file (it can be put back).
- **✏️ Mark a problem area**: draw around a part that came out badly, describe it in your own words or tick the
  problems (messy, small details lost, rough edges, too thick, gaps, stitch direction, stitch type, remove), see the
  suggestions for that area, and press **Redo this area** – only that part changes. Several areas possible.
- **Width and height are linked** (🔗) to the artwork's proportions: type either one and the other follows. Unlink to stretch.
- Machine-ready: starts at the design centre and returns there at the end (green cross in the preview); every cut is
  3+ jumps so the machine trims automatically; jumps max 7 mm; satin stitches over 5 mm are split.
- **Open a .DST file** to change it (also from other programs, e.g. Wilcom). Two ways:
  - **Keep the original stitches**: change the size (linked width/height, "Original size" button), rotate (⟲ ⟳ or any
    angle), mirror, thread colors, **sewing order** (↑ ↓), leave color stops out, remove too-short stitches, split long
    stitches, add cuts to long jumps. The pen marks an area to **erase** it ("remove") or to **redo only that area** with
    any fix (messy, direction, stitch type, details…) – the rest of the file stays exactly as it was.
    Resizing more than ±20 % shows a warning: the density stays the same, so use the second way for big size changes.
  - **Re-digitize the whole design**: every stitch is made again with this app's settings (all presets and advanced
    settings work). Give color stops the same thread color to merge them.
- Press **Download** to save the .dst. Nothing is stored on the server.

`inc/header.php` and `inc/fields.php` are the latest versions from the order app
(branch `claude/nifty-cannon-6tpv2n`, "Order date on the order form") with only these lines added.

## Install
Upload `looma-embroidery-update.zip` into the order app folder in File Manager and Extract (overwrite).
No database changes. Admins see the page right away; for staff, tick
**Embroidery → DST** under More → Staff → (person) → "What can they do?".
