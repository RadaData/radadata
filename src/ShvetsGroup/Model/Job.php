<?php

namespace ShvetsGroup\Model;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    public $timestamps = false;
    public $fillable = ['service', 'method', 'parameters', 'group', 'claimed'];
    protected $casts = [
        'parameters' => 'array',
        'claimed' => 'bool',
    ];

    public function execute($container = null)
    {
        _log(' ==== Job ==== ' . $this->service . '->' . $this->method . '(' . json_encode($this->parameters) . ')', 'title');

        if ($this->service) {
            $func = [$container->get($this->service), $this->method];
        } else {
            $func = $this->method;
        }
        call_user_func_array($func, $this->parameters);

        $this->delete();
    }
}

