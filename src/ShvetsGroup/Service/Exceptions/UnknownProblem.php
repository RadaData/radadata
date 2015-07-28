<?php

namespace ShvetsGroup\Service\Exceptions;

class UnknownProblem extends DownloadError
{
    public function __construct($message = '', $id = '', $data = [])
    {
        if ($id && $data) {
            $id = str_replace(['..', '/'], ['', '_'], $id);
            $id = date('Y-m-d_H-i-s') . $id;
            _logDump($id, $data);
        }
        parent::__construct($message);
    }
}