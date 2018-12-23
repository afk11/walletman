<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;

class DbKey
{
    /**
     * @var string
     */
    private $id;

    /**
     * @var string
     */
    private $walletId;

    /**
     * BIP32 path
     * @var string
     */
    private $path;

    /**
     * Depth of the key in the tree
     * @var string
     */
    private $depth;

    /**
     * key in use - xpub/ypub depending on the wallet
     * @var string
     */
    private $key;

    /**
     * is this node a leaf or a branch? branch
     * nodes can be incremented, leaf nodes can't
     * @var string
     */
    private $isLeaf;

    /**
     * Current best child node
     * Only set for branches
     * @var string
     */
    private $childSequence;

    /**
     * Order of the keys used in this script.
     * Zero for branch nodes, can be >0 for leaf nodes.
     * @var string
     */
    private $keyIndex;

    public function getWalletId(): int
    {
        return (int) $this->walletId;
    }

    public function getDepth(): int
    {
        return (int) $this->depth;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getChildSequence(): int
    {
        return (int) $this->childSequence;
    }

    public function getBase58Key(): string
    {
        return $this->key;
    }

    public function getHierarchicalKey(NetworkInterface $network, EcAdapterInterface $ecAdapter): HierarchicalKey
    {
        $factory = new HierarchicalKeyFactory($ecAdapter);
        return $factory->fromExtended($this->key, $network);
    }

    public function isLeaf(): bool
    {
        return (bool) $this->isLeaf;
    }

    public function getKeyIndex(): int
    {
        return (int) $this->keyIndex;
    }

    public function getNextSequence(DBInterface $db): int
    {
        $update = $db->getPdo()->prepare("
            UPDATE key
            SET childSequence = childSequence + 1
            WHERE walletId = ? AND path = ?");

        if (!$update->execute([
            $this->walletId, $this->path,
        ])) {
            throw new \RuntimeException("failed to generate new sequence");
        }

        $read = $db->getPdo()->prepare("SELECT childSequence from key where walletId = ? AND path = ?");
        $read->execute([
            $this->walletId, $this->path,
        ]);

        $childSequence = (int) $read->fetch()['childSequence'];

        return $childSequence;
    }
}
