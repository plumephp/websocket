<?php

namespace Plume;

use Plume\WebSocket\Core\Event;
use Plume\WebSocket\Handler\HandlerException;

class Cluster extends Event{

	//{"url":"plume/cluster/ping","data":"ping"}
	public function ping($data){
		$this->replay($data.'pong', false);
	}

	public function bind($uid){

	}

	//校验集群节点发送的集群信息 - {"url":"plume/cluster/broadcast","data":{"data":"yourdata","uid":"youruid"}}
	//TODO:提供对外访问的url接口
	public function broadcast($data){
	}

}