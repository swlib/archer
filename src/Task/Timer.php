<?php

/*
 * This file is part of Swlib\Archer.
 *
 * (c) fdream <fdream@brae.co>
 *
 * This source file is subject to the APACHE 2.0 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Swlib\Archer\Task;

abstract class Timer extends \Swlib\Archer\Task
{
    public $execute_timestamp;

    /**
     * 不要手动执行该方法！！！
     */
    public function execute()
    {
        $ret = null;
        $e = $this->callFunc($ret, true);
        if (isset($e)) {
            trigger_error("Throwable catched in timer:{$e->getMessage()} in {$e->getFile()}({$e->getLine()})");
        }
    }

    /**
     * @return bool 是否成功删除；删除失败是因为已经执行
     */
    public function clearTimer(): bool
    {
        return \Swlib\Archer\TimerHeap::getInstance()->delete($this->id);
    }
}
