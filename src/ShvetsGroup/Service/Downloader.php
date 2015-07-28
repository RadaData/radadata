<?php

namespace ShvetsGroup\Service;

use JonnyW\PhantomJs\Client as PJClient;
use ShvetsGroup\Model\Laws\Law;
use ShvetsGroup\Service\Proxy\ProxyManager;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DomCrawler\Crawler;

class Downloader
{

    const SUCCESS = 10;
    const FAILURE = 3;

    private $stop_words = [
        '404' => [
            '502 Bad Gateway',
            'Ліміт перегляду списків на сьогодні',
            'Дуже багато відкритих сторінок за хвилину',
            'Доступ до списку заборонен',
            'Документи потрібно відкривати по одному',
            'Сторiнку не знайдено',
            'Доступ тимчасово обмежено',
            'Документ не знайдено!',
            'Цього списку вже немає в кеші.',
        ],
        '403' => [
            'Error 403',
            'Доступ заборонено',
            'Ваш IP автоматично заблоковано'
        ],
        'error' => [
            '??.??.????'
        ]
    ];

    /**
     * Where to download the web pages.
     *
     * @var string
     */
    private $downloadsDir;

    /**
     * @var Identity
     */
    private $identity;

    /**
     * @var ProxyManager
     */
    private $proxyManager;

    /**
     * @param string       $downloadsDir
     * @param Identity     $identity
     * @param ProxyManager $proxyManager
     */
    public function __construct($downloadsDir, $identity, $proxyManager)
    {
        $this->downloadsDir = BASE_PATH . $downloadsDir;
        $this->identity = $identity;
        $this->proxyManager = $proxyManager;
    }

    /**
     * @param string $url List url to download
     * @param array  $options
     *
     * @return array[
     *   'html' => string,
     *   'page_count' => integer,
     *   'laws' => array[
     *     ['id' => string, 'date' => string],
     *     ...
     *   ]
     * ]
     */
    public function downloadList($url, $options = [])
    {
        $data = download($url, $options);
        $data['laws'] = [];
        $page = crawler($data['html']);
        $last_pager_link = $page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[last()]');
        $data['page_count'] = $last_pager_link->count() ? preg_replace('/(.*?)([0-9]+)$/', '$2', $last_pager_link->attr('href')) : 1;

        $page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li')->each(
            function (\Symfony\Component\DomCrawler\Crawler $node) use (&$data) {
                $url = $node->filterXPath('//a')->attr('href');
                $id = preg_replace('|/laws/show/|', '', shortURL($url));

                $raw_date = $node->filterXPath('//font[@color="#004499"]')->text();
                $date = $this->parseDate($raw_date, "Date has not been found in #{$id} at text: " . $node->text());

                $data['laws'][$id] = [
                    'id'   => $id,
                    'date' => $date
                ];
            }
        );

