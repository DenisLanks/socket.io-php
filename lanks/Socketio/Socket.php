<?php

namespace Lanks\Socketio;

use Evenement\EventEmitter;
use Lanks\Engineio\Parser\Parser;


class Socket extends EventEmitter
{
    /**
 * Blacklisted events.
 *
 * @api public
 */

 public $events = [
    'error',
    'connect',
    'disconnect',
    'disconnecting',
    'newListener',
    'removeListener'
  ];
  
  /**
   * Flags.
   *
   * @api private
   */
  
  public $flags = [
    'json'=> true,
    'volatile'=> true,
    'broadcast'=> true,
    'local'=> true
  ];
  
  public   $request;
  public   $nsp;
  public   $server;
  public   $adapter;
  public   $id ;
  public   $client;
  public   $conn;
  public   $rooms;
  public   $acks;
  public   $connected;
  public   $disconnected;
  public   $handshake;
  public   $fns;
  public   $_rooms;
  /**
   * Interface to a `Client` for a given `Namespace`.
   *
   * @param {Namespace} nsp
   * @param {Client} client
   * @api public
   */
  
  function __construct($nsp,Client $client, $query){
    $this->nsp = $nsp;
    $this->server = $nsp->server;
    $this->adapter = $this->nsp->adapter;
    $this->id = $nsp->name !== '/' ? $nsp->name . '#' . $client->id : $client->id;
    $this->client = $client;
    $this->conn = $client->conn;
    $this->rooms = [];
    $this->acks = [];
    $this->connected = true;
    $this->disconnected = false;
    $this->handshake = $this->buildHandshake(query);
    $this->fns = [];
    $this->flags = [];
    $this->_rooms = [];
    $this->request = $this->conn->request;
  }
  

  
  /**
   * Builds the `handshake` BC object
   *
   * @api private
   */
  
  public function buildHandshake($query){
    $self = $this;
    $buildQuery = function (){
      $requestQuery = parse_url($self->request->url);
      //if socket-specific query exist, replace query strings in requestQuery
      return array_merge($query, $requestQuery);
    };
    return Parser::toStdClass ([
      'headers'=> $this->request->headers,
      'time'=>(new Date) + '',
      'address'=>$this->conn->remoteAddress,
      'xdomain'=>!!$this->request->headers.origin,
      'secure'=>!!$this->request->connection.encrypted,
      'issued'=>+(new Date),
      'url'=>$this->request->url,
      'query'=>$buildQuery()
    ]);
  }
  
  /**
   * Emits to this client.
   *
   * @return {Socket} self
   * @api public
   */
  
    public function emit($ev){
        $args = func_get_args();
        parent::emit($ev,$args );

        $packet = Helper::toStdClass([
            'type'=>(!isset( $this->flags['binary']) ? $this->flags['binary'] : hasBin(args)) ? Parser::BINARY_EVENT : Parser::EVENT,
            'data'=>$args
        ]) ;

        // access last argument to see if it's an ACK callback
        if (is_callable(end($args))) {
            if (!empty($this->_rooms) || isset($this->flags['broadcast'])) {
                throw new Exception('Callbacks are not supported when broadcasting');
            }

            //debug('emitting packet with ack id %d', $this->nsp->ids);
            $this->acks[$this->nsp->ids] = end($args);
            $packet->id = $this->nsp->ids++;
        }

        $rooms = $this->_rooms.slice(0);
        $flags = $this->flags;

        // reset flags
        $this->_rooms = [];
        $this->flags = [];
        $json = json_encode([
            'except' => [$this->id],
            'rooms' => $rooms,
            'flags' => $flags
            ]);
        if (count($rooms) > 0 || $flags['broadcast']) {
            $this->adapter->broadcast($packet,\json_decode( $json));
        } else {
            // dispatch packet
            $this->packet($packet, $flags);
        }
        return $this;
    }
  
  /**
   * Targets a room when broadcasting.
   *
   * @param {String} name
   * @return {Socket} self
   * @api public
   */
  
  public function to($name){
    return $this->in($name);
  }

  public function in($name){
    if (!~$this->_rooms.indexOf(name)) $this->_rooms.push(name);
    return $this;
  }
  
