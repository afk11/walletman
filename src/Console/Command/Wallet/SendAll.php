<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39Mnemonic;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\P2pSyncDaemon;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class SendAll extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:sendall')

            // the short description shown while running "php bin/console list"
            ->setDescription('Sends entire balance to the provided address')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")
            ->addArgument('identifier', InputArgument::REQUIRED, "Wallet identifier")
            ->addArgument('address', InputArgument::REQUIRED, "Destination for funds")

            ->addOption('feerate-custom', null, InputOption::VALUE_REQUIRED, "Provide fee rate in satoshis per kilobyte")
            ->addOption('bip39-passphrase', 'p', InputOption::VALUE_NONE, "Prompt for a BIP39 passphrase")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Start wallet in regtest mode")

            ->setHelp('This command will create, sign, and broadcast a transaction emptying the wallet, sending all funds to the provided address');
    }
    private function promptForPassphrase(InputInterface $input, OutputInterface $output)
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
    private function promptForMnemonic(Bip39Mnemonic $bip39, InputInterface $input, OutputInterface $output)
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
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fIsRegtest = (bool) $input->getOption('regtest');
        $fBip39Pass = (bool) $input->getOption('bip39-passphrase');
        $customFeeRate = (int) $input->getOption('feerate-custom');
        $path = $input->getArgument('database');
        $identifier = $input->getArgument('identifier');

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $dbMgr = new DbManager();

        if ($fIsRegtest) {
            $net = NetworkFactory::bitcoinRegtest();
        } else {
            $net = NetworkFactory::bitcoin();
        }

        if ($customFeeRate) {
            $feeRate = $customFeeRate;
        } else {
            throw new \RuntimeException("must select a feerate option");
        }

        $destAddress = $input->getArgument('address');
        $scriptPubKey = $addrCreator->fromString($destAddress, $net)->getScriptPubKey();

        $db = $dbMgr->loadDb($path);
        $walletFactory = new Factory($db, $net, $ecAdapter);

        /** @var Bip44Wallet $bip44Wallet */
        $bip44Wallet = $walletFactory->loadWallet($identifier);

        $bip39 = MnemonicFactory::bip39();
        $mnemonic = $this->promptForMnemonic($bip39, $input, $output);

        $passphrase = '';
        if ($fBip39Pass) {
            $passphrase = $this->promptForPassphrase($input, $output);
        }

        $seed = (new Bip39SeedGenerator())->getSeed($mnemonic, $passphrase);
        $rootNode = HierarchicalKeyFactory::fromEntropy($seed, $ecAdapter);
        $accountNode = $rootNode->derivePath("44'/0'/0'");

        $bip44Wallet->loadAccountPrivateKey($accountNode);

        $signedTx = $bip44Wallet->sendAllCoins($scriptPubKey, $feeRate);
        echo $signedTx->getHex().PHP_EOL;
    }
}
