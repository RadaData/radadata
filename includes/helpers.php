<?php

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DependencyInjection\ContainerInterface;

define('LAW_PAGE', 1);

define('UNKNOWN', 0);
define('HAS_TEXT', 1);
define('NO_TEXT', 10);

define('SUCCESS', 10);
define('FAILURE', 3);

define('NOT_DOWNLOADED', 0);
define('DOWNLOADED_CARD', 5);
define('DOWNLOADED_REVISIONS', 10);
define('DOWNLOADED_RELATIONS', 15);
define('SAVED', 100);


require_once __DIR__ . '/database.php';
require_once __DIR__ . '/variables.php';

$GLOBALS['start_time'] = time();

function shell_parameters()
{
	global $argv;
	if (!isset($args)) {
		$args = [];
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
	$files = array_diff(scandir($dir), ['.','..']);
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

/**
 * Whether or not law has been discovered previously.
 *
 * @param string $law_url Law page URL.
 *
 * @return bool
 */
function is_law_discovered($law_url)
{
    $result = db('db')->prepare("SELECT COUNT(*) FROM laws WHERE law_id = :law_id");
    $result->execute([':law_id' => URL2LawId($law_url)]);

    return (bool) $result->fetchColumn();
}

/**
 * Add the law url (or array) to the database for future scans.
 *
 * @param array|string $law_url URL of particular law (or array).
 */
function mark_law_discovered($law_url)
{
    if (!is_array($law_url)) {
        $law_url = [$law_url];
    }

    $values = [];
    foreach ($law_url as $url) {
        $values[] = "('" . URL2LawId($url) . "', '" . NOT_DOWNLOADED . "')";
    }
    $sql = "REPLACE INTO laws (law_id, status) VALUES " . implode(', ', $values);
    db('db')->exec($sql);
}

function mark_law($law_id, $downloaded, $has_text = UNKNOWN) {
	db('db')->prepare("UPDATE laws SET status = :status, has_text = :has_text WHERE law_id = :law_id")
		->execute([
			':status' => $downloaded,
			':has_text' => $has_text,
			':law_id' => $law_id
		]);
}

/**
 * Return DOMCrawler from a HTML string.
 *
 * @param $html
 *
 * @return Crawler
 */
function crawler($html) {
	return new Crawler($html);
}

/**
 * @return ContainerInterface
 */
function container()
{
	global $container;
	return $container;
}

/**
 * @return \ShvetsGroup\Service\Downloader
 */
function downloader() {
    return container()->get('downloader');
}

function download($url, $re_download = false, $save_as = null, $required_text = array(), $cant_change_mirror = false)
{
	return downloader()->download($url, $re_download, $save_as, $required_text, $cant_change_mirror);
}

function shortURL($url)
{
	return downloader()->shortURL($url);
}

function fullURL($url)
{
	return downloader()->fullURL($url);
}

function URL2LawId($url)
{
	return preg_replace('|/laws/show/|', '', urldecode(shortURL($url)));
}
