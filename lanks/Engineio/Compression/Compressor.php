<?php

namespace Lanks\Engineio\Compression;

use Evenement\EventEmitter;
use Clue\React\Zlib\ZlibFilterStream;
use React\Stream\WritableStreamInterface;


class Compressor  extends EventEmitter implements WritableStreamInterface
{
    protected $enconding;
    public function __construct(Type $enconding = null) {
        $this->enconding = strtolower($enconding);
    }

    public function end($data)
    {
        if(empty($data)){
            $this->emit('error','ivalid data to compress',$data);
            return ;
        }

        switch ($this->enconding) {
            case 'gzip':{
                $encoded = gzencode($data,9);
                $this->emit('data', $encoded);
                $this->emit('end','',$encoded);
                return ;
            }
            case 'deflate':{
                $encoded = gzdeflate($data,9);
                $this->emit('data', $encoded);
                $this->emit('end','',$encoded);
                return ;
            }
            default:
                $this->emit('error', 'unsupported compression encoding',$this->enconding);
            break;
        }   
    }
}