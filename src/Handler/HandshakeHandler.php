<?php

namespace Plume\WebSocket\Handler;

use Plume\WebSocket\Handler\HandlerException;

class HandshakeHandler{

	protected $request;
	protected $response;
	protected $app;

	public function __construct($app, $request, $response) {
    	$this->app = $app;
    	$this->request = $request;
    	$this->response = $response;
    }

    public function handle(){
		//自定义握手规则，没有设置则用系统内置的（只支持version:13的）
        if (!isset($this->request->header['sec-websocket-key'])){
            //'Bad protocol implementation: it is not RFC6455.'
            $this->response->end();
            return false;
        }
        if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $this->request->header['sec-websocket-key'])
            || 16 !== strlen(base64_decode($this->request->header['sec-websocket-key']))
        ){
            //Header Sec-WebSocket-Key is illegal;
            $this->response->end();
            return false;
        }
        $key = base64_encode(sha1($this->request->header['sec-websocket-key']
            . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true));
        $headers = array(
            'Upgrade'               => 'websocket',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Accept'  => $key,
            'Sec-WebSocket-Version' => '13',
            'KeepAlive'             => 'off',

        );
        foreach ($headers as $key => $val){
            $this->response->header($key, $val);
        }
        $this->response->status(101);
        $this->response->end();
        return true;
    }
}