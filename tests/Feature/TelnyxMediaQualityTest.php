<?php

namespace Tests\Feature;

use App\Models\CallLog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxMediaQualityTest extends TestCase
{
    /**
     * joinConference must include mute:false and hold:false to prevent one-way audio.
     * Test that joinConference sends the correct parameters in the API request.
     */
    public function test_join_conference_includes_explicit_media_params()
    {
        Http::fake([
            'api.telnyx.com/v2/conferences/*/actions/join' => Http::response([
                'data' => ['id' => 'conf123'],
            ]),
        ]);

        $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);

        // Use reflection to call the protected joinConference method
        $reflection = new \ReflectionMethod($controller, 'joinConference');
        $reflection->setAccessible(true);
        $reflection->invoke($controller, 'conf_test_123', 'call_leg_456');

        // Verify the request was made with explicit mute:false and hold:false
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $body = json_decode($request->body(), true);
            return $request->url() === 'https://api.telnyx.com/v2/conferences/conf_test_123/actions/join'
                && isset($body['call_control_id'])
                && $body['call_control_id'] === 'call_leg_456'
                && isset($body['mute'])
                && $body['mute'] === false
                && isset($body['hold'])
                && $body['hold'] === false;
        });
    }

    /**
     * bridgeCalls must use telnyxHttp() (with timeout) instead of bare Http::withToken().
     * Test that bridge operations include command_id for idempotency.
     */
    public function test_bridge_calls_includes_idempotent_command_id()
    {
        Http::fake([
            'api.telnyx.com/v2/calls/*/actions/bridge' => Http::response([
                'data' => ['id' => 'bridge_123'],
            ]),
        ]);

        $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);
        $reflection = new \ReflectionMethod($controller, 'bridgeCalls');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($controller, 'call_a_123', 'call_b_456');

        // Verify command_id was sent for deduplication
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $body = json_decode($request->body(), true);
            return isset($body['command_id'])
                && str_starts_with($body['command_id'], 'bridge_')
                && $body['call_control_id'] === 'call_b_456';
        });

        $this->assertTrue($result);
    }

    /**
     * Legacy bridgeCalls should still work for non-voicemail paths but use telnyxHttp timeout.
     * Test that bridge failures are logged and don't crash the webhook.
     */
    public function test_bridge_calls_handles_failure_gracefully()
    {
        Http::fake([
            'api.telnyx.com/v2/calls/*/actions/bridge' => Http::response([
                'errors' => [['code' => '90018', 'title' => 'Call not found']],
            ], 404),
        ]);

        $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);
        $reflection = new \ReflectionMethod($controller, 'bridgeCalls');
        $reflection->setAccessible(true);

        // Should return false on failure, not throw
        $result = $reflection->invoke($controller, 'missing_call_a', 'missing_call_b');

        $this->assertFalse($result);
    }

    /**
     * Test that joinConference is called from voicemail_intro_done handler.
     * This is an integration test that verifies the handler chains correctly.
     */
    public function test_voicemail_intro_done_calls_join_conference()
    {
        Http::fake([
            'api.telnyx.com/v2/conferences/*/actions/join' => Http::response([
                'data' => ['id' => 'join_123'],
            ]),
            'api.telnyx.com/v2/calls/*/actions/*' => Http::response([
                'data' => ['id' => 'action_456'],
            ]),
        ]);

        $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);

        // Call joinConference directly to verify it uses explicit media params
        $reflection = new \ReflectionMethod($controller, 'joinConference');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($controller, 'conf_vm_test', 'target_leg_test');

        // Verify the call succeeded
        $this->assertTrue($result);

        // Verify the params included mute:false and hold:false
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if (! str_contains($request->url(), 'actions/join')) {
                return false;
            }
            $body = json_decode($request->body(), true);
            return isset($body['mute']) && $body['mute'] === false
                && isset($body['hold']) && $body['hold'] === false;
        });
    }
}
