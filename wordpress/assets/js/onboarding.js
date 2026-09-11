/**
 * Onboarding-page behaviors. Loaded on every onboarding step; each block
 * no-ops when its elements are not on the current one.
 */
(function () {
    // The URL field must only take part in the browser's form validation
    // while "I already run a BareBits server" is the selected mode. Any text
    // in a type="url" input that is not a scheme-qualified URL (a pasted
    // "pay.example.com", browser autofill) otherwise blocks the WHOLE form
    // with "Please enter a URL" — making the install option unselectable.
    // Disabling also drops the field from the POST, which the install branch
    // never reads anyway. Without JavaScript the field stays enabled and
    // optional, and the server-side probe validates it as before.
    const urlField = document.getElementById('barebits-server-url');
    if (urlField) {
        const sync = function () {
            const urlMode = document.getElementById('barebits-mode-url').checked;
            urlField.disabled = !urlMode;
            urlField.required = urlMode;
        };
        document.querySelectorAll('input[name="barebits_mode"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        // Browsers restore form state on back-navigation after scripts ran.
        window.addEventListener('pageshow', sync);
        sync();
    }

    // "Start over" asks for confirmation. On the click (not the submit)
    // event on purpose: cancelling here stops the submission before the
    // maintenance guard's probe-and-resubmit ever engages.
    const resetBtn = document.getElementById('barebits-reset-button');
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            if (!window.confirm("Start over? This only resets the plugin's connection state — an installed BareBits server and its funds are not touched.")) {
                e.preventDefault();
            }
        });
    }
})();
