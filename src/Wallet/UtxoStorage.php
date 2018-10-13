<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWallet;

class UtxoStorage
{
    private $db;
    private $dbWallet;
    public function __construct(DB $db, DbWallet $dbWallet)
    {
        $this->db = $db;
        $this->dbWallet = $dbWallet;
    }
    public function search(OutPointInterface $outPoint): ?DbUtxo
    {
        return $this->db->searchUnspentUtxo($this->dbWallet->getId(), $outPoint);
    }
}
