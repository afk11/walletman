<?php

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Signature\TransactionSignature;
use BitWasp\Buffertools\BufferInterface;

interface SignatureProducer
{
    public function sign(BufferInterface $buffer): TransactionSignature;
}
