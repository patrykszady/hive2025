<?php

/**
 * The personal browser's extension fetches its check schedule from Hive on
 * every run, so the cadence (hourly, 7–19 Central by default) is changed in
 * config, never by reloading an unpacked extension.
 */
beforeEach(function (): void {
    config(['services.menards.bridge_token' => 'bridge-secret']);
});

it('refuses the schedule without the bridge token', function (): void {
    $this->getJson('/api/menards/extension-settings')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/api/menards/extension-settings')->assertUnauthorized();
});

it('answers the default schedule: every hour, 7 to 19 Central, 14 days back', function (): void {
    $this->withToken('bridge-secret')->getJson('/api/menards/extension-settings')
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'interval_minutes' => 60,
            'window_start_hour' => 7,
            'window_end_hour' => 19,
            'timezone' => 'America/Chicago',
            'lookback_days' => 14,
        ]);
});

it('follows the configured cadence and never goes under five minutes', function (): void {
    config(['services.menards.personal' => ['interval_minutes' => 2, 'window_start_hour' => 6, 'window_end_hour' => 20, 'timezone' => 'America/New_York', 'lookback_days' => 7]]);

    $this->withToken('bridge-secret')->getJson('/api/menards/extension-settings')
        ->assertOk()
        ->assertJsonPath('interval_minutes', 5)
        ->assertJsonPath('window_start_hour', 6)
        ->assertJsonPath('window_end_hour', 20)
        ->assertJsonPath('timezone', 'America/New_York')
        ->assertJsonPath('lookback_days', 7);
});

it('says so when the bridge is not configured', function (): void {
    config(['services.menards.bridge_token' => '']);

    $this->getJson('/api/menards/extension-settings')->assertStatus(503);
});
