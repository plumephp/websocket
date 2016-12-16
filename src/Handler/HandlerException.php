<?php

namespace Plume\WebSocket\Handler;

class HandlerException extends \Exception{

	public function obj(){
		//返回数据格式 - {event:error,data:data}
		$ret = new \stdClass();
		$ret->event = 'error';
		$ret->data = $this->getMessage();
		return $ret;
	}

}