<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path redirects guests to the public welcome page.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('welcome'));
    }
}
