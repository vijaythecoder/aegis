<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

        $response = $this->get('/');

        $response->assertRedirect('/chat');
    }
}
