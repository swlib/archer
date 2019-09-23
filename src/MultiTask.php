<?php

/*
 * This file is part of Swlib\Archer.
 *
 * (c) fdream <fdream@brae.co>
 *
 * This source file is subject to the APACHE 2.0 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Swlib\Archer;

class MultiTask implements \Countable
{
    private const STATUS_PREPARING = 0;
    private const STATUS_WAITING = 1;
    private const STATUS_DONE = 2;
    private const TYPE_WAIT_FOR_ALL = 0;
    private const TYPE_YIELD_EACH_ONE = 1;
    private static $counter = 0;
    private $max_concurrent;
    private $max_concurrent_queue;
    private $running_task;
    private $id;
    private $result_map;
    private $error_map;
    private $status;
    private $size;
    private $result_receiver;
    private $type;
    /**
     * 键值对，用来记录每个Task的执行状态
     *
     * @var array
     */
    private $task_ids;

    public function __construct(?int $max_concurrent = null)
    {
        if (isset($max_concurrent)) {
            $this->max_concurrent = $max_concurrent;
            $this->max_concurrent_queue = new \SplQueue();
        }
        $this->running_task = 0;
        $this->id = ++self::$counter;
        $this->status = self::STATUS_PREPARING;
        $this->result_map = [];
        $this->error_map = [];
        $this->task_ids = [];
        $this->size = 0;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * 这个方法会向队列投递Task并立即返回Task id
     * 注意：Task执行时的协程与当前协程不是同一个.
     *
     * @param callable $task_callback
     * @param array    $params
     *
     * @throws Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     *
     * @return int Task id
     */
    public function addTask(callable $task_callback, ?array $params = null): int
    {
        if (self::STATUS_PREPARING !== $this->status) {
            throw new Exception\RuntimeException('Wrong status when adding task:'.$this->status);
        }
        $task = new Task\CoPackUnit($task_callback, $params, $this);
        $this->task_ids[$task->getId()] = null;
        ++$this->size;
        if (isset($this->max_concurrent_queue) && $this->running_task >= $this->max_concurrent) {
            $this->max_concurrent_queue->push($task);
        } else {
            ++$this->running_task;
            if (!Queue::getInstance()->push($task)) {
                throw new Exception\AddNewTaskFailException();
            }
        }

        return $task->getId();
    }

    public function count(): int
    {
        return $this->size;
    }

    /**
     * 若运行时所有Task已执行完，则会直接以键值对的形式返回所有Task的返回值。
     * 否则当前协程挂起。当该所有Task执行完成后，会恢复投递的协程，并返回结果。
     * 注意1：若Task抛出了任何\Throwable异常，本方法返回的结果集中将不包含该Task对应的id，需要使用getError($id)方法获取异常对象
     *
     * @param float $timeout
     *                       超时时间，缺省表示不超时
     *
     * @throws Exception\RuntimeException     因状态错误抛出的Exception，这是一种正常情况不应该出现的Exception
     * @throws exception\TaskTimeoutException 超时时抛出的Exception，注意这个超时不会影响Task的执行
     *
     * @return array
     */
    public function waitForAll(?float $timeout = null): array
    {
        if (isset($timeout)) {
            $start_time = microtime(true);
        }
        $this->type = self::TYPE_WAIT_FOR_ALL;
        if (!$this->prepareDone()) {
            return [];
        }
        // 已全部执行完
        if (0 === $this->getUnfinishedTaskCount()) {
            return $this->result_map;
        }
        // 尚未执行完，设置接收器
        $this->result_receiver = new \Swoole\Coroutine\Channel(1);
        if (isset($timeout)) {
            // 由于上面的操作可能会发生协程切换占用时间，这里调整一下pop的timeout减少时间误差
            $time_pass = microtime(true) - $start_time;
            if ($time_pass < $timeout) {
                $result = $this->result_receiver->pop($timeout - $time_pass);

                if (true === $result) {
                    $this->result_receiver = null;

                    return $this->result_map;
                }
                if (SWOOLE_CHANNEL_CLOSED === $this->result_receiver->errCode) {
                    throw new \Swlib\Archer\Exception\RuntimeException('Channel pop error');
                }
            }
            $this->result_receiver = null;

            throw new Exception\TaskTimeoutException();
        }
        $this->result_receiver->pop();
        if (SWOOLE_CHANNEL_OK !== $this->result_receiver->errCode) {
            throw new \Swlib\Archer\Exception\RuntimeException('Channel pop error');
        }
        $this->result_receiver = null;

        return $this->result_map;
    }

    /**
     * 若运行时已经有些Task已执行完，则会按执行完毕的顺序将他们先yield出来。
     * 若这之后仍存在未执行完的Task，则当前协程将会挂起，每有一个Task执行完，当前协程将恢复且其结果就会以以键值对的方式yield出来，然后协程会挂起等待下一个执行完的Task。
     * 注意1：若Task抛出了任何\Throwable异常，本方法将不会yild出该Task对应的键值对，getReturn()获取结果集数组也不会包含，需要使用getError($id)方法获取异常对象
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @param float $timeout
     *                       总超时时间，缺省表示不超时。（注意该时间表示花费在本方法内的时间，外界调用该方法处理每个返回值所耗费的时间不计入）
     *
     * @throws Exception\RuntimeException     因状态错误抛出的Exception，这是一种正常情况不应该出现的Exception
     * @throws exception\TaskTimeoutException 超时时抛出的Exception，注意这个超时不会影响Task的执行
     *
     * @return \Generator 迭代完所有项之后，可以通过 getReturn() 获取结果集数组
     */
    public function yieldEachOne(?float $timeout = null): \Generator
    {
        if (isset($timeout)) {
            $start_time = microtime(true);
        }
        $this->type = self::TYPE_YIELD_EACH_ONE;
        if (!$this->prepareDone()) {
            return [];
        }
        $unfinished_task_count = $this->getUnfinishedTaskCount();
        if (0 === $unfinished_task_count) {
            foreach ($this->result_map as $k => $v) {
                yield $k => $v;
            }

            return $this->result_map;
        }

        // 先设置接收器并记录下已有的result数量，yield已有Result的过程中若有新的Task完成，则不会产生影响
        $this->result_receiver = new \Swoole\Coroutine\Channel($unfinished_task_count);
        $count = \count($this->result_map);
        $outside_time_cost = 0;
        foreach ($this->result_map as $k => $v) {
            if (0 === $count) {
                break;
            }
            --$count;
            $yield_time = microtime(true);
            yield $k => $v;
            $outside_time_cost += microtime(true) - $yield_time;
        }

        if (isset($timeout)) {
            for ($i = 0; $i < $unfinished_task_count; ++$i) {
                $time_pass = microtime(true) - $start_time - $outside_time_cost;
                if ($time_pass < $timeout) {
                    $id = $this->result_receiver->pop($timeout - $time_pass);
                    if (is_numeric($id)) {
                        // 若不存在于 $this->result_map 中，表示Task抛出了异常
                        if (array_key_exists($id, $this->result_map)) {
                            $yield_time = microtime(true);
                            yield $id => $this->result_map[$id];
                            $outside_time_cost += microtime(true) - $yield_time;
                        }

                        continue;
                    }
                    if (SWOOLE_CHANNEL_CLOSED === $this->result_receiver->errCode) {
                        throw new \Swlib\Archer\Exception\RuntimeException('Channel pop error');
                    }
                }
                $this->result_receiver = null;

                throw new Exception\TaskTimeoutException();
            }
        } else {
            for ($i = 0; $i < $unfinished_task_count; ++$i) {
                $id = $this->result_receiver->pop();
                if (SWOOLE_CHANNEL_OK !== $this->result_receiver->errCode) {
                    throw new \Swlib\Archer\Exception\RuntimeException('Channel pop error');
                }
                // 若不存在于 $this->result_map 中，表示Task抛出了异常
                if (array_key_exists($id, $this->result_map)) {
                    yield $id => $this->result_map[$id];
                }
            }
        }
        $this->status = self::STATUS_DONE;
        $this->result_receiver = null;

        return $this->result_map;
    }

    public function registerResult(int $id, $result): void
    {
        $this->checkRegisterPrecondition($id);
        $this->result_map[$id] = $result;
        $this->notifyReceiver($id);
    }

    public function registerError(int $id, \Throwable $e): void
    {
        $this->checkRegisterPrecondition($id);
        $this->error_map[$id] = $e;
        $this->notifyReceiver($id);
    }

    public function getError(int $id): ?\Throwable
    {
        if (isset($this->error_map) && array_key_exists($id, $this->error_map)) {
            return $this->error_map[$id];
        }

        return null;
    }

    public function getErrorMap(): array
    {
        if (!isset($this->error_map)) {
            return [];
        }

        return $this->error_map;
    }

    private function prepareDone(): bool
    {
        if (self::STATUS_PREPARING !== $this->status) {
            throw new Exception\RuntimeException('Wrong status when executing:'.$this->status);
        }
        if (empty($this->task_ids)) {
            $this->status = self::STATUS_DONE;

            return false;
        }
        $this->status = self::STATUS_WAITING;

        return true;
    }

    private function getUnfinishedTaskCount(): int
    {
        return $this->size - \count($this->result_map) - \count($this->error_map);
    }

    private function checkRegisterPrecondition(int $id)
    {
        if (self::STATUS_DONE === $this->status) {
            throw new Exception\RuntimeException('Wrong status when registering result:'.$this->status);
        }
        if (!array_key_exists($id, $this->task_ids)) {
            throw new Exception\RuntimeException('Task not found when registering result');
        }
        if (array_key_exists($id, $this->result_map)) {
            throw new Exception\RuntimeException('Result already present when registering result');
        }
        if (array_key_exists($id, $this->error_map)) {
            throw new Exception\RuntimeException('Error already present when registering result');
        }
    }

    private function notifyReceiver(int $id)
    {
        --$this->running_task;
        if (isset($this->result_receiver)) {
            if (self::TYPE_YIELD_EACH_ONE === $this->type) {
                $this->result_receiver->push($id);
            } elseif (0 === $this->getUnfinishedTaskCount()) {
                $this->status = self::STATUS_DONE;
                $this->result_receiver->push(true);
            }
        }
        if (isset($this->max_concurrent_queue) && !$this->max_concurrent_queue->isEmpty() && $this->running_task < $this->max_concurrent) {
            ++$this->running_task;
            if (!Queue::getInstance()->push($this->max_concurrent_queue->pop())) {
                throw new Exception\AddNewTaskFailException();
            }
        }
    }
}
