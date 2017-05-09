<?php

namespace Plume\WebSocket\Handler;

use Plume\WebSocket\Handler\HandlerException;

/**
 * WebSocket服务器端链接关闭事件处理
 */
class CloseHandler{

	protected $server;
	protected $app;
	protected $fd;

	public function __construct($app, $server, $fd) {
    	$this->app = $app;
    	$this->server = $server;
    	$this->fd = $fd;
    }

    /**
     * 关闭事件处理
     * 1.清除用户绑定的数据
     * 2.依次调用注册关闭事件的类
     * @throws Plume\WebSocket\Handler\HandlerException 当执行注册的关闭事件类出异常时
     */
    public function handle(){
		// clear bind fd
		$redis = $this->app->provider('redis')->connect();
		$host = $this->app->getConfig()['server_config']['host'];
        $redis->del($host.':'.$this->fd);
        $groupKey = $redis->get($host.':'.$this->fd.':group');
        $redis->del($host.':'.$this->fd.':group');
		$result = $redis->lrem($groupKey, $this->fd, 1);
		$this->app->provider('log')->debug('close lrem result : ', $result);
        // invoke close classes
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