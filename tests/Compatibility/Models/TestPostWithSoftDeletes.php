<?php

namespace Tests\Compatibility\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class TestPostWithSoftDeletes extends TestPost
{
    use SoftDeletes;

    protected $table = 'comments';
}
