<?php

namespace Lanks\Engineio;

use Evenement\EventEmitter;
use Lanks\Engineio\Transport;


class Socket extends EventEmitter
{
    public $id;
    public $server;
    protected $upgrading = false;
    protected $upgraded = false;
    protected $readyState = 'opening';
    protected $writeBuffer = [];
    protected $packetsFn = [];
    protected $sentCallbackFn = [];
    protected $cleanupFn = [];
    public $request;
    public $checkIntervalTimer;
    public $upgradeTimeoutTimer;
    public $pingTimeoutTimer;

    public function __construct($id, $server, $transport, $req) {
        $this->id = $id;
        $this->server = $server;
        $this->upgrading = false;
        $this->upgraded = false;
        $this->readyState = 'opening';
        $this->writeBuffer = [];
        $this->packetsFn = [];
        $this->sentCallbackFn = [];
        $this->cleanupFn = [];
        $this->request = $req;

        $this->checkIntervalTimer = null;
        $this->upgradeTimeoutTimer = null;
        $this->pingTimeoutTimer = null;

        $this->setTransport($transport);
        $this->onOpen();
    }

    /**
     * Called upon transport considered open.
     *
     */
    private function onOpen()
    {
        $this->readyState = 'open';

        // sends an `open` packet
        $this->transport->sid = $this->id;
        $this->sendPacket('open', json_decode(json_encode([
            'sid'=> $this->id,
            'upgrades'=> $this->getAvailableUpgrades(),
            'pingInterval'=> $this->server->pingInterval,
            'pingTimeout'=> $this->server->pingTimeout
        ])));

        if ($this->server->initialPacket) {
            $this->sendPacket('message', $this->server->initialPacket);
        }

        $this->emit('open');
        $this->setPingTimeout();
    }

    /**
     * Called upon transport packet.
     *
     * @param {Object} packet
     * @api private
     */
    private function onPacket($packet)
    {
        if ('open' === $this->readyState) {
            // export packet event
            echo('packet'.PHP_EOL);
            $this->emit('packet', $packet);
        
            // Reset ping timeout on any packet, incoming data is a good sign of
            // other side's liveness
            $this->setPingTimeout();
        
            switch ($packet->type) {
              case 'ping':
                echo('got ping'.PHP_EOL);
                $this->sendPacket('pong');
                $this->emit('heartbeat');
                break;
        
              case 'error':
                $this->onClose('parse error');
                break;
        
              case 'message':
                $this->emit('data', $packet->data);
                $this->emit('message', $packet->data);
                break;
            }
          } else {
            echo('packet received with closed socket'.PHP_EOL);
          }
    }

    /**
     * Called upon transport error.
     *
     * @param {Error} error object
     * @api private
     */
    private function onError($err)
    {
        echo('transport error'.PHP_EOL);
        $this->onClose('transport error', $err);

    }

    /**
     * Sets and resets ping timeout timer based on client pings.
     *
     */
    private function setPingTimeout()
    {
        // $self = $this;
        // clearTimeout(self->pingTimeoutTimer);
        // $this->pingTimeoutTimer = setTimeout(function () {
        //     $sef->onClose('ping timeout');
        // }, $sef->server.pingInterval + $sef->server.pingTimeout);
    }

    /**
     * Attaches handlers for the given transport.
     *
     * @param {Transport} transport
     * @api private
     */
    private function setTransport(Transport $transport)
    {
        $that = $this;
        $onError = function ($err) use($that){ $that->onError();  };
        $onPacket = function ($packet) use($that){ $that->onPacket($packet);  };
        $flush = function () use($that){   };
        $onClose = function () use($that){   };

        $this->transport = $transport;
        $this->$transport->once('error', $onError);
        $this->$transport->on('packet', $onPacket);
        $this->$transport->on('drain', $flush);
        $this->$transport->once('close', $onClose);
        // this function will manage packet events (also message callbacks)
        $this->setupSendCallback();

        $this->cleanupFn[] = function () use($onError, $onPacket, $flush, $onClose, $transport) {
            $transport->removeListener('error', $onError);
            $transport->removeListener('packet', $onPacket);
            $transport->removeListener('drain', $flush);
            $transport->removeListener('close', $onClose);
        };
    }

    /**
     * Upgrades socket to the given transport
     *
     * @param {Transport} transport
     * @api private
     */
    private function maybeUpgrade(Transport $transport)
    {
        echo("might upgrade socket transport from {$this->transport->name} to {$transport->name}".PHP_EOL);
        
        $this->upgrading = true;
        
        $self = $this;
        
        // set transport upgrade timer
        $this->upgradeTimeoutTimer = setTimeout(function () use($transport, $cleanup){
            echo("client did not complete upgrade - closing transport".PHP_EOL);
            $cleanup();
            if ('open' === $transport->readyState) {
                $transport->close();
            }
      }, $this->server->upgradeTimeout);
    
      $onPacket = function  ($packet) use($transport, $self,$clearInterval) {
        if ('ping' === $packet->type && 'probe' === $packet->data) {
          $transport->send(\json_decode("{ type: 'pong', data: 'probe' }"));
          $self->emit('upgrading', $transport);
          $clearInterval($sef->checkIntervalTimer);
          $sef->checkIntervalTimer = setInterval($check, 100);
        } else if ('upgrade' === $packet->type && $sef->readyState !== 'closed') {
          debug('got upgrade packet - upgrading');
          cleanup();
          $sef->transport.discard();
          $sef->upgraded = true;
          $sef->clearTransport();
          $sef->setTransport(transport);
          $sef->emit('upgrade', transport);
          $sef->setPingTimeout();
          $sef->flush();
          if ($sef->readyState === 'closing') {
            transport.close(function () {
              $sef->onClose('forced close');
            });
          }
        } else {
          cleanup();
          transport.close();
        }
      };
    
      // we force a polling cycle to ensure a fast upgrade
      $check = function  () {
        if ('polling' === $sef->transport.name && $sef->transport.writable) {
          debug('writing a noop packet to polling for fast upgrade');
          $sef->transport.send( \json_decode("{ type: 'noop' }"));
        }
      };
    
      $cleanup = function  () {
        $sef->upgrading = false;
    
        clearInterval($sef->checkIntervalTimer);
        $sef->checkIntervalTimer = null;
    
        clearTimeout($sef->upgradeTimeoutTimer);
        $sef->upgradeTimeoutTimer = null;
    
        transport.removeListener('packet', $onPacket);
        transport.removeListener('close', $onTransportClose);
        transport.removeListener('error', $onError);
        $sef->removeListener('close', $onClose);
      };
    
      $onError = function ($err) {
        debug('client did not complete upgrade - %s', $err);
        cleanup();
        $transport->close();
        $transport = null;
      };
    
      $onTransportClose = function  () {
        onError('transport closed');
      };
    
      $onClose =function  () {
        onError('socket closed');
      };
    
      $transport->on('packet', $onPacket);
      $transport->once('close', $onTransportClose);
      $transport->once('error', $onError);
    
      $sef->once('close', $onClose);
    }
}