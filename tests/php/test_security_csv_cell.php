<?php
/**
 * Security::csvCell must neutralize spreadsheet formula injection: any cell
 * whose first visible character is = + - @ (or a leading tab/CR/LF that a
 * parser may strip before evaluating the first char) is prefixed with a single
 * quote so Excel / Sheets / LibreOffice render it literally instead of running
 * it. Ordinary values pass through untouched.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

// Dangerous leading characters -> single-quote prefixed.
assert_eq("'=HYPERLINK(\"http://evil\",\"x\")", Security::csvCell('=HYPERLINK("http://evil","x")'), 'equals');
assert_eq("'+1+1", Security::csvCell('+1+1'), 'plus');
assert_eq("'-2+3", Security::csvCell('-2+3'), 'minus');
assert_eq("'@SUM(A1)", Security::csvCell('@SUM(A1)'), 'at');
assert_eq("'\tcmd", Security::csvCell("\tcmd"), 'tab');
assert_eq("'\rcmd", Security::csvCell("\rcmd"), 'cr');
assert_eq("'\ncmd", Security::csvCell("\ncmd"), 'lf');

// A real payer email/memo that begins with a formula char is also caught.
assert_eq("'=cmd|'/c calc'!A1", Security::csvCell("=cmd|'/c calc'!A1"), 'dde');

// Benign values are returned unchanged.
assert_eq('alice@example.com', Security::csvCell('alice@example.com'), 'plain email');
assert_eq('Order 1234', Security::csvCell('Order 1234'), 'plain text');
assert_eq('500', Security::csvCell('500'), 'number-ish string');
assert_eq('', Security::csvCell(''), 'empty');
assert_eq('', Security::csvCell(null), 'null -> empty');
assert_eq('1000', Security::csvCell(1000), 'int -> string');

// A formula char that is NOT first is fine (only the leading char matters).
assert_eq('a=b', Security::csvCell('a=b'), 'equals not first');

// Leading-whitespace bypass: a formula hidden behind spaces/tabs that a parser
// trims before evaluating must still be neutralized (the whole original value,
// whitespace included, is quoted).
assert_eq("' =cmd|'/c calc'!A1", Security::csvCell(" =cmd|'/c calc'!A1"), 'space then equals');
assert_eq("'   =1+1", Security::csvCell('   =1+1'), 'multiple spaces then equals');
assert_eq("' @SUM(A1)", Security::csvCell(' @SUM(A1)'), 'space then at');
assert_eq("' \t-2+3", Security::csvCell(" \t-2+3"), 'space+tab then minus');

// Whitespace that does NOT hide a formula stays untouched.
assert_eq(' hello', Security::csvCell(' hello'), 'leading space, benign');
assert_eq('   ', Security::csvCell('   '), 'only whitespace');

fwrite(STDERR, "ok\n");
