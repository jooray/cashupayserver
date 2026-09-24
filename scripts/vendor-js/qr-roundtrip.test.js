// Decode every QR code the wrapper draws with an independent decoder (jsQR) and require
// the exact input back: invoices, tokens, payment requests, animated bc-ur frames.
// Run by scripts/build-vendor-js.sh after each build; exits non-zero on any mismatch.
'use strict';
const fs = require('fs'), path = require('path'), vm = require('vm'), jsQR = require('jsqr');
const assets = path.join(process.argv[2], 'assets/js');

function fakeCanvas() {
  const c = { width: 0, height: 0, px: null, style: {} };
  c.getContext = () => ({
    set fillStyle(v) { this._c = v; }, get fillStyle() { return this._c; },
    fillRect(x, y, w, h) {
      if (!c.px) c.px = new Uint8ClampedArray(c.width * c.height * 4);
      const v = this._c === '#000000' ? 0 : 255;
      for (let j = y; j < y + h; j++) for (let i = x; i < x + w; i++) {
        const k = (j * c.width + i) * 4; c.px[k] = c.px[k + 1] = c.px[k + 2] = v; c.px[k + 3] = 255;
      }
    },
  });
  return c;
}

const ctx = { console, TextEncoder, TextDecoder, Uint8Array, devicePixelRatio: 2 };
ctx.window = ctx; ctx.globalThis = ctx; vm.createContext(ctx);
for (const f of ['vendor/qrcode-generator.js', 'vendor/bc-ur.bundle.js', 'qr-canvas.js']) {
  vm.runInContext(fs.readFileSync(path.join(assets, f), 'utf8'), ctx);
}

function decodes(value, size, level) {
  const c = fakeCanvas();
  new ctx.QRious({ element: c, value, size, level, foreground: '#000000', background: '#ffffff' });
  const r = jsQR(c.px, c.width, c.height);
  return r !== null && r.data === value;
}

const b64 = n => Array.from({ length: n }, (_, i) => 'AbC123-_xYz'[i % 11]).join('');
const cases = [
  ['payment page invoice', 'lightning:LNBC100N1P4TT23PPP5223GT9XKXV844RLTL5TZ3' + 'Q'.repeat(260), 220, 'M'],
  ['lowercase invoice', 'lnbc100n1p4tt23ppp5223gt9xkxv844rltl5tz3' + 'q'.repeat(260), 220, 'M'],
  ['cashu token, mixed case', 'cashuBo2FteBxodHRwczovL21pbnQ' + b64(900), 400, 'L'],
  ['payment request', 'creqAp' + b64(180), 200, 'L'],
  ['UTF-8 memo', 'Ďakujem za kávu ☕ ' + b64(40), 200, 'L'],
  ['long token, small box', 'cashuB' + b64(700), 120, 'L'],
];
let failed = 0;
for (const [name, value, size, level] of cases) {
  const ok = decodes(value, size, level);
  if (!ok) failed++;
  console.log((ok ? 'ok   ' : 'FAIL ') + name);
}

const bytes = new TextEncoder().encode('cashuB' + b64(1500));
const cbor = new Uint8Array(3 + bytes.length);
cbor[0] = 0x79; cbor[1] = bytes.length >> 8; cbor[2] = bytes.length & 0xff; cbor.set(bytes, 3);
const enc = new ctx.bcur.UREncoder(new ctx.bcur.UR(cbor, 'bytes'), 200);
let frames = 0;
for (let i = 0; i < enc.fragmentsLength + 5; i++) {
  if (!decodes(enc.nextPart().toUpperCase(), 300, 'L')) failed++;
  frames++;
}
console.log((failed ? 'FAIL ' : 'ok   ') + `animated token: ${frames} frames`);
process.exit(failed ? 1 : 0);
