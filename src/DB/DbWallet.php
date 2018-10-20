<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

class DbWallet
{
    private $id;
    private $type;
    private $identifier;

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
}
