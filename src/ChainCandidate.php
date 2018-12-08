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
     * @var int
     */
    public $status;
    /**
     * @var int
     */
    public $bestBlockHeight;
    /**
     * @var DbHeader
     */
    public $dbHeader;
}
