<?php

require 'includes/bootstrap.php';
require 'includes/download.php';
require 'includes/operations/discover.php';

define('SUCCESS', 10);
define('FAILURE', 3);

define('LAW_PAGE', 1);

define('NOT_DOWNLOADED', 0);
define('DOWNLOADED', 1);


global $website;
$website = array(
  'regexp' => '^(http://)*zakon(0-9*)\.rada\.gov\.ua',
  'url' => 'http://zakon4.rada.gov.ua',
  'dir' => 'zakon.rada.gov.ua'
);


$command = $argv[1];

switch ($command) {
  case "discover":
    discover_urls();
    break;
  case "help":
    print('Type "php crawler.php discover" to find new articles.' . "\n" .
      'Type "php crawler.php comments" to crawl comments in discovered articles.');
}
