<?php

namespace ShvetsGroup\Service\Exceptions;

class ProxyBanned extends DownloadError
{
    public function __construct($proxy)
    {
        parent::__construct('Proxy banned: ' . $proxy);
    }
}