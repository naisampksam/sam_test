# Looma Apparels – Attendance & Salary

A small web app for recording employee attendance, working hours, leaves, monthly salary and the sales incentive.

- **Clock-in page** (`/`): each employee taps **Clock In** when they arrive or come back and **Clock Out** whenever they leave. Every in/out period is added up into their hours for the day and the month. They can also enter an in/out time by hand (marked as *manual* for the admin) and view their own monthly hours.
- **Admin panel** (`/admin`, password protected): today's status, monthly attendance, leaves, salary and incentive, employees, settings.

It needs no database and no `npm install`. It runs on plain Node.js 18 or newer and keeps its data in `data/db.json`.

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

## Imported history (April – September 2026)

`seed/looma-2026-04-to-09.json` holds the attendance from the old Excel sheet for the 4 staff: Nadeem Anshin, Fayis, Anshidha and Vyshnav. It includes every in/out time, the leave days, and each month's working days, reduced for the holidays on 9 Apr, 28–29 May and 25–26 Aug. The app loads it automatically **the first time it starts** (when `data/db.json` doesn't exist yet). Imported entries are labelled *import* in Attendance → Details.

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

If nobody works extra hours in a month, the 25 % is not paid out, and the Salary page says so.

Entries without a clock-out on a past day count as 0 hours and are flagged. Fix them in **Attendance → Details** before finalising salaries. Salary and attendance can be exported to CSV or printed.

## Tests

```bash
npm test
```
