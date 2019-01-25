<?php

namespace Lanks\Engineio\Parser;

class Parser
{
    private static $error = ['type'=>'error','data'=>'parser error'];

    /**
     * Packet type `connect`.
     *
     * @api public
     */

    public const CONNECT = 0;

    /**
     * Packet type `disconnect`.
     *
     * @api public
     */

    public const DISCONNECT = 1;

    /**
     * Packet type `event`.
     *
     * @api public
     */

    public const EVENT = 2;

    /**
     * Packet type `ack`.
     *
     * @api public
     */

    public const ACK = 3;

    /**
     * Packet type `error`.
     *
     * @api public
     */

    public const ERROR = 4;

    /**
     * Packet type 'binary event'
     *
     * @api public
     */

    public const BINARY_EVENT = 5;

    /**
     * Packet type `binary ack`. For acks with binary arguments.
     *
     * @api public
     */

    public const BINARY_ACK = 6;

    /**
     * Convert a array or object to stdclass instance
     */
    private static function toStdClass($data){
        return \json_decode(\json_encode($data));
    }

    /**
     * Encodes a packet.
     *
     *     <packet type id> [ <data> ]
     *
     * Example:
     *
     *     5hello world
     *     3
     *     4
     *
     * Binary is encoded in an identical principle
     *
     * @api private
     */
    public static function encodePacket($packet, $supportBinary, $utf8encode, Closure $callback=null)
    {
        $encoded = ''.$packet->type;
        if(empty($packet->data))
            return $callback($encoded);

        if (is_callable($supportsBinary)) {
            $callback = $supportsBinary;
            $supportsBinary = false;
        }
        
        if (is_callable($utf8encode)) {
            $callback = $utf8encode;
            $utf8encode = null;
        }
        
        if(is_string($packet->data)){
            $encoded .= $packet->data;
        } else if(\is_object($packet->data) || \is_array($packet->data)){

            if (isset($packet->data['base64']) || isset($packet->data->base64)) {
                return self::encodeBase64($packet, $callback);
            }

            $encode = json_encode($packet->data);
        }
      
        return $callback($encoded);
    }

    /**
     * Encodes a packet with binary data in a base64 string
     *
     * @param {Object} packet, has `type` and `data`
     * @return {String} base64 encoded message
     */
    public static function encodeBase64($packet,Closure $callback) {
        // packet data is an object { base64: true, data: dataAsBase64String }
        $message = 'b'.$packet->type.base64_encode(json_encode($packet->data));
        return callback($message);
    }
      
    /**
     * Decodes a packet. Changes format to Blob if requested.
     *
     * @return {Object} with `type` and `data` (if any)
     * @api private
     */

     public static function decodePacket($data, $binaryType=false, $utf8decode)
     {
        if (!isset($data)) {
            return self::$err;
        }
          // String data
        if (is_string($data)) {
            $type = intval( substr($data,0,1));
            if ($type === 'b') {
              return self::decodeBase64Packet(substr($data,1), $binaryType);
            }
        
            if ($utf8decode) {
              $data = \json_decode($data);
            }
            
            if (strlen($data) > 1) {
                return self::toStdClass(['type'=> $type, 'data'=>substr($data,1)]);
            } else {
                return self::toStdClass(['type'=> $type]);
            }
        }

        return self::toStdClass(['type'=> $type,  'data'=>$data]);
     }

    /**
     * Decodes a packet encoded in a base64 string
     *
     * @param {String} base64 encoded message
     * @return {Object} with `type` and `data` (if any)
     */

    public static function decodeBase64Packet($msg, $binaryType) {
        $type = intval(\substr($msg,0,1));
        $data = base64_decode(\substr($msg,1));
        return self::toStdClass(['type'=>$type, 'data'=>$data]);
    }

    /**
     * Encodes multiple messages (payload).
     *
     *     <length>:data
     *
     * Example:
     *
     *     11:hello world2:hi
     *
     * If any contents are binary, they will be encoded as base64 strings. Base64
     * encoded strings are marked with a b before the length specifier
     *
     * @param {Array} packets
     * @api private
     */
    public static function encodePayload($packets, $supportsBinary, $callback)
    {
        if ( count(!$packets) ==0) {
          return $callback('0:');
        }

        if (is_callable($supportsBinary)) {
            $callback = $supportsBinary;
            $supportsBinary = null;
        }
        $message ='';
        foreach ($packets as $packet) {
            $encoded = self::encodePacket($packet,$supportBinary, null,function ($message) {  return $message; } );
            $message = strlen($encoded) . ':' . $encoded;
        }
        $callback($message);
    }

    /*
    * Decodes data when a payload is maybe expected. Possible binary contents are
    * decoded from their base64 representation
    *
    * @param {String} data, callback method
    * @api public
    */
    public function decodePayload($data, $binaryType, $callback)
    {
        $packets =[];
        $totalLen = strlen($data);
        $pos = 0;
        if($binaryType){
            //decode binary payload
            //return self::decodePayloadBinary($data,$callback);
            $packets[]= self::toStdClass(['type'=>'error','data'=>'Binary is not supported']); 
        }else{
            while ($pos <$totalLen) {
                $len = substr($data,$pos,1);
                $pos;
                $encoded = \substr($data,$pos,$len);
                $pos+= $len; 
                $packet[] = self::decodePacket($encoded,$binaryType,null);
            }
        }

        return $packets;
        
    }
}