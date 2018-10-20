<?php

declare(strict_types=1);

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Factory as WalletFactory;

require "vendor/autoload.php";

$loop = \React\EventLoop\Factory::create();
$ecAdapter = Bitcoin::getEcAdapter();
$math = new Math();

$dbMgr = new DbManager();
if (getenv("REGTEST")) {
    $db = $dbMgr->loadDb("wallet-regtest.sqlite3");
    $port = 18444;
    $params = new RegtestParams(new Math());
    $net = NetworkFactory::bitcoinRegtest();
} else {
    $db = $dbMgr->loadDb("wallet.sqlite3");
    $port = 8333;
    $params = new Params(new Math());
    $net = NetworkFactory::bitcoin();
}

$seed = new Buffer("seed", 32);
$rootNode = HierarchicalKeyFactory::fromEntropy($seed, $ecAdapter);
echo $rootNode->toExtendedPrivateKey().PHP_EOL;

$addrCreator = new AddressCreator();
$walletFactory = new WalletFactory($db, $net, $ecAdapter);
$bip44Wallet = $walletFactory->createBip44WalletFromRootKey("lbl", $rootNode, 0, 0);
$addrGen = $bip44Wallet->getScriptGenerator();

for ($i = 0; $i < 5; $i++) {
    $dbScript = $addrGen->generate();
    $address = $dbScript->getAddress($addrCreator);
    echo $address->getAddress($net).PHP_EOL;
}


