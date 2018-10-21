<?php

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\DB\DB;

require "vendor/autoload.php";

if (getenv("REGTEST")) {
    $params = new RegtestParams(new Math());
    $access = new DB("sqlite:wallet-regtest.sqlite3");
} else {
    $params = new Params(new Math());
    $access = new DB("sqlite:wallet.sqlite3");
}

$header = $params->getGenesisBlockHeader();
$hash = $header->getHash();

$access->createHeaderTable();
$res = $access->addHeader(0, $hash, $header, 2);

$access->createWalletTable();
$access->createKeyTable();
$access->createScriptTable();
$access->createTxTable();
$access->createUtxoTable();
print_r($res);
