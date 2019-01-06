<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Wallet\DB\DbHeader;

class ChainCandidate
{
    /**
     * @var int
     */
    public $bestBlockHeight;

    /**
     * @var DbHeader
     */
    public $dbHeader;
}
