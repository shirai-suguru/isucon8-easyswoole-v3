<?php
/**
 */
namespace App\Utility\Pool;

use EasySwoole\Component\Pool\AbstractPool;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Mysqli;

class MysqlPool extends AbstractPool
{
    protected function createObject()
    {
        $conf = \EasySwoole\EasySwoole\Config::getInstance()->getConf("MYSQL");
        $dbConf = new Config($conf);
        return new MysqlObject($dbConf);
    }
}