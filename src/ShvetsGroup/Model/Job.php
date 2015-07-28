<?php

namespace ShvetsGroup\Model;

use Illuminate\Database\Eloquent\Model;
use ShvetsGroup\Service\Exceptions;

class Job extends Model
{
    public $timestamps = false;
    public $fillable = ['service', 'method', 'parameters', 'group', 'claimed', 'finished', 'priority'];
    protected $casts = [
        'parameters' => 'array',
        'finished' => 'bool',
    ];

    public function execute($container = null)
    {
        _log('==== Job ==== #' . $this->id . ' ' . $this->service . '->' . $this->method . '(' . json_encode($this->parameters, JSON_UNESCAPED_UNICODE) . ')', 'title');

        if ($this->service) {
            $func = [$container->get($this->service), $this->method];
        } else {
            $func = $this->method;
        }

        try {
            call_user_func_array($func, $this->parameters);
            $this->update(['finished' => time(), 'claimed' => 0]);
        }
        catch(\Exception $e) {
            if ($e instanceof Exceptions\JobChangePriorityException) {
                _log('JOB#' . $this->id . ' FAILURE(' . $e->getMessage() . '). Priority changed to ' . $e->newPriority, 'red');
                $this->update(['priority' => $e->newPriority]);
            }
            else {
                _log('JOB#' . $this->id . ' Type: ' . str_replace('ShvetsGroup\Service\Exceptions\\', '', get_class($e)) . ' Message: ' . $e->getMessage(), 'red');
            }
        }
    }
}

