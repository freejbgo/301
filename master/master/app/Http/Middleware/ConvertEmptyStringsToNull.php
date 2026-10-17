<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull as Middleware;
use Illuminate\Support\Str;

class ConvertEmptyStringsToNull extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [
        'domains',
        'domains.*',
        'version',
        'error',
    ];

    protected function transform($key, $value)
    {
        foreach ($this->except as $except) {
            if (Str::is($except, $key)) {
                return $value;
            }
        }

        return parent::transform($key, $value);
    }
}
