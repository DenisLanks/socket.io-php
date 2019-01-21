<?php

namespace Lanks\Engineio\Transports;

use Closure;
use React\Http\Response;
use Lanks\Engineio\Transport;
use Lanks\Engineio\Parser\Parser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;


class Polling extends Transport
{
    protected $closeTimeout;
    protected $maxHttpBufferSize;
    protected $httpCompression;

    /**
     * HTTP polling constructor.
     *
     */
    public function __construct(ServerRequestInterface $req) {
       parent::__construct($req);
       $this->closeTimeout = 30 * 1000;
       $this->maxHttpBufferSize = null;
       $this->httpCompression = null;
       $this->name = 'polling';
    }

    public function onRequest(ServerRequestInterface $req)
    {
        $resp = new Response();
        $method = $req->getMethod();
        switch ($method) {
            case 'GET':{
                $this->onPollRequest($req,$resp);
            }break;
            case 'POST':{
                return $this->onDataRequest($req, $resp);
            }break;
            
            default:{
                return $resp->withStatus(500,'Invalid method requested');
            }break;
        }
        
    }

    /**
     * The client sends a request awaiting for us to send data.
     *
     * @api private
     */
    public function onPollResquest(ServerRequestInterface $req,ResponseInterface $res)
    {
        if ( empty($this->req) ) {
            echo("request overlap".PHP_EOL);
            // assert: this.res, '.req and .res should be (un)set together'
            $this->onError('overlap from client');
            return new Response(500,[],'overlap from client');
          }
        echo("setting request".PHP_EOL);
        
        $this->req = $req;
        $this->res = $res;
        
        $that = $this;
        
        $onClose = function  () use ($that){ $that->onError('poll connection closed prematurely'); };
        
        $cleanup = function  () use($onClose, $that){
            $req->removeListener('close', $onClose);
            $that->req = $that->res = null;
        };
        
        $req->cleanup = $cleanup;
        $req->on('close', $onClose);
        
        $this->writable = true;
        $this->emit('drain');
        
        // if we're still writable but had a pending close, trigger an empty send
        if ($this->writable && $this->shouldClose) {
            echo("triggering empty send to append close packet".PHP_EOL);
            $this->send( json_decode(json_encode([ 'type'=> 'noop'])) );
        }
    }

    /**
     * The client sends a request with data.
     *
     */
    protected function onDataRequest($req, $res)
    {
        if ($this->dataReq) {
            // assert: this.dataRes, '.dataReq and .dataRes should be (un)set together'
            $this->onError('data request overlap from client');
            $res->writeHead(500);
            $res->end();
            return;
        }
        
        $isBinary = 'application/octet-stream' === $req->headers['content-type'];
        
        $this->dataReq = $req;
        $this->dataRes = $res;
        
        $chunks = $isBinary ? [] : '';
        $self = $this;
        
        $cleanup =  function  () use($onData, $onEnd, $onClose, $req, $self, $chunks){
            $req.removeListener('data', $onData);
            $req.removeListener('end', $onEnd);
            $req.removeListener('close', $onClose);
            $self->dataReq = $self->dataRes = $chunks = null;
        };
        
        $onClose =  function  () use ($cleanup ,$self){
            $cleanup();
            $self->onError('data request connection closed prematurely');
        };
        
        $onData =  function  ($data) use($isBinary, $chunks ) {
            $contentLength;
            if ($isBinary) {
              $chunks[] = $data;
              $contentLength = count($chunks);
            } else {
              $chunks .= $data;
              $contentLength = strlen($chunks);
            }
        
            if ($contentLength > $self->maxHttpBufferSize) {
              $chunks = $isBinary ? [] : '';
              $req->connection->destroy();
            }
        };
        
        $onEnd =  function  () use($self, $chunks, $res, $cleanup){
            $self->onData($chunks);
        
            $headers = [
              // text/html is required instead of text/plain to avoid an
              // unwanted download dialog on certain user-agents (GH-43)
              'Content-Type'=> 'text/html',
              'Content-Length'=> 2
            ];
        
            $res->writeHead(200, $self->headers($req, $headers));
            $res->end('ok');
            $cleanup();
        };
        
        $req->on('close', $onClose);
        if (!$isBinary) $req->setEncoding('utf8');
          $req->on('data', $onData);
          $req->on('end', $onEnd);
    }

    /**
     * Processes the incoming data payload.
     *
     * @param {String} encoded payload
     * @api private
     */
    protected function onData($data)
    {
        echo("received $data".PHP_EOL);
        $self = $this;
        $callback = function ($packet) use($self) {
          if ('close' === $packet->type) {
            echo("got xhr close packet".PHP_EOL);
            $self->onClose();
            return false;
          }
      
          $self.onPacket($packet);
        };
      
        Parser::decodePayload($data, $callback);
    }

    /**
     * Overrides onClose.
     *
     * @api private
     */
    protected function onClose()
    {
        if ($this->writable) {
            // close pending poll request
            $this->send([ json_encode([ 'type'=> 'noop' ])]);
        }
        parent::onClose();
    }

    /**
     * Writes a packet payload.
     *
     * @param {Object} packet
     * @api private
     */
    public function send(array $packets)
    {
        $this->writable = false;

        if ($this->shouldClose) {
            echo("appending close packet to payload".PHP_EOL);
            $packets[] = json_decode("{ type: 'close' }");
            $this->shouldClose();
            $this->shouldClose = null;
        }
      
        $self = $this;
        $compress =false;
        foreach ($packets as $packet) {
            if(isset($packet->options) && $packet->options->compress){
                $compress =true;
                break;
            }
        }
        Parser::encodePayload($packets, $this->supportsBinary, function ($data) use($compress) {
          $self->write($data, "{ 'compress': $compress }");
        });
    }

    /**
     * Writes data as response to poll request.
     *
     * @param {String} data
     * @param {Object} options
     */
    protected function write($data, $options)
    {
        echo("writing $data".PHP_EOL);
        $self = $this;
        $this->doWrite($data, $options, function() use($self){ $self->req->cleanup(); });
    }

    /**
     * Performs the write.
     *
     * @api private
     */
    protected function doWrite($data, $options, Closure $callback)
    {
        $self = $this;

        // explicit UTF-8 is required for pages not served under utf
        $isString =isset($data);
        $contentType = $isString
            ? 'text/plain; charset=UTF-8'
            : 'application/octet-stream';

        $headers = [
            'Content-Type'=> $contentType
        ];

        $respond = function ($data) use($headers, $self,$callback){
            $headers['Content-Length'] = is_string($data) ? strlen($data) : count($data);
            $self->res->writeHead(200, $self->headers($self->req, $headers));
            $self->res->end($data);
            $callback();
        };
        if (!$this->httpCompression || !$options->compress) {
            $respond($data);
            return;
        }

        $len = $isString ? strlen($data) : count($data);
        if ($len < $this->httpCompression->threshold) {
            $respond($data);
            return;
        }

        $encoding = accepts($this->req).encodings(['gzip', 'deflate']);
        if (!$encoding) {
            $respond($data);
            return;
        }

        $this->compress($data, $encoding, function ($err, $data) use($self, $callback, $headers, $encoding) {
            if ($err) {
                $self->res.writeHead(500);
                $self->res.end();
                $callback($err);
                return;
            }

            $headers['Content-Encoding'] = $encoding;
            $respond($data);
        });

       
    }
}