<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/IsolatedBusinessDatabase.php';
require __DIR__ . '/support/MessageRuntimeFactory.php';

\Webman\Config::clear();
\support\App::loadAllConfig(['route']);
