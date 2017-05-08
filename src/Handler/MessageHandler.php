<?php

namespace Plume\WebSocket\Handler;

use Plume\WebSocket\Handler\HandlerException;

/**
 * WebSocket服务端消息处理器
 * 数据格式：{url:'modules/eventClassName/action',data:strdata}
 *
 */
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

    /**
     * 消息处理
     * 1.校验消息格式
     * 2.解析消息
     * 3.执行消息处理类
     * 4.异常处理，当出现错误时直接回复异常到客户端
     * @throws Plume\WebSocket\Handler\HandlerException 当消息格式不正确时
     */
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
	        //执行URL对应类
            $this->exec($classInfo);
        } catch (HandlerException $e) {
            $this->debug('MessageHandler', $e->getMessage());
            $this->replay($e->obj());
        }
    }

    /**
     * 消息解析
     * @param json $msg json格式的消息
     * @return array array('classname' => '', 'action' => '', 'configPath' => '', 'data' => '')
     * @throws Plume\WebSocket\Handler\HandlerException 当类路径不对或类不存在时
     */
    public function handleRequest($msg){
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
    	return array('classname' => $classFullName, 'action' => $action, 'configPath' => $configPath, 'data' => $msg['data']);
	}

    /**
     * 执行指定的类方法
     * 1.获取指定类，方法，参数并初始化该类
     * 2.执行相应方法
     * @param array $classInfo 需要执行的类信息 see handleRequest()
     * @throws Plume\WebSocket\Handler\HandlerException 当执行类方法出现异常时
     */
	private function exec($classInfo){
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

    /**
     * 回复当前链接。
     * @param stdClass $data 回复数据
     */
    protected function replay($data) {
        $this->server->push($this->frame->fd, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 服务器端记录调试信息
     * @param string $title 日志标题
     * @param mixed $info 日志数据 string|array
     */
    private function debug($title, $info){
		$this->app->provider('log')->debug($title, $info);
	}
}