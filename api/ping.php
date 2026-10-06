<?php
/**
 * Discovery beacon for the ESP32 booth.
 *
 *   GET /api/ping.php  ->  {"disinfentry":true}
 *
 * The booth sweeps the local subnet looking for this signature, so it can tell
 * a DisinfEntry install from any other machine answering on port 80 and locate
 * the server without a hardcoded IP address.
 *
 * Deliberately dependency free: no config, no session, no database. A sweep
 * hits this file up to 254 times in a pass, and it must still answer while
 * MySQL is down so the booth can record the address and retry its sync later.
 *
 * Unauthenticated by necessity - discovery happens before the booth knows where
 * to send its API key. It discloses nothing the login page does not already.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

echo '{"disinfentry":true}';
