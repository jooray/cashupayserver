/**
 * "Reveal" button for the saved BareBits admin password — used on the
 * Connection page and on the onboarding chooser's reconnect hint. Fetches
 * the password over admin-ajax with the nonce the button carries.
 */
(function () {
    const btn = document.getElementById('barebits-reveal-password');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        const body = new URLSearchParams();
        body.set('action', 'barebits_reveal_password');
        body.set('nonce', btn.dataset.nonce);
        // A 503 is WordPress's own maintenance screen (an auto-update in
        // progress) — retry until it's back instead of failing silently;
        // anything else falls through as before.
        const attempt = function () {
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) {
                if (r.status === 503) {
                    btn.disabled = true;
                    btn.textContent = 'Waiting for WordPress…';
                    setTimeout(attempt, 5000);
                    return null;
                }
                return r.json();
            }).then(function (res) {
                if (res && res.success && res.data) {
                    document.getElementById('barebits-admin-password').textContent = res.data;
                    btn.remove();
                } else if (res) {
                    btn.disabled = false;
                    btn.textContent = 'Reveal';
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'Reveal';
            });
        };
        attempt();
    });
})();
