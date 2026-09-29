# Looma Apparels – Attendance & Salary

A small web app for recording employee attendance, working hours, leaves, monthly salary and the sales incentive.

- **Clock-in page** (`/`): each employee taps **Clock In** when they arrive or come back and **Clock Out** whenever they leave. Every in/out period is added up into their hours for the day and the month. They can also enter an in/out time by hand (marked as *manual* for the admin) and view their own monthly hours.
- **Admin panel** (`/admin`, password protected): today's status, monthly attendance, leaves, salary and incentive, employees, settings.

It runs on Node.js 18 or newer. On your own computer it keeps its data in `data/db.json` and needs no `npm install`. Online, it keeps its data in a PostgreSQL database (see **Host it online for free** below).

## Run it

```bash
npm start            # or: node server.js
```

Then open:

- `http://localhost:3000/` on the office computer or tablet used for clocking in
- `http://localhost:3000/admin` for the admin panel. The first visit asks you to choose the admin password.

Other phones and computers on the same Wi-Fi can use `http://<this-computer's-IP>:3000/`.

Options (environment variables): `PORT` (default 3000), `HOST` (default `0.0.0.0`), `DATA_DIR` (default `./data`).

**Back up `data/db.json` regularly.** It holds all the records. A copy of the previous version is kept automatically as `db.json.bak`.

## Own subdomain, e.g. attendance.loomaapparels.com (standalone PHP, recommended)

`dist/looma-attendance-site.zip` is the app as a small PHP website with no WordPress and no database server needed. It runs on ordinary PHP hosting such as Hostinger. Staff use the main address; the admin panel is at `/admin` and is password protected.

**Install on Hostinger**
1. hPanel → **Domains → Subdomains**: create `attendance` for loomaapparels.com (note the folder it uses, e.g. `public_html/attendance`).
2. hPanel → **Files → File Manager**: open that folder, upload `looma-attendance-site.zip`, right-click it → **Extract** (into the same folder), then delete the ZIP.
3. hPanel → **Security → SSL**: make sure the subdomain has SSL (HTTPS).
4. Open `https://attendance.loomaapparels.com/admin`. To choose the admin password it asks for a **setup code**: in File Manager open `data/setup-code.php` and copy the code shown there. The file is deleted once the password is set, so nobody else can claim the admin panel first.
5. Under **Employees**, enter salaries and give everyone a **PIN**.

**Notes**
- Data is kept in the `data` folder (`db.php`, plus `db-previous.php` as the previous version). The folder is blocked from the web. Hostinger backups include it; you can also download it from File Manager.
- **Forgot the admin password?** In File Manager, create an empty file named `reset-password` inside the `data` folder, then open `/admin` again. A new `data/setup-code.php` appears.
- Optional: copy `config.sample.php` to `config.php` to keep the data outside the website folder.
- Requires PHP 7.4 or newer (Hostinger default is fine).
- Rebuild the ZIP after code changes with `./scripts/build-php-site.sh`. To update a live site, upload and extract the new ZIP over the old files. The `data` folder is kept.

## WordPress plugin

The same app is also available as a WordPress plugin written in PHP, so it runs on ordinary WordPress hosting (such as Hostinger) with no Node.js. It has the same pages, rules and calculations, and keeps its data in the WordPress database.

**Install**
1. Download `dist/looma-attendance.zip`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, then **Install Now** and **Activate**.
3. Open **Looma Attendance** in the WordPress menu. It shows the two addresses:
   - Clock-in page for staff: `https://loomaapparels.com/attendance/`
   - Admin panel: `https://loomaapparels.com/attendance/admin`
4. While logged in to WordPress as an administrator, open the admin panel and choose the attendance admin password. For security, the first password can only be set by a WordPress administrator.

**Notes**
- The 4 staff are added automatically on first use. Enter their salaries and PINs under Employees.
- If you forget the attendance admin password, use **Looma Attendance → Reset admin password** in WordPress.
- You can change the `attendance` part of the address on the same page.
- Caching plugins (e.g. LiteSpeed Cache on Hostinger) are told not to cache these pages.
- Data is stored in the database table `wp_looma_attendance`, so your normal WordPress/Hostinger backups include it. Deactivating or deleting the plugin does not delete the data.

**Updating the plugin after code changes:** run `./scripts/build-wordpress-plugin.sh` (copies the pages from `public/` and rebuilds the ZIP), then upload the new ZIP in WordPress (it offers to replace the installed version).

The PHP code is in `wordpress/looma-attendance/`. `npm test` checks that its calculations match the Node.js version exactly (needs `php`). The API tests can also run against a WordPress site: `TEST_BASE_URL=https://site/attendance node --test test/api.test.js` (only on a test site with empty data and `define('LOOMA_ATT_OPEN_SETUP', true);` plus `define('LOOMA_ATT_SEED_FILE', '');` in `wp-config.php`).

## Host it online for free

Free hosts wipe their disk on every restart, so online the data goes in a free PostgreSQL database instead of `data/db.json`. The app does this automatically when the `DATABASE_URL` setting is present.

