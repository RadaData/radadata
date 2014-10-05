<?php

use Goutte\Client as GClient;
use Guzzle\Http\Exception as Exception;
use Symfony\Component\DomCrawler\Crawler;

global $website;
global $downloads_dir;
$downloads_dir = __DIR__ . '/../downloads';


function full_url($url) {
  global $website;
  if (strpos($url, 'http') === FALSE) {
    $url = $website['url'] . $url;
  }
  return $url;
}

function short_url($url) {
  $url = preg_replace('|^http(s*)://([^/]+)|', '', $url);
  return $url;
}

function download($url, $reset_cache = FALSE) {
  $url = full_url($url);

  $crawler = NULL;
  if (is_downloaded($url) && !$reset_cache) {
    $html = file_get_contents(_url2path($url));
    print('* ');
    return new Crawler($html);
  }

  $attempt = 0;
  while ($attempt < FAILURE) {
    try {
      $client = MyPJClient::getInstance();
      addPhantomOptions($client);
      $request = $client->getMessageFactory()->createRequest($url);
      addRequestHeaders($request);
      $response = $client->getMessageFactory()->createResponse();
      $client->send($request, $response);
      $status = $response->getStatus();

      switch ($status) {
        case 200:
        case 301:
          $html = $response->getContent();
          $html = doReplacements($html);
          $crawler = new Crawler($html);

          if (fake_content($crawler->text())) {
            sleep(5);
            $attempt += 1;
            print('-DA-');
            continue 2;
          }

          save_file($url, $crawler->html());
          $attempt = SUCCESS;
          print('@ ');
          return $crawler;
        case 404:
          print('-S' . $status . '');
          return;
          break;
        default:
          sleep(5);
          $attempt += 1;
          print('-S' . $status . '-');
          continue 2;
          break;
      }
    } catch (\Exception $e) {
      $crawler = NULL;
      $attempt += 3;
      print('-E-');
      continue;
    }
  }
}

function _url2path($url) {
  global $downloads_dir;
  $path = $url;
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

function fake_content($text) {
  return strpos($text, '502 Bad Gateway') !== FALSE || strpos($text, 'Сервер перевантажений') !== FALSE;
}

function addPhantomOptions($client) {
  //$client->addOption('--output-encoding=windows-1251');
}

function addRequestHeaders($request) {
  $request->addHeader('Cache-Control', "max-age=0");
  $request->addHeader('Accept', "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8");
  $request->addHeader('User-Agent', "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/32.0.1700.77 Safari/537.36");
}

function doReplacements($html) {
  $html = preg_replace('|charset="(.*?)"|', 'charset="utf-8"', $html);
  return $html;
}