<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command;

use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39Mnemonic;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
    protected function getStringArgument(InputInterface $input, string $argument): string
    {
        return (string) $input->getArgument($argument);
    }

    protected function promptForPassphrase(InputInterface $input, OutputInterface $output): string
    {
        $helper = $this->getHelper('question');
        $pwFirst = new Question('Enter your passphrase:');
        $pwFirst->setHidden(true);
        $pwFirst->setHiddenFallback(false);
        $pwFirst->setMaxAttempts(2);
        $pwFirst->setValidator(function ($answer) {
            if (strlen($answer) < 1) {
                throw new \RuntimeException("Cannot use an empty passphrase");
            }
        });

        $pwSecond = new Question('Enter your passphrase (again):');
        $pwSecond->setHidden(true);
        $pwSecond->setHiddenFallback(false);
        $pwSecond->setMaxAttempts(2);
        $pwSecond->setValidator(function ($answer) {
            if (strlen($answer) < 1) {
                throw new \RuntimeException("Cannot use an empty passphrase");
            }
        });

        $tries = 3;
        do {
            $answer1 = $helper->ask($input, $output, $pwFirst);
            $answer2 = $helper->ask($input, $output, $pwSecond);
        } while ($tries-- > 0 && $answer1 !== $answer2);

        if ($answer1 !== $answer2) {
            throw new \RuntimeException("Didn't enter matching password, abort");
        }

        return $helper->ask($input, $output, $pwFirst);
    }

    protected function promptForMnemonic(Bip39Mnemonic $bip39, InputInterface $input, OutputInterface $output): string
    {
        $helper = $this->getHelper('question');
        $question = new Question('Enter your bip39 seed:');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function ($answer) use ($bip39) {
            try {
                $bip39->mnemonicToEntropy($answer);
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    "Invalid mnemonic"
                );
            }
            return $answer;
        });
        $question->setMaxAttempts(2);
        return $helper->ask($input, $output, $question);
    }
}
