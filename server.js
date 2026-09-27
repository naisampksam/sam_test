// Looma Apparels Sales & P&L dashboard — local server.
// Serves index.html and stores all months in data/looma-data.json.
// Usage: npm start   (or: node server.js)   then open http://localhost:4000
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = Number(process.env.PORT) || 4000;
const ROOT = __dirname;
const DATA_DIR = path.join(ROOT, 'data');
const DATA_FILE = path.join(DATA_DIR, 'looma-data.json');
const BACKUP_DIR = path.join(DATA_DIR, 'backups');
const MAX_BODY = 20 * 1024 * 1024;

const STATIC = {
  '/': ['index.html', 'text/html; charset=utf-8'],
  '/index.html': ['index.html', 'text/html; charset=utf-8'],
  '/vendor/xlsx.full.min.js': ['vendor/xlsx.full.min.js', 'application/javascript; charset=utf-8'],
  '/vendor/chart.umd.min.js': ['vendor/chart.umd.min.js', 'application/javascript; charset=utf-8'],
};

function load() {
  try {
    const d = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
    return { months: d.months || {}, cats: Array.isArray(d.cats) ? d.cats : null };
  } catch (e) {
    if (e.code !== 'ENOENT') console.error('Could not read', DATA_FILE, '-', e.message);
    return { months: {}, cats: null };
  }
}

let store = load();

function save() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  // Keep one backup per day of the previous state before overwriting.
  if (fs.existsSync(DATA_FILE)) {
    fs.mkdirSync(BACKUP_DIR, { recursive: true });
    const daily = path.join(BACKUP_DIR, `looma-data-${new Date().toISOString().slice(0, 10)}.json`);
    if (!fs.existsSync(daily)) fs.copyFileSync(DATA_FILE, daily);
  }
  const tmp = DATA_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(store, null, 1));
  fs.renameSync(tmp, DATA_FILE);
}

function send(res, status, body, type = 'application/json; charset=utf-8') {
  res.writeHead(status, { 'Content-Type': type, 'Cache-Control': 'no-store' });
  res.end(typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', c => {
      size += c.length;
      if (size > MAX_BODY) { reject(new Error('Request too large')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => {
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}')); }
      catch (e) { reject(new Error('Invalid JSON')); }
    });
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const p = url.pathname;
  try {
    if (p === '/api/data' && req.method === 'GET') return send(res, 200, store);

    const mm = p.match(/^\/api\/months\/(\d{4}-\d{2})$/);
    if (mm && req.method === 'PUT') {
      const body = await readBody(req);
      if (!body || typeof body !== 'object' || body.id !== mm[1]) return send(res, 400, { error: 'Month id mismatch' });
      store.months[mm[1]] = body;
      save();
      return send(res, 200, { ok: true });
    }
    if (mm && req.method === 'DELETE') {
      delete store.months[mm[1]];
      save();
      return send(res, 200, { ok: true });
    }
    if (p === '/api/settings' && req.method === 'PUT') {
      const body = await readBody(req);
      if (!Array.isArray(body.cats)) return send(res, 400, { error: 'cats must be an array' });
      store.cats = body.cats;
      save();
      return send(res, 200, { ok: true });
    }
    if (p.startsWith('/api/')) return send(res, 404, { error: 'Not found' });

    const file = STATIC[p];
    if (file && req.method === 'GET') {
      return fs.readFile(path.join(ROOT, file[0]), (err, buf) => {
        if (err) return send(res, 404, 'Not found', 'text/plain');
        send(res, 200, buf, file[1]);
      });
    }
    send(res, 404, 'Not found', 'text/plain');
  } catch (e) {
    send(res, 400, { error: e.message });
  }
});

server.on('error', e => {
  if (e.code === 'EADDRINUSE') console.error(`Port ${PORT} is already in use. Close the other program or run with PORT=4001 npm start`);
  else console.error(e);
  process.exit(1);
});

server.listen(PORT, () => {
  console.log(`Looma Apparels dashboard running at http://localhost:${PORT}`);
  console.log(`Data file: ${DATA_FILE}`);
});
