<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Wallet\DB\DB;

class Factory
{
    private $db;
    private $network;
    private $ecAdapter;

    public function __construct(DB $db, NetworkInterface $network, EcAdapterInterface $ecAdapter)
    {
        $this->db = $db;
        $this->network = $network;
        $this->ecAdapter = $ecAdapter;
    }

    public function createBip44WalletFromRootKey(string $identifier, HierarchicalKey $rootKey, int $coinType, int $account): Bip44Wallet
    {
        if ($rootKey->getDepth() !== 0) {
            throw new \RuntimeException("invalid key - must be root");
        }

        $path = "M/44'/{$coinType}'/{$account}'";
        $accountNode = $rootKey->derivePath($path);

        $externalNode = $accountNode->deriveChild(Bip44Wallet::INDEX_EXTERNAL);
        $externalPath = "{$path}/{$externalNode->getSequence()}";

        $changeNode = $accountNode->deriveChild(Bip44Wallet::INDEX_CHANGE);
        $changePath = "{$path}/{$changeNode->getSequence()}";

        echo "begintx1\n";
        $this->db->getPdo()->beginTransaction();
        try {
            $walletId = $this->db->createWallet($identifier, WalletType::BIP44_WALLET);
            echo "$walletId $path 0\n";
            $this->db->createKey($walletId, $path, $accountNode, $this->network, 0, false);
            echo "$walletId $externalPath 0\n";
            $this->db->createKey($walletId, $externalPath, $externalNode, $this->network, 0, false);
            echo "$walletId $changePath 0\n";
            $this->db->createKey($walletId, $changePath, $changeNode, $this->network, 0, false);
            $this->db->getPdo()->commit();
        } catch (\Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }

        $rootKey = $this->db->loadKeyByPath($walletId, $path, 0);
        return new Bip44Wallet($this->db, $rootKey, $this->network, $this->ecAdapter);
    }
}
