'use strict';

// All app data is one JSON document kept in memory and written out after
// every change. It is stored either in a local file (default) or, when
// DATABASE_URL is set, in a PostgreSQL table, which is what free hosting
// needs because their disks are wiped on every restart.

const fs = require('fs');
const path = require('path');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, '..', 'data');
const FILE = path.join(DATA_DIR, 'db.json');
// On the very first start (no saved data yet) any JSON files in seed/ are
// loaded, e.g. the staff list.
const SEED_DIR = process.env.SEED_DIR || path.join(__dirname, '..', 'seed');
const DATABASE_URL = process.env.DATABASE_URL || '';

const DEFAULT_SETTINGS = {
  companyName: 'Looma Apparels',
  timezone: 'Asia/Kolkata',
  currency: '₹',
  workStart: '09:00',
  workEnd: '18:00',
  hoursPerDay: 9,
  weeklyOffs: [0], // 0 = Sunday
  incentivePercent: 1,
  halfDayShortHours: 2, // present but this many hours short = half-day leave (0 = off)
  fullDayShortHours: 5, // present but more than this many hours short = full-day leave (0 = off)
  restrictDevices: false, // only approved computers may use the staff clock-in page
  adminPasswordHash: null,
};

function empty() {
  return { settings: { ...DEFAULT_SETTINGS }, employees: [], sessions: [], leaves: [], leaveRequests: [], months: {}, devices: [], holidays: [], incentives: [] };
}

function normalise(raw) {
  const d = { ...empty(), ...raw, settings: { ...DEFAULT_SETTINGS, ...(raw.settings || {}) } };
  // earlier versions defaulted to a misspelt company name
  if (d.settings.companyName === 'Luma Apparels') d.settings.companyName = DEFAULT_SETTINGS.companyName;
  return d;
}

// The same object is used for the whole run; loading replaces its contents.
const db = empty();
function replace(next) {
  Object.keys(db).forEach((k) => delete db[k]);
  Object.assign(db, next);
}

function applySeeds() {
  let files = [];
  try {
    files = fs.readdirSync(SEED_DIR).filter((f) => f.endsWith('.json')).sort();
  } catch (e) {
    return false;
  }
  for (const f of files) {
    const seed = JSON.parse(fs.readFileSync(path.join(SEED_DIR, f), 'utf8'));
    db.employees.push(...(seed.employees || []));
    db.sessions.push(...(seed.sessions || []));
    db.leaves.push(...(seed.leaves || []));
    Object.assign(db.months, seed.months || {});
  }
  return files.length > 0;
}

// ---------- file storage ----------

function loadFile() {
  try {
    replace(normalise(JSON.parse(fs.readFileSync(FILE, 'utf8'))));
  } catch (e) {
    if (e.code !== 'ENOENT') throw e;
    replace(empty());
    if (applySeeds()) saveFile();
  }
}

// Write atomically (temp file + rename) and keep one backup of the previous file.
function saveFile() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2));
  if (fs.existsSync(FILE)) fs.copyFileSync(FILE, FILE + '.bak');
  fs.renameSync(tmp, FILE);
}

// ---------- PostgreSQL storage ----------

let pool = null;
let writing = Promise.resolve();

async function loadPg() {
  const { Pool } = require('pg');
  pool = new Pool({ connectionString: DATABASE_URL, max: 3 });
  await pool.query(`CREATE TABLE IF NOT EXISTS app_state (
    key text PRIMARY KEY, data jsonb NOT NULL, updated_at timestamptz NOT NULL DEFAULT now())`);
  const { rows } = await pool.query("SELECT data FROM app_state WHERE key = 'main'");
  if (rows.length) {
    replace(normalise(rows[0].data));
  } else {
    replace(empty());
    applySeeds();
    await writeRow('main', db);
  }
}

function writeRow(key, value) {
  return pool.query(
    `INSERT INTO app_state (key, data, updated_at) VALUES ($1, $2, now())
     ON CONFLICT (key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()`,
    [key, JSON.stringify(value)],
  );
}

// Writes are queued so they reach the database in order.
function queueWrite(key) {
  const snapshot = JSON.stringify(db);
  writing = writing
    .then(() => writeRow(key, JSON.parse(snapshot)))
    .catch((e) => console.error('Saving to the database failed:', e.message));
  return writing;
}

// ---------- public API ----------

// Load the saved data; await this before serving requests.
async function init() {
  if (DATABASE_URL) await loadPg();
  else loadFile();
  return db;
}

function save() {
  return DATABASE_URL ? queueWrite('main') : saveFile();
}

// Save a timestamped copy of the current data; returns its name.
function backup(label) {
  const name = `db-${label}-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
  if (DATABASE_URL) {
    queueWrite(name);
  } else {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(path.join(DATA_DIR, name), JSON.stringify(db, null, 2));
  }
  return name;
}

// Wait for queued database writes (used on shutdown).
async function close() {
  await writing;
  if (pool) await pool.end();
}

// File storage is ready straight away, which keeps tests and scripts simple.
if (!DATABASE_URL) loadFile();

module.exports = {
  db, init, save, backup, close, DATA_DIR, FILE,
  storage: DATABASE_URL ? 'database' : 'file',
};
