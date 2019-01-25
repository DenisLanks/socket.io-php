<?php

namespace Lanks\Engineio;

use Lanks\Engineio\Server;

class EngineIo
{
    /**
     * Creates an http.Server exclusively used for WS upgrades.
     *
     * @param {Number} port
     * @param {Function} callback
     * @param {Object} options
     * @return {Server} websocket.io server
     * @api public
     */


    public static function listen ($port, $options,Closure $fn =null) {
        if (\is_callable($options)) {
            $fn = $options;
            $options = [];
        }

        $server = $http->createServer(function ($req, $res) {
            $res->writeHead(501);
            $res->end('Not Implemented');
        });

        // create engine server
        $engine = self::attach($server, $options);
        $engine->httpServer = $server;

        $server->listen($port, $fn);

        return engine;
    }

    /**
     * Captures upgrade requests for a http.Server.
     *
     * @param {http.Server} server
     * @param {Object} options
     * @return {Server} engine server
     * @api public
     */


    public static function attach ($server, $options) {
        $engine = new Server($options);
        $engine->attach($server, $options);
        return $engine;
    }
}