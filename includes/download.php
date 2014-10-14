<?php

require_once __DIR__ . '/proxy.php';

use JonnyW\PhantomJs\Client as PJClient;

use GuzzleHttp\Client as GzClient;
use DigginGuzzle4CharsetSubscriber\CharsetSubscriber;
use Symfony\Component\DomCrawler\Crawler;

define('SUCCESS', 10);
define('FAILURE', 3);

global $user_agents, $active_user_agent;
$user_agents = array(
  'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
  'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
  'Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_2 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8H7 Safari/6533.18.5',
);
$active_user_agent = 0;

global $website;
global $active_mirror;
$website = array(
  'regexp' => '^(https?://)*zakon([0-9]*)\.rada\.gov\.ua',
  'mirrors' => array(
    'http://zakon1.rada.gov.ua',
    'http://zakon2.rada.gov.ua',
    'http://zakon3.rada.gov.ua',
    'http://zakon4.rada.gov.ua',
  ),
  'dir' => 'zakon.rada.gov.ua'
);
$active_mirror = 0;

global $downloads_dir;
$downloads_dir = __DIR__ . '/../downloads';

function get_mirror() {
  global $website, $active_mirror;
  if (!isset($website['shuffled']) || !$website['shuffled']) {
    shuffle($website['mirrors']);
    $website['shuffled'] = 1;
  }
  return $website['mirrors'][$active_mirror];
}

function switch_identity(&$url, $same_mirror) {
  global $active_mirror, $active_user_agent;

  if ($active_user_agent == 0) {
    $active_user_agent = 1;
    return TRUE;
  }

  if (!$same_mirror && $active_mirror < 3) {
    $active_mirror++;
    $url = full_url($url);
    $active_user_agent = 0;
    return TRUE;
  }
  return FALSE;
}

function full_url($url) {
  $url = short_url($url);

  $protocol = '';
  if (preg_match('@^(https?|file|ftp)://@', $url, $matches)) {
    $protocol = $matches[0];
    $url = preg_replace('@^(https?|file|ftp)://@', '', $url);
  }
  $url_parts = explode('/', $url);
  $new_url = array();
  foreach ($url_parts as $part) {
    $new_url[] = urlencode($part);
  }
  $url = $protocol . implode('/', $new_url);

  if (!preg_match('@^(https?|file|ftp)://@', $url)) {
    $url = get_mirror() . $url;
  }
  return $url;
}

function short_url($url) {
  global $website;
  $url = preg_replace('|'. $website['regexp'] .'|', '', $url);
  return $url;
}

function download($url, $reset_cache = FALSE, $save_as = null, $required_text = array(), $same_mirror = FALSE) {
  $output = '';
  $url = full_url($url);
  $save_as = $save_as ? full_url($save_as) : null;
  $output .= (getProxy() . ' → ' . short_url($url) . ': ');
  $style = 'default';

  $crawler = NULL;
  if (is_downloaded($save_as ?: $url) && !$reset_cache) {
    $html = file_get_contents(_url2path($save_as ?: $url));
    $output .= ('* ');
    _log($output);
    return $html;
  }

  $attempt = 0;
  while ($attempt < FAILURE) {
    try {
      $result = doDownload($url);

      switch ($result['status']) {
        case 200:
        case 301:
        case 302:
          while ($matches = js_protected($result['html'])) {
            $output .= ('-JS-');
            $attempt++;
            $result = doDownload(full_url(urldecode($matches[1])) . '?test=' . $matches[2], $attempt * 2);
            $style = 'yellow';
            if ($attempt > 5) {
              _log($output, 'red');
              throw new \Exception('Can not break JS protection.');
            }
          }

          if (fake_content($result['html'], $required_text)) {
            $output .= ('-F-');
            $style = 'yellow';

            if (switch_identity($url, $same_mirror)) {
              continue 2;
            }
            else {
              _log($output, 'red');
              throw new \Exception('Resource is not available (f).');
            }
          }

          save_file($save_as ?: $url, $result['html']);

          $output .= ('@'. $result['status'] . ' ');
          _log($output, $style);
          return $result['html'];
        case 404:
          $output .= ('-S' . $result['status'] . ' ');

          _log($output, 'red');
          throw new \Exception('Page is 404.');
          break;
        default:
          sleep(5);
          $attempt += 1;
          $output .= ('-S' . $result['status'] . '-');
          $style = 'yellow';
          continue 2;
          break;
      }
    } catch (\Exception $e) {
      $crawler = NULL;
      $attempt += 3;
      $output .= ('-E-');
      continue;
    }
  }
  _log($output, 'red');
  throw new \Exception('Resource is not available (a).');
}

