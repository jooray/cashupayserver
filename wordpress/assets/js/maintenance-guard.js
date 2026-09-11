/**
 * Gate every admin-post.php form on WordPress not being in maintenance mode
 * — see barebits_render_maintenance_guard(), which renders the waiting note
 * this script keys off. 503 is the ONLY status that waits; anything else
 * (including errors a broken probe might fabricate) falls through to a plain
 * submit — this guard may delay the merchant, never trap them. A second
 * click while waiting is the manual override, and without JavaScript every
 * form submits exactly as before.
 */
(function () {
    const note = document.getElementById('barebits-maintenance-waiting');
    if (!note) {
        return;
    }
    // admin-post.php with no action is a cheap no-op that still answers
    // 503 while WordPress is in maintenance mode.
    const probeUrl = note.dataset.probeUrl;
    document.querySelectorAll('form').forEach(function (form) {
        if ((form.getAttribute('action') || '').indexOf('admin-post.php') === -1) {
            return;
        }
        let cleared = false;
        form.addEventListener('submit', function (e) {
            if (cleared) {
                return; // the probe said go
            }
            if (form.dataset.barebitsWaiting === '1') {
                cleared = true; // second click while waiting: manual override
                return;
            }
            // Native validation already passed (the submit event only
            // fires afterwards), so submitting programmatically — which
            // skips both validation and this handler — is safe. Via the
            // prototype: WordPress's submit_button() renders an input
            // NAMED "submit", which shadows form.submit with the element.
            e.preventDefault();
            form.dataset.barebitsWaiting = '1';
            const go = function () {
                cleared = true;
                HTMLFormElement.prototype.submit.call(form);
            };
            const probe = function () {
                fetch(probeUrl, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (res) {
                        if (res.status === 503) {
                            form.insertAdjacentElement('afterend', note);
                            note.hidden = false;
                            setTimeout(probe, 5000);
                        } else {
                            go();
                        }
                    })
                    .catch(go);
            };
            probe();
        });
    });
})();
