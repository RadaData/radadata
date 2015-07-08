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
     * @param string $url URL of the page.
     * @param array  $options
     *                    - bool $re_download Whether or not to re-download the page if it's already in cache.
     *                    - bool $save Whether or not to cache a local copy of the page.
     *                    - string $save_as Alternative file name for the page.
     *                    - array $required_text If passed, this text should be found on the page in order to count the
     *                    download successful.
     *
     * @return string
     * @throws \Exception
     */
    public function download($url, $options = [])
    {
        $url = $this->fullURL($url);

        $default_options = [
            're_download'   => false,
            'save'          => true,
            'save_as'       => null,
            'required_text' => [],
        ];
        $options = array_merge($options, $default_options);

        $save_as = $options['save_as'] ? $this->fullURL($options['save_as']) : null;

        $output = '';
        $this->proxy->getProxy();
        $output .= ($this->proxy->proxy->address . '/' . $this->proxy->proxy->ip . ' → ' . $this->shortURL($url) . ': ');
        $style = 'default';

        if ($this->isDownloaded($save_as ?: $url) && !$options['re_download']) {
            $html = file_get_contents($this->URL2path($save_as ?: $url));
            $output .= ('* ');
            _log($output);

            $this->proxy->releaseProxy();
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
                        if (strpos($result['html'], 'Error 403') !== false || strpos($result['html'], 'Доступ заборонено') !== false) {
                            $output .= ('-S403 ');
                            _log($output, 'red');
                            $this->proxy->banProxy();
                            die();
                        }

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

                        if ($this->detectFakeContent($result['html'], $options['required_text'])) {
                            $output .= ('-F-');
                            $style = 'yellow';

                            if ($this->identity->switchIdentity()) {
                                $url = $this->fullURL($url);
                                continue 2;
                            } else {
                                _log($output, 'red');
                                throw new \Exception('Resource is not available (f).');
                            }
                        }

                        if ($options['save']) {
                            $this->saveFile($save_as ?: $url, $result['html']);
                        }

                        $output .= ('@' . $result['status'] . ' ');
                        _log($output, $style);

                        $this->proxy->releaseProxy();

                        return $result['html'];
                    case 403:
                        $output .= ('-S' . $result['status'] . ' ');
                        _log($output, 'red');
                        if (strpos($result['html'], 'Ви потрапили до забороненого ресурсу') !== false) {
                            $this->proxy->banProxy();
                            die();
                        }
                        $attempt++;
                        continue 2;
                    break;
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
                $output .= ('-E(' . $e->getMessage() . ')-');
                continue;
            }
        }

        _log($output, 'red');
        $this->proxy->releaseProxy();
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

