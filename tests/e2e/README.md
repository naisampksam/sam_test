# Browser tests (end-to-end)

These tests drive the real app in a headless Chromium browser (Playwright) and check the
database afterwards. They cover orders, prints & pricing, bills & GST, payments & dues,
quotations, shipping labels, stock & production costs, tasks & staff performance.

**Never run them against the live site.** They create and delete orders, bills, tasks and
production batches. Use a local copy of the app with a test database.

## What you need

- PHP 8 and MariaDB/MySQL with a test copy of the database (`looma`), the `mysql` command line
  client on the path (the tests read the database with it).
- Node 18+ and Playwright (`npm i -g playwright`, then `npx playwright install chromium`).
- Accounts in the test database: `admin` / `admin12345` (admin) and `anu` / `pass1234`
  (orders staff). `staff_e2e` creates `vedit` (video editor) and `dmark` (marketing) itself.
- The catalog from Catalog 2026 (e.g. 190 GSM Regular Fit - Single Jersey, 250 GSM Oversized
  Fit - French Terry) — the app loads it on first run.

## Run

```sh
php -S 127.0.0.1:8080                                 # from the app folder
PWPATH=$(npm root -g)/playwright tests/e2e/run-all.sh   # every test
PWPATH=$(npm root -g)/playwright tests/e2e/run-all.sh quote_e2e neck_e2e   # some
```

Settings (environment variables): `BASE_URL` (default `http://127.0.0.1:8080/`),
`DB_NAME` (default `looma`), `PWPATH` (Playwright package folder).

Each test prints `PASS …` / `FAIL …` lines and ends with `ALL PASSED` or `n FAILED`;
`run-all.sh` prints one line per test and the failed checks.

Order matters a little: `staff_e2e` clears all tasks before it starts and `pct_e2e` expects
what `staff_e2e` left; `prod_e2e` / `cost_e2e` clear production batches.

## Backup test (`backup_e2e`)

It uses a stand-in for Google Drive, so no Google account is needed. Start it, then start the app
pointing at it and with the upload limits the live site sets in `.htaccess`:

```sh
php -S 127.0.0.1:8090 -t tests/e2e/fixtures tests/e2e/fixtures/fake_drive.php &
LOOMA_GDRIVE_TEST=http://127.0.0.1:8090 php -d upload_max_filesize=20M -d post_max_size=80M -S 127.0.0.1:8080 &
tests/e2e/run-all.sh backup_e2e
```

It sets all stock to zero and restores it from a backup, makes and deletes backups in `backups/`.
