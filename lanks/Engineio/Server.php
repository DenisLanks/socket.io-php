<?php

namespace Lanks\Engineio;

use Evenement\EventEmitter;
use Lanks\Engineio\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;


class Server extends EventEmitter
{
    public $clients;
    public $clientsCount;

    /* eslint-disable */
  
  /**
   * From https://github.com/nodejs/node/blob/v8.4.0/lib/_http_common.js#L303-L354
   *
   * True if val contains an invalid field-vchar
   *  field-value    = *( field-content / obs-fold )
   *  field-content  = field-vchar [ 1*( SP / HTAB ) field-vchar ]
   *  field-vchar    = VCHAR / obs-text
   *
   * checkInvalidHeaderChar() is currently designed to be inlinable by v8,
   * so take care when making changes to the implementation so that the source
   * code size does not exceed v8's default max_inlined_source_size setting.
   **/
  public $validHdrChars = [
    0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, // 0 - 15
    0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, // 16 - 31
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // 32 - 47
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // 48 - 63
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // 64 - 79
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // 80 - 95
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // 96 - 111
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, // 112 - 127
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, // 128 ...
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1  // ... 255
  ];
    /**
     * Server constructor.
     *
     * @param {Object} options
     * @api public
     */

    public function __construct($options =[]) {
        $this->clients = [];
        $this->clientsCount = 0;
    
        $opts = \json_decode(json_encode($options));
    
        $this->wsEngine = $opts->wsEngine || 'ws';
        $this->pingTimeout = $opts->pingTimeout || 5000;
        $this->pingInterval = $opts->pingInterval || 25000;
        $this->upgradeTimeout = $opts->upgradeTimeout || 10000;
        $this->maxHttpBufferSize = $opts->maxHttpBufferSize || 10E7;
        $this->transports = $opts->transports || Object.keys(transports);
        $this->allowUpgrades = false !== $opts->allowUpgrades;
        $this->allowRequest = $opts->allowRequest;
        $this->cookie = false !== $opts->cookie ? ($opts->cookie || 'io') : false;
        $this->cookiePath = false !== $opts->cookiePath ? ($opts->cookiePath || '/') : false;
        $this->cookieHttpOnly = false !== $opts->cookieHttpOnly;
        $this->perMessageDeflate = false !== $opts->perMessageDeflate ? ($opts->perMessageDeflate || true) : false;
        $this->httpCompression = false !== $opts->httpCompression ? ($opts->httpCompression || []) : false;
        $this->initialPacket = $opts->initialPacket;
    
        $self = $this;
    
        // initialize compression options
        foreach (['perMessageDeflate', 'httpCompression'] as $key => $type) {
            # code...
            $compression = $self[$type];
            if (true === $compression) $self[$type] = $compression = [];
            if ($compression && null == $compression->threshold) {
            $compression->threshold = 1024;
            }
        }
    
        $self->init();
    }
  
  /**
   * Protocol errors mappings.
   */
  
  protected $errors = [
    'UNKNOWN_TRANSPORT'=> 0,
    'UNKNOWN_SID'=> 1,
    'BAD_HANDSHAKE_METHOD'=> 2,
    'BAD_REQUEST'=> 3,
    'FORBIDDEN'=> 4
  ];
  
  protected $errorMessages = [
    0=> 'Transport unknown',
    1=> 'Session ID unknown',
    2=> 'Bad handshake method',
    3=> 'Bad request',
    4=> 'Forbidden'
  ];
  
  /**
   * Initialize websocket server
   *
   * @api private
   */
  
  public function init  () {
    if (!~$this->transports.indexOf('websocket')) return;
  
    if ($this->ws) $this->ws.close();
  
    $wsModule;
    switch ($this->wsEngine) {
      case 'uws': $wsModule = require('uws'); break;
      case 'ws': $wsModule = require('ws'); break;
      default: throw new Error('unknown wsEngine');
    }
    $this->ws = new wsModule.Server([
      'noServer'=> true,
      'clientTracking'=> false,
      'perMessageDeflate'=> $this->perMessageDeflate,
      'maxPayload'=> $this->maxHttpBufferSize
    ]);
  }
  
