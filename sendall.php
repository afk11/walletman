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
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;

require "vendor/autoload.php";

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

if (getenv("SYNCIP")) {
    $ip = getenv("SYNCIP");
} else {
    $ip = "127.0.0.1";
}

$destAddr = "2N5uHJj1jk6CQG7ZbSxojpnooaWi3H1DauC";
$addrCreator = new AddressCreator();
$addr = $addrCreator->fromString($destAddr, $net);

$ecAdapter = Bitcoin::getEcAdapter();
$walletFactory = new Factory($db, $net, $ecAdapter);
/** @var Bip44Wallet $bip44Wallet */
$bip44Wallet = $walletFactory->loadWallet("lbl");


$seed = new Buffer("seed", 32);
$rootNode = HierarchicalKeyFactory::fromEntropy($seed, $ecAdapter);
$accountNode = $rootNode->derivePath("44'/0'/0'");

$bip44Wallet->loadAccountPrivateKey($accountNode);

$signedTx = $bip44Wallet->sendAllCoins($addr->getScriptPubKey(), 1);
echo $signedTx->getHex().PHP_EOL;
