<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Validation;

use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39Mnemonic;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39WordListInterface;
use BitWasp\PinEntry\PinValidation\PinValidatorInterface;

class Bip39MnemonicValidator implements PinValidatorInterface
{
    /**
     * @var Bip39WordListInterface
     */
    private $mnemonic;

    public function __construct(Bip39Mnemonic $mnemonic)
    {
        $this->mnemonic = $mnemonic;
    }

    public function validate(string $input, string &$error = null): bool
    {
        try {
            $this->mnemonic->mnemonicToEntropy($input);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
            return false;
        }
        return true;
    }
}
