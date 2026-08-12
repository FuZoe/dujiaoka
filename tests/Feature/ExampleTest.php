<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_unknown_page_returns_not_found()
    {
        $response = $this->get('/definitely-missing-page');

        $response->assertStatus(404);
    }
}
