<?php

namespace ShvetsGroup\Service\Exceptions;

class JobChangePriorityException extends JobException
{
    public $newPriority = -1;

    public function __construct($message = '', $priority = -1)
    {
        parent::__construct($message);
        $this->newPriority = $priority;
    }

}