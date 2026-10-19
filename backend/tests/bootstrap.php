<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/IsolatedBusinessDatabase.php';
require __DIR__ . '/support/MessageRuntimeFactory.php';
require __DIR__ . '/support/RecordingOrderProvisioning.php';

\Webman\Config::clear();
\support\App::loadAllConfig(['route']);

require_once __DIR__ . '/support/ProductRuntimeFactory.php';

require_once __DIR__ . '/support/UndeliveredOrderFixture.php';
