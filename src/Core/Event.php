<?php
namespace Plume\WebSocket\Core;


use Plume\WebSocket\Core\Client;
use Plume\Core\ApplicationTrait;
use Plume\Core\Application as App;
use Swoole\Websocket\Server as swoole_websocket_server;

class Event{

    use ApplicationTrait;

    protected $app;

    public function initModule($rootPath, $env){
        $this->app = new App($env);
        $this->app['plume.root.path'] = $rootPath;
    }


	protected $app_server;
    protected $server;
    protected $frame;
    protected $fd;
    protected $host;

    public function __construct($app_server, swoole_websocket_server $server, $frame, $isClose = false) {
    	$this->app_server = $app_server;
        $this->server = $server;
        $this->frame = $frame;
        if($isClose === false){
            $this->fd = $frame->fd;
        }else{
            $this->fd = $frame;
        }
        $this->host = $this->app_server->getConfig()['server_config']['host'];
    }

    // 注：
    // 1.所有参数均使用对象类型，以便转化为json对象,包含data属性和可选的uid属性
    // 2.返回结果为{"event":"broadcast","data":{"data":"yourdata","uid":"youruid"}}
    // 3.这里的方法都不会回复相关调用终端，需要在具体实现中处理

    //TODO:是否存在广播除自己外的所有在线终端

    // 广播指定终端
    public function broadcast($data, $uid = '') {
        $this->broadcastself($data, $uid);
        // 通知其它集群节点
        $slaves_client = $this->app_server->initNodes();
        $this->debug('broadcast - slaves - number', count($slaves_client));
        foreach ($slaves_client as $key => $value) {
            $this->debug('broadcast - slaves', "host index {$key}");
            $sendData = new \stdClass();
            $sendData->url = 'plumeWSService/cluster/notify';
            $sendData->uid = $uid;
            $sendData->data = $data;
            $this->debug('broadcast - slaves - data', $sendData);
            $value->send(json_encode($sendData, JSON_UNESCAPED_UNICODE));
        }
    }

    // 广播当前服务器终端
    public function broadcastself($data, $uid = '') {
        $this->debug('broadcastself', 'host is '.$this->host);
        $connections = array();
        if(empty($uid)){
            // 获取所有在线终端
            $this->debug('broadcastself', 'uid is empty');
            if (count($this->server->connections) > 0) {
                $connections = $this->server->connections;
            }
        }else{
            $this->debug('broadcastself', 'uid is '.$uid);
            $redis = $this->app_server->provider('redis')->connect();
            // 获取所有在线uid绑定终端
            $key = $uid.':'.$this->host;
            $connections = $redis->lrange($key, 0, -1);
        }
        // 广播终端
        $this->debug('broadcastself', 'broadcast connections');
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->debug('broadcastself - data', $data);
        $this->debug('broadcastself - node_fds', $this->app_server->nodeFDs);
        foreach ($connections as $fd) {
            if(isset($this->app_server->nodeFDs[$fd])){
                $this->debug('broadcastself', 'slave client fd is '.$fd);
                continue;
            }
            $this->server->push($fd, $json_data);
        }
    }

    //TODO:没有广播指定fd终端的需求，因为fd没有任何意义

    // 通知来源终端
    public function replay($data, $encode = true) {
        $this->push($this->fd, $data, $encode);
    }

    // 通知指定终端
    public function push($fd, $data, $encode = true) {
        $json_data = $data;
        if($encode){
            $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);    
        }
        $this->server->push($fd, $json_data);
    }

    // 关闭指定终端
    public function closefd($fd){
        $this->server->close($fd);
    }

    //绑定的几种状态:
    //1.无命名空间
    //list => host->fd (获取当前节点的在线用户);
    //key-value => host:fd->value (获取当前节点当地前连接的数据)
    //2.命名空间
    //list => uid:host->fd (获取当前节点的在线用户);
    //key-value => host:fd->value (获取当前节点当地前连接的数据)
    
    public function bind($uid = '', $value = ''){
        $redis = $this->app_server->provider('redis')->connect();
        $host = $this->app_server->getConfig()['server_config']['host'];
        if(empty($uid)){
            $redis->rpush($host, $this->fd);
            $redis->set($host.':'.$this->fd, $value);
        }else{
            $redis->rpush($uid.':'.$host, $this->fd);
            $redis->set($host.':'.$this->fd, $value);
        }
    }

    public function getBindValue(){
        $redis = $this->app_server->provider('redis')->connect();
        $host = $this->app_server->getConfig()['server_config']['host'];
        $value = $redis->get($host.':'.$this->fd);
        return $value;
    }

    protected function debug($title, $info){
        $this->app_server->provider('log')->debug($title, $info);
    }

}