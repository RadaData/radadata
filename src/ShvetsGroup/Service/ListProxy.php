<?php

namespace ShvetsGroup\Service;

class ListProxy implements IProxyProvider
{
    private $proxy_list = [];
    private $banned_list_path = BASE_PATH . 'app/banned_proxies.txt';

    public function __construct($proxy_list)
    {
        $this->proxy_list = $proxy_list;
    }

    public function get($count = null, $reset = false)
    {
        $proxies = [];
        if (file_exists($this->banned_list_path)) {
            $banned = file($this->banned_list_path, FILE_IGNORE_NEW_LINES);
        }
        else {
            $banned = [];
        }

        foreach ($this->proxy_list as $proxy) {
            $ip = preg_replace('|:.*|', '', $proxy);
            if (array_search($ip, $banned) === false) {
                $proxies[$proxy] = $ip;
            }
        }
        return $proxies;
    }

    public function ban($ip)
    {
        unset($this->proxy_list[$ip]);
        $banned = file(BASE_PATH . 'app/banned_proxies.txt', FILE_IGNORE_NEW_LINES);
        $banned[] = $ip;
        file_put_contents(BASE_PATH . 'app/banned_proxies.txt', implode("\n",$banned));
    }
}