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

`inc/header.php` and `inc/fields.php` are the latest versions from the order app
(branch `claude/nifty-cannon-6tpv2n`, "Order date on the order form") with only these lines added.

## Install
Upload `looma-embroidery-update.zip` into the order app folder in File Manager and Extract (overwrite).
No database changes. Admins see the page right away; for staff, tick
**Embroidery → DST** under More → Staff → (person) → "What can they do?".