  /**
   * Sends a `message` event.
   *
   * @return {Socket} self
   * @api public
   */
  
  public function send (){
    $args = func_get_args();
    return $this->write($args);
  }
  public function write(){
    $args = \array_unshift(func_get_args(), 'message');
    $this->emit($args);
    return $this;
  }
  
  /**
   * Writes a packet.
   *
   * @param {Object} packet object
   * @param {Object} opts options
   * @api private
   */
  
  public function packet($packet, $opts){
    $packet->nsp = $this->nsp->name;
    if(is_array($opts))
        $opts = Parser::toStdClass($opts);
    $opts->compress = false !== $opts->compress;
    $this->client.packet($packet, $opts);
 }
  
  /**
   * Joins a room.
   *
   * @param {String|Array} room or array of rooms
   * @param {Function} fn optional, callback
   * @return {Socket} self
   * @api private
   */
  
  public function join($rooms, $fn){
    debug('joining room %s', $rooms);
    $self = $this;
    if (!is_array($rooms)) {
      $rooms = [$rooms];
    }
    $rooms = array_filter($rooms,function ($room)use($self) {
      return ! isset($self->rooms[$room]);
    });
    if (!count($rooms)>0) {
      $fn && $fn(null);
      return $this;
    }
    $this->adapter.addAll($this->id, $rooms, function($err)use($self, $fn) {
      if ($err) return $fn && $fn($err);
      debug('joined room %s', $rooms);
      foreach ($rooms as $key => $room) {
          $self->rooms[$room] = $room;
      }
      $fn && $fn(null);
    });
    return $this;
 }
  
  /**
   * Leaves a room.
   *
   * @param {String} room
   * @param {Function} fn optional, callback
   * @return {Socket} self
   * @api private
   */
  
  public function leave($room, $fn){
    debug('leave room %s', room);
    $self = $this;
    $this->adapter.del($this->id, room, function($err){
      if ($err) return $fn && $fn($err);
      debug('left room %s', room);
      unset($self->rooms[$room]);
      $fn && $fn(null);
    });
    return $this;
 }
  
  /**
   * Leave all rooms.
   *
   * @api private
   */
  
  public function leaveAll(){
    $this->adapter.delAll($this->id);
    $this->rooms = [];
 }
  
  /**
   * Called by `Namespace` upon successful
   * middleware execution (ie: authorization).
   * Socket is added to namespace array before
   * call to join, so adapters can access it.
   *
   * @api private
   */
  
  public function onconnect(){
    debug('socket connected - writing packet');
    $this->nsp->connected[$this->id] = $this;
    $this->join($this->id);
    $skip = $this->nsp->name === '/' && $this->nsp->fns.length === 0;
    if (skip) {
      debug('packet already sent in initial handshake');
    } else {
      $this->packet(Helper::toStdClass([ 'type'=>Parser::CONNECT ]));
    }
 }
  
  /**
   * Called with each packet. Called by `Client`.
   *
   * @param {Object} packet
   * @api private
   */
  
  public function onpacket($packet){
    debug('got packet %j', $packet);
    switch ($packet->type) {
      case Parser::EVENT:
        $this->onevent($packet);
        break;
  
      case Parser::BINARY_EVENT:
        $this->onevent($packet);
        break;
  
      case Parser::ACK:
        $this->onack($packet);
        break;
  
      case Parser::BINARY_ACK:
        $this->onack($packet);
        break;
  
      case Parser::DISCONNECT:
        $this->ondisconnect();
        break;
  
      case Parser::ERROR:
        $this->onerror(new Error($packet->data));
    }
 }
  
  /**
   * Called upon event packet.
   *
   * @param {Object} packet object
   * @api private
   */
  
  public function onevent($packet){
    $args = isset($packet->data)? $packet->data : [];
    //debug('emitting event %j', args);
  
    if (null != $packet->id) {
      echo('attaching ack callback to event'.PHP_EOL);
      $args[] = $this->ack($packet->id);
    }
  
    $this->dispatch($args);
 }
  
  /**
   * Produces an ack callback to emit with an event.
   *
   * @param {Number} id packet id
   * @api private
   */
  
