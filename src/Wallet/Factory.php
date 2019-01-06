<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\DB\DBInterface;

class Factory
{
    private $db;
    private $network;
    private $ecAdapter;
    private $serializer;

    public function __construct(DBInterface $db, NetworkInterface $network, Base58ExtendedKeySerializer $serializer, EcAdapterInterface $ecAdapter)
    {
        $this->db = $db;
        $this->network = $network;
        $this->serializer = $serializer;
        $this->ecAdapter = $ecAdapter;
    }

    public function loadWallet(string $identifier): WalletInterface
    {
        $dbWallet = $this->db->loadWallet($identifier);
        switch ($dbWallet->getType()) {
            case 1:
                $rootKey = $this->db->loadBip44WalletKey($dbWallet->getId());
                return new Bip44Wallet($this->db, $this->serializer, $dbWallet, $rootKey, $this->network, $this->ecAdapter);
            default:
                throw new \RuntimeException("Unknown type");
        }
    }

    public function createBip44WalletFromRootKey(string $identifier, HierarchicalKey $rootKey, string $accountPath, int $gapLimit, ?BlockRef $birthday): WalletInterface
    {
        if ($rootKey->getDepth() !== 0) {
            throw new \RuntimeException("invalid key - must be root");
        }

        // Account path is absolute, and includes 3 derivations.
        $chunks = explode("/", $accountPath);
        if (count($chunks) !== 4 || $chunks[0] !== "M") {
            throw new \RuntimeException("invalid path for bip44 account: M/44'/coinType'/account'");
        }

        // Check the path decodes correctly
        $seq = new HierarchicalKeySequence();
        $path = $seq->decodeAbsolute($accountPath)[1];
        if (count($path) + 1 !== count($chunks)) {
            throw new \RuntimeException("invalid path");
        }

        // Compute accountKey from root
        $accountNode = $rootKey->derivePath(substr($accountPath, 2))->withoutPrivateKey();
        return $this->createBip44WalletFromAccountKey($identifier, $accountNode, $accountPath, $gapLimit, $birthday);
    }

    public function createBip44WalletFromAccountKey(string $identifier, HierarchicalKey $accountNode, string $path, int $gapLimit, ?BlockRef $birthday): WalletInterface
    {
        if ($accountNode->getDepth() !== 3) {
            throw new \RuntimeException("invalid key - must be root");
        }

        if ($accountNode->isPrivate()) {
            throw new \RuntimeException("Cannot initialize bip44 wallet with private account node");
        }

        // Account path is absolute, and includes 3 derivations.
        $chunks = explode("/", $path);
        if (count($chunks) !== 4 || $chunks[0] !== "M") {
            throw new \RuntimeException("invalid path for bip44 account: $path");
        }

        $externalNode = $accountNode->deriveChild(Bip44Wallet::INDEX_EXTERNAL);
        $changeNode = $accountNode->deriveChild(Bip44Wallet::INDEX_CHANGE);

        $this->db->getPdo()->beginTransaction();
        try {
            $walletId = $this->db->createWallet($identifier, WalletType::BIP44_WALLET, $gapLimit, $birthday);
            $this->db->createKey($walletId, $this->serializer, $path, $accountNode, $this->network, 0, false);
            $this->db->createKey($walletId, $this->serializer, "$path/{$externalNode->getSequence()}", $externalNode, $this->network, 0, false);
            $this->db->createKey($walletId, $this->serializer, "$path/{$changeNode->getSequence()}", $changeNode, $this->network, 0, false);
            $this->db->getPdo()->commit();
        } catch (\Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }

        return $this->loadWallet($identifier);
    }
}
