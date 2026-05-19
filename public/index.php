<?php

declare(strict_types=1);

use AccessLogger\App;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/load-settings.php';
$settings = access_logger_load_settings();

$app = AppFactory::create();
App::register($app, $settings);
$app->run();
