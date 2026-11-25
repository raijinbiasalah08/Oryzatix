<?php

use App\Models\RiceScan;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('saves manual severity and returns it in history without inventing an area percentage', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $scan = RiceScan::create(['user_id' => $user->id, 'image_path' => 'scans/test.jpg',
        'disease_name' => 'Leaf Blast', 'confidence' => 90, 'severity' => 'unassessed']);
    $url = '/api/v1/rice-detector/scan/'.$scan->id.'/severity';
    $this->patchJson($url, ['severity' => 'moderate'])->assertOk()->assertJsonPath('severity_status', 'user_assessed');
    expect($scan->fresh()->severity_source)->toBe('user_assessed');
    $history = $this->getJson('/api/v1/rice-detector/history')->assertOk()->assertJsonPath('stats.moderate', 1);
    $record = collect($history->json('scans'))->flatten(1)->first();
    expect($record['severity'])->toBe('moderate')
        ->and($record['severity_status'])->toBe('user_assessed')
        ->and($record['affected_percentage'])->toBeNull();
    $this->patchJson($url, ['severity' => 'unassessed'])->assertOk();
    expect($scan->fresh()->severity_source)->toBeNull();
});

it('rejects unauthorized or invalid manual severity updates', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $scan = RiceScan::create(['user_id' => $owner->id, 'image_path' => 'scans/test.jpg',
        'disease_name' => 'Leaf Blast', 'confidence' => 90, 'severity' => 'unassessed']);
    $url = '/api/v1/rice-detector/scan/'.$scan->id.'/severity';
    $this->patchJson($url, ['severity' => 'mild'])->assertUnauthorized();
    Sanctum::actingAs($other);
    $this->patchJson($url, ['severity' => 'mild'])->assertNotFound();
    Sanctum::actingAs($owner);
    $this->patchJson($url, ['severity' => 'healthy'])->assertUnprocessable();
    $scan->update(['severity' => 'healthy', 'disease_name' => 'Healthy Rice Leaf']);
    $this->patchJson($url, ['severity' => 'severe'])->assertUnprocessable();
});

it('labels experimental estimates in history and uses a later manual assessment for treatment', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $scan = RiceScan::create(['user_id' => $user->id, 'image_path' => 'scans/test.jpg',
        'disease_name' => 'Leaf Blast', 'severity' => 'severe', 'severity_source' => 'model_estimated',
        'treatment_recommendation' => ['chemical' => [['name' => 'Old recommendation']]]]);
    $history = fn () => collect($this->getJson('/api/v1/rice-detector/history')->assertOk()->json('scans'))->flatten(1)->first();
    $estimate = $history();
    expect($estimate['severity_label'])->toBe('Severe (experimental estimate)')
        ->and($estimate['affected_percentage'])->toBeNull()
        ->and($estimate['treatments'])->toBeNull();

    $url = '/api/v1/rice-detector/scan/'.$scan->id.'/severity';
    $this->patchJson($url, ['severity' => 'mild'])->assertOk();
    $this->patchJson($url, ['severity' => 'severe', 'source' => 'model_estimated', 'model_version' => 'severity_experimental_v1'])
        ->assertOk()->assertJsonPath('severity', 'mild')->assertJsonPath('severity_status', 'user_assessed')
        ->assertJsonPath('severity_model_version', null);
    $manual = $history();
    expect($manual['severity_label'])->toBe('Mild (manual)')
        ->and($manual['treatments']['level_info']['severity'])->toBe('mild');
});
