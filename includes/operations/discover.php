<?php

function discover_meta($reset = FALSE) {
  $issuers = variable_get('issuers');
  if (!$issuers || $reset) {
    $group = NULL;

    $list = download('/laws/stru/a');
    $count = $list->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[1]/tbody/tr/td/table/tbody/tr')
      ->count();
    $list->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[1]/tbody/tr/td/table/tbody/tr')
      ->each(function ($node) use (&$issuers, &$group) {
        $cells = $node->filterXPath('//td');
        if ($cells->count() == 1) {
          $text = better_trim($cells->text());
          if ($text) {
            $group = $text;
          }
        }
        elseif ($cells->count() == 4) {
          $issuer_link = $node->filterXPath('//td[2]/a');
          $issuer = array(
            'name' => better_trim($issuer_link->text()),
            'url' => $issuer_link->attr('href'),
            'group' => $group,
          );
          if (preg_match('|(.*?) \((.*?)\)|', $issuer['name'], $match)) {
            if (isset($match[2])) {
              $issuer['name'] = $match[2];

            }
            $issuer['full_name'] = $match[1];
          }
          if (!isset($issuers[$issuer['name']])) {
            $issuers[$issuer['name']] = $issuer;
          }
        }
      });
    variable_set('issuers', $issuers);
  }
  return $issuers;
}

function discover_urls($list = '/laws/main/o1') {
  $issuers = discover_meta();

  $fast_forward = true;
  $issuer_to_scan = variable_get('last_parsed_issuer', null);

  foreach ($issuers as $name => $issuer) {
    if ($fast_forward && $issuer_to_scan && $issuer_to_scan != $name) {
      continue;
    }
    $fast_forward = false;
    variable_set('last_parsed_issuer', -1);

    $page_to_scan = variable_get('last_parsed_page', -1);
    $first_page = download($list);
    $page_count = $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[10]')
      ->attr('href');
    $page_count = preg_replace('/(.*?)([0-9]+)$/', '$2', $page_count);

    if ($page_to_scan == -1) {
      $page_to_scan = $page_count;
    }
    else {
      while ($page_to_scan < $page_count) {
        $list_page = download($list . '/page' . $page_to_scan);
        $first_law = $list_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li[1]/a')
          ->attr('href');
        if (is_discovered($first_law)) {
          $page_to_scan--;
          break;
        }
        else {
          $page_to_scan++;
        }
      }
    }


    while ($page_to_scan > 0) {
      $list_page = download($list . '/page' . $page_to_scan);
      $list_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li')
        ->each(function ($node) {
          $url = $node->filterXPath('//a')->attr('href');
          if (!is_discovered($url)) {
            discover($url);
          }
        });
      variable_set('last_parsed_page', $page_to_scan);
      $page_to_scan--;
    }

    variable_set('last_parsed_page', -1);
  }
}

function discover($url) {
  db('db')->exec("INSERT INTO urls (url, status, type) VALUES ('" . short_url($url) . "', '" . NOT_DOWNLOADED . "', '" . LAW_PAGE . "');");
}

function is_discovered($url) {
  $result = db('db')->prepare("SELECT COUNT(*) FROM urls WHERE url = :url");
  $result->execute(array(':url' => $url));
  return (bool) $result->fetchColumn();
}

function better_trim($text) {
  $text = preg_replace('|^[\n\s ]*|', '', $text);
  $text = preg_replace('|[\n\s ]*$|', '', $text);
  return $text;
}