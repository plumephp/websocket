<?php

namespace Plume;

use Plume\WebSocket\Core\Event;

class Cluster extends Event{

	//{"url":"plume/cluster/ping","data":"ping"}
	public function ping($data){
		$this->replay($data.'pong');
	}

	public function bind($uid){

	}

	//校验集群节点发送的集群信息 - {"url":"plume/cluster/broadcast","data":{"data":"yourdata","uid":"youruid"}}
	public function broadcast($data){
		$data = json_decode($data);
		if(empty($data)){
			throw new HandlerException("plume/cluster/broadcast nothing to broadcast");
		}
		//全网广播
		if(empty($data->uid)){

		}else{
			//根据uid广播
		}



    	if($msg->url !== 'cluster'){
    		return;
    	}
    	//处理集群通知消息,这里不允许执行业务逻辑，仅是消息转发
    	$data = $msg->data;
    	$host = $this->app->getConfig()['server']['master']['host'];
    	//bind关系 - uid:host => fd1,fd2
    	if(empty($msg->uid)){
    		$key = $host;
    	}else{
    		$key = $msg->uid.':'.$host;
    	}
    	$redis = $this->app->provider('redis')->connect();
        $fds = $redis->lrange($key, 0, -1);
        $this->others($fds, $data);
        //转发完毕后回复集群节点
        throw new HandlerException("replay handleCluster from {$key}");
	}

}