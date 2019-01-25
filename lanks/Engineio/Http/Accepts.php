<?php

namespace Lanks\Engineio\Http;

class Accepts
{
    protected $req;
    protected $headers;
    public function __construct(Request $req) {
        $this->headers = $req->getHeaders();
    }

    public function enconding(array $terms): bool
    {
        $encodings = $terms;

        //Content-Encoding' may not exists]
        if(!isset($this->headers['Accept-Encoding'])){
            return false;
        }
        // support flattened arguments
        if (!empty($encodings) && !is_array($encodings)) {
            $args = func_get_args();
            $encodings = [];
            foreach ($args as $key => $value) {
                $encodings[] = $value;
            }
        }
      
        // no encodings, return all requested encodings
        if (empty($encodings)) {
          return $this->headers['Accept-Encoding'];
        }
      
        $resquestEnconding = explode(',',$this->headers['Accept-Encoding']);
        foreach ($resquestEnconding as $key => $value) {
            $resquestEnconding[$key] = trim($value);
        }
        foreach ($econdings as $key => $value) {
            if(in_array($value ,$resquestEnconding)){
                return $value;
            }
        }
        return false;
    }
}