  public function ack($id){
    $self = $this;
    $sent = false;
    return function() use($id, $sent, $self){
      // prevent double callbacks
      if ($sent) return;
      $args = func_get_args();
      $debug('sending ack %j', args);
  
      $self->packet(Helper::toStdClass([
        'id'=> $id,
        'type'=> hasBin($args) ? Parser::BINARY_ACK : Parser::ACK,
        'data'=> $args
      ]));
  
      $sent = true;
    };
 }
  
  /**
   * Called upon ack packet->
   *
   * @api private
   */
  
  public function onack($packet){
    $ack = $this->acks[$packet->id];
    if (is_callable($ack)) {
      debug('calling ack %s with %j', $packet->id, $packet->data);
      $ack($this,$packet->data );
      //ack.apply(this, $packet->data);
      unset($this->acks[$packet->id]);
    } else {
      debug('bad ack %s', $packet->id);
    }
 }
  
  /**
   * Called upon client disconnect packet.
   *
   * @api private
   */
  
  public function ondisconnect(){
    debug('got disconnect packet');
    $this->onclose('client namespace disconnect');
 }
  
  /**
   * Handles a client error.
   *
   * @api private
   */
  
  public function onerror($err){
    if (count($this->listeners('error'))) {
      $this->emit('error', $err);
    } else {
      //console.error('Missing error handler on `socket`.');
      //console.error($err.stack);
    }
 }
  
  /**
   * Called upon closing. Called by `Client`.
   *
   * @param {String} reason
   * @throw {Error} optional error object
   * @api private
   */
  
  public function onclose($reason){
    if (!$this->connected) return $this;
    debug('closing socket - reason %s', $reason);
    $this->emit('disconnecting', $reason);
    $this->leaveAll();
    $this->nsp->remove($this);
    $this->client.remove($this);
    $this->connected = false;
    $this->disconnected = true;
    unset($this->nsp->connected[$this->id]);
    $this->emit('disconnect', $reason);
 }
  
  /**
   * Produces an `error` packet.
   *
   * @param {Object} err error object
   * @api private
   */
  
  public function error($err){
    $this->packet(Helper::toStdClass([ 'type'=> Parser::ERROR, 'data'=> $err ]));
 }
  
  /**
   * Disconnects this client.
   *
   * @param {Boolean} close if `true`, closes the underlying connection
   * @return {Socket} self
   * @api public
   */
  
  public function disconnect($close){
    if (!$this->connected) return $this;
    if (close) {
      $this->client.disconnect();
    } else {
      $this->packet(Parser::toStdClass([ 'type'=> Parser::DISCONNECT ]));
      $this->onclose('server namespace disconnect');
    }
    return $this;
 }
  
  /**
   * Sets the compress flag.
   *
   * @param {Boolean} compress if `true`, compresses the sending data
   * @return {Socket} self
   * @api public
   */
  
  public function compress($compress){
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
  
   public function binary ($binary) {
     $this->flags[$binary] = $binary;
     return $this;
  }
  
  /**
   * Dispatch incoming event to socket listeners.
   *
   * @param {Array} event that will get emitted
   * @api private
   */
  
  public function dispatch($event){
    //debug('dispatching an event %j', event);
    $self = $this;
    function dispatchSocket($err) {
      process.nextTick(function(){
        if ($err) {
          return $self->error($err.data || err.message);
        }
        emit.apply($self, event);
      });
    }
    $this->run(event, dispatchSocket);
 }
  
  /**
   * Sets up socket middleware.
   *
   * @param {Function} middleware function (event, next)
   * @return {Socket} self
   * @api public
   */
  
  public function use($fn){
    $this->fns.push($fn);
    return $this;
 }
  
  /**
   * Executes the middleware for an incoming event.
   *
   * @param {Array} event that will get emitted
   * @param {Function} last fn call in the middleware
   * @api private
   */
  public function run($event, Closure $fn){
    $fns = $this->fns.slice(0);
    if (!fns.length) return $fn(null);
  
    function run($i){
      $fns[i](event, function($err){
        // upon error, short-circuit
        if ($err) return $fn($err);
  
        // if no middleware left, summon callback
        if (!fns[i + 1]) return $fn(null);
  
        // go on to next
        run(i + 1);
      });
    }
  
    run(0);
 }
}