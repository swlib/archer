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

class Once extends \Swlib\Archer\Task\Timer
{
    public function __construct(callable $task_callback, ?array $params, int $after)
    {
        parent::__construct($task_callback, $params);
        $now = (int) (microtime(true) * 1000);
        $this->execute_timestamp = $now + $after;
    }
}
