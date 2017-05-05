<?php

namespace PlumeWSService;

use Plume\WebSocket\Core\Event;
use Plume\WebSocket\Handler\HandlerException;

/**
 * WebSocket服务端集群节点之间交互逻辑实现类
 */
class Cluster extends Event{

    /**
     * ping->pong 实现.
     *
     * @param string $data client json message like {"url":"plumeWSService/cluster/ping","data":"ping"}
     */
    public function ping($data){
		$this->replay($data.'pong', false);
	}

   /**
    * 集群节点通知其他节点后的逻辑实现
    *
    * @param string $data server json message like {"url":"plumeWSService/cluster/notify","data":"ping","uid":"000001"}
    */
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