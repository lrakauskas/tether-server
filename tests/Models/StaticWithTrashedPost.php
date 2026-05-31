<?php

namespace Tests\Models;

class StaticWithTrashedPost extends TestPost
{
    public static function withTrashed()
    {
        return static::query();
    }
}
