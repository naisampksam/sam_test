'use strict';

const fs = require('fs');
const path = require('path');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, '..', 'data');
const FILE = path.join(DATA_DIR, 'db.json');

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
  }
  return db;
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
