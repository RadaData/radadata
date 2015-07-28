<?php

namespace ShvetsGroup\Service\Exceptions;

class DocumentHasErrors extends ContentError
{
    public function __construct($error)
    {
        parent::__construct("Document has following error: '{$error}'");
    }
}