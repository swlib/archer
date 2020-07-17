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

abstract class Task
{
    protected static $finish_func;
    protected $task_callback;
    protected $params;
    protected $id;
    private static $counter = 0;

    public function __construct(callable $task_callback, ?array $params = null)
    {
        $this->id = ++self::$counter;
        $this->task_callback = $task_callback;
        $this->params = $params ?? [];
    }

    /**
     * 在Task内调用可以获取当前的Taskid，否则会返回null.
     *
     * @return null|int
     */
    public static function getCurrentTaskId(): ?int
    {
        return \Swoole\Coroutine::getContext()->swlib_archer_taskid ?? null;
    }

    /**
     * 这里设置的回调函数会在每个Task结束时执行，不论Task是否抛出了异常，不论Task模式.
     *
     * @param callable $func
     */
    public static function registerTaskFinishFunc(callable $func): void
    {
        self::$finish_func = $func;
    }

    public function getId(): int
    {
        return $this->id;
    }

    abstract public function execute();

    protected function callFunc(&$ret, bool $clear_after_finish = true): ?\Throwable
    {
        if (!isset($this->task_callback)) {
            throw new Exception\RuntimeException(
                'Task already executed. This maybe caused by manually calling execute().'
            );
        }

        try {
            $context = \Swoole\Coroutine::getContext();
            $context->swlib_archer_taskid = $this->id;
            $ret = ($this->task_callback)(...$this->params);
            $return = null;
            if (isset(self::$finish_func)) {
                (self::$finish_func)($this->id, $ret, null);
            }
        } catch (\Throwable $e) {
            $return = $e;
            if (isset(self::$finish_func)) {
                (self::$finish_func)($this->id, null, $e);
            }
        }
        $context->swlib_archer_taskid = null;
        if ($clear_after_finish) {
            $this->task_callback = null;
            $this->params = null;
        }
        \Swlib\Archer\Queue::getInstance()->taskOver();

        return $return;
    }
}
