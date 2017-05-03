<?php

namespace PlumeWSService;

use Plume\WebSocket\Core\Event;
use Plume\WebSocket\Handler\HandlerException;

class Cluster extends Event{

	//{"url":"plumeWSService/cluster/ping","data":"ping"}
	public function ping($data){
		$this->replay($data.'pong', false);
	}

	public function notify($data){
		$msg = json_decode($data, true);
		if(!isset($msg['data'])){
			$this->debug('notify', 'data is null');
			return;
		}
		$this->debug('notify - data', $msg);
		if(isset($msg['uid']) && !empty($msg['uid'])){
			$this->broadcastself($msg['data'], $msg['uid']);
		}else{
			$this->broadcastself($msg['data']);
		}
	}

}