<?php

declare(strict_types=1);

/*
 * Eenmalige OPcache-reset voor de testomgeving.
 *
 * Combell's PHP-FPM cachet gecompileerde bytecode (opcache.validate_timestamps=0),
 * waardoor gewijzigde PHP/Twig-bestanden na een deploy niet actief worden — alleen
 * een reset van de FPM-OPcache helpt, en die staat los van de CLI (cache:clear).
 * De deploy-workflow roept dit endpoint na de deploy aan (via het publieke domein,
 * zodat FPM het uitvoert). Beveiligd met een token.
 */
$token = 'acs-oc-7Yq2'; // alleen voor de niet-indexeerbare testomgeving
if (($_GET['t'] ?? '') !== $token) {
    http_response_code(403);
    echo 'forbidden';

    return;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (\function_exists('opcache_reset')) {
    $ok = \opcache_reset();
    echo $ok ? "opcache reset: OK\n" : "opcache reset: geweigerd (false)\n";
} else {
    echo "opcache_reset niet beschikbaar\n";
}

// Realpath-cache ook leegmaken zodat nieuwe symlinks/paden meteen kloppen.
\clearstatcache(true);
echo 'php ' . \PHP_VERSION . "\n";
