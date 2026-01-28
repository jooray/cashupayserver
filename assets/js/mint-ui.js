/**
 * Mint Discovery UI Controller
 *
 * Provides UI components for discovering Cashu mints via Nostr
 * and testing mint expiry times.
 */

/**
 * Mint Discovery UI Class
 */
class MintDiscoveryUI {
    constructor(options = {}) {
        this.onSelect = options.onSelect || (() => {});
        this.discovery = null;
        this.mints = [];
        this.modalId = options.modalId || 'mint-discovery-modal';
        this.csrf = options.csrf || null;
    }

    /**
     * Initialize the discovery library
     */
    async init() {
        if (typeof MintDiscovery === 'undefined') {
            throw new Error('MintDiscovery library not loaded');
        }
        this.discovery = MintDiscovery.create({
            httpTimeout: 8000,
            nostrTimeout: 15000
        });
    }

    /**
     * Discover mints from Nostr
     * @param {Function} onProgress - Progress callback
     * @returns {Promise<Array>} Array of mint recommendations
     */
    async discover(onProgress) {
        if (!this.discovery) {
            await this.init();
        }
        this.mints = await this.discovery.discover({ onProgress });
        return this.mints;
    }

    /**
     * Filter mints by unit
     * @param {string} unit - Unit to filter by (e.g., 'sat', 'eur')
     * @returns {Array} Filtered mints
     */
    filterByUnit(unit) {
        if (!unit) return this.mints;
        return this.mints.filter(m => {
            const units = this.getUnitsFromInfo(m.info);
            return units.includes(unit);
        });
    }

    /**
     * Extract units from mint info
     * @param {Object} info - Mint info object
     * @returns {Array} Array of unit strings
     */
    getUnitsFromInfo(info) {
        if (!info?.nuts?.[4]?.methods) return [];
        return [...new Set(info.nuts[4].methods.map(m => m.unit).filter(Boolean))];
    }

    /**
     * Get reviews for a specific mint
     * @param {string} url - Mint URL
     * @returns {Array} Array of reviews
     */
    getReviewsForMint(url) {
        if (!this.discovery) return [];
        return this.discovery.getReviewsForMint(url);
    }

    /**
     * Close all connections
     */
    close() {
        if (this.discovery) {
            this.discovery.close();
            this.discovery = null;
        }
    }

    /**
     * Render stars for a rating
     * @param {number|null} rating - Rating value 1-5
     * @returns {string} HTML string of stars
     */
    renderStars(rating) {
        if (rating === null || rating === undefined) return '---';
        const full = Math.floor(rating);
        const half = rating - full >= 0.5 ? 1 : 0;
        const empty = 5 - full - half;
        return '<span class="stars" style="color: #FFC107;">' +
            '\u2605'.repeat(full) +
            (half ? '\u2606' : '') +
            '\u2606'.repeat(empty) +
            '</span> ' + rating.toFixed(1);
    }

    /**
     * Render a single mint card
     * @param {Object} mint - Mint recommendation object
     * @returns {string} HTML string
     */
    renderMintCard(mint) {
        const name = mint.info?.name || 'Unknown Mint';
        const url = mint.url;
        const rating = mint.averageRating;
        const reviewCount = mint.reviewsCount || 0;
        const isOnline = !mint.error && mint.info != null;
        const units = this.getUnitsFromInfo(mint.info);

        return `
            <div class="mint-card" data-url="${this.escapeHtml(url)}" data-units="${units.join(',')}">
                <div class="mint-header">
                    <div class="mint-rating">
                        ${this.renderStars(rating)}
                        <span class="review-count">(${reviewCount} reviews)</span>
                    </div>
                    <span class="mint-status ${isOnline ? 'online' : 'offline'}">
                        ${isOnline ? '\u25CF Online' : '\u25CB Offline'}
                    </span>
                </div>
                <h4 class="mint-name">${this.escapeHtml(name)}</h4>
                <p class="mint-url">${this.escapeHtml(url)}</p>
                <div class="mint-units">${units.map(u => u.toUpperCase()).join(' \u2022 ') || 'Unknown units'}</div>
                <button type="button" class="btn btn-sm mint-select-btn" onclick="window.mintDiscoveryUI.selectMint('${this.escapeHtml(url)}')">Select</button>
            </div>
        `;
    }

