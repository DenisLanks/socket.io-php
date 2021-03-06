<?php

namespace Lanks\Socketio;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use Lanks\Engineio\Parser\Parser;
use React\Http\Response;


class Socketio extends \React\Socket\Server
{
      /**
   * Socket.IO client source.
   */

  protected $clientSource;
  protected $clientSourceMap;

   /**
   * Old settings for backwards compatibility
   */

  protected $oldSettings = [
    "transports"=> "transports",
    "heartbeat timeout"=> "pingTimeout",
    "heartbeat interval"=> "pingInterval",
    "destroy buffer size"=> "maxHttpBufferSize"
  ];

  /**
   * Server constructor.
   *
   * @param {http.Server|Number|Object} srv http server, port or options
   * @param {Object} [opts]
   * @api public
   */

  public function __construct($srv, $opts){

    $this->nsps = [];
    $this->parentNsps = [];
    $this->path($opts->path || '/socket.io');
    $this->serveClient(false !== $opts->serveClient);
    $this->parser = $opts->parser || parser;
    $this->encoder = Parser::Encoder();
    $this->adapter($opts->adapter || Adapter);
    $this->origins($opts->origins || '*:*');
    $this->sockets = $this->of('/');
    $this->listen($srv, $opts);
    //if (srv) $this->attach(srv, opts);
  }

  /**
   * Server request verification function, that checks for allowed origins
   *
   * @param {http.IncomingMessage} req request
   * @param {Function} fn callback to be called with the result: `fn(err, success)`
   */

  public function checkRequest (ServerRequestInterface $req,\Closure $fn) {
    $headers = $req->getHeaders();
    $origin = $headers['origin']|| $headers['referer'];

    // file:// URLs produce a null Origin which can't be authorized via echo-back
    if (empty($origin)) $origin = '*';

    if (!!origin && typeof($this->_origins) == 'function') return $this->_origins(origin, fn);
    if ($this->_origins.indexOf('*:*') !== -1) return fn(null, true);
    if ($origin) {
      try {
        $parts = url.parse(origin);
        $defaultPort = 'https:' == parts.protocol ? 443 : 80;
        $parts->port = $parts->port != null
          ? parts.port
          : defaultPort;
        $ok =
          ~$this->_origins.indexOf(parts.protocol + '//' + parts.hostname + ':' + parts.port) ||
          ~$this->_origins.indexOf(parts.hostname + ':' + parts.port) ||
          ~$this->_origins.indexOf(parts.hostname + ':*') ||
          ~$this->_origins.indexOf('*:' + parts.port);
        debug('origin %s is %svalid', origin, !!ok ? '' : 'not ');
        return fn(null, !!$ok);
      } catch (Exception $ex) {
      }
    }
    $fn(null, false);
  
  }
  /**
   * Sets/gets whether client code is being served.
   *
   * @param {Boolean} v whether to serve client code
   * @return {Server|Boolean} self when setting or value when getting
   * @api public
   */

  public function serveClient ($v){
    if (!arguments.length) return $this->_serveClient;
    $this->_serveClient = $v;
    $resolvePath = function ($file){
      $filepath = path.resolve(__dirname, './../../', file);
      if (exists(filepath)) {
        return filepath;
      }
      return $require.resolve(file);
    };
    if (v && !clientSource) {
      $clientSource = read(resolvePath( 'socket.io-client/dist/socket.io.js'), 'utf-8');
      try {
        $clientSourceMap = read(resolvePath( 'socket.io-client/dist/socket.io.js.map'), 'utf-8');
      } catch(Exception $err) {
        debug('could not load sourcemap file');
      }
    }
    return $this;
  }

 

  /**
   * Backwards compatibility.
   *
   * @api public
   */

  public function set ($key, $val){
    if ('authorization' == $key && $val) {
      $this->use(function($socket, $next) {
        val($socket->request, function($err, $authorized) {
          if ($err) return next(new Error($err));
          if (!$authorized) return next(new Error('Not authorized'));
          next();
        });
      });
    } else if ('origins' == $key && $val) {
      $this->origins($val);
    } else if ('resource' == $key) {
      $this->path($val);
    } else if ($oldSettings[$key] && $this->eio[$oldSettings[$key]]) {
      $this->eio[$oldSettings[$key]] = $val;
    } else {
      $console.error('Option %s is not valid. Please refer to the README.', key);
    }

    return $this;
  }

  /**
   * Executes the middleware for an incoming namespace not already created on the server.
   *
   * @param {String} name name of incoming namespace
   * @param {Object} query the query parameters
   * @param {Function} fn callback
   * @api private
   */

