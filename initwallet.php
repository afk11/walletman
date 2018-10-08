<?php

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Bip44Wallet;

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

$walletId = $db->createWallet("lbl", 1);

$seed = new Buffer("seed", 32);
$rootNode = HierarchicalKeyFactory::fromEntropy($seed);
$accountNode = $rootNode->derivePath("44'/0'/0'");

$bip44Wallet = new Bip44Wallet($db, $walletId, $accountNode, 44, 0, 0);
$addrGen = $bip44Wallet->getAddressGenerator();

for ($i = 0; $i < 5; $i++) {
    $dbScript = $addrGen->generate();
    $address = $dbScript->getAddress();
    echo $address->getAddress($net).PHP_EOL;
}


