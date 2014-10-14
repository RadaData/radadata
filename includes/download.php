<?php

require_once __DIR__ . '/proxy.php';
require_once __DIR__ . '/download_utils.php';

use JonnyW\PhantomJs\Client as PJClient;

use GuzzleHttp\Client as GzClient;
use DigginGuzzle4CharsetSubscriber\CharsetSubscriber;
use Symfony\Component\DomCrawler\Crawler;



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

