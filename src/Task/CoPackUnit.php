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

class CoPackUnit extends \Swlib\Archer\Task
{
    protected $multi_task;

    public function __construct(callable $task_callback, ?array $params, \Swlib\Archer\MultiTask $multi_task)
    {
        parent::__construct($task_callback, $params);
        $this->multi_task = $multi_task;
    }

    public function execute()
    {
        $ret = null;
        $e = $this->callFunc($ret);
        if (isset($e)) {
            $this->multi_task->registerError($this->id, $e);
        } else {
            $this->multi_task->registerResult($this->id, $ret);
        }

        $this->multi_task = null;
    }
}
