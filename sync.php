<?php

use BitWasp\Bitcoin\Math\Math;
use BitWasp\Wallet\P2pSyncDaemon;

require "vendor/autoload.php";

$loop = \React\EventLoop\Factory::create();
$math = new Math();
$daemon = new P2pSyncDaemon("127.0.0.1", 8333, "abc");
$daemon->sync($loop);
$loop->run();
