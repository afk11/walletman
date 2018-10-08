<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;


use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Wallet\DB\DB;

class Bip44Wallet
{
    /**
     * @var HierarchicalKey
     */
    private $key;
    private $db;
    private $walletId;

    public function __construct(DB $db, int $walletId, HierarchicalKey $hierarchicalKey, int $purpose, int $coinType, int $account)
    {
        if ($hierarchicalKey->getDepth() !== 3) {
            throw new \RuntimeException("invalid key depth for bip44 account, should provide M/purpose'/coinType'/account'");
        }
        if ($hierarchicalKey->getSequence() !== $account + (1 << 31)) {
            echo ($account + 1<<31).PHP_EOL;
            throw new \RuntimeException("key's address index {$hierarchicalKey->getSequence()}'t match path value");
        }
        $this->key = $hierarchicalKey;
        $this->purpose = $purpose;
        $this->coinType = $coinType;
        $this->account = $account;
        $this->db = $db;
        $this->walletId = $walletId;
    }

    public function getAddressGenerator(): AddressGenerator {
        $key = $this->key->deriveChild(0);
        $idx = 0;
        return new Bip32Generator($this->db, $this->walletId, $key, $idx);
    }

    public function getChangeAddressGenerator(): AddressGenerator {

    }
}
