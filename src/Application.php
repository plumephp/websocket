<?php

/*
* websocket application.
*
* @author zhangbaitong <https://github.com/zhangbaitong>
*/

namespace Plume\WebSocket;

use Plume\Core\Application as App;
use Plume\WebSocket\Core\Client;
use Plume\WebSocket\Handler\MessageHandler;
use Plume\WebSocket\Handler\CloseHandler;
use Plume\WebSocket\Handler\HandshakeHandler;
use Plume\WebSocket\Handler\HandlerException;
use Swoole\Websocket\Server as swoole_websocket_server;
use Swoole\Http\Request as swoole_http_request;
use Swoole\Http\response as swoole_http_response;

/**
 * WebSocket服务器应用
 * 1.服务器端HTTP获取集群节点信息
 * 2.集群间广播通信
 */
class Application extends App{

	public $host = '127.0.0.1';
    public $allowIP = '0.0.0.0';
	public $port = '9501';
	private $workerNum = 1;
	private $nodes = array();
	private $nodeClients = array();
	public $nodeFDs = array();

    public function __construct($env = 'dev') {
        $this['plume.env'] = $env;
        $this['plume.root.path'] = __DIR__.'/../../../../';
    }

    /**
     * 初始化集群节点链接客户端
     * 1.初始化未初始化的客户端
     * 2.检查已有客户端是否可用
     * @return array 集群节点的客户端链接集合
     */
    public function initNodes(){
		//init cluster node clients
		foreach ($this->nodes as $key => $value) {
			$host = $value['host'];
			$port = $value['port'];
			$isConnected = false;
			if(isset($this->nodeClients[$host])){
				$client_exist = $this->nodeClients[$host];
				$result = $client_exist->send('ping');
				//TODO:如果发现内存占用比较大，则设置缓冲区大小
				$client_exist->recv();
				$this->debug('ping cluster node', $result);
				if($result > 0){
					$this->debug('ping cluster node', 'old connection is ok');
					$isConnected = true;
				}else{
					$client_exist->disconnect();
					unset($this->nodeClients[$host]);
				}
			}
			if($isConnected == true){
				$this->debug('ping cluster node', "connect cluster node {$value['host']} is ok");
				continue;
			}else{
				//TODO:如果host是一个无法ping通的IP则会阻塞
				$client = new Client($host, $port);
	            if (!$client->connect()) {
	            	$this->debug('init cluster node client', "connect cluster node {$host} {$port} faild");
	            	//连接失败后函数结束会自动触发析构函数进行释放
	            }else{
	                $this->nodeClients[$host] = $client;
	                $this->debug('init cluster node client', "connect cluster node {$host} {$port} successed");
	            }
			}
        }
        $nodes_length = count($this->nodeClients);
        $this->debug('init nodes ', "init {$nodes_length} cluster node clients");
        return $this->nodeClients;
    }

    /**
     * 初始化服务器信息
     * 1.初始化WebSocket服务
     * 2.获取集群节点
     * 3.清空之前的绑定信息
     * @return swoole_websocket_server
     */
    private function initServer(){
    	$config = $this->getConfig();
    	//local server config
    	$server = $config['server_config'];
        $this->host = $server['host'];
        $this->port = $server['port'];
        $this->allowIP = $server['allow_ip'];
        $this->workerNum = $config['swoole_config']['worker_num'];
        //cluster nodes config
    	$this->nodes = $config['cluster_nodes'];
        //clear bind fd
    	$redis = $this->provider('redis')->connect();//不能直接使用$this->provider('redis')->useLongConnect()，会递归
		$redis->set('plume__ping' , 'pong');
        $keyList = $redis->keys("*{$this->host}*");
        foreach ($keyList as $key => $value) {
        	$redis->del($value);
        }
        //init server
		$server = new swoole_websocket_server($this->allowIP, $this->port, SWOOLE_PROCESS);
		$server->set($config['swoole_config']);
		return $server;
    }

    /**
     * WebSocekt服务器端启动
     * 1.初始化服务器信息
     * 2.注册关键事件处理逻辑
     */
	public function run(){
        //默认关闭警告信息
		//if($this['plume.env'] != 'dev'){
        //error_reporting(0);
        //}
        //error_reporting(E_ERROR|E_PARSE);
		$server = $this->initServer();
		//start server process
		$server->on('WorkerStart', function ($serv, $worker_id){
			$this->debug('WorkerStart', 'server process start success');
            // if($worker_id >= $this->workerNum) {
            //     swoole_set_process_name("php websocket task worker");
            // } else {
            //     swoole_set_process_name("php websocket event worker");
            // }
		});

		//open event behind connected
		$server->on('open',function (swoole_websocket_server $_server , swoole_http_request $request) {
			$this->debug('open', 'one client has connected');
			$header = $request->header;
			if(isset($header['user-agent']) && ($header['user-agent'] === 'cluster_client')){
				$this->nodeFDs[$request->fd] = $request->fd;
			}
		});

		//execute received message
		$server->on('message', function (swoole_websocket_server $_server , $frame) {
			$this->debug('message', 'fd:'.$frame->fd);
			$this->debug('message', 'data:'.$frame->data);
            try {
            	//TODO:need to impl singleton?
                $messageHandler = new MessageHandler($this, $_server, $frame);
                $messageHandler->handle();
            } catch (Exception $e) {
                $handlerException = new HandlerException('exception :'.$e->getMessage());
                $this->debug('message', $handlerException->getMessage());
                //TODO:对于恶意扫描和攻击请求，建议直接关闭
                $_server->push($frame->fd, json_encode($handlerException->obj(), JSON_UNESCAPED_UNICODE));
            }
        });

		//client closed
		$server->on('close', function (swoole_websocket_server $_server , $fd) {
			$this->debug('close', 'fd:'.$fd);
		    try {
                $closeHandler = new CloseHandler($this, $_server, $fd);
                $closeHandler->handle();
            } catch (Exception $e) {
                $this->debug('close', 'exception :'.$e->getMessage());
            }
		});

		//http request
		$server->on('request', function (swoole_http_request $request, swoole_http_response $response) {
			$this->debug('request', 'it is a http request');
			// var_dump($request);
			//$server = $request->server;
			//if(isset($server['request_uri']) && (strpos($server['request_uri'], 'wslist') > 0)){
				//JSON support
            //$config = $this->getConfig();
                //ws list config
                //array('ws://127.0.0.1:9501', 'ws://localhost:9502')
            //    $wsList = $config['ws_list'];
			//	$wsListJson = json_encode($wsList);
			//	$response->end("success({$wsListJson});");
			//}else{
				$response->end("<h1>This is Swoole WebSocket Server</h1>");
                //}
		});

		// $server->on('handshake', function(swoole_http_request $request, swoole_http_response $response){
		// 	$this->debug('handshake', 'handshake method');
		//     try {
  //               $handshakeHandler = new HandshakeHandler($this, $request, $response);
  //               return $handshakeHandler->handle();
  //           } catch (Exception $e) {
  //               $this->debug('handshake', 'exception :'.$e->getMessage());
  //           }
		// 	return true;
		// });
		echo "server listen on {$this->host}:{$this->port} allow ip is {$this->allowIP}".PHP_EOL;
		$server->start();
	}

    /**
     * 服务器端记录调试信息
     * @param string $title 日志标题
     * @param mixed $info 日志数据 string|array
     */
	private function debug($title, $info){
		$this->provider('log')->debug($title, $info);
	}

}
