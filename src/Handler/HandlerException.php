<?php

namespace Plume\WebSocket\Handler;

/**
 * WebSocket服务器端异常定义
 * 返回数据格式 - {event:error,data:data}
 */
class HandlerException extends \Exception{

	public function obj(){
		$ret = new \stdClass();
		$ret->event = 'error';
		$ret->data = $this->getMessage();
		return $ret;
	}
}