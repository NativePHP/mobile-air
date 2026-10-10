<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model, with a table only in the tests that make one.
 * Its attributes are magic properties, which is what a dotted
 * `when` key has to read when an event carries a model.
 */
class Order extends Model
{
    protected $guarded = [];
}
