/**
 * NUT-16 Animated QR Code Generator for Cashu Tokens
 *
 * Implements Blockchain Commons Uniform Resources (UR) encoding with fountain codes
 * for splitting large Cashu tokens into scannable QR code fragments.
 *
 * Format: ur:bytes/1-50/lpqzs5d...
 * (Uses generic ur:bytes type, not ur:cashu, as per Cashu wallet implementations)
 *
 * Requires:
 * - QRious library (for QR rendering)
 * - @gandlaf21/bc-ur library (for UR encoding)
 */
class AnimatedQR {
    constructor(container, options = {}) {
        this.container = typeof container === 'string'
            ? document.getElementById(container)
            : container;

        this.options = {
            frameRate: options.frameRate || 200, // ms per frame (5 fps)
            maxFragmentLen: options.maxFragmentLen || 200, // max bytes per fragment
            qrSize: options.qrSize || 280,
            errorCorrection: options.errorCorrection || 'M',
            ...options
        };

        this.encoder = null;
        this.currentFrame = 0;
        this.intervalId = null;
        this.canvas = null;
        this.frameCounter = null;
    }

    /**
     * Encode token string as UR and start animation
     * @param {string} tokenString - Complete Cashu token string (cashuA... or cashuB...)
     * @returns {boolean} Success
     */
    encode(tokenString) {
        // Check if bc-ur library is loaded
        if (typeof window.bcur === 'undefined') {
            console.error('bc-ur library not loaded');
            return false;
        }

        try {
            // Encode the token string as CBOR
            // CBOR text string: major type 3
            // For strings < 256 bytes: 0x78 (major type 3, additional info 24) + length byte + string bytes
            // For strings >= 256 bytes: 0x79 (major type 3, additional info 25) + 2 length bytes + string bytes
            const textEncoder = new TextEncoder();
            const stringBytes = textEncoder.encode(tokenString);

            let cborData;
            if (stringBytes.length < 24) {
                // Tiny string: major type 3 (0x60) + length in additional info
                cborData = new Uint8Array(1 + stringBytes.length);
                cborData[0] = 0x60 | stringBytes.length;  // major type 3, length in low 5 bits
                cborData.set(stringBytes, 1);
            } else if (stringBytes.length < 256) {
                // 1-byte length
                cborData = new Uint8Array(2 + stringBytes.length);
                cborData[0] = 0x78;  // major type 3, additional info 24
                cborData[1] = stringBytes.length;
                cborData.set(stringBytes, 2);
            } else if (stringBytes.length < 65536) {
                // 2-byte length (big-endian)
                cborData = new Uint8Array(3 + stringBytes.length);
                cborData[0] = 0x79;  // major type 3, additional info 25
                cborData[1] = (stringBytes.length >> 8) & 0xFF;  // high byte
                cborData[2] = stringBytes.length & 0xFF;  // low byte
                cborData.set(stringBytes, 3);
            } else {
                throw new Error('Token too large for CBOR encoding');
            }

            console.log('[AnimatedQR] Encoding token:');
            console.log('  Token length:', tokenString.length, 'chars');
            console.log('  Token prefix:', tokenString.substring(0, 10));
            console.log('  String bytes:', stringBytes.length);
            console.log('  CBOR bytes:', cborData.length);
            console.log('  CBOR header:', Array.from(cborData.slice(0, 5)));

            // Create UR with CBOR-encoded data
            console.log('[AnimatedQR] Creating UR with CBOR-encoded token');
            const ur = new window.bcur.UR(cborData, 'bytes');

            // Create encoder with max fragment length
            this.encoder = new window.bcur.UREncoder(ur, this.options.maxFragmentLen);

            // Log first part for debugging
            const firstPart = this.encoder.nextPart();
            console.log('[AnimatedQR] First UR part:', firstPart.substring(0, 50) + '...');

            // Reset encoder to start from beginning
            this.encoder = new window.bcur.UREncoder(ur, this.options.maxFragmentLen);

            // Create UI
            this.createUI();

            // Start animation
            this.start();

            return true;
        } catch (e) {
            console.error('[AnimatedQR] Failed to encode UR:', e);
            return false;
        }
    }

    /**
     * Create the UI elements for animated display
     */
    createUI() {
        // Clear container
        this.container.innerHTML = '';

        // Create wrapper
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'display: flex; flex-direction: column; align-items: center; gap: 0.75rem;';

        // Create canvas (QR code)
        this.canvas = document.createElement('canvas');
        wrapper.appendChild(this.canvas);

        // Add info text (no frame counter - wallet shows that)
        const info = document.createElement('div');
        info.style.cssText = 'font-size: 0.85rem; color: var(--text-secondary, #888); text-align: center; max-width: 280px; margin-top: 0.5rem;';
        info.textContent = 'Keep scanning - wallet will collect frames automatically';
        wrapper.appendChild(info);

        this.container.appendChild(wrapper);
    }

    /**
     * Render next frame
     */
    renderNextFrame() {
        if (!this.encoder) return;

        // Get next part from fountain encoder
        const part = this.encoder.nextPart();
        // UREncoder returns uppercase string
        const urString = part;

        // Debug log (remove in production)
        if (this.currentFrame === 0) {
            console.log('First UR frame:', urString.substring(0, 50));
        }

        // Render QR code
        if (typeof QRious !== 'undefined') {
            new QRious({
                element: this.canvas,
                value: urString,
                size: this.options.qrSize,
                backgroundAlpha: 1,
                foreground: '#000000',
                background: '#ffffff',
                level: this.options.errorCorrection
            });
        }

        this.currentFrame++;
    }

    /**
     * Start animation
     */
    start() {
        if (!this.encoder) return;

        this.stop(); // Clear any existing interval

        // Render first frame immediately
        this.renderNextFrame();

        // Start animation loop
        this.intervalId = setInterval(() => {
            this.renderNextFrame();
        }, this.options.frameRate);
    }

    /**
     * Stop animation
     */
    stop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    /**
     * Check if animation is running
     */
    isRunning() {
        return this.intervalId !== null;
    }

    /**
     * Destroy and cleanup
     */
    destroy() {
        this.stop();
        this.container.innerHTML = '';
        this.encoder = null;
        this.currentFrame = 0;
    }

    /**
     * Check if token needs animated QR (is too large for single QR)
     * At 280px, QR Version 12 (65x65 modules, ~4.3px per module) is the practical
     * limit for reliable screen scanning. That's ~367 bytes in byte mode with L correction.
     * Use 350 as threshold to stay within scannable density.
     *
     * @param {string} tokenString - Complete Cashu token string
     * @returns {boolean}
     */
    static needsAnimation(tokenString) {
        return tokenString.length > 350;
    }

}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AnimatedQR;
}
