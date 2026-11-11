/**
 * QRious-compatible QR rendering shim.
 *
 * Backed by the MIT-licensed qrcode-generator library (loaded first, as
 * assets/js/qrcode-generator.js, exposing the global `qrcode`). Pages used to
 * load the QRious library from a public CDN; every QR here encodes payment
 * data (invoices, addresses, ecash tokens), so the renderer must be served
 * locally — a CDN could observe payers or serve a tampered renderer that
 * encodes an attacker's address. This shim keeps the existing
 * `new QRious({element, value, size, ...})` call sites working unchanged.
 *
 * Supported options (the subset this codebase uses): element (a <canvas>),
 * value (ASCII payment string), size (pixels), foreground, background,
 * level ('L'|'M'|'Q'|'H'). backgroundAlpha is accepted but only fully opaque
 * rendering is produced, matching how every caller sets it (1).
 */
(function (global) {
    'use strict';

    var QUIET_ZONE_MODULES = 4; // standard QR quiet zone, matches qrious' look

    function QRious(options) {
        var opts = options || {};
        var canvas = opts.element;
        var value = String(opts.value === undefined || opts.value === null ? '' : opts.value);
        var size = Number(opts.size) > 0 ? Math.floor(Number(opts.size)) : 100;
        var foreground = opts.foreground || '#000000';
        var background = opts.background || '#ffffff';
        var level = /^[LMQH]$/.test(String(opts.level)) ? String(opts.level) : 'L';

        if (!canvas || typeof canvas.getContext !== 'function') {
            throw new Error('QRious shim: options.element must be a <canvas>');
        }
        if (typeof global.qrcode !== 'function') {
            throw new Error('QRious shim: qrcode-generator.js must be loaded first');
        }

        var qr = global.qrcode(0 /* 0 = smallest version that fits */, level);
        qr.addData(value, 'Byte');
        qr.make();

        var modules = qr.getModuleCount();
        var total = modules + 2 * QUIET_ZONE_MODULES;
        var cell = size / total;

        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = background;
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = foreground;
        for (var row = 0; row < modules; row++) {
            // Rounded cell edges (not rounded sizes) so adjacent modules
            // always share a pixel boundary — no hairline gaps, no overlap.
            var y0 = Math.round((QUIET_ZONE_MODULES + row) * cell);
            var y1 = Math.round((QUIET_ZONE_MODULES + row + 1) * cell);
            for (var col = 0; col < modules; col++) {
                if (!qr.isDark(row, col)) continue;
                var x0 = Math.round((QUIET_ZONE_MODULES + col) * cell);
                var x1 = Math.round((QUIET_ZONE_MODULES + col + 1) * cell);
                ctx.fillRect(x0, y0, x1 - x0, y1 - y0);
            }
        }

        this.element = canvas;
        this.value = value;
        this.size = size;
    }

    global.QRious = QRious;
})(typeof window !== 'undefined' ? window : this);