  public function checkNamespace ($name, $query, $fn){
    if ($this->parentNsps.size === 0) return fn(false);

    $keysIterator = $this->parentNsps.keys();

    $run = () => {
      $nextFn = keysIterator.next();
      if (nextFn.done) {
        return fn(false);
      }
      nextFn.value(name, query, (err, allow) => {
        if (err || !allow) {
          run();
        } else {
          fn($this->parentNsps.get(nextFn.value).createChild(name));
        }
      });
    }

    run();
  }

  /**
   * Sets the client serving path.
   *
   * @param {String} v pathname
   * @return {Server|String} self when setting or value when getting
   * @api public
   */

  public function path (v){
    if (!arguments.length) return $this->_path;
    $this->_path = v.replace(/\/$/, '');
    return $this;
  }

  /**
   * Sets the adapter for rooms.
   *
   * @param {Adapter} v pathname
   * @return {Server|Adapter} self when setting or value when getting
   * @api public
   */

  public function adapter (v){
    if (!arguments.length) return $this->_adapter;
    $this->_adapter = v;
    for ($i in $this->nsps) {
      if ($this->nsps.hasOwnProperty(i)) {
        $this->nsps[i].initAdapter();
      }
    }
    return $this;
   }

  /**
   * Sets the allowed origins for requests.
   *
   * @param {String|String[]} v origins
   * @return {Server|Adapter} self when setting or value when getting
   * @api public
   */

  public function origins (v){
    if (!arguments.length) return $this->_origins;

    $this->_origins = v;
    return $this;
   }

  /**
   * Attaches socket.io to a server or port.
   *
   * @param {http.Server|Number} server or port
   * @param {Object} options passed to engine.io
   * @return {Server} self
   * @api public
   */

  public function listen($srv, $opts){

  }
  public function attach ($srv, $opts){
    if ('function' == typeof srv) {
      $msg = 'You are trying to attach socket.io to an express ' +
      'request handler function. Please pass a http.Server instance.';
      throw new Error(msg);
    }

    // handle a port as a string
    if (Number(srv) == srv) {
      srv = Number(srv);
    }

    if (\is_numeric($srv)) {
      //Start the serve with only port through
      debug('creating http server and binding to %d', $srv);
      $port = $srv;
      $srv = http.Server(function($req, $res){
         $res->writeHead(404);
         $res->end();
      });
      $srv.listen($port);

    }

    // set engine.io path to `/socket.io`
    $opts->path = $opts->path || $this->path();
    // set origins verification
    $opts->allowRequest = $opts->allowRequest || $this->checkRequest.bind(this);

    if ($this->sockets.fns.length > 0) {
      $this->initEngine(srv, opts);
      return $this;
    }

    $self = $this;
    $connectPacket = { type: Parser::CONNECT, $nsp: '/' };
    $this->encoder.encode(connectPacket, function (encodedPacket){
      // the CONNECT packet will be merged with Engine.IO handshake,
      // to reduce the number of round trips
      $opts->initialPacket = encodedPacket;

      self.initEngine(srv, opts);
    });
    return $this;
   }

  /**
   * Initialize engine
   *
   * @param {Object} options passed to engine.io
   * @api private
   */

  public function initEngine (srv, opts){
    // initialize engine
    debug('creating engine.io instance with opts %j', opts);
    $this->eio = engine.attach(srv, opts);

    // attach static file serving
    if ($this->_serveClient) $this->attachServe(srv);

    // Export http server
    $this->httpServer = srv;

    // bind to engine events
    $this->bind($this->eio);
    }

  /**
   * Attaches the static file serving.
   *
   * @param {Function|http.Server} srv http server
   * @api private
   */

  public function attachServe (srv){
    debug('attaching client serving req handler');
    $url = $this->_path + '/socket.io.js';
    $urlMap = $this->_path + '/socket.io.js.map';
    $evs = srv.listeners('request').slice(0);
    $self = $this;
    srv.removeAllListeners('request');
    srv.on('request', function(req, res) {
      if (0 === req.url.indexOf(urlMap)) {
        self.serveMap(req, res);
      } else if (0 === req.url.indexOf(url)) {
        self.serve(req, res);
      } else {
        for ($i = 0; i < evs.length; i++) {
          evs[i].call(srv, req, res);
        }
      }
    });
  }

  /**
   * Handles a request serving `/socket.io.js`
   *
   * @param {http.Request} req
   * @param {http.Response} res
   * @api private
   */

