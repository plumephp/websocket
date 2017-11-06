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
    * @param string $data server json message like {"url":"plumeWSService/cluster/notify","data":{"uid":"000001"}}
    */
	public function notify($data){
		if(!isset($data['data'])){
			$this->debug('notify', 'data is null');
			return;
		}
		$this->debug('notify - data', $data);
		if(isset($data['uid']) && !empty($data['uid'])){
			$this->broadcastself($data, $data['uid']);
		}else{
			$this->broadcastself($data);
		}
	}

}