        return $data;
    }

    /**
     * @param string $law_id Id of the law.
     * @param array  $options
     *
     * @return array[
     *   'html' => string,
     *   'meta' => array,
     *   'has_text' => bool,
     *   'revisions' => array[
     *     ['law_id' => string, 'date' => string, 'comment' => string, 'no_text' => null|bool, 'needs_update' =>
     *     null|bool],
     *     ...
     *   ],
     *   'active_revision' => string,
     *   'changes_laws' => null|array[
     *     ['id' => string, 'date' => string],
     *     ...
     *   ]
     * ]
     *
     * @throws \Exception
     */
    public function downloadCard($law_id, $options = [])
    {
        $url = '/laws/card/' . $law_id;
        $data = download($url, $options);
        $crawler = crawler($data['html'])->filter('.txt');
        $data['html'] = $crawler->html();
        $data['meta'] = [];
        $last_field = null;
        $crawler->filterXPath('//h2[text()="Картка документа"]/following-sibling::dl[1]')->children()->each(function (Crawler $node) use (&$data, &$last_field) {
            if ($node->getNode(0)->tagName == 'dt') {
                $last_field = rtrim($node->text(), ':');
                $data['meta'][$last_field] = [];
            } elseif ($node->getNode(0)->tagName == 'dd') {
                $data['meta'][$last_field][] = $node->text();
            }
        });

        $data['has_text'] = (strpos($data['html'], 'Текст відсутній') === false && strpos($data['html'], 'Текст документа') !== false);

        $data['revisions'] = [];
        $last_revision = null;
        $data['active_revision'] = null;
        $crawler->filterXPath('//h2[contains(text(), "Історія документа")]/following-sibling::dl[1]')->children()->each(function (Crawler $node) use (&$data, &$last_revision, $law_id) {
            if ($node->getNode(0)->tagName == 'dt') {
                $raw_date = $node->filterXPath('//span[@style="color: #004499" or @style="color: #006600"]')->text();
                $date = $this->parseDate($raw_date, "Revision date '{$raw_date}' is not valid in card of '{$law_id}'");
                $last_revision = count($data['revisions']);

                $data['revisions'][] = [
                    'law_id'  => $law_id,
                    'date'    => $date,
                    'comment' => []
                ];
                if (!$node->filter('a')->count()) {
                    $data['revisions'][$last_revision]['no_text'] = true;
                }

                if (str_contains($node->text(), 'поточна редакція')) {
                    $data['active_revision'] = $data['revisions'][$last_revision]['date'];
                }
            } elseif ($node->getNode(0)->tagName == 'dd') {
                if (strpos($node->html(), '<a name="Current"></a>') !== FALSE) {
                    $data['active_revision'] = $data['revisions'][$last_revision]['date'];
                    $data['revisions'][$last_revision]['needs_update'] = true;
                }
                $data['revisions'][$last_revision]['comment'][] = str_replace('<a name="Current"></a>', '', $node->html());
            }
        });
        foreach ($data['revisions'] as $date => &$revision) {
            $revision['comment'] = implode("\n", $revision['comment']);
        }

        if (!$data['active_revision'] && $data['has_text']) {
            throw new \Exception("Card has text, but no revisions in '{$law_id}'");
        }

        if (isset($options['check_related']) && $options['check_related']) {
            $changes_link =
                $crawler->filterXPath('//h2[contains(text(), "Пов\'язані документи")]/following-sibling::dl[1]/*/a/font[text()="Змінює документ..."]/..');
            if ($changes_link->count()) {
                $list = downloadList($changes_link->attr('href'));
                $data['changes_laws'] = $list['laws'];
                for ($i = 2; $i <= $list['page_count']; $i++) {
                    $list = downloadList($changes_link->attr('href') . '/page' . $i);
                    $data['changes_laws'] += $list['laws'];
                }
            }
        }

        return $data;
    }

    /**
     * @param       $law_id
     * @param       $date
     * @param array $options
     *
     * @return string
     */
    public function downloadRevision($law_id, $date, $options = [])
    {
        $law = Law::find($law_id);
        $law_url = '/laws/show/' . $law_id;
        $edition_part = '/ed' . date_format(date_create_from_format('Y-m-d', $date), 'Ymd');

        if ($law->active_revision == $date) {
            $url = $law_url;
            $options['save_as'] = $law_url . $edition_part . '/page';
        }
        else {
            $url = $law_url . $edition_part;
            $options['save_as'] = $law_url . $edition_part . '/page';
        }

        $data = download($url, $options);
        $crawler = crawler($data['html'])->filter('.txt');
        $data['text'] = $crawler->html();
        $raw_date = crawler($data['html'])->filterXPath('//div[@id="pan_title"]/*/font[@color="#004499"]/b')->text();
        $revision_date = $this->parseDate($raw_date, "Revision date has not been found in text of $url");

        if ($revision_date != $date) {
            throw new Exceptions\RevisionDateNotFound("Revision date does not match the planned date (planned: {$date}, but found {$data['revision_date']}).");
        }

        $pager = $crawler->filterXPath('(//span[@class="nums"])[1]/br/preceding-sibling::a[1]');
        $page_count = $pager->count() ? $pager->text() : 1;

        for ($i = 2; $i <= $page_count; $i++) {
            $page_url = $url . '/page' . $i;
            $options['save_as'] = $law_url . $edition_part . '/page' . $i;
            $data = download($page_url, $options);
            $data['text'] .= crawler($data['html'])->filter('.txt')->html();

            $raw_date = crawler($data['html'])->filterXPath('//div[@id="pan_title"]/*/font[@color="#004499"]/b')->text();
            $revision_date = $this->parseDate($raw_date, "Revision date has not been found in text of $url");
            if ($revision_date != $date) {
                throw new Exceptions\RevisionDateNotFound("Revision date does not match the planned date (planned: {$date}, but found {$revision_date}).");
            }
        }

        return $data;
    }

    /**
     * Download a page.
     *
     * @param string $url URL of the page.
     * @param array  $options
     *                    - bool $re_download Whether or not to re-download the page if it's already in cache.
     *                    - bool $save Whether or not to cache a local copy of the page.
     *                    - string $save_as Alternative file name for the page.
     *                    download successful.
     *
     * @return string
     * @throws \Exception
     */
    public function download($url, $options = [])
    {
        $default_options = [
            're_download'   => false,
            'save'          => true,
            'save_as'       => null,
        ];
        $options = array_merge($default_options, $options);

        $save_as = $options['save_as'] ? $options['save_as'] : null;

        $output = $this->shortURL($url) . ': ';
        $style = 'default';

        if ($this->isDownloaded($save_as ?: $url) && !$options['re_download']) {
            $file_path = $this->URL2path($save_as ?: $url);
            $html = file_get_contents($file_path);

            if ($this->detectFakeContent($html)) {
                unlink($file_path);
            }
            else {

                $output .= ('* ');
                _log($output);

                return [
                    'html'      => $html,
                    'timestamp' => filemtime($file_path)
                ];
            }
        }

        $output = ($this->proxyManager->getProxyAddress() . '/' . $this->proxyManager->getProxyIp() . ' → ' . $output);

        $attempt = 0;
        while ($attempt < Downloader::FAILURE) {
            try {
                $result = $this->doDownload($url);

                switch ($result['status']) {
                    case 200:
                    case 301:
                    case 302:
                        if ($this->detectFakeContent($result['html'], '403')) {
                            $output .= ('-S403 ');
                            _log($output, 'red');
                            $this->proxyManager->banProxy();
                            die();
                        }

                        while ($matches = $this->detectJSProtection($result['html'])) {
                            $output .= ('-JS-');
                            $attempt++;
                            $result = $this->doDownload($matches[1] . '?test=' . $matches[2],
                                $attempt * 2);
                            $style = 'yellow';
                            if ($attempt > 5) {
                                throw new \Exception('Can not break JS protection.');
                            }
                        }

                        if ($this->detectFakeContent($result['html'], 'error')) {
                            throw new \Exception('Content has errors. Parsing rescheduled.');
                        }

                        if ($this->detectFakeContent($result['html'], '404')) {
                            $output .= ('-F-');
                            $style = 'yellow';

                            if ($this->identity->switchIdentity()) {
                                $attempt++;
                                continue 2;
                            } else {
                                throw new \Exception('Resource is not available (f).');
                            }
                        }

                        if ($options['save']) {
                            $this->saveFile($save_as ?: $url, $result['html']);
                        }

                        $output .= ('@' . $result['status'] . ' ');
                        _log($output, $style);

                        $this->proxyManager->releaseProxy();

                        return [
                            'html' => $result['html'],
                            'timestamp' => time()
                        ];
                    case 403:
                        $output .= ('-S' . $result['status'] . ' ');
                        _log($output, 'red');
                        if (strpos($result['html'], 'Ви потрапили до забороненого ресурсу') !== false) {
                            $this->proxyManager->banProxy();
                            die();
                        }
                        $attempt++;
                        continue 2;
                        break;
                    case 404:
                        throw new \Exception('Page is 404.');
                        break;
                    default:
                        $attempt += 1;
                        $output .= ('-S' . $result['status'] . '-');
                        $style = 'yellow';
                        continue 2;
                        break;
                }
            } catch (\Exception $e) {
                $output .= ('-E(' . $e->getMessage() . ')-');
                _log($output, 'red');
                $this->proxyManager->releaseProxy();
                throw new \Exception($e->getMessage());
            }
        }

        _log($output, 'red');
        $this->proxyManager->releaseProxy();
        throw new \Exception('Resource is not available (a).');
    }

    /**
     * Perform the actual download.
     *
     * @param string $url
     * @param int    $delay
     *
     * @return array
     */
    private function doDownload($url, $delay = 5)
    {
        $client = PJClient::getInstance();
        if ($this->proxyManager->useProxy()) {
            $client->addOption('--proxy=' . $this->proxyManager->getProxyAddress());
        }
        $client->addOption('--load-images=false');
        $request = $client->getMessageFactory()->createRequest($this->fullURL($url));
        $request->setDelay($delay);
        $request->setTimeout(60000);
        $request->addHeader('User-Agent', $this->identity->getUserAgent());
        $response = $client->getMessageFactory()->createResponse();
        $client->send($request, $response);
        $status = $response->getStatus();
        $html = $response->getContent();

        sleep(10);

        return [
            'status' => $status,
            'html'   => preg_replace('|charset="?windows-1251"?|', 'charset="utf-8"', $html)
        ];
    }

    /**
     * Save the HTML content to specified path under downloads dir.
     *
     * @param string $path
     * @param string $html
     */
    function saveFile($path, $html)
    {
        $path = $this->URL2path($path);
        $dir = preg_replace('|/[^/]*$|', '/', $path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // replace encoding to utf8 to achieve nice browsing experience.
        file_put_contents($path, $html);
    }

    /**
     * Return a directory, where all the downloads will be saved.
     *
     * @return string
     */
    public function getDownloadsDir()
    {
        return $this->downloadsDir;
    }

    /**
     * Whether or not the url can be found in download dir.
     *
     * @param string $url
     *
     * @return bool
     */
    function isDownloaded($url)
    {
        if (!$url) {
            return false;
        }

        $path = $this->URL2path($url);

        return file_exists($path);
    }

    /**
     * Return full URL (with domain name) of the page by given path or short url.
     *
     * @param string $url Path or short URL.
     *
     * @return mixed|string
     */
    function fullURL($url, $urlencode = true)
    {
        $url = $this->shortURL($url);

        $protocol = '';
        if (preg_match('@^(https?|file|ftp)://@', $url, $matches)) {
            $protocol = $matches[0];
            $url = preg_replace('@^(https?|file|ftp)://@', '', $url);
        }

        if ($urlencode) {
            list($url, $query) = explode('?', $url . '?');
            $url_parts = explode('/', $url);
            $new_url = [];
            foreach ($url_parts as $part) {
                $new_url[] = urlencode($part);
            }
            $url = $protocol . implode('/', $new_url);
            if ($query) {
                $query = urlencode($query);
                $query = preg_replace('|%3d|i', '=', $query);
                $query = preg_replace('|%26|i', '&', $query);
                $url .= '?' . $query;
            }
        }

        if (!preg_match('@^(https?|file|ftp)://@', $url)) {
            $url = $this->identity->getMirror() . $url;
        }

        return $url;
    }

    /**
     * Return short URL (without domain name) of the page by given path or short url.
     *
     * @param string $url Path or long URL.
     *
     * @return string
     */
    function shortURL($url)
    {
        $url = preg_replace('|' . $this->getWebsiteRegexp() . '|', '', $url);

        if (strpos($url, '%') !== false) {
            $url = urldecode($url);
        }

        return $url;
    }

    /**
     * Get the regular expression to cut the domain from the RADA addresses.
     *
     * @return string
     */
    public function getWebsiteRegexp()
    {
        return '^(https?://)*zakon([0-9]*)\.rada\.gov\.ua';
    }

    /**
     * Return file path under downloads dir for a given short or long URL.
     *
     * @param $url
     *
     * @return mixed|string
     */
    function URL2path($url)
    {
        $url = $this->fullURL($url, false);

        $path = preg_replace('@(https?|file|ftp)://@', '', $url);
        $path = preg_replace('@zakon[0-9]+\.rada@', 'zakon.rada', $path);

        if (substr($path, -1) == '/') {
            $path .= 'index.html';
        } else {
            $path .= '.html';
        }
        $path = $this->getDownloadsDir() . '/' . $path;

        return $path;
    }

    /**
     * Sometimes even when download seem to be successful, the actual page contains crap. This function tries to detect
     * such cases to signal page for re-download.
     *
     * @param string $html HTML content of the page.
     *
     * @return bool
     */
    public function detectFakeContent($html, $type = 'all')
    {
        if ($html == '') {
            return true;
        }
        if ($type == 'all') {
            $words = array_merge($this->stop_words['404'], $this->stop_words['403']);
        }
        else {
            $words = $this->stop_words[$type];
        }
        return $this->contains($html, $words);
    }

    private function contains($str, array $arr, $all = false)
    {
        foreach($arr as $a) {
            if ((!$all && stripos($str,$a) !== false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * See if download triggered a JS robot protection.
     *
     * @param string $html HTML content of the page.
     *
     * @return bool
     */
    public function detectJSProtection($html)
    {
        if (preg_match('|<a href="?(.*)\?test=(.*)"? target="?_top"?><b>посилання</b></a>|', $html, $matches)) {
            return $matches;
        }

        return false;
    }

    private function parseDate($radaDate, $error_text = null)
    {
        $raw_date = preg_replace('|([0-9]{2}\.[0-9]{2}\.[0-9]{4}).*|', '$1', $radaDate);
        if (!preg_match('|[0-9]{2}\.[0-9]{2}\.[0-9]{4}|', $raw_date)) {
            $error_text = $error_text ?: "Date {$radaDate} is not valid date.";
            throw new \Exception($error_text);
        }
        $date = date_format(date_create_from_format('d.m.Y', $raw_date), 'Y-m-d');

        return $date;
    }

}