1. **Database (Neon, free):** sign up at neon.tech, create a project, and copy its **connection string** (it starts with `postgresql://` and ends with `?sslmode=require`).
2. **App (Render, free):** sign up at render.com with your GitHub account, then **New → Web Service** and pick this repository.
   - Branch: the branch that has this code
   - Runtime: **Node** · Build command: `npm install` · Start command: `npm start`
   - Instance type: **Free**
   - Environment variable: `DATABASE_URL` = the Neon connection string
3. Click **Create Web Service**. After a few minutes Render gives you an address like `https://looma-attendance.onrender.com`. Open `/admin` there and set the admin password.

Notes:
- The free Render service sleeps after about 15 minutes without visitors; the next visit takes up to a minute to wake it.
- Anyone with the address can open the clock-in page, so give every employee a **PIN** (Employees → Edit).
- Pushing new code to the branch redeploys automatically; the data in Neon is kept.
- Settings → Clear attendance data saves its backup as an extra row in the database table `app_state`.

## Staff

`seed/looma-staff.json` holds the 4 staff: Nadeem Anshin, Fayis, Anshidha and Vyshnav (Vyshnav without sales incentive). The app loads them automatically **the first time it starts** (when `data/db.json` doesn't exist yet). Enter their basic salaries under Employees.

To start over while keeping the staff, use **Settings → Clear attendance data**. It deletes all in/out entries, leaves and monthly figures, and saves a backup copy in `data/` first.

## Only office computers can use the staff page

Admin panel → **Settings → Clock-in computers**:
1. On each office computer, open the admin panel, log in, enter a name (e.g. "Front desk") and click **Approve this computer**. This stores a secret key in that browser.
2. Tick **Only approved computers can open the staff page**.

Other phones and computers then see "This device can't be used for clock-in". The admin panel itself still works anywhere with the password. The key is renewed each time the computer is used. It is lost if that browser's cookies or site data are cleared, or in a private/incognito window; then just approve the computer again. Remove computers from the same list.

## Planned leave requests

On the clock-in page each person has **Request leave**. They pick the days on a month calendar (today or later, up to 6 months ahead; weekly offs and days already booked can't be picked), choose full or half day, and give a reason. They can see the status of their requests and cancel ones that are still pending.

The admin sees waiting requests in **Leaves** (with a count badge in the sidebar and a notice on Today) and can **Approve** or **Reject** each one, optionally with a note the employee will see. Approving turns the days into recorded leave, which then counts in the salary calculation like any other leave.

## First steps

1. Open `/admin` and set the admin password.
2. **Employees → Add employee**: name, position, basic monthly salary and, optionally, a 4–6 digit PIN. With a PIN set, nobody else can clock in for that person.
3. **Settings**: check the timezone (default `Asia/Kolkata`), office hours (9:00–18:00), required hours per day (9), weekly off days (Sunday) and the incentive settings (1 % of sales; 75 % / 25 % split).

## How it calculates

For a month with **W** working days (default: every day except the weekly offs; you can change it on the Salary page for holidays) and **H** hours per day (9):

| Item | Formula |
|---|---|
| Hours worked | Sum of every in → out period in the month |
| Leave days | Recorded leaves, plus automatic leave on working days when someone was present but short of the 9 h: **half a day** if 2 h or more short (worked 7 h or less), a **full day** if more than 4.5 h short (worked less than 4.5 h). Both limits can be changed in Settings; 0 turns a rule off. Hours worked on those days still count towards worked and extra hours. |
| Leave deduction | basic ÷ W × leave days (a half-day counts as 0.5) |
| Salary | basic − leave deduction |
| Required hours | (W − leave days) × H. For example, 24 days × 9 h = 216 h; with 1 leave day it is 207 h |
| Extra hours | max(0, hours worked − required hours) |
| Incentive pool | 1 % × total sales entered for the month |
| Hours incentive (75 % of pool) | pool × 75 % × (own hours ÷ everyone's hours) |
| Extra-hours incentive (25 % of pool) | pool × 25 % × (own extra hours ÷ everyone's extra hours). Only people who worked beyond their required hours get this |
| **Net pay** | salary + hours incentive + extra-hours incentive |

Example: total sales ₹1,00,000 gives a pool of ₹1,000.
- 75 % = ₹750. If A worked 100 h and B worked 90 h, A gets 750 × 100/190 = ₹394.74 and B gets ₹355.26.
- 25 % = ₹250. If the requirement is 216 h and A worked 250 h (34 extra) and B worked 260 h (44 extra), A gets 250 × 34/78 = ₹108.97 and B gets ₹141.03.

Staff with **Gets sales incentive** switched off (Employees → Edit) receive no incentive, and their hours are left out when the pool is shared. The whole pool goes to the eligible staff. Vyshnav is set up this way.

If nobody works extra hours in a month, the 25 % is not paid out, and the Salary page says so.

Entries without a clock-out on a past day count as 0 hours and are flagged. Fix them in **Attendance → Details** before finalising salaries. Salary and attendance can be exported to CSV or printed.

## Tests

```bash
npm test
```

To also test database storage, point `TEST_DATABASE_URL` at an empty PostgreSQL database (its `app_state` table is dropped).
