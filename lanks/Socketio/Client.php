<?php

namespace Lanks\Socketio;

use Exception;
use Lanks\Engineio\Server;
use Lanks\Socketio\Socket;

class Client
{
    public $server;
    public $conn;
    public $encoder;
    public $decoder;
    public $id;
    public $request;
    public $sockets;
    public $nsps;
    public $connectBuffer;
    /**
 * Client constructor.
 *
 * @param {Server} server instance
 * @param {Socket} conn
 * @api private
 */

public function __construct(Server $server,Socket $conn){
    $this->server = $server;
    $this->conn = $conn;
    $this->encoder = server.encoder;
    $this->decoder = new server.parser.Decoder();
    $this->id = $conn->id;
    $this->request = $conn->request;
    $this->setup();
    $this->sockets = [];
    $this->nsps = [];
    $this->connectBuffer = [];
  }
  
  /**
   * Sets up event listeners.
   *
   * @api private
   */
  
  public function setup(){
    $this->onclose = $this->onclose.bind($this);
    $this->ondata = $this->ondata.bind($this);
    $this->onerror = $this->onerror.bind($this);
    $this->ondecoded = $this->ondecoded.bind($this);
  
    $this->decoder->on('decoded', $this->ondecoded);
    $this->conn->on('data', $this->ondata);
    $this->conn->on('error', $this->onerror);
    $this->conn->on('close', $this->onclose);
  }
  
  /**
   * Connects a client to a namespace.
   *
   * @param {String} name namespace
   * @param {Object} query the query parameters
   * @api private
   */
  
  public function connect($name, $query){
    if ($this->server->nsps[$name]) {
      debug('connecting to namespace %s', name);
      return $this->doConnect($name, $query);
    }
  
    $this->server.checkNamespace($name, $query, function($dynamicNsp) {
      if ($dynamicNsp) {
        debug('dynamic namespace %s was created', dynamicNsp.name);
        $this->doConnect($name, $query);
      } else {
        debug('creation of namespace %s was denied', name);
        $type = \json_encode(['type'=>'error','data'=>'parser error']);
        $this->packet("{ type: $type, nsp: $name, data: 'Invalid namespace' }");
      }
    });
  }
  
  /**
   * Connects a client to a namespace.
   *
   * @param {String} name namespace
   * @param {String} query the query parameters
   * @api private
   */
  
  public function doConnect($name, $query){
    $nsp = $this->server.of($name);
  
    if ('/' != $name && !$this->nsps['/']) {
      $this->connectBuffer[] = $name;
      return;
    }
  
    $self = $this;
    $socket = $nsp->add($this, $query, function(){
      $self->sockets[$socket->id] = $socket;
      $self->nsps[$nsp->name] = $socket;
  
      if ('/' == $nsp->name && $self->connectBuffer.length > 0) {
        foreach ($self->connectBuffer as $key => $value) {
          $this->connect($value);
        }
        //$self->connectBuffer.forEach($self->connect, $self);
        $self->connectBuffer = [];
      }
    });
  }
  
  /**
   * Disconnects from all namespaces and closes transport.
   *
   * @api private
   */
  
  public function disconnect(){
    foreach ($this->sockets as $id=>$socket) {
      $socket->disconnect();
    }
    $this->sockets = [];
    $this->close();
  }
  
  /**
   * Removes a socket. Called by each `Socket`.
   *
   * @api private
   */
  
  public function remove($socket){
    if (isset($this->sockets[$socket->id])) {
      $nsp = $this->sockets[$socket->id]->nsp->name;
      unset($this->sockets[$socket->id]);
      unset($this->nsps[$nsp]);
    } else {
      echo("ignoring remove for {$socket->id}".PHP_EOL);
    }
  }
  
  /**
   * Closes the underlying connection.
   *
   * @api private
   */
  
  public function close(){
    if ('open' == $this->conn->readyState) {
      debug('forcing transport close');
      $this->conn->close();
      $this->onclose('forced server close');
    }
  }
  
  /**
   * Writes a packet to the transport.
   *
   * @param {Object} packet object
   * @param {Object} opts
   * @api private
   */
  
  public function packet($packet, $opts=[]){
    $self = $this;
  
    // this writes to the actual connection
    $writeToEngine = function ($encodedPackets) {
      if ($opts->volatile && !$self->conn->transport->writable) return;
      foreach ($encodedPackets as $key => $packet) {
        $self->conn->write($packet,\json_decode("{ compress:{$opts->compress} }"));
      }
    };
  
    if ('open' == $this->conn->readyState) {
      debug('writing packet %j', packet);
      if (!$opts->preEncoded) { // not broadcasting, need to encode
        $this->$encoder->encode($packet, $writeToEngine); // encode, then write results to engine
      } else { // a broadcast pre-encodes a packet
        $this->writeToEngine($packet);
      }
    } else {
      debug('ignoring packet write %j', packet);
    }
  }
  
  /**
   * Called with incoming transport data.
   *
   * @api private
   */
  
  public function ondata($data){
    // try/catch is needed for protocol violations (GH-1880)
    try {
      $this->decoder->add($data);
    } catch(Exception $e) {
      $this->onerror($e);
    }
  }
  
  /**
   * Called when parser fully decodes a packet.
   *
   * @api private
   */
  
  public function ondecoded($packet) {
    if (parser.CONNECT == $packet->type) {
      $this->connect(url.parse($packet->nsp).pathname, url.parse($packet->nsp, true).query);
    } else {
      $socket = $this->nsps[$packet->nsp];
      if ($socket) {
        process.nextTick(function() {
          $socket->onpacket($packet);
        });
      } else {
        debug('no socket for namespace %s', $packet->nsp);
      }
    }
  }
  
  /**
   * Handles an error.
   *
   * @param {Object} err object
   * @api private
   */
  
  public function onerror($err){
    foreach ($this->sockets as $id=>$socket) {
      $socket->onerror($err);
    }
    $this->conn->close();
  }
  
  /**
   * Called upon transport close.
   *
   * @param {String} reason
   * @api private
   */
  
  public function onclose($reason){
    debug('client close with reason %s', reason);
  
    // ignore a potential subsequent `close` event
    $this->destroy();
  
    // `nsps` and `sockets` are cleaned up seamlessly
    foreach ($this->sockets as $id =>$socket ) {
      $socket->onclose($reason);
    }
    $this->sockets = [];
  
    $this->decoder->destroy(); // clean up decoder
  }
  
  /**
   * Cleans up event listeners.
   *
   * @api private
   */
  
  public function destroy(){
    $this->conn->removeListener('data', $this->ondata);
    $this->conn->removeListener('error', $this->onerror);
    $this->conn->removeListener('close', $this->onclose);
    $this->decoder->removeListener('decoded', $this->ondecoded);
  }
}