<?php

namespace Plume\WebSocket\Handler;

use Plume\WebSocket\Handler\HandlerException;

class CloseHandler{

	protected $server;
	protected $app;
	protected $fd;

	public function __construct($app, $server, $fd) {
    	$this->app = $app;
    	$this->server = $server;
    	$this->fd = $fd;
    }

    public function handle(){
		//清除用户绑定的fd
		$redis = $this->app->provider('redis')->connect();
		$host = $this->app->getConfig()['server']['master']['host'];
		$result = $redis->lrem($host, $this->fd, 1);
		$this->app->provider('log')->debug('close lrem result : ', $result);
        //依次调用注册事件
        $config = $this->app->getConfig();
        if(!isset($config['actions_close'])){
        	return;
        }
        $actions = $config['actions_close'];
        foreach ($actions as $key => $value) {
        	$classFullName = $value;
			$action = 'close';
	    	try {
		        $class = new $classFullName($this->app, $this->server, $this->fd, true);
	        	call_user_func_array(array($class, $action), array());
	        } catch (\Exception $e) {
	        	throw new HandlerException("call func exception : ".$e->getMessage());
	        }
        }
    }
}