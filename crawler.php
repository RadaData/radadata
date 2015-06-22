<?php

require 'includes/bootstrap.php';


if (empty($argv[1])) {
    die();
}

$command = $argv[1];
$argument = isset($argv[2]) ? $argv[2] : null;

$me = getmypid();
exec('pgrep -f "crawler.php ' . $command . '" | grep -v "' . $me . '" | xargs kill -9');

switch ($command) {
    case "check":
        check($argument);
        break;
    case "discover":
    case "update":
    case "download_laws":
    case "clean_jobs":
        require_once __DIR__ . '/includes/jobs.php';
        require_once __DIR__ . '/includes/download.php';
        require_once __DIR__ . '/includes/operations.php';
        switch ($command) {
            case "discover":
                discover($argument);
                break;
            case "update":
                update($argument);
                break;
            case "download_laws":
                download_laws($argument);
                break;
            case "clean_jobs":
                clean_jobs($argument);
                break;
        }
        break;
    case "help":
        print('Type "php crawler.php discover" to find new articles.' . "\n" .
          'Type "php crawler.php comments" to crawl comments in discovered articles.');
}
