<?php

namespace BitWasp\Wallet;

use BitWasp\Wallet\DB\DbHeader;

class ChainCandidate
{
    /**
     * @var \GMP
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

    public static function fromHeader(DbHeader $header, int $bestBlockHeight): ChainCandidate
    {
        $candidate = new ChainCandidate();
        $candidate->work = $header->getWork();
        $candidate->status = $header->getStatus();
        $candidate->bestBlockHeight = $bestBlockHeight;
        $candidate->dbHeader = $header;
        return $candidate;
    }
}
