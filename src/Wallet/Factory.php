<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Key\Factory\ElectrumKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Electrum\ElectrumWordListInterface;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\Wallet\Electrum\ElectrumWallet;

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
            case WalletType::BIP44_WALLET:
                $rootKey = $this->db->loadBip44WalletKey($dbWallet->getId());
                return new Bip44Wallet($this->db, $this->serializer, $dbWallet, $rootKey, $this->network, $this->ecAdapter);
            case WalletType::ELECTRUM_WALLET:
                $rootKey = $this->db->loadKeyByPath($dbWallet->getId(), (string)ElectrumWallet::INDEX_EXTERNAL, 0);
                return new ElectrumWallet($this->db, $dbWallet, $rootKey, $this->ecAdapter);
            default:
                throw new \RuntimeException("Unknown type");
        }
    }

    public function createBip44WalletFromRootKey(string $identifier, HierarchicalKey $rootKey, string $accountPath, int $gapLimit, ?BlockRef $birthday): Bip44Wallet
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

    public function createBip44WalletFromAccountKey(string $identifier, HierarchicalKey $accountNode, string $path, int $gapLimit, ?BlockRef $birthday): Bip44Wallet
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
            $this->db->createBip32Key($walletId, $this->serializer, $path, $accountNode, $this->network, 0, false);
            $this->db->createBip32Key($walletId, $this->serializer, "$path/{$externalNode->getSequence()}", $externalNode, $this->network, 0, false);
            $this->db->createBip32Key($walletId, $this->serializer, "$path/{$changeNode->getSequence()}", $changeNode, $this->network, 0, false);
            $this->db->getPdo()->commit();
        } catch (\Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }

        /** @var Bip44Wallet $wallet */
        $wallet = $this->loadWallet($identifier);
        return $wallet;
    }

    public function createElectrumWalletFromSeed(string $identifier, string $mnemonic, int $gapLimit, ?BlockRef $birthday, ?ElectrumWordListInterface $wordList): ElectrumWallet
    {
        $electrumFactory = new ElectrumKeyFactory();
        $masterKey = $electrumFactory->fromMnemonic($mnemonic, $wordList);
        return $this->createElectrumWalletFromMPK($identifier, $masterKey->getMasterPublicKey(), $gapLimit, $birthday);
    }

    public function createElectrumWalletFromMPK(string $identifier, PublicKeyInterface $publicKey, int $gapLimit, ?BlockRef $birthday): ElectrumWallet
    {
        $this->db->getPdo()->beginTransaction();
        try {
            $walletId = $this->db->createWallet($identifier, WalletType::ELECTRUM_WALLET, $gapLimit, $birthday);
            $this->db->createElectrumKey($walletId, $publicKey, 0, ElectrumWallet::INDEX_EXTERNAL);
            $this->db->createElectrumKey($walletId, $publicKey, 0, ElectrumWallet::INDEX_CHANGE);
            $this->db->getPdo()->commit();
        } catch (\Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }

        /** @var ElectrumWallet $wallet */
        $wallet = $this->loadWallet($identifier);
        return $wallet;
    }
}
