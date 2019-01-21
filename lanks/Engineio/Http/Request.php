<?php

namespace Lanks\Engineio\Http;

use Evenement\EventEmitter;
use Psr\Http\Message\ServerRequestInterface;
use React\Socket\ConnectionInterface;


class Request extends EventEmitter
{
    public function __construct(ServerRequestInterface $req, ConnectionInterface $con) {
        $this->req = $req;
        $this->con = $con;
    }
}