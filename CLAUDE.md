# Looma ERP — notes for Claude

- **Every change to what staff see or do must also update the How to (SOP) page** (`inc/sop.php`), in both English and
  Malayalam: add or change the topic (max 5 short steps, each `short line :: explanation`), its search words, and the
  “What’s in the app” option in `sop_options()` for a new feature. `tests/e2e/help_e2e.js` checks the page.
- Data must never be lost: deploy zips contain code only (no `config.php`, uploads, sessions, `attendance/data/db.php`).
- The live site is erp.loomaapparels.com on Hostinger (PHP 8, MySQL); `.htaccess` rewrites may not apply there.
