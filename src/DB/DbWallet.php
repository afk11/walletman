<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

use BitWasp\Buffertools\Buffer;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\Wallet\Wallet;
use BitWasp\Wallet\Wallet\WalletType;

class DbWallet
{
    /**
     * @var string
     */
    private $id;
    /**
     * @var string
     */
    private $type;

    /**
     * @var string
     */
    private $identifier;

    /**
     * @var string
     */
    private $birthday_hash;

    /**
     * @var string
     */
    private $birthday_height;

    /**
     * @var string
     */
    private $gapLimit;

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getType(): int
    {
        return (int) $this->type;
    }

    public function getGapLimit(): int
    {
        if ($this->getType() === WalletType::BIP44_WALLET) {
            return (int) $this->gapLimit;
        }

        throw new \LogicException("wallet type does not have a gap limit");
    }

    public function getBirthday(): ?BlockRef
    {
        if (!$this->birthday_hash) {
            return null;
        }

        return new BlockRef((int) $this->birthday_height, Buffer::hex($this->birthday_hash, 32));
    }
}
