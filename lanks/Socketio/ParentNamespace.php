<?php

namespace Lanks\Sockeio;

use Lanks\Engineio\Server;
use Lanks\Socketio\SocketNameSpace;

class ParentNamespace
{
    public function __constructor(Server $server) {
        parent::__constructor($server, '/_' . ($this->count++));
        $this->children = [];
      }
    
    public function initAdapter() {}
    
    public function   emit() {
        $args = func_get_args();
        foreach ($this->children as $key => $nsp) {
            $nsp->rooms = $this->rooms;
            $nsp->flags = $this->flags;
            $nsp->emit($args);
        }
        
        $this->rooms = [];
        $this->flags = [];
      }
    
    public function    createChild($name) {
        $namespace = new SocketNameSpace($this->server, $name);
        $namespace->fns = $this->fns.slice(0);
        foreach ($this->listeners('connect') as $key => $listener) {
            $namespace->on('connect', $listener);
        }

        foreach ($this->listeners('connection') as $key => $listener) {
            $namespace->on('connection', $listener);
        }

        $this->children.add($namespace);
        $this->server->nsps[$name] = $namespace;
        return $namespace;
      }
}