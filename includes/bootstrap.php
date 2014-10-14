<?php
global $argv;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/variables.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/download.php';
require_once __DIR__ . '/operations.php';


$GLOBALS['start_time'] = time();

function shell_parameters()
{
    global $argv;
    if (!isset($args)) {
        $args = array();
        foreach ($argv as $i => $param) {
            if ($i > 0) {
                list($key, $value) = explode('=', $param . '=');
                $args[$key] = $value;
            }
        }
    }

    return $args;
}

function _log($message, $style = 'default')
{
    date_default_timezone_set('Europe/Kiev');

    $output = date('Y-m-d H:i:s') . ' :: ' . $message . "\n";
    if ($style == 'title') {
        $output = "\n\n" . $output;
    }
    $args = shell_parameters();
    $log_file = isset($args['log']) ? $args['log'] : __DIR__ . '/../logs/log.txt';
    file_put_contents($log_file, $output, FILE_APPEND);


    if ($style == 'red') {
      //echo "\x07\x07\x07\x07\x07\x07\x07\x07\x07";
      $output = "\033[0;31m" . $output . "\033[0m";
    } elseif ($style == 'yellow') {
        $output = "\033[1;33m" . $output . "\033[0m";
    } elseif ($style == 'green') {
        $output = "\033[0;32m" . $output . "\033[0m";
    } elseif ($style == 'title') {
        $output = "\033[1m" . $output . "\033[0m";
    }
    print($output);
}

function aws()
{
    global $conf;

    return $conf['aws'];
}

function delTree($dir) {
  $files = array_diff(scandir($dir), array('.','..'));
  foreach ($files as $file) {
    (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
  }
  return rmdir($dir);
}

function better_trim($text) {
  $text = preg_replace('|^[\n\s ]*|', '', $text);
  $text = preg_replace('|[\n\s ]*$|', '', $text);
  return $text;
}