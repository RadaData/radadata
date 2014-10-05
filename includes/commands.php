<?php

require_once __DIR__ . '/variables.php';


use Goutte\Client;

/************************************************************
 * Domain parsing commands.
 ************************************************************/

function parse_domain_list_letter($letter)
{
    $tasks = new TaskFactory();
    $client = new Client();
    $crawler = $client->request('GET', "http://www.whois.ws/whois_index/index.$letter.php");
    $sub_pages = array();
    $crawler->filterXPath("//a[contains(@href, 'domain_list')]")->each(
        function ($sub_page, $i) use (&$sub_pages) {
            $sub_pages[] = $sub_page->getAttribute('href');
        }
    );
    _log("Processed letter: http://www.whois.ws/whois_index/index.$letter.php");

    $tasks_created = 0;
    foreach ($sub_pages as $sub_page_url) {
        $tasks_created += $tasks->add_task('parse_domain_list_subpage', $sub_page_url, PRIORITY_LOW + 1);
    }
    ;
    _log("Subpages found: $tasks_created");
    unset($client);
    unset($tasks);
}


/**
 * Parses the domain list page and returns the list of all domains found.
 * @param GearmanJob $job
 * @return string Serialized results of the job.
 */
function parse_domain_list_subpage($sub_page_url)
{
    $tasks = new TaskFactory();
    $client = new Client();
    $crawler = $client->request('GET', "http://www.whois.ws/whois_index/$sub_page_url");

    $domains = array();
    $crawler->filterXPath("//a[contains(@href, 'ip-address')]")->each(
        function ($domain_text, $i) use (&$domains) {
            $domains[] = $domain_text->nodeValue;
        }
    );
    _log("Processed page: http://www.whois.ws/whois_index/$sub_page_url");

    $tasks_created = 0;
    foreach ($domains as $domain) {
        $query = db()->prepare('SELECT domain FROM domains WHERE domain = ":domain"');
        $query->execute(array(':domain' => $domain));
        $domain_exists = $query->fetch(PDO::FETCH_ASSOC);
        if (!$domain_exists) {
            $tasks_created += $tasks->add_task('process_domain', $domain, PRIORITY_LOW + 2);
        }
    }
    _log("Domains found: $tasks_created");
    unset($client);
    unset($tasks);
    close_db();
}


function process_domain($domain)
{
    $info = perform_basic_scan($domain);
    db()->prepare("INSERT INTO domains (domain, active, cms, version) VALUES (:domain, :active, :cms, :version);")
      ->execute(
          array(
              ':domain' => $info['domain'],
              ':active' => $info['active'],
              ':cms' => $info['cms'],
              ':version' => $info['version']
          )
      );
    close_db();
    return TRUE;
}

/**
 * Returns array like this:
 * array(
 *   'domain' => 'google.com',
 *   'active' => 1,
 *   'cms' => 'drupal',
 *   'version' => '7',
 * );
 */
function perform_basic_scan($domain)
{
    $info = array(
        'domain' => $domain,
        'active' => 0,
        'cms' => null,
        'version' => null,
    );

    _log("$domain: Performing basic scan...");
    try {
        $client = new Guzzle\Http\Client("http://$domain/robots.txt", curl_options());
        $client->get('/')->send();
        $info['active'] = 1;
    } catch (Exception $e) {
        _log("$domain: front page throws:\n" . $e->getMessage(), 'red');
        unset($client);

        return $info;
    }

    // Best case: CHANGELOG is found and exact version can be scanned.
    try {
        $content = (string) $client->get('CHANGELOG.txt')->send();
        if (preg_match('|Drupal ([^,]*),|', $content, $matches)) {
            $info['cms'] = 'drupal';
            $info['version'] = $matches[1];
        }
    } catch (Exception $e) {
        _log("$domain: CHANGELOG.txt is not found.");
    }

    //////////////////////////////////////////////////////////////////////////////////////////////
    // Guesswork starts here. We could only vaguely detect Drupal version with following checks.

    // Check Drupal version fingerprints in themes.
    $theme_fingerprints = array(
        '7.x' => array(
            'uri' => 'themes/seven/style.css',
            'match' => '|Generic elements|',
        ),
        '6.x' => array(
            'uri' => 'themes/garland/fix-ie-rtl.css',
            'match' => '|Reduce amount of damage done by extremely wide content|',
        ),
        '5.x' => array(
            'uri' => 'themes/garland/fix-ie.css',
            'match' => '|Reduce amount of damage done by extremely wide content|',
        ),
        '4.7.x' => array(
            'uri' => 'themes/bluemarine/style.css',
            'match' => '|HTML elements|',
        ),
    );
    $info = _scan_fingerprints($info, $theme_fingerprints);

    // Check Drupal version fingerprints in INSTALL.mysql.txt.
    $INSTALL_mysql_fingerprints = array(
        'uri' => 'INSTALL.mysql.txt',
        '7.x' => array(
            'match' => '|CREATE THE MySQL DATABASE.*If the InnoDB storage engine is available|ms',
        ),
        '6.x' => array(
            'match' => '|CREATE THE MySQL DATABASE.*CREATE TEMPORARY TABLES ON databasename|ms',
        ),
        '5.x' => array(
            'match' => '|CREATE THE MySQL DATABASE.*To activate the new permissions, enter the following command|ms',
        ),
        '4.7.x' => array(
            'match' => '|CONTENTS OF THIS FILE.*This file describes how to create a MySQL database for Drupal.|ms',
        ),
    );
    $info = _scan_fingerprints($info, $INSTALL_mysql_fingerprints);

    if ($info['active'] && $info['cms'] == 'drupal') {
        _log("$domain: Drupal " . $info['version'], 'green');
    } else {
        _log("$domain: Not a Drupal website.", 'yellow');
    }
    unset($client);

    return $info;
}

/**
 * Scans site for given matches.
 */
function _scan_fingerprints($info, $fingers)
{
    // Don't do anything if version is already determined.
    if (isset($info['version'])) {
        return $info;
    }

    if (isset($fingers['uri'])) {
        $default_uri = $fingers['uri'];
        unset($fingers['uri']);
    }
    $client = new Guzzle\Http\Client("http://" . $info['domain'] . "/", curl_options());
    foreach ($fingers as $version => $fingerprint) {
        try {
            $uri = isset($fingerprint['uri']) ? $fingerprint['uri'] : $default_uri;
            $content = (string) $client->get($uri)->send();
            if (preg_match($fingerprint['match'], $content)) {
                $info['cms'] = 'drupal';
                $info['version'] = $version;
                break;
            }
        } catch (Exception $e) {
        }
    }
    unset($client);

    return $info;
}

function perform_benchmark_task()
{
    $domains = file(__DIR__ . '/../servers/benchmark-list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $benchmark_index = variable_get('benchmark_index', 0, 'db_local');
    perform_basic_scan($domains[$benchmark_index]);

    $benchmark_index++;
    if ($benchmark_index >= count($domains)) {
        $benchmark_index = 0;
    }
    if (!is_dir('/dpro/log')) {
        exec('mkdir /dpro/log');
    }
    variable_set('benchmark_index', $benchmark_index, 'db_local');

    return true;
}