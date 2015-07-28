<?php

namespace ShvetsGroup\Service\Exceptions;

class JobChangePriorityException extends JobException
{
    public $newPriority = -1;

    public function __construct($priority = -1)
    {
        parent::__construct();
        $this->newPriority = $priority;
    }

}