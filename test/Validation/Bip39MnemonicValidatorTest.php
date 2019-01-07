<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Validation;

use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39WordListInterface;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\EnglishWordList;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\Validation\Bip39MnemonicValidator;

class Bip39MnemonicValidatorTest extends TestCase
{
    public function getValidatorFixtures(): array
    {
        $generator = MnemonicFactory::bip39();
        return [
            [
                $generator->create(16*8),
                true,
                new EnglishWordList(),
            ],
            [
                $generator->create(160),
                true,
                new EnglishWordList(),
            ],
            [
                $generator->create(192),
                true,
                new EnglishWordList(),
            ],
            [
                $generator->create(224),
                true,
                new EnglishWordList(),
            ],
            [
                $generator->create(32*8),
                true,
                new EnglishWordList(),
            ],
            [
                "reunion kitchen kitchen spring",
                false,
                new EnglishWordList(),
            ],
        ];
    }

    /**
     * @dataProvider getValidatorFixtures
     * @param string $input
     * @param bool $result
     * @param Bip39WordListInterface $wordList
     */
    public function testValidator(string $input, bool $result, Bip39WordListInterface $wordList)
    {
        $validator = new Bip39MnemonicValidator(MnemonicFactory::bip39($wordList));
        if ($result) {
            $this->assertTrue($validator->validate($input));
        } else {
            $this->assertFalse($validator->validate($input));
        }
    }
}
