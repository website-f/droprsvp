<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        // Root redirects to the default locale home (/en-my).
        $this->get(route('home'))->assertRedirect('/en-my');
        $this->get('/en-my')->assertOk();
    }
}
