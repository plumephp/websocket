<?php
namespace Plume\WebSocket\Core;

use Plume\WebSocket\Core\Client;
use Plume\Core\ApplicationTrait;
use Plume\Core\Application as App;
use Swoole\Websocket\Server as swoole_websocket_server;

/**
 * WebSocket客户端通信事件基类
 */
class Event{

    use ApplicationTrait;

    /**
     * 业务模块应用上下文
     * @var Plume\Core\Application
     */
    protected $app;

    /**
     * 业务模块初始化
     * @param string $rootPath 业务模块的根目录
     * @param string $env 业务模块运行的环境 dev|test|pro
     *
     */
    public function initModule($rootPath, $env){
        $this->app = new App($env);
        $this->app['plume.root.path'] = $rootPath;
    }

    /**
     * WebSocket服务端应用上下文
     * @var Plume\Core\Application
     */
	protected $app_server;

    protected $server;
    protected $frame;
    protected $fd;

    /**
     * WebSocekt服务端本地IP
     * @var string
     */
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
    // 2.返回结果为{"event":"broadcast","data":{"data":"yourdata","gid":"yourgid"}}
    // 3.这里的方法都不会回复相关调用终端，需要在具体实现中处理

    //TODO:是否存在广播除自己外的所有在线终端
    //TODO:是否需要查找fd,并通知集群中指定分组的fd

