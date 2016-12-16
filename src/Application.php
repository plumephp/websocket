<?php

/*
* websocket application.
*
* @author zhangbaitong <https://github.com/zhangbaitong>
*/

namespace Plume\WebSocket;

use Plume\Provider\ProviderTrait;
use Plume\Core\ArrayTrait;
use Plume\Core\ConfigTrait;
use Plume\WebSocket\Core\ContextTrait;
use Plume\WebSocket\Core\Client;
use Plume\WebSocket\Handler\MessageHandler;
use Plume\WebSocket\Handler\CloseHandler;
use Plume\WebSocket\Handler\HandlerException;
use Swoole\Websocket\Server as swoole_websocket_server;
use Swoole\Http\Request as swoole_http_request;
use Swoole\Http\response as swoole_http_response;

class Application implements \ArrayAccess{

    use ProviderTrait;
    use ArrayTrait;
    use ConfigTrait;
	use ContextTrait;

	public $host = '127.0.0.1';
	public $port = '9501';
	private $slaves = array();
	private $slaves_client = array();

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
		error_reporting(0);
		$server = $this->server();

		//start server process
		$server->on('WorkerStart', function ($serv, $worker_id){
			$this->debug('WorkerStart', 'server process start success');
		});

		//open event behind connected
		$server->on('open',function (swoole_websocket_server $_server , swoole_http_request $request) {
			$this->debug('open', 'one client has connected');
		});

		//execute received message
		$server->on('message', function (swoole_websocket_server $_server , $frame) {
			$this->debug('message', 'fd:'.$frame->fd);
			$this->debug('message', 'data:'.$frame->data);
            try {
                $messageHandler = new MessageHandler($this, $_server, $frame);
                $messageHandler->handle();
            } catch (Exception $e) {
                $handlerException = new HandlerException('exception :'.$e->getMessage());
                $this->debug('message', $handlerException->getMessage());
                $_server->push($frame->fd, json_encode($handlerException->obj(), JSON_UNESCAPED_UNICODE));
            }
        });

		//client closed
		$server->on('close', function (swoole_websocket_server $_server , $fd) {
			//TODO:过滤slave socket的fd，但不通过异步socket貌似无法获得fd
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
		    $response->end("<h1>This is Swoole WebSocket Server</h1>");
		});
		echo "server listen on {$this->host}:{$this->port}";
		$server->start();
	}

	private function debug($title, $info){
		$this->provider('log')->debug($title, $info);
	}

}