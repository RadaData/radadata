<?php

namespace ShvetsGroup\Service;

use JonnyW\PhantomJs\Client as PJClient;

class Downloader
{
    const SUCCESS = 10;
    const FAILURE = 3;

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
     * @var Proxy
     */
    private $proxy;

    /**
     * @param string   $downloadsDir
     * @param Identity $identity
     * @param Proxy    $proxy
     */
    public function __construct($downloadsDir, $identity, $proxy)
    {
        $this->downloadsDir = BASE_PATH . $downloadsDir;
        $this->identity = $identity;
        $this->proxy = $proxy;
    }

    /**
     * Download a page.
     *
     * @param string $url           URL of the page.
     * @param bool   $re_download   Whether or not to re-download the page if it's already in cache.
     * @param null   $save_as       Alternative file name for the page.
     * @param array  $required_text If passed, this text should be found on the page in order to count the download
     *                              successful.
     * @param bool   $cant_change_mirror
     *
     * @return string
     * @throws \Exception
     */
    public function download($url, $re_download = false, $save_as = null, $required_text = [], $cant_change_mirror = false)
    {
        $output = '';
        $url = $this->fullURL($url);
        $save_as = $save_as ? $this->fullURL($save_as) : null;
        $output .= ($this->proxy->getProxy() . ' → ' . $this->shortURL($url) . ': ');
        $style = 'default';

        if ($this->isDownloaded($save_as ?: $url) && !$re_download) {
            $html = file_get_contents($this->URL2path($save_as ?: $url));
            $output .= ('* ');
            _log($output);

            return $html;
        }

        $attempt = 0;
        while ($attempt < Downloader::FAILURE) {
            try {
                $result = $this->doDownload($url);

                switch ($result['status']) {
                    case 200:
                    case 301:
                    case 302:
                        while ($matches = $this->detectJSProtection($result['html'])) {
                            $output .= ('-JS-');
                            $attempt++;
                            $result = $this->doDownload($this->fullURL(urldecode($matches[1])) . '?test=' . $matches[2],
                                $attempt * 2);
                            $style = 'yellow';
                            if ($attempt > 5) {
                                _log($output, 'red');
                                throw new \Exception('Can not break JS protection.');
                            }
                        }

                        if ($this->detectFakeContent($result['html'], $required_text)) {
                            $output .= ('-F-');
                            $style = 'yellow';

                            if ($this->identity->switchIdentity($cant_change_mirror)) {
                                $url = $this->fullURL($url);
                                continue 2;
                            } else {
                                _log($output, 'red');
                                throw new \Exception('Resource is not available (f).');
                            }
                        }

                        $this->saveFile($save_as ?: $url, $result['html']);

                        $output .= ('@' . $result['status'] . ' ');
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
                $attempt += 3;
                $output .= ('-E-');
                continue;
            }
        }
        _log($output, 'red');
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
    private function doDownload($url, $delay = 0)
    {
        $client = PJClient::getInstance();
        $client->setBinDir('app/bin');
        if ($this->proxy->useProxy()) {
            $client->addOption('--proxy=' . $this->proxy->getProxy());
        }
        $client->addOption('--load-images=false');
        $request = $client->getMessageFactory()->createRequest($url);
        $request->setDelay($delay);
        $request->addHeader('User-Agent', $this->identity->getUserAgent());
        $response = $client->getMessageFactory()->createResponse();
        $client->send($request, $response);
        $status = $response->getStatus();
        $html = $response->getContent();

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
    function fullURL($url)
    {
        $url = $this->shortURL($url);

        $protocol = '';
        if (preg_match('@^(https?|file|ftp)://@', $url, $matches)) {
            $protocol = $matches[0];
            $url = preg_replace('@^(https?|file|ftp)://@', '', $url);
        }
        $url_parts = explode('/', $url);
        $new_url = [];
        foreach ($url_parts as $part) {
            $new_url[] = urlencode($part);
        }
        $url = $protocol . implode('/', $new_url);

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
        $path = urldecode($url);
        $path = preg_replace('|http://|', '', $path);
        $path = preg_replace('|zakon[0-9]+\.rada|', 'zakon.rada', $path);

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
     * @param array  $requirements
     *                     - array['stop'] List of strings which should NOT be in text.
     *                     - array['required'] List of string which should be in text to pass check.
     *
     * @return bool
     */
    public function detectFakeContent($html, $requirements = [])
    {
        $default_stop = [
            '502 Bad Gateway',
            'Ліміт перегляду списків на сьогодні',
            'Дуже багато відкритих сторінок за хвилину',
            'Доступ до списку заборонен',
            'Документи потрібно відкривати по одному',
        ];
        if (isset($requirements['stop']) && is_array($requirements['stop'])) {
            $default_stop = array_merge($default_stop, $requirements['stop']);
        }
        foreach ($default_stop as $stop) {
            if (strpos($html, $stop) !== false) {
                return true;
            }
        }

        $default_required = [];
        if (isset($requirements['required']) && is_array($requirements['required'])) {
            $default_required = array_merge($default_required, $requirements['required']);
        }
        foreach ($default_required as $rt) {
            if (strpos($html, $rt) === false) {
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

}

