<?php

/*
 * This file is part of Swlib\Archer.
 *
 * (c) fdream <fdream@brae.co>
 *
 * This source file is subject to the APACHE 2.0 license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Swlib\Archer\Exception;

class AddNewTaskFailException extends \Exception
{
    public function __construct()
    {
        parent::__construct('Add new task fail because channel closed unexpectedly');
    }
}
