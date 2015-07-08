<?php

namespace ShvetsGroup\Service;

interface IProxyProvider
{
    public function get($count = null, $reset = false);
    public function ban($ip);
    public function reset();
}