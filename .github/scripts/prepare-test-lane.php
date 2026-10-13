<?php

// This edits only the disposable CI manifest, never the published package policy.
$manifest = json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
$laravel = $manifest['require']['laravel/framework'];
if (!in_array($laravel, ['11.*', '12.*', '13.*'], true)) {
    throw new RuntimeException('Expected an explicitly selected Laravel test lane.');
}

// Test releases, rather than silently resolving an unaudited development branch.
$manifest['minimum-stability'] = 'stable';
if ($laravel === '11.*') {
    // Laravel 11 is end-of-security-support. Every released version is affected.
    // These specific exceptions allow isolated data-migration compatibility tests;
    // no HTTP/debug pages, signed URLs or email validation are exercised here.
    // New advisories and every other dependency remain blocked/audited normally.
    $manifest['config']['policy']['advisories']['ignore-id'] = [
        'PKSA-d5tc-s1qs-h781' => 'Laravel 11 CI only: debug-page XSS; no HTTP/debug pages in these isolated tests (GHSA-jh5r-qr3c-85q8).',
        'PKSA-m5cs-t1y6-qpcs' => 'Laravel 11 CI only: signed-URL path confusion; signed URLs are not used (GHSA-crmm-hgp2-wgrp).',
        'PKSA-3r5d-mb8f-1qw9' => 'Laravel 11 CI only: email-rule CRLF injection; email validation is not used (GHSA-5vg9-5847-vvmq).',
        'PKSA-mdq4-51ck-6kdq' => 'Laravel 11 CI only: duplicate CRLF advisory record; email validation is not used (GHSA-5vg9-5847-vvmq).',
    ];
}
file_put_contents('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
