<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Benchmark;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DbManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MemoryAllHeaders extends Command
{
    private $logInterval = 100000;

    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('benchmark:memory-all-headers')

            // the short description shown while running "php bin/console list"
            ->setDescription('Dumps the memory usage while loading chain data into memory')

            ->addArgument("database", InputArgument::REQUIRED, "Name of database")

            // optionally use regtest mode
            ->addOption('dbheader', null, InputOption::VALUE_NONE, "Load DbHeader map into mmeory")
            ->addOption('header', null, InputOption::VALUE_NONE, "Load BlockHeaderInterface map into memory")
            ->addOption('headerbin', null, InputOption::VALUE_NONE, "Load BlockHeaderInterface map into memory")
            ->addOption('null', null, InputOption::VALUE_NONE, "Load BlockHeaderInterface map into memory")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $this->getStringArgument($input, "database");
        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);

        $features = [0, 1, 2, 3];
        foreach ($features as $feature) {
            $begin = microtime(true);
            $this->benchmarkMap($db, $feature);
            $duration = microtime(true) - $begin;
            echo "duration: $duration\n";

            $begin = microtime(true);
            $this->benchmarkArray($db, $feature);
            $duration = microtime(true) - $begin;
            echo "duration: $duration\n";
        }
    }

    private function getDataInfo(int $feature)
    {
        if ($feature === 3) {
            $dataName = "BlockHeaderInterface";
            $size = 85;
        } else if ($feature === 2) {
            $dataName = "headerbin";
            /** @var string $data */
            $size = 80;
        } else if ($feature === 1) {
            $dataName = "number";
            $size = 1;
        } else {
            $dataName = 'DbHeader';
            $size = 90;
        }
        return [$dataName, $size];
    }
    private function benchmarkMap(DBInterface $db, int $feature)
    {

        $statement = $db->getPdo()->prepare("SELECT * FROM header");
        $statement->execute();

        list ($dataName, $size) = $this->getDataInfo($feature);
        echo "------------------------ ".__FUNCTION__." $feature {$dataName} \n";

        $usage = memory_get_usage(true);
        $map = [];
        $count = 0;
        while ($data = $statement->fetchObject(DbHeader::class)) {
            $count++;
            /** @var DbHeader $data */
            $hash = $data->getHash();
            if ($feature === 3) {
                $data = $data->getHeader();
                /** @var BlockHeaderInterface $data */
            } else if ($feature === 2) {
                $data = $data->getHeader()->getBinary();
                /** @var string $data */
            } else if ($feature === 1) {
                $data = 1;
            }

            $map[$hash->getBinary()] = $data;
            if ($count % $this->logInterval === 0) {
                echo "$count map usage " . number_format((memory_get_usage(true)-$usage), 0) . PHP_EOL;
            }
            unset($data);
        }
        $statement->closeCursor();
        $usageDiff = memory_get_usage(true) - $usage;
        echo "Usage diff: ".number_format($usageDiff, 0)." bytes\n";
        echo "Objects: $count\n";
        echo "diff per obj (avg): ".($usageDiff/$count)."\n";

        $theoreticalMin = ((32+$size)*$count);
        echo "theoretical min: (hash:32+$dataName:$size)*$count = ".number_format($theoreticalMin, 0)."\n";
        echo "real to theoretical per obj: " . ($usageDiff/$theoreticalMin) . "x\n";
    }

    private function benchmarkArray(DBInterface $db, int $feature)
    {
        $statement = $db->getPdo()->prepare("SELECT * FROM header");
        $statement->execute();

        list ($dataName, $size) = $this->getDataInfo($feature);
        echo "------------------------ ".__FUNCTION__." $feature {$dataName} \n";

        $usage = memory_get_usage(true);
        $map = [];
        $count = 0;

        while ($data = $statement->fetchObject(DbHeader::class)) {
            /** @var DbHeader $data */
            if ($feature === 3) {
                $data = $data->getHeader();
                /** @var BlockHeaderInterface $data */
            } else if ($feature === 2) {
                $data = $data->getHeader()->getBinary();
                /** @var string $data */
            } else if ($feature === 1) {
                $data = 1;
            }

            $map[] = $data;
            if ($count % $this->logInterval === 0) {
                echo "$count array usage " . number_format((memory_get_usage(true)-$usage), 0) . PHP_EOL;
            }

            $count = count($map);
        }
        $statement->closeCursor();
        $usageDiff = memory_get_usage(true) - $usage;
        echo "Usage diff: ".number_format($usageDiff, 0)." bytes\n";
        echo "Objects: $count\n";
        echo "diff per obj (avg): ".($usageDiff/$count)."\n";

        $theoreticalMin = (($size)*$count);
        echo "theoretical min: ($dataName:$size)*$count = ".number_format($theoreticalMin, 0)."\n";
        echo "real to theoretical per obj: " . ($usageDiff/$theoreticalMin) . "x\n";
    }
}
