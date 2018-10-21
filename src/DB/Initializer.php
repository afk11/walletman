<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Chain\ParamsInterface;

class Initializer
{
    public function setup(string $fileName, ParamsInterface $params): DB
    {
        if (file_exists($fileName)) {
            throw new \LogicException("DB filename already exists");
        }

        $pdo = new DB("sqlite:$fileName");
        $pdo->createHeaderTable();
        $pdo->createWalletTable();
        $pdo->createKeyTable();
        $pdo->createScriptTable();
        $pdo->createTxTable();
        $pdo->createUtxoTable();

        $header = $params->getGenesisBlockHeader();
        $hash = $header->getHash();
        $res = $pdo->addHeader(0, $hash, $header, 2);
        return $pdo;
    }
}
