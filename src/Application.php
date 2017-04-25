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

class Application extends App{

	public $host = '0.0.0.0';
	public $port = '9501';
	private $workerNum = 1;
	private $slaves = array();
	private $slaves_client = array();
	public $slaves_fd = array();

    public function __construct($env = 'dev') {
        $this['plume.env'] = $env;
        $this['plume.root.path'] = __DIR__.'/../../../../';
    }

    public function slaves(){
		//init slaves_client
		foreach ($this->slaves as $key => $value) {
			$host = $value['host'];
			$port = $value['port'];
			$isConnected = false;
			if(isset($this->slaves_client[$host])){
				$client_exist = $this->slaves_client[$host];
				$result = $client_exist->send('ping');
				//TODO:如果发现内存占用比较大，则设置缓冲区大小
				$client_exist->recv();
				$this->debug('slaves - ping', $result);
				if($result > 0){
					$this->debug('slaves - ping', 'old connection is ok');
					$isConnected = true;
				}else{
					$client_exist->disconnect();
					unset($this->slaves_client[$host]);
				}
			}
			if($isConnected == true){
				$this->debug('slaves', "connect cluster node {$value['host']} is ok");
				continue;
			}else{
				//TODO:如果host是一个无法ping通的IP则会阻塞
				$client = new Client($host, $port);
	            if (!$client->connect()) {
	            	$this->debug('slaves', "connect cluster node {$value['host']} faild");
	            	//连接失败后函数结束会自动触发析构函数进行释放
	            }else{
	                $this->slaves_client[$host] = $client;
	                $this->debug('slaves', "connect cluster node {$value['host']} successed");
	            }	
			}
        }
        $slaves_length = count($this->slaves_client);
        $this->debug('slaves', "init {$slaves_length} slaves");
        return $this->slaves_client;
    }

    private function server(){
    	$config = $this->getConfig();
    	//master config
    	$master = $config['server']['master'];
        $this->host = $master['host'];
        $this->port = $master['port'];
        $this->workerNum = $config['config']['worker_num'];
        //slaves config
    	$this->slaves = $config['server']['slaves'];
    	//clear bind fd
    	$redis = $this->provider('redis')->connect();
        $keyList = $redis->keys("*{$this->host}*");
        foreach ($keyList as $key => $value) {
        	$redis->del($value);
        }
        //init server
		$server = new swoole_websocket_server($this->host, $this->port, SWOOLE_PROCESS);
		$server->set($config['config']);
		return $server;
    }

	public function run(){
		if($this['plume.env'] != 'dev'){
			error_reporting(0);
		}
		$server = $this->server();

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
				$this->slaves_fd[$request->fd] = $request->fd;
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
			//过滤slave client socket的fd
			if(isset($this->slaves_fd[$fd])){
				return;
			}
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
			$server = $request->server;
			if(isset($server['request_uri']) && (strpos($server['request_uri'], 'wslist') > 0)){
				//JSON support
				 $wslist = array('ws://127.0.0.1:9501', 'ws://localhost:9502');
				// $response->end(json_encode($wslist));
				//JSONP support
				// $wslist = array(
				// 	'wslist' => 'test',
				// 	'callback' => 'success'
				// 	);
				$wslistJson = json_encode($wslist);
				$response->end("success({$wslistJson});");
				// $response->end('{wslist:"test",callback:"success"}');
			}else{
				$response->end("<h1>This is Swoole WebSocket Server</h1>");
			}
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
		echo "server listen on {$this->host}:{$this->port}";
		$server->start();
	}

	private function debug($title, $info){
		$this->provider('log')->debug($title, $info);
	}

}