<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Wallet\BlockRef;
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

    public function loadWallet(string $identifier): WalletInterface
    {
        $dbWallet = $this->db->loadWallet($identifier);
        switch ($dbWallet->getType()) {
            case 1:
                $rootKey = $this->db->loadBip44WalletKey($dbWallet->getId());
                return new Bip44Wallet($this->db, $dbWallet, $rootKey, $this->network, $this->ecAdapter);
            default:
                throw new \RuntimeException("Unknown type");
        }
    }

    public function createBip44WalletFromRootKey(string $identifier, HierarchicalKey $rootKey, string $accountPath, ?BlockRef $birthday): WalletInterface
    {
        if ($rootKey->getDepth() !== 0) {
            throw new \RuntimeException("invalid key - must be root");
        }
        $chunks = explode("/", $accountPath);
        if (count($chunks) !== 4 || $chunks[0] !== "M") {
            throw new \RuntimeException("invalid path for bip44 account: M/44'/coinType'/account'");
        }

        $seq = new HierarchicalKeySequence();
        $path = $seq->decodeAbsolute($accountPath)[1];
        if (count($path) + 1 !== count($chunks)) {
            throw new \RuntimeException("invalid path");
        }

        $accountNode = $rootKey->derivePath(substr($accountPath, 2))->withoutPrivateKey();
        return $this->createBip44WalletFromAccountKey($identifier, $accountNode, $accountPath, $birthday);
    }

    public function createBip44WalletFromAccountKey(string $identifier, HierarchicalKey $accountNode, string $path, ?BlockRef $birthday): WalletInterface
    {
        if ($accountNode->getDepth() !== 3) {
            throw new \RuntimeException("invalid key - must be root");
        }

        if ($accountNode->isPrivate()) {
            throw new \RuntimeException("Cannot initialize bip44 wallet with private account node");
        }
        $externalNode = $accountNode->deriveChild(Bip44Wallet::INDEX_EXTERNAL);
        $externalPath = "{$path}/{$externalNode->getSequence()}";

        $changeNode = $accountNode->deriveChild(Bip44Wallet::INDEX_CHANGE);
        $changePath = "{$path}/{$changeNode->getSequence()}";

        $this->db->getPdo()->beginTransaction();
        try {
            $walletId = $this->db->createWallet($identifier, WalletType::BIP44_WALLET, $birthday);
            $this->db->createKey($walletId, $path, $accountNode, $this->network, 0, false);
            $this->db->createKey($walletId, $externalPath, $externalNode, $this->network, 0, false);
            $this->db->createKey($walletId, $changePath, $changeNode, $this->network, 0, false);
            $this->db->getPdo()->commit();
        } catch (\Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }

        return $this->loadWallet($identifier);
    }
}
