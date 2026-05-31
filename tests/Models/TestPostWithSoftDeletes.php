<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestPostWithSoftDeletes extends Model
{
    use SoftDeletes;

    protected $table = 'test_posts_soft';

    protected $guarded = [];
}
