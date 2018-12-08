<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

class Initializer
{
    public function setup(string $fileName): DB
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

        return $pdo;
    }
}
