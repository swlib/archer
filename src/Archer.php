<?php

/*
 * This file is part of Swlib\Archer.
 *
 * (c) fdream <fdream@brae.co>
 *
 * This source file is subject to the APACHE 2.0 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Swlib;

class Archer extends Archer\AbstractArcher
{
    protected $task_callback;
    protected $params = [];

    public function setTaskCallback(callable $task_callback): self
    {
        $this->task_callback = $task_callback;

        return $this;
    }

    public function setParams(...$param): self
    {
        $this->params = $param;

        return $this;
    }

    /**
     * 投递一个Task进入队列异步执行，该方法立即返回
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后将该异常作为第三个参数传递给$finish_callback，若未设置则会产生一个warnning。
     * 注意2：Task执行时的协程与当前协程不是同一个
     * 注意3：Task执行时的协程与回调函数执行时的协程是同一个.
     *
     * @param callable $finish_callback
     *                                  $task_callback完成后触发的回调，参数1为Task的id，参数2为$task_callback的返回值，参数3为Task内抛出的\Throwable异常，参数2和3只会存在一个。可缺省
     *
     * @throws Archer\Exception\AddNewTaskFailException
     *
     * @return null|int Task的id
     */
    public function asyncExecute(?callable $finish_callback = null): int
    {
        if (!isset($this->task_callback)) {
            throw new Archer\Exception\RuntimeException('Not yet set task callback');
        }

        return self::task($this->task_callback, $this->params, $finish_callback);
    }

    /**
     * 投递一个Task进入队列，同时当前协程挂起。当该Task执行完成后，会恢复投递的协程，并返回Task的返回值。
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在这里抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @param float $timeout
     *                       超时时间，超时后函数会直接返回。注意：超时返回后Task仍会继续执行，不会中断。若缺省则表示不会超时
     *
     * @throws Archer\Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     * @throws Archer\Exception\TaskTimeoutException    超时时抛出的Exception，注意这个超时不会影响Task的执行
     *
     * @return mixed $task_callback的返回值
     */
    public function waitExecute(?float $timeout = null)
    {
        if (!isset($this->task_callback)) {
            throw new Archer\Exception\RuntimeException('Not yet set task callback');
        }

        return self::taskWait($this->task_callback, $this->params, $timeout);
    }

    /**
     * 投递一个Task进入队列，该方法立即返回刚才所投递的Task。通过执行$task->recv()获得执行结果
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在$task->recv()抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @throws Archer\Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     *
     * @return Archer\Task\Defer 刚投递的Task
     */
    public function deferExecute(): Archer\Task\Defer
    {
        if (!isset($this->task_callback)) {
            throw new Archer\Exception\RuntimeException('Not yet set task callback');
        }

        return self::taskDefer($this->task_callback, $this->params);
    }

    /**
     * 设置一个在指定时间后执行一次的Task，该Task并没有投递进入队列，所以不受队列size和最大并发数的影响。
     * Archer会捕获Task抛出的异常并产生一个warnning.
     *
     * @param int $after_time_ms 计时时间，单位为毫秒
     *
     * @return Task\Timer\Once 刚生成的Task
     */
    public function afterTimeExecute(int $after_time_ms): Archer\Task\Timer\Once
    {
        return self::taskTimerAfter($after_time_ms, $this->task_callback, $this->params);
    }

    /**
     * 设置一个间隔时钟定时器，该Task并没有投递进入队列，所以不受队列size和最大并发数的影响。
     * Archer会捕获Task抛出的异常并产生一个warnning.
     *
     * @param int $tick_time_ms     每次执行间隔时间，单位为毫秒
     * @param int $first_time_after 初次执行时间，单位为毫秒；若缺省，则初次执行时间与$tick_time_ms相等
     *
     * @return Task\Timer\Tick 刚生成的Task
     */
    public function tickExecute(int $tick_time_ms, ?int $first_time_after = null): Archer\Task\Timer\Tick
    {
        return self::taskTimerTick($tick_time_ms, $this->task_callback, $this->params, $first_time_after);
    }

    /**
     * 这个方法会向队列投递Task并立即返回Task id
     * 注意：Task执行时的协程与当前协程不是同一个.
     *
     * @param Archer\MultiTask $multi_task
     *                                     MultiTask容器
     *
     * @throws Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     *
     * @return int Task id
     */
    public function attachToMultiTask(Archer\MultiTask $multi_task): int
    {
        return $multi_task->addTask($this->task_callback, $this->params);
    }
}