    /**
     * 全集群广播事件。对指定数据和分组进行全集群广播。
     * @param \stdClass $data 广播数据
     * @param string $groupID 分组标识
     */
    public function broadcast($data, $groupID = '') {
        $this->broadcastself($data, $groupID);
        // 通知其它集群节点
        $nodeClients = $this->app_server->initNodes();
        $this->debug('broadcast nodes number', count($nodeClients));
        foreach ($nodeClients as $key => $nodeClient) {
            $this->debug('broadcast - notify node info', "host is {$key}");
            $notifyData = new \stdClass();
            $notifyData->url = 'plumeWSService/cluster/notify';
	        $data = json_encode($data);
	        $data = json_decode($data, true);
            $data['uid'] = $groupID;
            $notifyData->data = $data;
            $this->debug('broadcast - notify data', $notifyData);
            $nodeClient->send(json_encode($notifyData, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 当前集群节点广播事件。对指定数据和分组对当前集群节点进行广播。
     * @param \stdClass $data 广播数据
     * @param string $groupID 分组标识
     */
    public function broadcastself($data, $groupID = '') {
        $this->debug('broadcastself', 'host is '.$this->host);
        $connections = array();
        if(empty($groupID)){
            // 获取所有无分组标识在线终端
            $this->debug('broadcastself', 'groupID is empty');
            $onlines = count($this->server->connections);
            $this->debug('broadcastself', 'connection number is '.$onlines);
            if ($onlines > 0) {
                $connections = $this->server->connections;
            }
        }else{
            // 获取所有分组标识在线终端 key = groupID:host
            $this->debug('broadcastself', 'groupID is '.$groupID);
	        $key = $groupID . ':' . $this->host;
	        try {
		        $redis = $this->app_server->provider('redis')->connect();
		        $connections = $redis->lrange($key, 0, -1);
		        if (!is_array($connections)) {//redis异常
			        $this->app_server->provider('log')->log('exception', 'lrange exception , key(' . $key . ')', $data);
			        return;
		        }
	        } catch (\Exception $e) {
		        $this->app_server->provider('log')->log('exception', 'redis exception , key(' . $key . ')',
			        array('data' => $data, 'err_msg' => $e->getMessage()));
		        return;
	        }
        }
        // 广播获取的在线终端
        $this->debug('broadcastself', 'broadcast connections');
        $this->debug('broadcastself - data', $data);
        $this->debug('broadcastself - node_fds', $this->app_server->nodeFDs);
        foreach ($connections as $fd) {
            if(isset($this->app_server->nodeFDs[$fd])){
                $this->debug('broadcastself', 'nodeClient fd is '.$fd);
                continue;
            }
            $this->push($fd, $data);
        }
    }

    /**
     * 回复当前链接。
     * @param \stdClass $data 回复数据
     * @param bool $encode 如果$data是string格式，则encode为false
     */
    public function replay($data, $encode = true) {
        $this->push($this->fd, $data, $encode);
    }

    /**
     * 回复指定链接。
     * @param $fd string 服务器自动生成的整型自增链接ID
     * @param \stdClass $data 回复数据
     * @param bool $encode 如果$data是string格式，则encode为false
     */
    public function push($fd, $data, $encode = true) {
        $json_data = $data;
        if($encode){
            $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $this->debug('push - data', $json_data);
        $this->server->push($fd, $json_data);
    }

    /**
     * 关闭指定链接。
     * @param $fd string 服务器自动生成的整型自增链接ID
     */
    public function closefd($fd){
        $this->server->close($fd);
    }

    /**
     * 当前链接的绑定事件。
     * 绑定的几种状态:
     * 1.无分组
     * host -> fds (获取当前节点的在线链接列表)
     * host:fd -> value (获取当前节点当前链接的绑定数据)
     * host:fd:group -> host (获取当前节点当前链接的绑定分组)
     * 2.有分组
     * groupID:host -> fds
     * host:fd -> value
     * host:fd:group -> groupID:host
     * @param string $groupID 需要绑定当前链接所在分组的分组标识，如果为空则使用默认分组
     * @param string $value 需要绑定当前链接对应的业务数据,如果为空则不绑定
     */
    public function bind($groupID = '', $value = ''){
        $redis = $this->app_server->provider('redis')->connect();
        $host = $this->app_server->getConfig()['server_config']['host'];
        // 绑定分组和当前链接对应的分组标识
        if(empty($groupID)){
            $redis->rpush($host, $this->fd);
            $redis->set($host.':'.$this->fd.':group', $host);
        }else{
            $redis->rpush($groupID.':'.$host, $this->fd);
            $redis->set($host.':'.$this->fd.':group', $groupID.':'.$host);
        }
        // 绑定业务数据
        if(!empty($value)){
            $redis->set($host.':'.$this->fd, $value);
        }
    }

    /**
     * 获取当前链接的绑定数据或者返回分组内所有的绑定数据
     *
     * @return string
     */
    public function getBindValue(string $groupID = ''){
        $redis = $this->app_server->provider('redis')->connect();
        $host = $this->app_server->getConfig()['server_config']['host'];
        if(empty($groupID)){
            return $redis->get($host.':'.$this->fd);
        }else{
            $fds = $redis->lRange($groupID.':'.$host, 0, -1);
            $values = array();
            foreach($fds as $fd){
                $values[] = $redis->get($host.':'.$fd);
            }
            return $values;
        }
    }

    public function status(){
        $this->debug('Plume WebSocket Server', '----------status----------');
        $this->debug('status', array(
            'fd' => $this->fd,
            'node fds' => $this->app_server->nodeFDs,
            'bind value' => $this->getBindValue(),
            'connection nums' => count($this->server->connections),
            'connections' => $this->server->connections,
            //'' => '',
        ));
        $this->debug('Plume WebSocket Server', '----------status----------');
    }

    /**
     * 获取指定分组在线数
     *
     * @param string $groupId
     *
     * @return int online client numbers
     */
    public function countByGroup(string $groupID) :int {
        $redis = $this->app_server->provider('redis')->connect();
        $host = $this->app_server->getConfig()['server_config']['host'];
        if(empty($groupID)){
            return $redis->lLen($host);
        }else{
            return $redis->lLen($groupID.':'.$host);
        }
    }

    /**
     * 记录调试日志
     * @param string $title 日志内容标题
     * @param mixed $info 日志数据 string|array
     */
    protected function debug($title, $info){
        $this->app_server->provider('log')->debug($title, $info);
    }

}