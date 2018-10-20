<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39Mnemonic;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\EnglishWordList;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\JapaneseWordList;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\Initializer;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class SetupWallet extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:create')
            // the short description shown while running "php bin/console list"
            ->setDescription('Create a new wallet')

            // An identifier is required for this wallet
            ->addArgument("database", InputArgument::REQUIRED, "Database to use for wallet")
            ->addArgument("identifier", InputArgument::REQUIRED, "Identifier for wallet")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Initialize wallet for regtest network")

            // wallet init options
            ->addOption('bip44', null, InputOption::VALUE_NONE, "Setup a bip44 wallet account")
            ->addOption('bip44-account', null, InputOption::VALUE_REQUIRED, "BIP44 'account' value", 0)
            ->addOption('bip44-cointype', null, InputOption::VALUE_REQUIRED, "BIP44 'cointype' value", 0)

            ->addOption('bip39-en', null, InputOption::VALUE_NONE, "Use the english wordlist for BIP39 (default)")
            ->addOption('bip39-jp', null, InputOption::VALUE_NONE, "Use the japanese wordlist for BIP39")
            ->addOption('bip39-custommnemonic', 'm', InputOption::VALUE_NONE, "Prompt for a user-provided BIP39 mnemonic")
            ->addOption('bip39-passphrase', 'p', InputOption::VALUE_NONE, "Prompt for a BIP39 passphrase")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
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

    private function getBip39Wordlist(InputInterface $input)
    {
        if ($input->getOption('bip39-jp')) {
            return new JapaneseWordList();
        } else {
            return new EnglishWordList();
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('database');
        $identifier = $input->getArgument('identifier');
        $fIsRegtest = (bool) $input->getOption('regtest');
        $fOwnMnemonic = (bool) $input->getOption('bip39-custommnemonic');
        $fBip39Pass = (bool) $input->getOption('bip39-passphrase');

        if ($fIsRegtest) {
            $params = new RegtestParams(new Math());
            $net = NetworkFactory::bitcoinRegtest();
        } else {
            $params = new Params(new Math());
            $net = NetworkFactory::bitcoin();
        }
        $ecAdapter = Bitcoin::getEcAdapter();
        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);
        $walletFactory = new Factory($db, $net, $ecAdapter);

        $wordlist = $this->getBip39Wordlist($input);
        $bip39 = MnemonicFactory::bip39($wordlist);
        if ($fOwnMnemonic) {
            $mnemonic = $this->promptForMnemonic($bip39, $input, $output);
        } else {
            $random = new Random();
            $entropy = $random->bytes(16);
            $mnemonic = $bip39->entropyToMnemonic($entropy);
        }

        $seedGenerator = new Bip39SeedGenerator();
        $output->write("<info>Your mnemonic is: $mnemonic</info>\n");
        $output->write("<comment>It is vital that you record your seed now so your wallet can\n" .
                    "be recovered in case of hardware failure.</comment>\n");

        $passphrase = '';
        if ($fBip39Pass) {
            $passphrase = $this->promptForPassphrase($input, $output);
        }

        $seed = $seedGenerator->getSeed($mnemonic, $passphrase);
        $root = HierarchicalKeyFactory::fromEntropy($seed);

        $bip44Wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $root, 0, 0);
        $addrGen = $bip44Wallet->getScriptGenerator();
        $dbScript = $addrGen->generate();
        $addrString = $dbScript->getAddress(new AddressCreator())->getAddress($net);
        echo "$addrString\n";
    }
}
