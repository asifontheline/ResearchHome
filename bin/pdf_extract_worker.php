<?php
// Standalone PDF-text-extraction worker, deliberately isolated from the
// main app (validator.php etc) -- invoked as its own short-lived child
// process by extract_pdf_text() in includes/functions.php, via `timeout`
// + a tight memory_limit, specifically so a crash HERE can never take
// down the process that spawned it.
//
// Confirmed on production (2026-08-24): smalot/pdfparser can hit PHP's
// "Allowed memory size exhausted" fatal error on certain PDFs (one
// example: a single embedded-font allocation of 320MB against a 512M
// limit, in Font.php). That specific error class is NOT catchable via
// try/catch in PHP (unlike a normal Exception/Error) and NOT
// interruptible via pcntl_alarm -- it terminates the process immediately,
// unconditionally, the moment the allocation is attempted. Running the
// parse in-process inside the validator sweep meant one bad PDF could
// kill the entire validator run mid-sweep, no matter what safety net
// wrapped the *call* to the parser -- confirmed on production: 770 of
// 864 validator runs failed to complete over 3 days once real PDF
// content started flowing through the sweep's rescue path.
//
// Process isolation is the actual fix: this worker gets its OWN, much
// smaller memory_limit (set below) and is launched under `timeout` for a
// hard wall-clock cap too. If it OOMs or hangs, only THIS process dies --
// the parent (validator.php) just sees a failed child invocation (no
// output, non-zero exit) and treats that exactly like "couldn't extract
// anything," the same as a dead link or a malformed response always has.
//
// Usage: php bin/pdf_extract_worker.php <path-to-pdf-file>
// Prints extracted, whitespace-collapsed text to stdout on success.
// Prints nothing and exits non-zero on any failure.

ini_set('memory_limit', '256M');

$path = $argv[1] ?? null;
if (!$path || !is_readable($path)) {
    fwrite(STDERR, "usage: pdf_extract_worker.php <path-to-pdf-file>\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

try {
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($path);
    $text = trim(preg_replace('/\s+/u', ' ', $pdf->getText()) ?? '');
    if ($text === '') {
        exit(1); // no text layer (e.g. a scanned-image-only PDF) -- same "nothing found" outcome
    }
    // Cap here too, not just in the parent -- no reason to pipe an
    // unbounded amount of text back over the pipe.
    echo mb_substr($text, 0, 20000);
    exit(0);
} catch (\Throwable $e) {
    // Malformed/encrypted/unsupported PDFs throw here -- ordinary
    // exceptions ARE catchable, this only exists as a backstop for
    // everything except the memory-exhaustion case above (which never
    // reaches this catch block at all -- the whole reason this worker is
    // a separate process).
    exit(1);
}
