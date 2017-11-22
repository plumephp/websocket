<?php

namespace PlumeWSService;

use Plume\WebSocket\Core\Event;

class Heartbeat extends Event{

	//浏览器客户端心跳检测方法
	public function ping($data){
		$ret = new \stdClass();
		$ret->event = 'plume_pong';
		$ret->data = $data;
		$this->replay($ret);
	}

}