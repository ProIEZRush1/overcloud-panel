<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_dashboard()
    {
        // '/' is defined as a redirect to the dashboard, so it returns 302, not 200.
        $response = $this->get(route('home'));

        $response->assertRedirect(route('dashboard'));
    }
}
