<?php
/**
 * CashuPayServer - Recovery kit
 *
 * The seed phrase alone does not recover a lost server's money: restore scans one mint,
 * the mint the operator types in. Funds at a backup mint, or at a mint the store used
 * before switching, come back only if the operator remembers each of them. The kit is
 * the recovery words plus every mint and unit the store has ever used, as a page to
 * print or a text file to keep offline (R8, 2026-10-28).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class RecoveryKit {
    /**
     * Every (mint, unit) the store has used: its current accounts, and any mint an
     * invoice or a withdrawal of this store went through.
     *
     * @return array<int, array{mint_url: string, unit: string, current: bool}>
     */
    public static function mints(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store) {
            return [];
        }
        $storeUnit = strtolower((string)($store['mint_unit'] ?? 'sat'));
        $found = [];
        $add = function (?string $url, ?string $unit, bool $current) use (&$found, $storeUnit): void {
            $url = rtrim(trim((string)$url), '/');
            if ($url === '') {
                return;
            }
            $unit = strtolower(trim((string)($unit ?: $storeUnit)));
            $key = $url . '|' . $unit;
            $found[$key] = ['mint_url' => $url, 'unit' => $unit, 'current' => ($found[$key]['current'] ?? false) || $current];
        };
        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            $add($account['mint_url'], $account['unit'], true);
        }
        foreach (Database::fetchAll(
            'SELECT DISTINCT mint_url, mint_unit FROM invoices WHERE store_id = ? AND mint_url IS NOT NULL', [$storeId]
        ) as $row) {
            $add($row['mint_url'], $row['mint_unit'], false);
        }
        foreach (Database::fetchAll(
            'SELECT DISTINCT mint_url, unit FROM transfers WHERE store_id = ? AND mint_url IS NOT NULL', [$storeId]
        ) as $row) {
            $add($row['mint_url'], $row['unit'], false);
        }
        ksort($found);
        return array_values($found);
    }

    /** Changes whenever the list of mints does, so the dashboard can ask for a new sheet. */
    public static function fingerprint(string $storeId): string {
        return hash('sha256', json_encode(array_map(fn($m) => $m['mint_url'] . '|' . $m['unit'], self::mints($storeId))));
    }

    /** Remember that the operator took a kit for the current list of mints. */
    public static function markTaken(string $storeId): void {
        Config::set('recovery_kit_' . $storeId, ['fingerprint' => self::fingerprint($storeId), 'at' => time()]);
    }

    /** Null when the operator has a kit for the current mints, else why a new one is needed. */
    public static function reminder(string $storeId): ?string {
        $taken = Config::get('recovery_kit_' . $storeId);
        if (!is_array($taken) || empty($taken['fingerprint'])) {
            return 'never';
        }
        return hash_equals((string)$taken['fingerprint'], self::fingerprint($storeId)) ? null : 'mints_changed';
    }

    public static function text(array $store): string {
        $words = preg_split('/\s+/', trim((string)$store['seed_phrase']));
        $lines = [
            'CashuPayServer recovery sheet',
            'Store: ' . $store['name'],
            'Made: ' . gmdate('Y-m-d H:i') . ' UTC',
            '',
            'KEEP THIS SAFE. Anyone with these words can take the money. Do not email it,',
            'do not put it in a cloud folder, and do not type the words into any other wallet.',
            '',
            'Recovery words:',
        ];
        foreach ($words as $i => $word) {
            $lines[] = sprintf('  %2d. %s', $i + 1, $word);
        }
        $lines[] = '';
        $lines[] = 'Payment services (mints) this store has used. To recover, install CashuPayServer,';
        $lines[] = 'choose "Use existing seed" with the words above and the first service below, then';
        $lines[] = 'add each other service as a backup mint in the dashboard (same unit):';
        foreach (self::mints((string)$store['id']) as $mint) {
            $lines[] = '  - ' . $mint['mint_url'] . '  (unit: ' . $mint['unit'] . ($mint['current'] ? ', in use' : ', used before') . ')';
        }
        return implode("\n", $lines) . "\n";
    }

    public static function html(array $store): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $words = preg_split('/\s+/', trim((string)$store['seed_phrase']));
        $wordList = '';
        foreach ($words as $i => $word) {
            $wordList .= '<li><span>' . ($i + 1) . '.</span> ' . $h($word) . '</li>';
        }
        $mintList = '';
        foreach (self::mints((string)$store['id']) as $mint) {
            $mintList .= '<li><code>' . $h($mint['mint_url']) . '</code> &middot; unit ' . $h($mint['unit'])
                . ($mint['current'] ? ' &middot; in use' : ' &middot; used before') . '</li>';
        }
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Recovery sheet</title><style>'
            . 'body{font-family:Georgia,serif;color:#111;background:#fff;max-width:720px;margin:2rem auto;padding:0 1rem}'
            . 'h1{font-size:1.5rem;margin-bottom:.25rem}.meta{color:#555;margin-top:0}'
            . '.warn{border:2px solid #111;padding:.75rem 1rem;margin:1rem 0}'
            . 'ol.words{columns:3;list-style:none;padding:0;font-size:1.15rem;line-height:2}'
            . 'ol.words span{display:inline-block;width:2rem;color:#555}code{font-size:.95rem}'
            . '@media print{button{display:none}}</style></head><body>'
            . '<button onclick="window.print()" style="float:right;padding:.5rem 1rem">Print</button>'
            . '<h1>CashuPayServer recovery sheet</h1>'
            . '<p class="meta">Store: <strong>' . $h($store['name']) . '</strong> &middot; made ' . gmdate('Y-m-d') . '</p>'
            . '<div class="warn"><strong>Keep this sheet safe, like cash.</strong> Anyone with these words can take '
            . 'the money. Do not photograph it, email it, or type the words into any other wallet.</div>'
            . '<h2>Recovery words</h2><ol class="words">' . $wordList . '</ol>'
            . '<h2>Payment services this store has used</h2>'
            . '<p>To recover after losing this website: install CashuPayServer, choose <em>Use existing seed</em> with '
            . 'the words above and the first service below, then add each other service as a backup mint in the '
            . 'dashboard, with the same unit.</p><ul>' . $mintList . '</ul>'
            . '<p class="meta">Print a new sheet whenever the dashboard asks for one: it does when this list changes.</p>'
            . '</body></html>';
    }
}
