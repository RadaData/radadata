<?php
global $argv;

define('LAW_PAGE', 1);

define('UNKNOWN', 0);
define('HAS_TEXT', 1);
define('NO_TEXT', 10);


require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/variables.php';
require_once __DIR__ . '/download_utils.php';

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
    if (!is_dir(__DIR__ . '/../logs')) {
      mkdir(__DIR__ . '/../logs');
    }
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

function check($fix = FALSE) {
  $downloaded = db('db')->query('SELECT COUNT(*) FROM urls WHERE status = 1')->fetchColumn();
  $without = db('db')->query('SELECT COUNT(*) FROM urls WHERE status = 1 AND has_text = 10')->fetchColumn();
  $pending = db('db')->query('SELECT COUNT(*) FROM urls WHERE status = 0')->fetchColumn();

  $result = db('db')->query('SELECT id, status, has_text FROM urls  ORDER BY id');
  $law_dir = 'downloads/zakon.rada.gov.ua/laws/show/';
  function is_fake($html, $text = true) {
    return fake_content($html, array(
      'required' => array('<div id="article"', '</body>'),
      'stop' => $text ? array('<div id="pan_title"') : null
    ));
  }
  function remove_dir($dir) {
    exec('rm -rf ' . $dir);
  }
  $nd_orphaned_dirs = 0;
  $d_no_files = 0;
  $d_fake_content = 0;
  $d_unknown_text_true_content = 0;
  $d_unknown_text_no_text = 0;
  foreach ($result as $row) {
    $law = $row['id'];
    $law_path = $law_dir . $law;
    $card_path = $law_dir . $law . '/card.html';
    $text_path = $law_dir . $law . '/text.html';
    $page_path = $law_dir . $law . '/page.html';

    if ($row['status'] == NOT_DOWNLOADED && is_dir($law_path)) {
      $nd_orphaned_dirs++;
      if ($fix) {
        remove_dir($law_path);
      }
      continue;
    }

    if ($row['status'] == DOWNLOADED && $row['has_text'] == HAS_TEXT && !file_exists($text_path) && !file_exists($page_path)) {
      $d_no_files++;
      if ($fix) {
        remove_dir($law_path);
        mark_law($law, NOT_DOWNLOADED);
      }
    }
    if ($row['status'] == DOWNLOADED && $row['has_text'] == HAS_TEXT && (file_exists($text_path) || file_exists($page_path))) {
      if ((file_exists($text_path) && is_fake(file_get_contents($text_path), 1)) || (file_exists($page_path) && is_fake(file_get_contents($page_path), 0))) {
        $d_fake_content++;
        if ($fix) {
          remove_dir($law_path);
          mark_law($law, NOT_DOWNLOADED);
        }
      }
    }

    if ($row['status'] == DOWNLOADED && $row['has_text'] == UNKNOWN && (file_exists($text_path) || file_exists($page_path))) {
      if ((file_exists($text_path) && is_fake(file_get_contents($text_path), 1)) || (file_exists($page_path) && is_fake(file_get_contents($page_path), 0))) {
        $d_fake_content++;
        if ($fix) {
          remove_dir($law_path);
          mark_law($law, NOT_DOWNLOADED);
        }
      }
      else {
        $d_unknown_text_true_content++;
        if ($fix) {
          mark_law($law, DOWNLOADED, HAS_TEXT);
        }
      }
    }

    if ($row['status'] == DOWNLOADED && $row['has_text'] == UNKNOWN && !(file_exists($text_path) || file_exists($page_path)) && file_exists($card_path)) {
      $html = file_get_contents($card_path);
      if (strpos($html, 'Текст відсутній') !== FALSE) {
        $d_unknown_text_no_text++;
        if ($fix) {
          mark_law($law, DOWNLOADED, NO_TEXT);
        }
      }
      else {
        $d_no_files++;
        if ($fix) {
          mark_law($law, NOT_DOWNLOADED);
        }
      }
    }
    if ($row['status'] == DOWNLOADED && $row['has_text'] == UNKNOWN && !(file_exists($text_path) || file_exists($page_path)) && !file_exists($card_path)) {
      if ($fix) {
        mark_law($law, NOT_DOWNLOADED);
      }
    }
  }

  print("\n" . 'Downloaded : ' . $downloaded . ' (without text: ' . $without . ')');
  print("\n" . 'Pending    : ' . $pending);
  print("\n" . '-------------------------------------------------');
  print("\n" . 'Junk directories           : ' . $nd_orphaned_dirs);
  print("\n" . 'Missing files for downloads: ' . $d_no_files);
  print("\n" . 'Fake content for downloads : ' . $d_fake_content);
  print("\n" . 'Has text, but not marked   : ' . $d_unknown_text_true_content);
  print("\n" . 'No text, but not marked    : ' . $d_unknown_text_no_text);
  if ($fix) {
    print("\n" . 'ALL PROBLEMS FIXED');
  }
  print("\n");
}