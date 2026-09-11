/**
 * Provision step: expand/collapse the BareBits setup wizard iframe between
 * in-page and full-screen (the shell's barebits-expanded class — styles in
 * admin.css).
 */
(function () {
    const shell = document.getElementById('barebits-wizard-shell');
    if (!shell) {
        return;
    }
    const setExpanded = function (on) {
        shell.classList.toggle('barebits-expanded', on);
        // Survive reloads mid-wizard: an accidental refresh (or the
        // page revisited while setup is unfinished) returns to the
        // view the merchant chose.
        try { sessionStorage.setItem('barebitsWizardExpanded', on ? '1' : '0'); } catch (e) {}
    };
    document.getElementById('barebits-wizard-expand').addEventListener('click', function () { setExpanded(true); });
    document.getElementById('barebits-wizard-exit').addEventListener('click', function () { setExpanded(false); });
    // Full screen by default — the wizard is the whole point of this
    // step, so it opens expanded with no click. Only a stored '0' (the
    // merchant clicked "Exit full screen" this session) keeps it
    // collapsed; blocked sessionStorage also falls back to expanded.
    let collapsed = false;
    try { collapsed = sessionStorage.getItem('barebitsWizardExpanded') === '0'; } catch (e) {}
    setExpanded(!collapsed);
})();
