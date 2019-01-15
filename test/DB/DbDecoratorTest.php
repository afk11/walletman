<?php

declare(strict_types=1);

namespace BitWasp\Test\DB;

class DbDecoratorTest extends DBTest
{
    protected $dbDebug = true;
    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->dbWriter = function () {
        };
    }
}
