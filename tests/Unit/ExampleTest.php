<?php

namespace Tests\Unit;

use Tests\Concerns\RefreshDatabaseAsOwner;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    public function test_that_true_is_true()
    {
        $this->assertTrue(true);
    }
}
