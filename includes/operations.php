<?php

declare(ticks = 1);

function download_laws($reset = FALSE) {
  if ($reset) {
    clear_jobs('download_laws');

    $result = db('db')->query('SELECT id FROM urls WHERE status = ' . NOT_DOWNLOADED . ' ORDER BY id');
    foreach ($result as $row) {
      add_job('download_law', array('id' => $row['id']), 'download_laws');
    }
  }
  launch_workers(20, 'download_laws', 'download_law');
}

function download_law($id) {
  try {
    $html = download('/laws/card/' . $id, 0, '/laws/show/' . $id . '/card');

    if (strpos($html, 'Текст відсутній') !== FALSE) {
      mark_law($id, DOWNLOADED, NO_TEXT);
    }
    else {
      $url = '/laws/show/' . $id . '/page';
      $html = download($url, 0, null,
        array('required' => array('<div id="article"', '</body>'))
      );
      while (preg_match('|<a href="?(.*?)"? title="наступна сторінка">наступна сторінка</a>|', $html, $matches)) {
        $url = urldecode($matches[1]);
        $html = download($url, 0, null,
          array('required' => array('<div id="article"', '</body>'))
        );
      }

      mark_law($id, DOWNLOADED, HAS_TEXT);
    }
  } catch (Exception $e) {
    _log($e->getMessage(), 'red');
  }
}

function update() {
  $issuers = discover_meta();
  clear_jobs('update');

  foreach ($issuers as $name => $issuer) {
    add_job('update_issuer', array('issuer_url' => $issuer['url']), 'update');
  }
  launch_workers(4, 'update', 'update_issuer');
}

function update_issuer($url) {
  try {
    $first_page = getCrawler(download($url, TRUE));
    $last_pager_link = $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[last()]');
    $page_count = $last_pager_link->count() ? preg_replace('/(.*?)([0-9]+)$/', '$2', $last_pager_link->attr('href')) : 1;

    $urls = array();
    $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li/a')
      ->each(function ($node) use (&$urls) {
        $urls[] = $node->attr('href');
      });

    $i = 2;
    while (!is_discovered_url($urls[count($urls) - 1]) && $i < $page_count) {
      $list_page = getCrawler(download($url . '/page' . $i));
      $list_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li/a')
        ->each(function ($node) use (&$urls) {
          $urls[] = $node->attr('href');
        });
      $i++;
    }
    mark_discovered_url($urls);
  } catch (Exception $e) {
    _log($e->getMessage(), 'red');
  }
}


function discover($reset = FALSE) {
  if ($reset) {
    $issuers = discover_meta();
    clear_jobs('discover');

    foreach ($issuers as $name => $issuer) {
      add_job('discover_issuer', array('issuer_url' => $issuer['url']), 'discover');
    }
    variable_set('last_discover', time());
  }
  launch_workers(1, 'discover', 'discover_issuer');
  launch_workers(1, 'discover', 'discover_law_urls');
}

function discover_meta($reset = FALSE) {
  $issuers = variable_get('issuers');
  if (!$issuers || $reset) {
    $group = NULL;

    $list = getCrawler(download('/laws/stru/a'));
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

function discover_issuer($url) {
  try {
    $first_page = getCrawler(download($url));
    $last_pager_link = $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[last()]');
    $page_count = $last_pager_link->count() ? preg_replace('/(.*?)([0-9]+)$/', '$2', $last_pager_link->attr('href')) : 1;
    for ($i = $page_count; $i >= 1; $i--) {
      add_job('discover_law_urls', array('url' => $url . ($i > 1 ? '/page' . $i : '')), 'discover');
    }
  } catch (Exception $e) {
    _log($e->getMessage(), 'red');
  }
}

function discover_law_urls($url) {
  try {
    $list_page = getCrawler(download($url));

    $urls = array();
    $list_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li/a')
      ->each(function ($node) use (&$urls) {
        $urls[] = $node->attr('href');
      });
    mark_discovered_url($urls);
  } catch (Exception $e) {
    _log($e->getMessage(), 'red');
  }
}

function url_to_lawID($url) {
  return preg_replace('|/laws/show/|', '', urldecode(short_url($url)));
}

function is_discovered_url($url) {
  $result = db('db')->prepare("SELECT COUNT(*) FROM urls WHERE id = :id");
  $result->execute(array(':id' => url_to_lawID($url)));
  return (bool) $result->fetchColumn();
}

function mark_discovered_url($urls) {
  if (!is_array($urls)) {
    $urls = array($urls);
  }

  $values = array();
  foreach ($urls as $url) {
    $values[] = "('" . url_to_lawID($url) . "', '" . NOT_DOWNLOADED . "', '" . LAW_PAGE . "')";
  }
  $sql = "INSERT IGNORE INTO urls (url, status, type) VALUES " . implode(', ', $values);

  $result = db('db')->exec($sql);
}

