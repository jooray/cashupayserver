/**
 * Persist dismissal of the "leave us a review" notice. WP core adds the
 * dismiss (X) button to .is-dismissible notices after DOM ready, so a
 * delegated listener is the reliable way to catch the click. The X already
 * hides the notice for this page load; we just persist it.
 */
document.addEventListener('click', function (e) {
    const notice = e.target.closest('#barebits-review-notice');
    if (!notice || !e.target.closest('.notice-dismiss')) {
        return;
    }
    const body = new URLSearchParams();
    body.set('action', 'barebits_dismiss_review');
    body.set('nonce', notice.dataset.nonce);
    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
    });
});
