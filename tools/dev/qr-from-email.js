// Reads an email's HTML on stdin, rebuilds the QR table as pixels and decodes it with the
// same jsQR the door scanner uses. Prints the decoded text, or nothing.
const fs = require('fs');
const path = require('path');
const jsQR = (() => { const m = { exports: {} }; new Function('module', 'exports', fs.readFileSync(path.join(__dirname, '../../resok-portal/public/js/vendor/jsqr-1.4.0.js'), 'utf8'))(m, m.exports); return m.exports.default || m.exports; })();
const html = fs.readFileSync(0, 'utf8');
const table = (html.match(/<table role="img" aria-label="QR code[\s\S]*?<\/table>/) || [''])[0];
const rows = [...table.matchAll(/<tr>([\s\S]*?)<\/tr>/g)].map((r) => [...r[1].matchAll(/bgcolor="(#[0-9a-f]{6})"/g)].map((c) => c[1] === '#000000'));
if (!rows.length) process.exit(0);
const scale = 5, n = rows.length, size = n * scale;
const px = new Uint8ClampedArray(size * size * 4).fill(255);
rows.forEach((row, y) => row.forEach((dark, x) => {
  if (!dark) return;
  for (let dy = 0; dy < scale; dy++) for (let dx = 0; dx < scale; dx++) { const i = ((y * scale + dy) * size + x * scale + dx) * 4; px[i] = px[i + 1] = px[i + 2] = 0; }
}));
const r = jsQR(px, size, size);
process.stdout.write(r ? r.data : '');
