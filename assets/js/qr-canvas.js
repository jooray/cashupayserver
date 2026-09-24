/**
 * Draw a QR code onto a <canvas>, with the constructor QRious had.
 *
 * QRious came from a CDN (and is GPL-3.0, unmaintained since 2017). This keeps every
 * `new QRious({ element, value, size, level, foreground, background })` call working,
 * rendered by the vendored qrcode-generator (MIT, assets/js/vendor/qrcode-generator.js),
 * which must be loaded first. Animated QR codes (assets/js/animated-qr.js) call this once
 * per frame; the animation itself is theirs.
 *
 * Draws a 2-module quiet zone in the background colour. Every page puts the canvas in a
 * white box with at least 1rem of padding, which brings the total to the standard four
 * modules or more while letting the code itself fill the space it is given.
 */
(function () {
    'use strict';

    var ALPHANUMERIC = /^[0-9A-Z $%*+\-.\/:]*$/;
    var QUIET_ZONE = 2;

    function QRious(options) {
        options = options || {};
        var canvas = options.element;
        if (!canvas || typeof canvas.getContext !== 'function') {
            throw new Error('QRious: options.element must be a <canvas>');
        }
        if (typeof qrcode !== 'function') {
            throw new Error('QRious: qrcode-generator is not loaded');
        }

        var value = String(options.value == null ? '' : options.value);
        var level = String(options.level || 'L').toUpperCase();
        var size = Math.max(21, Math.floor(options.size || 100));

        // Type 0 = smallest version that fits. Upper-case bc-ur fragments fit far more
        // densely in alphanumeric mode; everything else is UTF-8 bytes.
        var qr = qrcode(0, 'LMQH'.indexOf(level) >= 0 ? level : 'L');
        if (ALPHANUMERIC.test(value)) {
            qr.addData(value, 'Alphanumeric');
        } else {
            qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
            qr.addData(value, 'Byte');
        }
        qr.make();

        // Fill the whole canvas: modules take fractional widths with rounded edges (no
        // gaps, no leftover margin), drawn at the screen's pixel density so they stay
        // sharp. Whole-pixel modules left a wide empty border and a small code that
        // scanned worse. The white box around the canvas adds to the quiet zone.
        var count = qr.getModuleCount();
        var total = count + 2 * QUIET_ZONE;
        var ratio = Math.max(1, Math.min(3, window.devicePixelRatio || 1));
        // Never fewer device pixels than modules: a long value in a small box then
        // shrinks on screen instead of losing modules.
        var px = Math.max(Math.round(size * ratio), total);
        var step = px / total;

        canvas.width = px;
        canvas.height = px;
        canvas.style.width = size + 'px';
        canvas.style.height = 'auto';
        canvas.style.maxWidth = '100%';
        canvas.style.imageRendering = 'pixelated';

        var ctx = canvas.getContext('2d');
        ctx.fillStyle = options.background || '#ffffff';
        ctx.fillRect(0, 0, px, px);
        ctx.fillStyle = options.foreground || '#000000';
        for (var row = 0; row < count; row++) {
            var y0 = Math.round((row + QUIET_ZONE) * step);
            var y1 = Math.round((row + QUIET_ZONE + 1) * step);
            for (var col = 0; col < count; col++) {
                if (qr.isDark(row, col)) {
                    var x0 = Math.round((col + QUIET_ZONE) * step);
                    var x1 = Math.round((col + QUIET_ZONE + 1) * step);
                    ctx.fillRect(x0, y0, x1 - x0, y1 - y0);
                }
            }
        }
    }

    window.QRious = QRious;
})();
