<?php

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DependencyInjection\ContainerInterface;

define('LOG_PATH', BASE_PATH . 'logs/');

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

	$output = str_pad(getmypid(), 7, ':') . ' | ' . date('Y-m-d H:i:s') . ' :: ' . $message . "\n";
	if ($style == 'title') {
		$output = "\n\n" . $output;
	}
	$args = shell_parameters();
	if (!is_dir(LOG_PATH)) {
		mkdir(LOG_PATH);
	}
	$log_file = isset($args['log']) ? $args['log'] : LOG_PATH . 'log.txt';
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

function max_date()
{
	return container()->getParameter('max_crawl_date') ?: '3000-01-01';
}

/**
 * @return \ShvetsGroup\Service\JobsManager
 */
function job_manager() {
	return container()->get('jobManager');
}

/**
 * @return \ShvetsGroup\Service\Downloader
 */
function downloader() {
    return container()->get('downloader');
}

function download($url, $options = [])
{
	return downloader()->download($url, $options);
}

function downloadList($url, $options = [])
{
	return downloader()->downloadList($url, $options);
}

function downloadCard($url, $options = [])
{
	return downloader()->downloadCard($url, $options);
}

function shortURL($url)
{
	return downloader()->shortURL($url);
}

function fullURL($url)
{
	return downloader()->fullURL($url);
}