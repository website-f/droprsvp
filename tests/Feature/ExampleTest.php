<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        // Root redirects to the canonical locale home (/en-my/, trailing slash).
        $this->assertSame(rtrim(url('/'), '/').'/en-my/', $this->get(route('home'))->headers->get('Location'));
        $this->get('/en-my')->assertOk();
    }
}
