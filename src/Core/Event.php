<?php
namespace Plume\WebSocket\Core;


use Plume\WebSocket\Core\Client;
use Swoole\Websocket\Server as swoole_websocket_server;

class Event{

	protected $app;
    protected $server;
    protected $frame;
    protected $fd;
    protected $host;

    public function __construct($app, swoole_websocket_server $server, $frame, $isClose = false) {
    	$this->app = $app;
        $this->server = $server;
        $this->frame = $frame;
        if($isClose === false){
            $this->fd = $frame->fd;
        }else{
            $this->fd = $frame;
        }
        $this->host = $this->app->getConfig()['server']['master']['host'];
        
    }

    // 注：
    // 1.所有参数均使用对象类型，以便转化为json对象,包含data属性和可选的uid属性
    // 2.返回结果为{"event":"broadcast","data":{"data":"yourdata","uid":"youruid"}}
    // 3.这里的方法都不会回复相关调用终端，需要在具体实现中处理

    //TODO:是否存在广播除自己外的所有在线终端

    // 广播指定终端
    public function broadcast($data) {
        $this->broadcastself($data);
        // 通知其它集群节点
        $slaves_client = $this->app->slaves();
        $this->debug('broadcast - slaves - number', count($slaves_client));
        foreach ($slaves_client as $key => $value) {
            $this->debug('broadcast - slaves', "host index {$key}");
            $sendData = new \stdClass();
            $sendData->url = 'plume/cluster/broadcastself';
            $sendData->data = $data;
            $this->debug('broadcast - slaves - dadta', $sendData);
            $value->send(json_encode($sendData, JSON_UNESCAPED_UNICODE));
        }
    }

    // 广播当前服务器终端
    public function broadcastself($data) {
        $this->debug('broadcastself', 'host is '.$this->host);
        $connections = array();
        if(empty($data->uid)){
            // 获取所有在线终端
            $this->debug('broadcastself', 'uid is empty');
            if (count($this->server->connections) > 0) {
                $connections = $this->server->connections;
            }
        }else{
            $this->debug('broadcastself', 'uid is '.$data->uid);
            // 获取所有在线uid绑定终端
            $key = $data->uid.':'.$host;
            $connections = $redis->lrange($key, 0, -1);
        }
        // 广播终端
        $this->debug('broadcastself', 'broadcast connections');
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->debug('broadcastself - data', $data);
        foreach ($connections as $fd) {
            $this->server->push($fd, $json_data);
        }
        
    }

    //TODO:没有广播指定fd终端的需求，因为fd没有任何意义

    // 通知来源终端
    public function replay($data, $encode = true) {
        $json_data = $data;
        if($encode){
            $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);    
        }
        $this->server->push($this->fd, $json_data);
    }

    public function bind($data){
        $redis = $this->app->provider('redis')->connect();
        $host = $this->app->getConfig()['server']['master']['host'];
        //处理uid和host的bind关系 - 
        if(empty($data->uid)){
            //host,为了区分是否需要对uid以外的fd广播问题
            $redis->rpush($host, $this->fd);
        }else{
            //uid:host->fd1,fd2
            $redis->rpush($data->uid.':'.$host, $this->fd);
        }
    }

    protected function debug($title, $info){
        $this->app->provider('log')->debug($title, $info);
    }

}