<?php

require 'includes/bootstrap.php';

define('LAW_PAGE', 1);

define('UNKNOWN', 0);
define('HAS_TEXT', 1);
define('NO_TEXT', 10);

define('NOT_DOWNLOADED', 0);
define('DOWNLOADED', 1);

$command = $argv[1];
$argument = isset($argv[2]) ? $argv[2] : null;

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
  case "check":
    check($argument);
    break;
  case "help":
    print('Type "php crawler.php discover" to find new articles.' . "\n" .
      'Type "php crawler.php comments" to crawl comments in discovered articles.');
}