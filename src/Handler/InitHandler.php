<?php

namespace Plume\WebSocket\Handler;

use Swoole\Websocket\Server as swoole_websocket_server;

class InitHandler{

	protected $app;

	public function __construct($app) {
    	$this->app = $app;
    }


	public function server(){
		$config = $this->app->getConfig();
		$master = $config['server']['master'];
        $host = $master['host'];
        $port = $master['port'];
        //清空之前绑定的fd
        $redis = $this->app->provider('redis')->connect();
        $keyList = $redis->keys("*{$host}*");
        foreach ($keyList as $key => $value) {
        	$redis->del($value);
        }
		$server = new swoole_websocket_server($host, $port, SWOOLE_PROCESS);
        $config_server = $config['config'];
		$server->set($config_server);
		return $server;
	}

}