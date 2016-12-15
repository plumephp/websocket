<?php

namespace Plume\WebSocket\Handler;

class HandlerException extends \Exception{

	public function obj(){
		//返回数据格式 - {code:200|500,data:data}
		$ret = new \stdClass();
		$ret->code = '500';
		$ret->data = $this->getMessage();
		return $ret;
	}

}