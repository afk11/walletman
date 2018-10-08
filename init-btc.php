<?php

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;

require "vendor/autoload.php";

$params = new Params(new Math());
$header = $params->getGenesisBlockHeader();
print_r($header);
$hash = $header->getHash();

$access = new DB("sqlite:wallet.sqlite3");
$access->createHeaderTable();
$res = $access->addHeader(0, $hash, $header);
$access->createWalletTable();
$access->createKeyTable();
$access->createScriptTable();
print_r($res);
