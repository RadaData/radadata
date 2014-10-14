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

function mark_law($law, $downloaded, $has_text = UNKNOWN) {
  db('db')->prepare("UPDATE urls SET `status` = :status, `has_text` = :has_text WHERE `id` = :id")
    ->execute(array(
      ':status' => $downloaded,
      ':has_text' => $has_text,
      ':id' => $law
    ));
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
}