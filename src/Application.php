<?php

/*
* websocket application.
*
* @author zhangbaitong <https://github.com/zhangbaitong>
*/

namespace Plume\WebSocket;

use Plume\Provider\ProviderTrait;
use Plume\Core\ArrayTrait;
use Plume\Core\ConfigTrait;
use Plume\WebSocket\Core\ContextTrait;
use Plume\WebSocket\Handler\InitHandler;
use Plume\WebSocket\Handler\MessageHandler;
use Plume\WebSocket\Handler\CloseHandler;
use Plume\WebSocket\Handler\HandlerException;

use Swoole\Websocket\Server as swoole_websocket_server;
use Swoole\Http\Request as swoole_http_request;
use Swoole\Http\response as swoole_http_response;



class Application implements \ArrayAccess{

    use ProviderTrait;
    use ArrayTrait;
    use ConfigTrait;
	use ContextTrait;

    public function __construct($env = 'dev') {
        $this['plume.env'] = $env;
        $this['plume.root.path'] = __DIR__.'/../../../../';
    }


	public function run(){

        $initHandler = new InitHandler($this);
		$server = $initHandler->server();

		//start server process
		$server->on('WorkerStart', function ($serv, $worker_id){
			$this->debug('WorkerStart', 'server process start success');
		});

		//open event behind connected
		$server->on('open',function (swoole_websocket_server $_server , swoole_http_request $request) {
			$this->debug('open', 'one client has connected');
		});

		//execute received message
		$server->on('message', function (swoole_websocket_server $_server , $frame) {
			$this->debug('message', 'fd:'.$frame->fd);
			$this->debug('message', 'data:'.$frame->data);
            try {
                $messageHandler = new MessageHandler($this, $_server, $frame);
                $messageHandler->handle();
            } catch (Exception $e) {
                $handlerException = new HandlerException('exception :'.$e->getMessage());
                $this->debug('message', $handlerException->getMessage());
                $_server->push($frame->fd, json_encode($handlerException->obj(), JSON_UNESCAPED_UNICODE));
            }
        });

		//client closed
		$server->on('close', function (swoole_websocket_server $_server , $fd) {
			$this->debug('close', 'fd:'.$fd);
		    try {
                $closeHandler = new CloseHandler($this, $_server, $fd);
                $closeHandler->handle();
            } catch (Exception $e) {
                $this->debug('close', 'exception :'.$e->getMessage());
            }
		});

		//http request
		$server->on('request', function (swoole_http_request $request, swoole_http_response $response) {
			$this->debug('request', 'it is a http request');
		    $response->end("<h1>This is Swoole WebSocket Server</h1>");
		});

		$server->start();
	}

	private function debug($title, $info){
		$this->provider('log')->debug($title, $info);
	}

}





