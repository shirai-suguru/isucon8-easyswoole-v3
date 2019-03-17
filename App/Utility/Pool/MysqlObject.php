<?php
/**
 */

namespace App\Utility\Pool;

use EasySwoole\Component\Pool\PoolObjectInterface;
use EasySwoole\Mysqli\Mysqli;

class MysqlObject extends Mysqli implements PoolObjectInterface
{
    /**
     * gc
     */
    public function gc()
    {
        // 重置为初始状态
        $this->resetDbStatus();
        // 关闭数据库连接
        $this->getMysqlClient()->close();
    }

    /**
     * objectRestore
     */
    public function objectRestore()
    {
        $this->resetDbStatus();
    }

    /**
     * @return bool
     */
    public function beforeUse(): bool
    {
        return $this->getMysqlClient()->connected;
    }
}
