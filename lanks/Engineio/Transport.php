<?php

namespace Lanks\Engineio;

use Evenement\EventEmitter;
use Psr\Http\Message\ServerRequestInterface;

class Transport  extends EventEmitter implements DuplexStreamInterface
{
    protected $name = 'transport';
    protected $readyState = '';
    protected $discarded = false;
    protected $req;

    /**
     * Transport constructor.
     *
     * @param {http.IncomingMessage} request
     * @api public
     */
    public function __construct(ServerRequestInterface $req) {
        $this->req = $req;
    }
    
    /**
     * Flags the transport as discarded.
     *
     * @api private
     */
    public function discard()
    {
        $this->discarded = true;
    }
    
    /**
     * Called with an incoming HTTP request.
     *
     * @param {http.IncomingMessage} request
     * @api private
     */
    public function onRequest(ServerRequestInterface $req)
    {
        $this->req = $req;
    }

    /**
     * Closes the transport.
     *
     */
    public function close($fn)
    {
        if(empty($fn)){
            $that = $this;
            $fn = function () use($that) { $that->noop(); };
        }
        if ('closed' === $this->readyState || 'closing' === $this->readyState) return;
            $this->doClose($fn);
    }

    /**
     * Called with a transport error.
     *
     * @param {String} message error
     * @param {Object} error description
     */
    protected function onError($msg, $desc)
    {
        if( count($this->listeners('error')) > 0 ){
            $err = new stdClass();
            $err->type = 'TransportError';
            $err->description = $desc;
            $this->emit('error', $err);
        }else{
            echo("ignored transport error $msg ($desc)");
        }
    }
    
    /**
     * Called with parsed out a packets from the data stream.
     *
     * @param {Object} packet
     */
    protected function onPacket($packet)
    {
        $this->emit('packet', $packet);
    }

    /**
     * Called with the encoded packet data.
     *
     * @param {String} data
     */
    protected function onData($data)
    {
        $this->onPacket(Parser::decodePacket($data));
    }

    /**
     * Called upon transport close.
     *
     */
    protected function onClose()
    {
        $this->readyState = 'closed';
        $this->emit('close');
    }

    /**
     * Writes a packet payload.
     *
     * @param {Array} packets
     */
    public function send(array $packets)
    {
        echo("send tranport method is not implemented");
    }
}