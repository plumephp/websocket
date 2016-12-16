<?php

namespace Plume\WebSocket\Handler;

use Plume\WebSocket\Handler\HandlerException;

class MessageHandler{

	protected $server;
	protected $frame;
	protected $app;
	protected $fd;
	protected $data;

	public function __construct($app, $server, $frame) {
    	$this->app = $app;
    	$this->server = $server;
    	$this->frame = $frame;
    	$this->fd = $frame->fd;
    	$this->data = $frame->data;
    }

    public function handle(){
		//数据格式约定为JSON
        $msg = json_decode($this->data);
        //处理消息的URL
        try {
        	//校验客户端发送数据格式 - {url:'modules/eventClassName/action',data:strdata}
        	if(empty($msg) || empty($msg->url) || empty($msg->data)){
				throw new HandlerException('data format error : it is json with url and data property?');
			}
			// $this->handleCluster($msg);
            $classInfo = $this->handleRequest($msg);
            //处理消息绑定关系
	        // $this->bind($msg);
	        //执行url对应类
            $this->exec($classInfo);
        } catch (HandlerException $e) {
            $this->debug('MessageHandler', $e->getMessage());
            $this->replay($e->obj());
        }
    }

    public function handleRequest($msg){
		//URL解析格式 - "module/className/action"
		$urlArr = explode('/', $msg->url);
		if(count($urlArr) != 3){
			throw new HandlerException('data format error : url is like module/className/action ?');
		}
        $module = ucfirst($urlArr[0]);
        $className = ucfirst($urlArr[1]);
        $action = ucfirst($urlArr[2]);
    	$classFullName = "{$module}\\{$className}";
    	if (!class_exists($classFullName)) {
    		throw new HandlerException("url {$classFullName} is not exist");
    	}
    	return array('classname' => $classFullName, 'action' => $action, 'data' => $msg->data);
	}


	// public function bind($msg){
 //        $redis = $this->app->provider('redis')->connect();
 //        $host = $this->app->getConfig()['server']['master']['host'];
 //        //处理uid和host的bind关系 - 
 //        if(empty($msg->uid)){
 //            //host,为了区分是否需要对uid以外的fd广播问题
 //            $redis->rpush($host, $this->fd);
 //        }else{
 //            //uid:host->fd1,fd2
 //            $redis->rpush($msg->uid.':'.$host, $this->fd);
 //        }
 //    }


	public function exec($classInfo){
		$classFullName = $classInfo['classname'];
		$action = $classInfo['action'];
		$data = $classInfo['data'];
    	try {
	        $class = new $classFullName($this->app, $this->server, $this->frame);
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

    // // 通知其他终端
    // protected function others($fds, $data) {
    //     $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
    //     var_dump($data);
    //     // $this->server->push($this->frame->fd, $json_data);
    // }

    private function debug($title, $info){
		$this->app->provider('log')->debug($title, $info);
	}
	
}