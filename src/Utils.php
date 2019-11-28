<?php

/*
 * This file is part of the QBBR code.
 *
 * (c) Sokolov Innokenty <imqbbr@gmail.com>
 */

namespace QBBR;

class Utils
{
    public static function getBaseDir(): string
    {
        return \dirname(__DIR__);
    }
}
