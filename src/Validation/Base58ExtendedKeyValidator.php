<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Validation;

use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\PinEntry\PinValidation\PinValidatorInterface;

class Base58ExtendedKeyValidator implements PinValidatorInterface
{
    /**
     * @var Base58ExtendedKeySerializer
     */
    private $serializer;

    /**
     * @var NetworkInterface
     */
    private $network;

    public function __construct(Base58ExtendedKeySerializer $serializer, NetworkInterface $network)
    {
        $this->serializer = $serializer;
        $this->network = $network;
    }

    public function validate(string $input, string &$error = null): bool
    {
        try {
            $this->serializer->parse($this->network, $input);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            return false;
        }
        return true;
    }
}
