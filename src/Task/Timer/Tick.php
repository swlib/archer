<?php

/*
 * This file is part of Swlib\Archer.
 *
 * (c) fdream <fdream@brae.co>
 *
 * This source file is subject to the APACHE 2.0 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Swlib\Archer\Task\Timer;

class Tick extends \Swlib\Archer\Task\Timer
{
    protected $tick_length;
    protected $stopped = false;

    public function __construct(callable $task_callback, ?array $params, int $tick, int $first_time_after)
    {
        parent::__construct($task_callback, $params);
        $now = (int) (microtime(true) * 1000);
        $this->execute_timestamp = $now + $first_time_after;
        $this->tick_length = $tick;
    }

    /**
     * 不要手动执行该方法！！！
     */
    public function execute()
    {
        if ($this->stopped) {
            $this->task_callback = null;
            $this->params = null;

            return;
        }
        $ret = null;
        $e = $this->callFunc($ret, false);
        if (isset($e)) {
            trigger_error("Throwable catched in timer:{$e->getMessage()} in {$e->getFile()}({$e->getLine()})");
        }

        // 有可能在执行过程中，stopped变为了true
        if ($this->stopped) {
            $this->task_callback = null;
            $this->params = null;

            return;
        }
        $this->execute_timestamp += $this->tick_length;
        \Swlib\Archer\TimerHeap::getInstance()->insert($this);
    }

    /**
     * 不要手动执行该方法，请使用 clearTimer() 使Tick停止继续执行.
     *
     * @return bool
     */
    public function stop(): bool
    {
        if ($this->stopped) {
            return false;
        }
        $this->stopped = true;

        return true;
    }
}
