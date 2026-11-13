<?php
/**
 * CashuPayServer - The plain "cannot start" page.
 *
 * Shared by the startup checks (preflight.php) and the last-resort exception handler in
 * entrypoint_guard.php. Written in old, plain PHP like preflight.php, so it still runs
 * where the rest of the code cannot.
 */

if (!function_exists('cashupay_preflight_page')) {
    function cashupay_preflight_page($title, $explanation, $supportMessage, $detail) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>CashuPayServer cannot start</title><style>'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f1117;'
            . 'color:#e2e8f0;margin:0;padding:2rem 1rem;line-height:1.5}'
            . '.card{max-width:640px;margin:0 auto;background:#1a1d27;border:1px solid #2d3343;border-radius:12px;padding:1.5rem}'
            . 'h1{font-size:1.3rem;margin:0 0 1rem;color:#f7931a}p{color:#cbd5e0}'
            . 'textarea{width:100%;box-sizing:border-box;min-height:9rem;background:#0f1117;color:#e2e8f0;'
            . 'border:1px solid #2d3343;border-radius:8px;padding:.75rem;font:inherit}'
            . 'a.btn,button{display:inline-block;margin-top:1rem;margin-right:.5rem;padding:.6rem 1.1rem;border-radius:8px;'
            . 'border:0;background:#f7931a;color:#1a1a1a;font-weight:600;text-decoration:none;cursor:pointer;font-size:1rem}'
            . 'details{margin-top:1rem;color:#a0aec0;font-size:.85rem}code{word-break:break-all}'
            . '</style></head><body><div class="card">'
            . '<h1>' . $h($title) . '</h1>'
            . '<p>' . $explanation . '</p>'
            . '<p><strong>What to do:</strong> send this message to your hosting provider\'s support. '
            . 'You can copy it as it is.</p>'
            . '<textarea id="msg" readonly>' . $h($supportMessage) . '</textarea>'
            . '<button type="button" onclick="var t=document.getElementById(\'msg\');t.select();'
            . 'try{document.execCommand(\'copy\');this.textContent=\'Copied\';}catch(e){}">Copy message</button>'
            . '<a class="btn" href="">Check again</a>'
            . ($detail !== '' ? '<details><summary>Technical detail (for your hosting provider)</summary><code>'
                . $h($detail) . '</code></details>' : '')
            . '</div></body></html>';
        exit;
    }
}