  /**
   * Returns a list of available transports for upgrade given a certain transport.
   *
   * @return {Array}
   * @api public
   */
  
  public function upgrades  ($transport) {
    if (!$this->allowUpgrades) return [];
    return $transports[$transport]->upgradesTo || [];
  }
  
  /**
   * Verifies a request.
   *
   * @param {http.IncomingMessage}
   * @return {Boolean} whether the request is valid
   * @api private
   */
  
  public function verify($req, $upgrade, $fn) {
    // transport check
    $transport = $req->query['transport'];
    if (!~$this->transports.indexOf($transport)) {
      debug('unknown transport "%s"', transport);
      return $fn($this->errors['UNKNOWN_TRANSPORT'], false);
    }
  
    // 'Origin' header check
    $isOriginInvalid = $this->checkInvalidHeaderChar($req->headers[origin]);
    if ($isOriginInvalid) {
      $req->headers[origin] = null;
      return fn(Server.errors.BAD_REQUEST, false);
    }
  
    // sid check
    $sid = req._query.sid;
    if (sid) {
      if (!$this->clients.hasOwnProperty(sid)) {
        return fn(Server.errors.UNKNOWN_SID, false);
      }
      if (!upgrade && $this->clients[sid].transport.name !== transport) {
        debug('bad request: unexpected transport without upgrade');
        return fn(Server.errors.BAD_REQUEST, false);
      }
    } else {
      // handshake is GET only
      if ('GET' !== req.method) return fn(Server.errors.BAD_HANDSHAKE_METHOD, false);
      if (!$this->allowRequest) return fn(null, true);
      return $this->allowRequest(req, fn);
    }
  
    fn(null, true);
  }
  
  /**
   * Prepares a request by processing the query string.
   *
   * @api private
   */
  
  public function prepare  ($req) {
    // try to leverage pre-existing `req._query` (e.g: from connect)
    if (empty($req->query)) {
      $req->query = ~$req->url.indexOf('?') ? $qs->parse(parse(req.url).query) : [];
    }
  }
  
  /**
   * Closes all clients.
   *
   * @api public
   */
  
  public function close  () {
    debug('closing all open clients');
    foreach ($this->clients as $i=>$client) {
        $client->close(true);
    }
    if ($this->ws) {
      debug('closing webSocketServer');
      $this->ws->close();
      // don't delete this.ws because it can be used again if the http server starts listening again
    }
    return $this;
  }
  
  /**
   * Handles an Engine.IO HTTP request.
   *
   * @param {http.IncomingMessage} request
   * @param {http.ServerResponse|http.OutgoingMessage} response
   * @api public
   */
  
  public function handleRequest  (ServerRequestInterface $req, $res) {
    debug('handling "%s" http request "%s"', $req->method, $req->url);
   // $this->prepare($req);
    //$req->res = $res;
  
    $self = $this;
    $this->verify($req, false, function ($err, $success) use($req) {
      if (!$success) {
        return $this->sendErrorMessage($req, $err);
      }
      $queryParams = $req->getQueryParams();
      #req._query.sid
      if (isset($queryParams['sids'])) {
        debug('setting new request for existing client');
        $self->clients[$queryParams['sids']]->transport->onRequest($req);
      } else {
        $self->handshake($queryParams['transport'], $req);
      }
    });
  }
  
  /**
   * Sends an Engine.IO Error Message
   *
   * @param {http.ServerResponse} response
   * @param {code} error code
   * @api private
   */
  
