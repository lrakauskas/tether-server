<?php

namespace Tests\Compatibility\Models;

use Illuminate\Database\Eloquent\Model;

class TestPost extends Model
{
    protected $connection = 'legacy-client';

    protected $table = 'posts';

    protected $guarded = [];
}
