<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

class DbWallet
{
    private $id;
    private $type;
    private $name;

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getType(): int
    {
        return (int) $this->type;
    }
}
