<?php

namespace BitWasp\Wallet;

use BitWasp\Wallet\DB\DbHeader;

class ChainCandidate
{
    /**
     * @var int|string
     */
    public $work;

    /**
     * @var DbHeader
     */
    public $dbHeader;
}
