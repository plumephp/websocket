<?php

require_once __DIR__.'/../vendor/autoload.php';

use Plume\WebSocket\Application;

$app = new Application();
$app->run();