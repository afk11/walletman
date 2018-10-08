<?php

use BitWasp\Bitcoin\Math\Math;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\DB\DB;

require "vendor/autoload.php";

$params = new RegtestParams(new Math());
$header = $params->getGenesisBlockHeader();
$hash = $header->getHash();

$access = new DB("sqlite:wallet-regtest.sqlite3");
$access->createHeaderTable();
$res = $access->addHeader(0, $hash, $header);

$access->createWalletTable();
$access->createKeyTable();
$access->createScriptTable();
print_r($res);
