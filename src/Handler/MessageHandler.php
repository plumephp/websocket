<?php

namespace Plume\WebSocket\Handler;

use Plume\WebSocket\Handler\HandlerException;

class MessageHandler{

	protected $server;
	protected $frame;
	protected $app;
	protected $fd;
	protected $data;
    protected $defaultClass;
    protected $key_url;

	public function __construct($app, $server, $frame) {
    	$this->app = $app;
    	$this->server = $server;
    	$this->frame = $frame;
    	$this->fd = $frame->fd;
    	$this->data = $frame->data;
        $this->defaultClass = isset($this->app['plume.ws.msg.url.default'])?$this->app['plume.ws.msg.url.default']:'';
        $this->key_url = isset($this->app['plume.ws.msg.url.key'])?$this->app['plume.ws.msg.url.key']:'url';
    }

    public function handle(){
		//数据格式约定为JSON
        $msg = json_decode($this->data, true);
        //处理消息的URL
        try {
        	//校验客户端发送数据格式 - {url:'modules/eventClassName/action',data:strdata}
        	if(empty($msg) || (!isset($msg[$this->key_url]) && !isset($msg['url'])) || !isset($msg['data'])){
				throw new HandlerException('data format error : it is json with url and data property?');
			}
            $classInfo = $this->handleRequest($msg);
	        //执行url对应类
            $classInfo['data'] = $this->data;
            $this->exec($classInfo);
        } catch (HandlerException $e) {
            $this->debug('MessageHandler', $e->getMessage());
            $this->replay($e->obj());
        }
    }

    public function handleRequest($msg){
		//URL解析格式 - "module/className/action"
        if(isset($msg['url'])){
            $urlArr = explode('/', $msg['url']);
        }else{
            $msg[$this->key_url] = $this->defaultClass.'/'.$msg[$this->key_url];
            $urlArr = explode('/', $msg[$this->key_url]);
        }
		if(count($urlArr) != 3){
			throw new HandlerException('data format error : url is like module/className/action ?');
		}
        $module = ucfirst($urlArr[0]);
        $className = ucfirst($urlArr[1]);
        $action = ucfirst($urlArr[2]);
    	$classFullName = "{$module}\\{$className}";
        $configPath = $this->app['plume.root.path'].'/modules/'.$module.'/';
    	if (!class_exists($classFullName)) {
    		throw new HandlerException("url {$classFullName} is not exist");
    	}
    	return array('classname' => $classFullName, 'action' => $action, 'configPath' => $configPath);
	}


	public function exec($classInfo){
		$classFullName = $classInfo['classname'];
		$action = $classInfo['action'];
		$data = $classInfo['data'];
        $configPath = $classInfo['configPath'];
    	try {
	        $class = new $classFullName($this->app, $this->server, $this->frame);
            $class->initModule($configPath, $this->app['plume.env']);
        	call_user_func_array(array($class, $action), array($data));
        } catch (\Exception $e) {
        	throw new HandlerException("call func exception : ".$e->getMessage());
        }
    }

    // 回复来源终端
    protected function replay($data) {
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->server->push($this->frame->fd, $json_data);
    }

    private function debug($title, $info){
		$this->app->provider('log')->debug($title, $info);
	}
	
}