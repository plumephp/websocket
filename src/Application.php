<?php

namespace Plume\WebSocket;

use Swoole\Websocket\Server;

class Application{

	public function run(){
		$serv = new Server("127.0.0.1", 9501);

		$serv->on('Open', function($server, $req) {
		    echo "connection open: ".$req->fd;
		});

		$serv->on('Message', function($server, $frame) {
		    echo "message: ".$frame->data;
		    $server->push($frame->fd, json_encode(["hello", "world"]));
		});

		$serv->on('Close', function($server, $fd) {
		    echo "connection close: ".$fd;
		});

		$serv->start();


		$server = new swoole_websocket_server("0.0.0.0", 9501);

	}

}






