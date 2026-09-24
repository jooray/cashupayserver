/**
 * Draw a QR code onto a <canvas>, with the constructor QRious had.
 *
 * QRious came from a CDN (and is GPL-3.0, unmaintained since 2017). This keeps every
 * `new QRious({ element, value, size, level, foreground, background })` call working,
 * rendered by the vendored qrcode-generator (MIT, assets/js/vendor/qrcode-generator.js),
 * which must be loaded first. Animated QR codes (assets/js/animated-qr.js) call this once
 * per frame; the animation itself is theirs.
 *
 * Draws a 2-module quiet zone in the background colour so phones scan it on any backdrop.
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

        var count = qr.getModuleCount();
        var cell = Math.max(1, Math.floor(size / (count + 2 * QUIET_ZONE)));
        var offset = Math.floor((size - cell * count) / 2);

        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = options.background || '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = options.foreground || '#000000';
        for (var row = 0; row < count; row++) {
            for (var col = 0; col < count; col++) {
                if (qr.isDark(row, col)) {
                    ctx.fillRect(offset + col * cell, offset + row * cell, cell, cell);
                }
            }
        }
    }

    window.QRious = QRious;
})();
