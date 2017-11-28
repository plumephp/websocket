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
    	// 客户端下线时，删除集群通信节点
	    if(isset($this->app->nodeFds[$this->fd])){
		    unset($this->app->nodeFds[$this->fd]);
	    }
		// get config
        $config = $this->app->getConfig();
	    try {
		    // clear bind fd
		    $redis = $this->app->provider('redis')->connect();
		    $host = $config['server_config']['host'];
		    $redis->del($host . ':' . $this->fd);
		    $groupKey = $redis->get($host . ':' . $this->fd . ':group');
		    $redis->del($host . ':' . $this->fd . ':group');
		    if (!empty($groupKey)) {
			    $result = $redis->lrem($groupKey, $this->fd, 1);
			    $this->app->provider('log')->debug('close lrem result : ', $result);
		    }
	    } catch (\Exception $e) {
		    $this->app->provider('log')->debug('close fd exception : ', $e->getMessage());
	    }
	    // invoke close classes
        if(!isset($config['actions_close'])){
        	return;
        }
        $actions = $config['actions_close'];
        foreach ($actions as $key => $value) {
        	$classFullName = $value;
			$action = 'close';
	    	try {
                //fix bug:配置文件没有匹配
                list($module, $class) = explode('\\', $classFullName);
                $configPath = $this->app['plume.root.path'].'/modules/'.$module.'/';
		        $class = new $classFullName($this->app, $this->server, $this->fd, true);
                //fix bug:模块内app对象没有初始化问题
                $class->initModule($configPath, $this->app['plume.env']);
	        	call_user_func_array(array($class, $action), array());
	        } catch (\Exception $e) {
	        	throw new HandlerException("call func exception : ".$e->getMessage());
	        }
        }
    }
}
