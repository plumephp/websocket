<?php

namespace Plume\WebSocket\Core;

trait ContextTrait{
	
    protected $context = array(
        'plume.env' => 'dev',
        'plume.log.debug' => false,
        'plume.root.path' => ''
    );

    public function getContext(){
        return $this->context;
    }
}