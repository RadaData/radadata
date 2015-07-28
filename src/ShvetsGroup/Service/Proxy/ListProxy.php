<?php

namespace ShvetsGroup\Service\Proxy;

class ListProxy implements IProxyProvider
{
    private $proxy_list = [];
    private $banned_list_path;

    public function __construct($proxy_list)
    {
        $this->proxy_list = $proxy_list;
        $this->banned_list_path = BASE_PATH . 'app/banned_proxies.txt';
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
        if (file_exists($this->banned_list_path)) {
            $banned = file($this->banned_list_path, FILE_IGNORE_NEW_LINES);
        }
        else {
            $banned = [];
        }
        $banned[] = $ip;
        file_put_contents($this->banned_list_path, implode("\n",$banned));
    }

    public function reset()
    {
        if (file_exists($this->banned_list_path)) {
            unlink($this->banned_list_path);
        }
    }
}