function doDownload($url, $delay = 0) {
  if (1) { //isset($GLOBALS['phantom']) && $GLOBALS['phantom']) {
    $client = PJClient::getInstance();
    $client->addOption('--proxy=' . getProxy());
    $client->addOption('--load-images=false');
    $request = $client->getMessageFactory()->createRequest($url);
    $request->setDelay($delay);
    $request->addHeader('User-Agent', getUserAgent());
    $response = $client->getMessageFactory()->createResponse();
    $client->send($request, $response);
    $status = $response->getStatus();
    $html = $response->getContent();
  }
  else {
    $client = new GzClient();
    $client->getEmitter()->attach(new CharsetSubscriber);
    $response = $client->get($url, [
      'headers' => [
        'User-Agent' => getUserAgent(),
        'proxy'   => getProxy()
      ]
    ]);
    $status = $response->getStatusCode();
    $html = $response->getBody()->__toString();
  }
  return array(
    'status' => $status,
    'html' => doReplacements($html)
  );
}

function _url2path($url) {
  global $downloads_dir;
  $path = urldecode($url);
  $path = preg_replace('|http://|', '', $path);
  $path = preg_replace('|zakon[0-9]+\.rada|', 'zakon.rada', $path);

  if (substr($path, -1) == '/') {
    $path .= 'index.html';
  }
  else {
    $path .= '.html';
  }
  $path = $downloads_dir . '/' . $path;
  return $path;
}

function is_downloaded($url) {
  if (!$url) {
    return FALSE;
  }

  $path = _url2path($url);
  return file_exists($path);
}

function save_file($path, $html) {
  $path = _url2path($path);
  $dir = preg_replace('|/[^/]*$|', '/', $path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, TRUE);
  }
  // replace encoding to utf8 to achieve nice browsing experience.
  file_put_contents($path, $html);
}

function fake_content($text, $requirements = array()) {
  $default_stop = array(
    '502 Bad Gateway',
    'Ліміт перегляду списків на сьогодні',
    'Дуже багато відкритих сторінок за хвилину',
    'Доступ до списку заборонен',
    'Документи потрібно відкривати по одному',
  );
  if (isset($requirements['stop']) && is_array($requirements['stop'])) {
    $default_stop = array_merge($default_stop, $requirements['stop']);
  }
  foreach ($default_stop as $stop) {
    if (strpos($text, $stop) !== FALSE) return true;
  }

  $default_required = array();
  if (isset($requirements['required']) && is_array($requirements['required'])) {
    $default_required = array_merge($default_required, $requirements['required']);
  }
  foreach ($default_required as $rt) {
    if (strpos($text, $rt) === FALSE) return true;
  }

  return FALSE;
}

function js_protected($html) {
  if (preg_match('|<a href="?(.*)\?test=(.*)"? target="?_top"?><b>посилання</b></a>|', $html, $matches)) {
    return $matches;
  }
  return FALSE;
}

function getUserAgent() {
  global $user_agents, $active_user_agent;
  return $user_agents[$active_user_agent];
}

function doReplacements($html) {
  $html = preg_replace('|charset="?windows-1251"?|', 'charset="utf-8"', $html);
  return $html;
}

function getCrawler($html) {
  return new Crawler($html);
}