    /**
     * Select a mint and pass to callback
     * @param {string} url - Mint URL
     */
    selectMint(url) {
        const mint = this.mints.find(m => m.url === url);
        if (mint) {
            this.onSelect(mint);
        }
        this.closeModal();
    }

    /**
     * Open the discovery modal
     */
    openModal() {
        const modal = document.getElementById(this.modalId);
        if (modal) {
            modal.style.display = 'flex';
            this.startDiscovery();
        }
    }

    /**
     * Close the discovery modal
     */
    closeModal() {
        const modal = document.getElementById(this.modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    /**
     * Start the discovery process
     */
    async startDiscovery() {
        const listEl = document.getElementById('mint-discovery-list');
        const loadingEl = document.getElementById('mint-discovery-loading');
        const statusEl = document.getElementById('mint-discovery-status');

        if (loadingEl) loadingEl.style.display = 'block';
        if (listEl) listEl.innerHTML = '';
        if (statusEl) statusEl.textContent = 'Connecting to Nostr relays...';

        try {
            await this.discover((progress) => {
                if (statusEl) {
                    if (progress.phase === 'nostr' && progress.step === 'mint-info') {
                        statusEl.textContent = 'Fetching mint announcements...';
                    } else if (progress.phase === 'nostr' && progress.step === 'reviews') {
                        statusEl.textContent = 'Fetching reviews...';
                    } else if (progress.phase === 'http') {
                        statusEl.textContent = 'Checking mint status...';
                    } else if (progress.phase === 'done') {
                        statusEl.textContent = `Found ${this.mints.length} mints`;
                    }
                }
            });

            if (loadingEl) loadingEl.style.display = 'none';
            this.renderMintList();
        } catch (error) {
            if (loadingEl) loadingEl.style.display = 'none';
            if (statusEl) statusEl.textContent = 'Error: ' + error.message;
        }
    }

    /**
     * Render the mint list based on current filters
     */
    renderMintList() {
        const listEl = document.getElementById('mint-discovery-list');
        const filterEl = document.getElementById('mint-unit-filter');
        const searchEl = document.getElementById('mint-search');

        if (!listEl) return;

        let filtered = this.mints;

        // Apply unit filter
        if (filterEl && filterEl.value) {
            filtered = this.filterByUnit(filterEl.value);
        }

        // Apply search filter
        if (searchEl && searchEl.value.trim()) {
            const search = searchEl.value.trim().toLowerCase();
            filtered = filtered.filter(m =>
                (m.info?.name || '').toLowerCase().includes(search) ||
                m.url.toLowerCase().includes(search)
            );
        }

        if (filtered.length === 0) {
            listEl.innerHTML = '<p class="no-results">No mints found matching your criteria</p>';
        } else {
            listEl.innerHTML = filtered.map(m => this.renderMintCard(m)).join('');
        }

        // Update status
        const statusEl = document.getElementById('mint-discovery-status');
        if (statusEl) {
            statusEl.textContent = `Showing ${filtered.length} of ${this.mints.length} mints`;
        }
    }

    /**
     * Escape HTML entities
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

/**
 * Test mint expiry via AJAX
 * @param {string} mintUrl - Mint URL to test
 * @param {string} unit - Unit to test with
 * @param {string} csrfToken - CSRF token for request
 * @returns {Promise<Object>} Expiry test result
 */
async function testMintExpiry(mintUrl, unit, csrfToken) {
    const formData = new URLSearchParams();
    formData.append('action', 'test_mint_expiry');
    formData.append('mint_url', mintUrl);
    formData.append('unit', unit);
    formData.append('csrf_token', csrfToken);

    const response = await fetch(window.location.pathname, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    });

    return await response.json();
}

/**
 * Show expiry warning UI
 * @param {Object} result - Expiry test result
 * @param {HTMLElement} containerEl - Container element
 */
function showExpiryWarning(result, containerEl) {
    if (!containerEl) return;

    if (result.warning && result.message) {
        containerEl.style.display = 'block';
        const messageEl = containerEl.querySelector('#expiry-warning-message');
        if (messageEl) {
            messageEl.textContent = result.message;
        }
    } else {
        containerEl.style.display = 'none';
    }
}

/**
 * Get discovery modal HTML
 * @returns {string} Modal HTML string
 */
function getMintDiscoveryModalHtml() {
    return `
        <div id="mint-discovery-modal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 700px; max-height: 85vh;">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0;">Discover Mints</h3>
                    <button type="button" onclick="window.mintDiscoveryUI.closeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: inherit;">&times;</button>
                </div>
                <div class="modal-body" style="overflow-y: auto;">
                    <div id="mint-discovery-status" style="font-size: 0.85rem; color: var(--text-secondary, #a0aec0); margin-bottom: 1rem;">
                        Click Refresh to load mints
                    </div>

                    <div id="mint-discovery-filters" style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                        <input type="text" id="mint-search" placeholder="Search mints..." style="flex: 1; padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border, rgba(255,255,255,0.1)); background: var(--input-bg, rgba(0,0,0,0.2)); color: inherit;" onkeyup="window.mintDiscoveryUI.renderMintList()">
                        <select id="mint-unit-filter" style="padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border, rgba(255,255,255,0.1)); background: var(--input-bg, rgba(0,0,0,0.2)); color: inherit;" onchange="window.mintDiscoveryUI.renderMintList()">
                            <option value="">All units</option>
                            <option value="sat">SAT</option>
                            <option value="eur">EUR</option>
                            <option value="usd">USD</option>
                        </select>
                        <button type="button" class="btn btn-secondary" onclick="window.mintDiscoveryUI.startDiscovery()" style="white-space: nowrap;">Refresh</button>
                    </div>

                    <div id="mint-discovery-list" style="max-height: 400px; overflow-y: auto;">
                        <p style="color: var(--text-secondary, #a0aec0); font-size: 0.9rem;">Click Refresh to discover mints from the Nostr network.</p>
                    </div>

                    <div id="mint-discovery-loading" style="display: none; text-align: center; padding: 2rem;">
                        <div class="spinner" style="width: 40px; height: 40px; border: 3px solid var(--border, rgba(255,255,255,0.2)); border-top-color: var(--accent, #f7931a); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                        <p style="margin-top: 1rem; color: var(--text-secondary, #a0aec0);">Connecting to Nostr relays...</p>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .mint-card {
                background: var(--card-bg, rgba(0,0,0,0.2));
                border: 1px solid var(--border, rgba(255,255,255,0.1));
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 0.75rem;
                transition: box-shadow 150ms ease;
            }
            .mint-card:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            }
            .mint-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0.5rem;
            }
            .mint-rating {
                font-size: 0.9rem;
            }
            .review-count {
                color: var(--text-secondary, #a0aec0);
                font-size: 0.8rem;
            }
            .mint-status {
                font-size: 0.8rem;
                padding: 0.25rem 0.5rem;
                border-radius: 4px;
            }
            .mint-status.online {
                color: #48bb78;
            }
            .mint-status.offline {
                color: #e53e3e;
            }
            .mint-name {
                margin: 0 0 0.25rem 0;
                font-size: 1rem;
            }
            .mint-url {
                font-size: 0.8rem;
                color: var(--text-secondary, #a0aec0);
                margin: 0 0 0.5rem 0;
                word-break: break-all;
            }
            .mint-units {
                font-size: 0.8rem;
                color: var(--text-secondary, #a0aec0);
                margin-bottom: 0.75rem;
            }
            .mint-select-btn {
                width: 100%;
            }
            .no-results {
                text-align: center;
                color: var(--text-secondary, #a0aec0);
                padding: 2rem;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
    `;
}

/**
 * Get expiry warning HTML
 * @returns {string} Warning HTML string
 */
function getExpiryWarningHtml() {
    return `
        <div id="expiry-warning" class="warning" style="display: none; margin-top: 1rem;">
            <strong style="display: block; margin-bottom: 0.5rem;">Short Invoice Expiry</strong>
            <p id="expiry-warning-message" style="margin-bottom: 0.75rem;"></p>
            <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" id="expiry-acknowledged" style="width: 18px; height: 18px; margin-top: 2px;">
                <span style="font-size: 0.9rem;">I understand this may cause payment issues and want to proceed anyway</span>
            </label>
        </div>
    `;
}

// Export for use in both module and non-module contexts
if (typeof window !== 'undefined') {
    window.MintDiscoveryUI = MintDiscoveryUI;
    window.testMintExpiry = testMintExpiry;
    window.showExpiryWarning = showExpiryWarning;
    window.getMintDiscoveryModalHtml = getMintDiscoveryModalHtml;
    window.getExpiryWarningHtml = getExpiryWarningHtml;
}
