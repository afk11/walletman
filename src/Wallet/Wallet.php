<?php

namespace BitWasp\Wallet\Wallet;

use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbWallet;

abstract class Wallet implements WalletInterface
{
    /**
     * @var DB
     */
    protected $db;

    /**
     * @var DbWallet
     */
    protected $dbWallet;

    public function __construct(DB $db, DbWallet $dbWallet)
    {
        $this->db = $db;
        $this->dbWallet = $dbWallet;
    }

    public function getConfirmedBalance(): int
    {
        return $this->db->getConfirmedBalance($this->dbWallet->getId());
    }

    public function getDbWallet(): DbWallet
    {
        return $this->dbWallet;
    }
}
