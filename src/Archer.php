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

abstract class Archer
{
    /**
     * 投递一个Task进入队列异步执行，该方法立即返回
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后将该异常作为第三个参数传递给$finish_callback，若未设置则会产生一个warnning。
     * 注意2：Task执行时的协程与当前协程不是同一个
     * 注意3：Task执行时的协程与回调函数执行时的协程是同一个.
     *
     * @param callable $task_callback
     *                                  需要执行的函数
     * @param array    $params
     *                                  传递进$task_callback中的参数，可缺省
     * @param callable $finish_callback
     *                                  $task_callback完成后触发的回调，参数1为Task的id，参数2为$task_callback的返回值，参数3为Task内抛出的\Throwable异常，参数2和3只会存在一个。可缺省
     *
     * @throws Archer\Exception\AddNewTaskFailException
     *
     * @return null|int Task的id
     */
    public static function task(callable $task_callback, ?array $params = null, ?callable $finish_callback = null): int
    {
        $task = new Archer\Task\Async($task_callback, $params, $finish_callback);
        if (!Archer\Queue::getInstance()->push($task)) {
            throw new Archer\Exception\AddNewTaskFailException();
        }

        return $task->getId();
    }

    /**
     * 投递一个Task进入队列，同时当前协程挂起。当该Task执行完成后，会恢复投递的协程，并返回Task的返回值。
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在这里抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @param callable $task_callback
     *                                需要执行的函数
     * @param array    $params
     *                                传递进$task_callback中的参数，可缺省
     * @param float    $timeout
     *                                超时时间，超时后函数会直接返回。注意：超时返回后Task仍会继续执行，不会中断。若缺省则表示不会超时
     *
     * @throws Archer\Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     * @throws Archer\Exception\TaskTimeoutException    超时时抛出的Exception，注意这个超时不会影响Task的执行
     *
     * @return mixed $task_callback的返回值
     */
    public static function taskWait(callable $task_callback, ?array $params = null, ?float $timeout = null)
    {
        if (isset($timeout)) {
            $start_time = microtime(true);
        }

        $result_receiver = new \Swoole\Coroutine\Channel();
        $task = new Archer\Task\Co($task_callback, $params, $result_receiver);
        if (!Archer\Queue::getInstance()->push($task)) {
            throw new Archer\Exception\AddNewTaskFailException();
        }
        if (isset($timeout)) {
            // 由于上面的操作可能会发生协程切换占用时间，这里调整一下pop的timeout减少时间误差
            $time_pass = microtime(true) - $start_time;
            if ($time_pass < $timeout) {
                $result = $result_receiver->pop($timeout - $time_pass);

                $errCode = $result_receiver->errCode;
                if (SWOOLE_CHANNEL_OK === $errCode) {
                    if (\is_array($result)) {
                        return current($result);
                    }

                    throw $result;
                }
                if (SWOOLE_CHANNEL_TIMEOUT === $errCode) {
                    throw new \Swlib\Archer\Exception\TaskTimeoutException();
                }

                throw new Archer\Exception\RuntimeException('Channel pop error');
            }

            throw new Archer\Exception\TaskTimeoutException();
        }
        $result = $result_receiver->pop();
        $errCode = $result_receiver->errCode;
        if (SWOOLE_CHANNEL_OK === $errCode) {
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return current($result);
        }

        throw new \Swlib\Archer\Exception\RuntimeException('Channel pop error');
    }

    /**
     * 投递一个Task进入队列，该方法立即返回刚才所投递的Task。通过执行$task->recv()获得执行结果
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在$task->recv()抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @param callable $task_callback
     *                                需要执行的函数
     * @param array    $params
     *                                传递进$task_callback中的参数，可缺省
     *
     * @throws Archer\Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     *
     * @return Archer\Task\Defer 刚投递的Task
     */
    public static function taskDefer(callable $task_callback, ?array $params = null): Archer\Task\Defer
    {
        $task = new Archer\Task\Defer($task_callback, $params);
        if (!Archer\Queue::getInstance()->push($task)) {
            throw new Archer\Exception\AddNewTaskFailException();
        }

        return $task;
    }

    /**
     * 设置一个在指定时间后执行一次的Task，该Task并没有投递进入队列，所以不受队列size和最大并发数的影响。
     * Archer会捕获Task抛出的异常并产生一个warnning.
     *
     * @param float    $after_time_ms 计时时间
     * @param callable $task_callback
     *                                需要执行的函数
     * @param array    $params
     *                                传递进$task_callback中的参数，可缺省
     *
     * @return int Task的id
     */
    public static function taskTimerAfter(float $after_time, callable $task_callback, ?array $params = null): int
    {
        $task = new Archer\Task\Timer\Once($task_callback, $params, (int) ($after_time * 1000));
        Archer\TimerHeap::getInstance()->insert($task);

        return $task->getId();
    }

    /**
     * 设置一个间隔时钟定时器，该Task并没有投递进入队列，所以不受队列size和最大并发数的影响。
     * Archer会捕获Task抛出的异常并产生一个warnning.
     *
     * @param float    $tick_time        每次执行间隔时间
     * @param callable $task_callback    需要执行的函数
     * @param array    $params           传递进$task_callback中的参数，可缺省
     * @param float    $first_time_after 初次执行时间；若缺省，则初次执行时间为$tick_time
     *
     * @return int Task的id
     */
    public static function taskTimerTick(float $tick_time, callable $task_callback, ?array $params = null, ?float $first_time_after = null): int
    {
        $task = new Archer\Task\Timer\Tick($task_callback, $params, (int) ($tick_time * 1000), (int) (($first_time_after ?? $tick_time) * 1000));
        Archer\TimerHeap::getInstance()->insert($task);

        return $task->getId();
    }

    /**
     * @param int $task_id 通过 $task->getId() 获得
     *
     * @return bool 是否成功删除；删除失败是因为已经执行或taskid不存在
     */
    public static function clearTimerTask(int $task_id): bool
    {
        return Archer\TimerHeap::getInstance()->delete($task_id);
    }

    /**
     * 获取多Task的处理容器，每次执行都是获取一个全新的对象
     * 
     * @param int $max_concurrent 该处理容器中的最大并行数量
     *
     * @return Archer\MultiTask
     */
    public static function getMultiTask(?int $max_concurrent = null): Archer\MultiTask
    {
        return new Archer\MultiTask($max_concurrent);
    }
}