  function sendErrorMessage (ServerRequestInterface $req, $code) : Response {
    $headers =\json_decode("{ 'Content-Type': 'application/json' }");
  
    $isForbidden = !isset($this->errors[$code]);
    if ($isForbidden) {
      return new Response(403, $headers, json_encode(['code'=> $this->errors['FORBIDDEN'], 'message'=>$code|| $this->errorMessages[$this->errors['FORBIDDEN']]]));
    }
    $origin =  $req->getHeaderLine('origin');
    if (!empty($origin)) {
      $headers['Access-Control-Allow-Credentials'] = 'true';
      $headers['Access-Control-Allow-Origin'] = $origin;
    } else {
      $headers['Access-Control-Allow-Origin'] = '*';
    }

    return new Response(400, $headers, json_encode(['code'=> $code, 'message'=>$this->errorMessages[$code]]));
  }
  
  /**
   * generate a socket id.
   * Overwrite this method to generate your custom socket id
   *
   * @param {Object} request object
   * @api public
   */
  
  public function generateId  (ServerRequestInterface $req) {
    return base64_encode('engine-io-'.time());
  }

  /**
   * Handshakes a new client.
   *
   * @param {String} transport name
   * @param {Object} request object
   * @api private
   */
  
  public function handshake  ($transportName,ServerRequestInterface $req) {
    $id = $this->generateId($req);
  
    debug('handshaking client "%s"', $id);
    $queryParams = $req->getQueryParams();
    try {
      $transport = new Transport($req);# transports[transportName](req);
      if ('polling' === $transportName) {
        $transport->maxHttpBufferSize = $this->maxHttpBufferSize;
        $transport->httpCompression = $this->httpCompression;
      } else if ('websocket' === $transportName) {
        $transport->perMessageDeflate = $this->perMessageDeflate;
      }
  
      if (isset($queryParams['b64']) ) {
        $transport->supportsBinary = false;
      } else {
        $transport->supportsBinary = true;
      }
    } catch (Exception $e) {
      return $this->sendErrorMessage($req, $this->errors['BAD_REQUEST']);
    }
    $socket = new Socket($id, $this, $transport, $req);
    $self = $this;
  
    if (false !== $this->cookie) {
      $transport->on('headers', function ($headers) {
        $headers['Set-Cookie'] = cookieMod.serialize($self->cookie, $id,
          [
            'path'=> $self->cookiePath,
            'httpOnly'=> $self->cookiePath ? $self->cookieHttpOnly : false
          ]);
      });
    }
  
    $transport->onRequest($req);
  
    $this->clients[id] = socket;
    $this->clientsCount++;
  
    $socket->once('close', function () {
      unset($self->clients[$id]);
      $self->clientsCount--;
    });
  
    $this->emit('connection', $socket);
  }
  
  /**
   * Handles an Engine.IO HTTP Upgrade.
   *
   * @api public
   */
  
  public function handleUpgrade  (ServerRequestInterface $req,Socket $socket, $upgradeHead) {
    $this->prepare($req);
  
    $self = $this;
    $this->verify($req, true, function ($err, $success) use($self) {
      if (!$success) {
        $this->abortConnection($socket, $err);
        return;
      }
  
      $head = $upgradeHead; // eslint-disable-line node/no-deprecated-api
      $upgradeHead = null;
  
      // delegate to ws
      $self->ws->handleUpgrade($req, $socket, $head, function ($conn) {
        $self->onWebSocket($req, $conn);
      });
    });
  }
  
  /**
   * Called upon a ws.io connection.
   *
   * @param {ws.Socket} websocket
   * @api private
   */
  
