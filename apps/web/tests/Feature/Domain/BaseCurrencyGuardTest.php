<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
});

it('lets the owner change the base currency while no domain data exists', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->patch(route('settings.workspace.update'), ['name' => $workspace->name, 'currency' => 'EUR', 'timezone' => 'UTC'])->assertSessionHasNoErrors();

    expect($workspace->refresh()->currency)->toBe('EUR');
});

it('locks the base currency after the first payment request', function (string $locale, string $message): void {
    $s = Scenario::make();
    $s->request();
    $owner = $s->user('owner')->forceFill(['locale' => $locale]);
    $owner->save();

    $this->actingAs($owner)
        ->from('/settings/workspace')
        ->patch(route('settings.workspace.update'), ['name' => $s->workspace->name, 'currency' => 'EUR', 'timezone' => 'UTC'])
        ->assertSessionHasErrors(['currency' => $message]);

    $this->actingAs($owner)
        ->patchJson(route('settings.workspace.update'), ['name' => $s->workspace->name, 'currency' => 'EUR', 'timezone' => 'UTC'])
        ->assertStatus(422);

    expect($s->workspace->refresh()->currency)->toBe('USD');

    $this->actingAs($owner)
        ->patch(route('settings.workspace.update'), ['name' => 'Renamed Co', 'currency' => 'USD', 'timezone' => 'UTC'])
        ->assertSessionHasNoErrors();

    expect($s->workspace->refresh()->name)->toBe('Renamed Co');
})->with([
    ['en', "Base currency can't be changed after the first payment requests"],
    ['ru', 'Базовую валюту нельзя менять после первых платёжных запросов'],
]);
