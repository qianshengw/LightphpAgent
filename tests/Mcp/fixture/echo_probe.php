<?php
// Simplified fixture to bisect: minimal echo server
error_reporting(E_ALL);
ini_set('display_errors', '1');
fwrite(STDERR, "fixture-started\n");
$line = fgets(STDIN);
fwrite(STDERR, "got: " . var_export($line, true) . "\n");
fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]) . "\n");
fflush(STDOUT);
