<?php

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\P2pSyncDaemon;

require "vendor/autoload.php";

$loop = \React\EventLoop\Factory::create();
$math = new Math();

$dbMgr = new DbManager();
if (getenv("REGTEST")) {
    $db = $dbMgr->loadDb("sqlite:wallet-regtest.sqlite3");
    $port = 18444;
    $params = new RegtestParams(new Math());
    $net = NetworkFactory::bitcoinRegtest();
} else {
    $db = $dbMgr->loadDb("sqlite:wallet.sqlite3");
    $port = 8333;
    $params = new Params(new Math());
    $net = NetworkFactory::bitcoin();
}
$daemon = new P2pSyncDaemon("127.0.0.1", $port, $net, $params, $db);
$daemon->sync($loop);
$loop->run();
