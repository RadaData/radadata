<?php

namespace ShvetsGroup\Service\Proxy;

interface IProxyProvider
{
    public function get($count = null, $reset = false);
    public function ban($ip);
    public function reset();
}