  public function serve (ServerRequestInterface $req,Response $res){
    // Per the standard, ETags must be quoted:
    // https://tools.ietf.org/html/rfc7232#section-2.3
    $expectedEtag = '"' . $clientVersion . '"';

    $etag = $req->getHeaderLine('if-none-match');
    if ($etag) {
      if ($expectedEtag == $etag) {
        debug('serve client 304');
         $res->writeHead(304);
         $res->end();
        return;
      }
    }

    debug('serve client source');
     $res->setHeader("Cache-Control", "public, max-age=0");
     $res->setHeader('Content-Type', 'application/javascript');
     $res->setHeader('ETag', $expectedEtag);
     $res->writeHead(200);
     $res->end($clientSource);
  }

  /**
   * Handles a request serving `/socket.io.js.map`
   *
   * @param {http.Request} req
   * @param {http.Response} res
   * @api private
   */

  public function serveMap ($req, $res){
    // Per the standard, ETags must be quoted:
    // https://tools.ietf.org/html/rfc7232#section-2.3
    $expectedEtag = '"' . $clientVersion . '"';

    $etag = $req->getHeaderLine('if-none-match');
    if ($etag) {
      if ($expectedEtag == $etag) {
        debug('serve client 304');
         $res->writeHead(304);
         $res->end();
        return;
      }
    }

    debug('serve client sourcemap');
     $res->setHeader('Content-Type', 'application/json');
     $res->setHeader('ETag', expectedEtag);
     $res->writeHead(200);
     $res->end(clientSourceMap);
    }
  /**
   * Binds socket.io to an engine.io instance.
   *
   * @param {engine.Server} engine engine.io (or compatible) server
   * @return {Server} self
   * @api public
   */

  public function bind ($engine){
    $this->engine = $engine;
    $this->engine->on('connection', $this->onconnection.bind(this));
    return $this;
  }

  /**
   * Called with each incoming transport connection.
   *
   * @param {engine.Socket} conn
   * @return {Server} self
   * @api public
   */

  public function onconnection ($conn){
    debug('incoming connection with id %s', conn.id);
    $client = new Client(this, conn);
    client.connect('/');
  }

  /**
   * Looks up a namespace.
   *
   * @param {String|RegExp|Function} name nsp name
   * @param {Function} [fn] optional, nsp `connection` ev handler
   * @api public
   */

  public function of ($name, $fn){
    if (typeof name === 'function' || name instanceof RegExp) {
      const parentNsp = new ParentNamespace(this);
      debug('initializing parent namespace %s', parentNsp.name);
      if (typeof name === 'function') {
        $this->parentNsps.set(name, parentNsp);
      } else {
        $this->parentNsps.set((nsp, conn, next) => next(null, name.test(nsp)), parentNsp);
      }
      if (fn) parentNsp.on('connect', fn);
      return parentNsp;
    }

    if (String($name)[0] !== '/') name = '/' + name;

    $nsp = $this->nsps[$name];
    if (!$nsp) {
      debug('initializing namespace %s', name);
      nsp = new SocketNamespace($this, $name);
      $this->nsps[$name] = $nsp;
    }
    if ($fn) $nsp.on('connect', $fn);
    return $nsp;
  }

  /**
   * Closes server connection
   *
   * @param {Function} [fn] optional, called as `fn([err])` on error OR all conns closed
   * @api public
   */

  public function close ($fn){
    foreach ($this->nsps['/']->sockets as $id ) {
      if ($this->nsps['/'].sockets.hasOwnProperty(id)) {
        $this->nsps['/'].sockets[id].onclose();
      }
    }

    $this->engine.close();

    if ($this->httpServer) {
      $this->httpServer.close(fn);
    } else {
      fn && fn();
    }
  }

  /**
   * Expose main namespace (/).
   */

  $emitterMethods = Object.keys(Emitter.prototype).filter(function(key){
    return typeof Emitter.prototype[key] === 'function';
  });

  emitterMethods.concat(['to', 'in', 'use', 'send', 'write', 'clients', 'compress', 'binary']).forEach(function(fn){
    Server.prototype[fn] (){
      return $this->sockets[fn].apply($this->sockets, arguments);
    };
  });

  Namespace.flags.forEach(function(flag){
    Object.defineProperty(Server.prototype, flag, {
      get: function() {
        $this->sockets.flags = $this->sockets.flags || {};
        $this->sockets.flags[flag] = true;
        return $this;
      }
    });
  });
}