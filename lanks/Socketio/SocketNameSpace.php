<?php

namespace Lanks\Socketio;

use Lanks\Engineio\Parser\Parser;


class SocketNameSpace
{
    /**
     * Namespace constructor.
     *
     * @param {Server} server instance
     * @param {Socket} name
     * @api private
     */

    public function __construct($server, $name){
        $this->name = $name;
        $this->server = $server;
        $this->sockets = [];
        $this->connected = [];
        $this->fns = [];
        $this->ids = 0;
        $this->rooms = [];
        $this->flags = [];
        $this->initAdapter();
    }
    
  /**
   * Inherits from `EventEmitter`.
   */
  
  //public function __proto__ = Emitter.prototype;
  
  /**
   * Apply flags from `Socket`.
   */
  
  // exports.flags.forEach(function(flag){
  //   Object.defineProperty(Namespace.prototype, flag, {
  //     get: function() {
  //       $this->flags[flag] = true;
  //       return $this;
  //     }
  //   });
  // });
  
  /**
   * Initializes the `Adapter` for this nsp.
   * Run upon changing adapter by `Server#adapter`
   * in addition to the constructor.
   *
   * @api private
   */
  
  public function initAdapter (){
    $this->adapter = new Adapter();
  }
  
  /**
   * Sets up namespace middleware.
   *
   * @return {Namespace} self
   * @api public
   */
  
  public function use ($fn){
    if ($this->server->eio && $this->name === '/') {
      debug('removing initial packet');
      unset($this->server->eio->initialPacket);
    }
    $this->fns.push(fn);
    return $this;
  }
  
  /**
   * Executes the middleware for an incoming client.
   *
   * @param {Socket} socket that will get added
   * @param {Function} fn last fn call in the middleware
   * @api private
   */
  
  public function run ($socket, $fn){
    $fns = $this->fns.slice(0);
    if (!count($fns)) return fn(null);
  
    $run =function ($i){
      $fns[$i]($socket, function($err){
        // upon error, short-circuit
        if ($err) return $fn($err);
  
        // if no middleware left, summon callback
        if (!$fns[$i + 1]) return $fn(null);
  
        // go on to next
        $run($i + 1);
      });
    };
  
    $run(0);
  }
  
  /**
   * Targets a room when emitting.
   *
   * @param {String} name
   * @return {Namespace} self
   * @api public
   */
  
  public function to($name){
    return $this->in($name);
  }

  public function in ($name){
    if (!~$this->rooms.indexOf($name)) $this->rooms.push($name);
    return $this;
  }
  
  /**
   * Adds a new client.
   *
   * @return {Socket}
   * @api private
   */
  
  public function add ($client, $query, $fn){
    debug('adding socket to nsp %s', $this->name);
    $socket = new Socket($this, $client, $query);
    $self = $this;
    $this->run($socket, function($err){
      process.nextTick(function(){
        if ('open' == $client->conn->readyState) {
          if ($err) return $socket->error($err->data || $err->message);
  
          // track socket
          $self->sockets[$socket->id] = $socket;
  
          // it's paramount that the internal `onconnect` logic
          // fires before user-set events to prevent state order
          // violations (such as a disconnection before the connection
          // logic is complete)
          $socket->onconnect();
          if (fn) fn();
  
          // fire user-set events
          $self->emit('connect', $socket);
          $self->emit('connection', $socket);
        } else {
          debug('next called after client was closed - ignoring socket');
        }
      });
    });
    return $socket;
  }
  
  /**
   * Removes a client. Called by each `Socket`.
   *
   * @api private
   */
  
  public function remove ($socket){
    if ($this->sockets.hasOwnProperty($socket->id)) {
      unset($this->sockets[$socket->id]);
    } else {
      debug('ignoring remove for %s', $socket->id);
    }
  }
  
  /**
   * Emits to all clients.
   *
   * @return {Namespace} self
   * @api public
   */
  
  public function emit ($ev){
    if (~exports.events.indexOf(ev)) {
      emit.apply($this, $arguments);
      return $this;
    }
    // set up packet object
    $args = func_get_args();
    $packet = Parser::toStdClass ([
      'type'=> (!isset($this->flags['binary']) ? $this->flags['binary'] :( hasBin($args)) ? Parser::BINARY_EVENT : Parser::EVENT),
      'data'=> $args
    ]);
  
    if (is_callable(end($args))) {
      throw new \Exception('Callbacks are not supported when broadcasting');
    }
  
    $rooms = $this->rooms;
    $flags = $this->flags;
  
    // reset flags
    $this->rooms = [];
    $this->flags = [];
  
    $this->adapter->broadcast($packet, Parser::toStdClass( [
      'rooms'=> $rooms,
      'flags'=> $flags
    ]));
  
    return $this;
  }
  
  /**
   * Sends a `message` event to all clients.
   *
   * @return {Namespace} self
   * @api public
   */
  
  public function send (){
    $args = func_get_args();
    return $this->write($args);
  }
  public function write (){
    $args = \array_unshift(func_get_args(), 'message');
    $this->emit($args);
    return $this;
  }
  
  /**
   * Gets a list of clients.
   *
   * @return {Namespace} self
   * @api public
   */
  
  public function clients ($fn){
    if(!$this->adapter){
      throw new Error('No adapter for this namespace, are you trying to get the list of clients of a dynamic namespace?');
    }
    $this->adapter.clients($this->rooms, fn);
    // reset rooms for scenario:
    // .in('room').clients() (GH-1978)
    $this->rooms = [];
    return $this;
  }
  
  /**
   * Sets the compress flag.
   *
   * @param {Boolean} compress if `true`, compresses the sending data
   * @return {Socket} self
   * @api public
   */
  
  public function compress ($compress){
    $this->flags[$compress] = $compress;
     return $this;
  }
  
  /**
   * Sets the binary flag
   *
   * @param {Boolean} Encode as if it has binary data if `true`, Encode as if it doesnt have binary data if `false`
   * @return {Socket} self
   * @api public
   */
  
   public function binary  ($binary) {
     $this->flags[$binary] = $binary;
     return $this;
   }
}