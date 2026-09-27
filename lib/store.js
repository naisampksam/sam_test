'use strict';

const fs = require('fs');
const path = require('path');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, '..', 'data');
const FILE = path.join(DATA_DIR, 'db.json');
// On the very first start (no db.json yet) any JSON files in seed/ are loaded,
// e.g. attendance history imported from the old Excel sheets.
const SEED_DIR = process.env.SEED_DIR || path.join(__dirname, '..', 'seed');

const DEFAULT_SETTINGS = {
  companyName: 'Looma Apparels',
  timezone: 'Asia/Kolkata',
  currency: '₹',
  workStart: '09:00',
  workEnd: '18:00',
  hoursPerDay: 9,
  weeklyOffs: [0], // 0 = Sunday
  incentivePercent: 1,
  hoursPoolPercent: 75,
  halfDayShortHours: 2, // present but this many hours short = half-day leave (0 = off)
  adminPasswordHash: null,
};

function empty() {
  return { settings: { ...DEFAULT_SETTINGS }, employees: [], sessions: [], leaves: [], months: {} };
}

let db = null;

function load() {
  if (db) return db;
  try {
    const raw = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    db = { ...empty(), ...raw, settings: { ...DEFAULT_SETTINGS, ...(raw.settings || {}) } };
    // earlier versions defaulted to a misspelt company name
    if (db.settings.companyName === 'Luma Apparels') db.settings.companyName = DEFAULT_SETTINGS.companyName;
  } catch (e) {
    if (e.code !== 'ENOENT') throw e;
    db = empty();
    loadSeeds();
  }
  return db;
}

function loadSeeds() {
  let files = [];
  try {
    files = fs.readdirSync(SEED_DIR).filter((f) => f.endsWith('.json')).sort();
  } catch (e) {
    return;
  }
  for (const f of files) {
    const seed = JSON.parse(fs.readFileSync(path.join(SEED_DIR, f), 'utf8'));
    db.employees.push(...(seed.employees || []));
    db.sessions.push(...(seed.sessions || []));
    db.leaves.push(...(seed.leaves || []));
    Object.assign(db.months, seed.months || {});
  }
  if (files.length) save();
}

// Write atomically (temp file + rename) and keep one backup of the previous file.
function save() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2));
  if (fs.existsSync(FILE)) fs.copyFileSync(FILE, FILE + '.bak');
  fs.renameSync(tmp, FILE);
}

module.exports = { load, save, DATA_DIR, FILE };
