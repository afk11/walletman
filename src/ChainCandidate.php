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

    public static function fromHeader(DbHeader $header, int $bestBlockHeight): ChainCandidate
    {
        $candidate = new ChainCandidate();
        $candidate->work = gmp_init($header->getWork());
        $candidate->status = $header->getStatus();
        $candidate->bestBlockHeight = $bestBlockHeight;
        $candidate->dbHeader = $header;
        return $candidate;
    }
}
