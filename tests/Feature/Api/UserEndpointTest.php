<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Tests\Concerns\RefreshDatabaseAsOwner;
use Tests\TestCase;

class UserEndpointTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    public function test_guests_are_rejected_with_json_not_a_redirect()
    {
        $response = $this->get('/v1/user');

        $response->assertUnauthorized();
        $response->assertHeader('content-type', 'application/json');
    }

    public function test_a_bearer_token_without_cookies_returns_the_authenticated_user()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/v1/user');

        $response->assertOk();
        $response->assertJsonPath('id', $user->id);
        $response->assertJsonPath('email', $user->email);
        $response->assertJsonMissingPath('password');
    }

    public function test_the_api_user_payload_matches_the_prop_shared_with_the_web_surface()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $api = $this->withToken($token)->getJson('/v1/user')->json();

        $web = $this->actingAs($user->refresh())
            ->get(route('dashboard'))
            ->viewData('page')['props']['auth']['user'];

        $this->assertEquals($api, json_decode(json_encode($web), true));
    }
}
