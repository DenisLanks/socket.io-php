<?php

namespace Lanks\Engineio\Http;

use Evenement\EventEmitter;


class Response extends EventEmitter
{
    public function __construct() {
        $this->var = $var;
    }
}