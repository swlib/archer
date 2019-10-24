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

class TimerHeap extends \SplHeap
{
    private static $instance;
    private $task_map = [];
    /**
     * 缓存所有Task的时间戳，即便Timer被移除，时间戳也依然缓存。
     * 这是为了保证最小堆内元素的比较依据不发生变化，避免数据Corrupted.
     *
     * @var array
     */
    private $time_map = [];
    private $tick_task_map = [];
    private $tick_heap;
    private $receiver;
    private $receive_flag;

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            \Swoole\Coroutine::create([
                self::$instance,
                'loop',
            ]);
        }

        return self::$instance;
    }

    public static function stop(): void
    {
        if (isset(self::$instance) && isset(self::$instance->receiver)) {
            self::$instance->receiver->close();
        }
    }

    public function loop(): void
    {
        $this->tick_heap = new \SplMinHeap();
        $this->receiver = new \Swoole\Coroutine\Channel();
        do {
            $this->receive_flag = true;
            $result = $this->receiver->pop();
            $this->receive_flag = false;
            if ($result === false) {
                return;
            }
            if (SWOOLE_CHANNEL_OK !== $this->receiver->errCode) {
                throw new Exception\RuntimeException('Channel pop error');
            }
            for ($task = $this->extract(); isset($task); $task = $this->extract()) {
                // 下一个Task的执行时间未到，注册Tick事件并退出循环开始重新监听
                if (is_numeric($task)) {
                    $this->registerTick($task);

                    break;
                }
                \Swoole\Coroutine::create(
                    [
                        $task,
                        'execute',
                    ]
                );
            }
        } while (true);
    }

    public function extract()
    {
        while (!$this->isEmpty()) {
            $id = $this->top();

            // 已被删除
            if (!array_key_exists($id, $this->task_map)) {
                parent::extract();
                unset($this->time_map[$id]);

                continue;
            }

            $now = (int) (microtime(true) * 1000);
            // 还未达到最近任务的执行时间
            if ($this->time_map[$id] > $now) {
                return $this->time_map[$id] - $now;
            }
            // 符合运行条件
            parent::extract();
            unset($this->time_map[$id]);
            $task = $this->task_map[$id];
            unset($this->task_map[$id]);

            return $task;
        }

        return null;
    }

    public function insert($task): void
    {
        if (!$task instanceof Task\Timer) {
            throw new Exception\RuntimeException('Only accept Swlib\\Archer\\Task\\Timer');
        }
        $id = $task->getId();
        $this->task_map[$id] = $task;
        $this->time_map[$id] = $task->execute_timestamp;
        if ($task instanceof Task\Timer\Tick) {
            $this->tick_task_map[$id] = $task;
        }
        parent::insert($id);
        $after_ms = $task->execute_timestamp - (int) (microtime(true) * 1000);
        if ($after_ms > 0) {
            $this->registerTick($after_ms);
        } else {
            $this->tick(false);
        }
    }

    public function delete(int $id): bool
    {
        // Tick task 的删除要特殊处理，调用stop方法使Task不再继续执行。因为有可能在删除时该Task正在执行的过程中，从而在 $this->task_map 中不存在
        if (array_key_exists($id, $this->tick_task_map)) {
            $this->tick_task_map[$id]->stop();
            unset($this->tick_task_map[$id]);
            if (array_key_exists($id, $this->task_map)) {
                unset($this->task_map[$id]);
            }

            return true;
        }
        if (!array_key_exists($id, $this->task_map)) {
            return false;
        }
        unset($this->task_map[$id]);

        return true;
    }

    public function tick(bool $has_registered = true): void
    {
        if ($has_registered) {
            $this->tick_heap->extract();
        }

        // 如果队列正在执行状态，是无需再注册Tick的。因为当队列遇到一个时间未到的Task后会自动注册Tick
        if ($this->receive_flag) {
            $this->receiver->push(true);
        }
    }

    protected function compare($task_id_1, $task_id_2)
    {
        $time_offset = $this->time_map[$task_id_2] - $this->time_map[$task_id_1];

        // 时间相同的情况下，先投递的先执行
        if (0 === $time_offset) {
            return $task_id_2 - $task_id_1;
        }

        return $time_offset;
    }

    private function registerTick(int $after_ms): bool
    {
        $tick_timestamp_ms = (int) (microtime(true) * 1000) + $after_ms;
        if (!$this->tick_heap->isEmpty()) {
            $timestamp_ms = $this->tick_heap->top();

            // 在目标时间之前，已注册过一次Tick，无需在这之后再注册
            if ($timestamp_ms <= $tick_timestamp_ms) {
                return false;
            }
        }
        $this->tick_heap->insert($tick_timestamp_ms);
        $self = $this;
        \Swoole\Coroutine::create(function () use ($after_ms, $self) {
            \Swoole\Coroutine::sleep(round($after_ms / 1000 + 0.0005, 3));
            $self->tick();
        });

        return true;
    }
}