  public function onWebSocket  (ServerRequestInterface $req,Socket $socket) {
    $socket->on('error', $onUpgradeError);
  
    if (transports[req._query.transport] !== undefined && !transports[req._query.transport].prototype.handlesUpgrades) {
      debug('transport doesnt handle upgraded requests');
      $socket->close();
      return;
    }
    $queryParams = $req->getQueryParams();
    // get client id
    $id = $queryParams[$sid];
  
    // keep a reference to the ws.Socket
    $req->websocket = $socket;
  
    if ($id) {
      $client = $this->clients[$id];
      if (!$client) {
        debug('upgrade attempt for closed client');
        $socket->close();
      } else if ($client->upgrading) {
        debug('transport has already been trying to upgrade');
        $socket->close();
      } else if ($client->upgraded) {
        debug('transport had already been upgraded');
        $socket->close();
      } else {
        debug('upgrading existing transport');
  
        // transport error handling takes over
        $socket->removeListener('error', $onUpgradeError);
  
        $transport = new Transport($req);
        if ($queryParams['b64']) {
          $transport->supportsBinary = false;
        } else {
          $transport->supportsBinary = true;
        }
        $transport->perMessageDeflate = $this->perMessageDeflate;
        $client->maybeUpgrade($transport);
      }
    } else {
      // transport error handling takes over
      $socket->removeListener('error', $onUpgradeError);
  
      $this->handshake($queryParams['transport'], $req);
    }
  
    function onUpgradeError () {
      debug('websocket error before upgrade');
      // $socket->close() not needed
    }
  }
  
  /**
   * Captures upgrade requests for a http.Server.
   *
   * @param {http.Server} server
   * @param {Object} options
   * @api public
   */
  
  public function attach  ($server, $options) {
    $self = $this;
    $path = $options->path;
  
    $destroyUpgradeTimeout = $options->destroyUpgradeTimeout || 1000;
  
    // normalize path
    $path .= '/';
  
    $check = function  ($req) {
      if ('OPTIONS' === $req->method() && false === $options->handlePreflightRequest) {
        return false;
      }
      return $path === $req.url.substr(0, path.length);
    };
  
    // cache and clean up listeners
    $listeners = $server->listeners('request');
    $server->removeAllListeners('request');
    $server->on('close', self.close.bind(self));
    $server->on('listening', self.init.bind(self));
  
    // add request handler
    $server->on('request', function (ServerRequestInterface $req) {
      if (check($req)) {
        debug('intercepting request for path "%s"', $path);
        if ('OPTIONS' === $req->getMethod() && is_callable($options->handlePreflightRequest)) {
          return $options->handlePreflightRequest($server, $req);
        } else {
          return $self.handleRequest($req);
        }
      } else {
        foreach ($listeners as $listener) {
          $listener($server, $req);
        }
      }
    });
  
    if (~self.transports.indexOf('websocket')) {
      $server->on('upgrade', function (ServerRequestInterface $req, $socket, $head) {
        if (check($req)) {
          $self->handleUpgrade($req, $socket, $head);
        } else if (false !== $options->destroyUpgrade) {
          // default node behavior is to disconnect when no handlers
          // but by adding a handler, we prevent that
          // and if no eio thing handles the upgrade
          // then the socket needs to die!
          setTimeout(function () {
            if ($socket->writable && $socket->bytesWritten <= 0) {
              return $socket->end();
            }
          }, destroyUpgradeTimeout);
        }
      });
    }
  }
  
  /**
   * Closes the connection
   *
   * @param {net.Socket} socket
   * @param {code} error code
   * @api private
   */
  
  function abortConnection ($socket, $code) {
    if ($socket->writable) {
      $message = isset($this->errorMessages[$code]) ?$this->errorMessages[$code] : $code.'';
      $length = strlen($message);
      $socket->write(
        'HTTP/1.1 400 Bad Request\r\n' .
        'Connection: close\r\n' .
        'Content-type: text/html\r\n' .
        'Content-Length: $length \r\n' .
        '\r\n' .
        $message
      );
    }
    $socket->destroy();
  }
  
  
  
  function checkInvalidHeaderChar($val) {
    $val += '';
    $length = strlen($val);
    if ($length < 1)
      return false;
    if (!$this->validHdrChars[ord($val)])
      return true;
    if ($length < 2)
      return false;
    if (!$this->validHdrChars[ord($val)])
      return true;
    if ($length < 3)
      return false;
    if (!$this->validHdrChars[ord($val)])
      return true;
    if ($length < 4)
      return false;
    if (!$this->validHdrChars[ord($val)])
      return true;
    for ($i = 4; $i < $length; ++$i) {
      if (!$this->validHdrChars[ord($val)])
        return true;
    }
    return false;
  }
}