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

class Co extends \Swlib\Archer\Task
{
    protected $result_receiver;

    public function __construct(callable $task_callback, ?array $params, \Swoole\Coroutine\Channel $result_receiver)
    {
        parent::__construct($task_callback, $params);
        $this->result_receiver = $result_receiver;
    }

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

        $this->result_receiver = null;
    }
}
