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

class Defer extends \Swlib\Archer\Task
{
    protected $result_receiver;

    public function __construct(callable $task_callback, ?array $params)
    {
        parent::__construct($task_callback, $params);
        $this->result_receiver = new \Swoole\Coroutine\Channel(1);
    }

    /**
     * 不要手动执行该方法！！！
     */
    public function execute()
    {
        $ret = null;
        $e = $this->callFunc($ret);
        if (isset($e)) {
            $this->result_receiver->push($e);
        } else {
            // 将返回值放入数组中是为了与\Throwable区分开
            $this->result_receiver->push([
                $ret,
            ]);
        }
    }

    public function recv(?float $timeout = null)
    {
        $ret = $this->result_receiver->pop($timeout ?? 0);
        $errCode = $this->result_receiver->errCode;
        if (SWOOLE_CHANNEL_OK === $errCode) {
            if (\is_array($ret)) {
                return current($ret);
            }

            throw $ret;
        }
        if (SWOOLE_CHANNEL_TIMEOUT === $errCode) {
            throw new \Swlib\Archer\Exception\TaskTimeoutException();
        }

        throw new \Swlib\Archer\Exception\RuntimeException('Channel pop error');
    }
}
