<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Intake\Enums\PipelineStage;
use App\Domain\Intake\Enums\PipelineState;
use App\Domain\Intake\Models\PaymentRequest;
use Carbon\CarbonInterface;

final class Pipeline
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    public static function of(PaymentRequest $request): self
    {
        $data = $request->getAttribute('pipeline');

        return new self(is_array($data) ? $data : []);
    }

    public static function initial(bool $skipExtract = false): self
    {
        $pipeline = new self([]);

        foreach (PipelineStage::cases() as $stage) {
            $pipeline->set($stage, $skipExtract && $stage === PipelineStage::Extract ? PipelineState::Skipped : PipelineState::Pending, null);
        }

        return $pipeline;
    }

    public static function completed(CarbonInterface $at): self
    {
        $pipeline = new self([]);

        foreach (PipelineStage::cases() as $stage) {
            $pipeline->set($stage, $stage === PipelineStage::Extract || $stage === PipelineStage::Domain ? PipelineState::Skipped : PipelineState::Done, $at);
        }

        return $pipeline;
    }

    public function set(PipelineStage $stage, PipelineState $state, ?CarbonInterface $at, ?string $error = null): self
    {
        $this->data[$stage->value] = [
            'state' => $state->value,
            'at' => $at?->toIso8601ZuluString(),
            'error' => $error === null ? null : mb_substr($error, 0, 300, 'UTF-8'),
        ];

        return $this;
    }

    public function state(PipelineStage $stage): PipelineState
    {
        $entry = $this->data[$stage->value] ?? null;

        return is_array($entry) ? (PipelineState::tryFrom((string) ($entry['state'] ?? '')) ?? PipelineState::Pending) : PipelineState::Pending;
    }

    /** @return array{state: string, at: string|null, error: string|null} */
    public function stage(PipelineStage $stage): array
    {
        $entry = $this->data[$stage->value] ?? null;
        $entry = is_array($entry) ? $entry : [];

        return [
            'state' => $this->state($stage)->value,
            'at' => is_string($entry['at'] ?? null) ? $entry['at'] : null,
            'error' => is_string($entry['error'] ?? null) ? $entry['error'] : null,
        ];
    }

    /** @return array<string, array{state: string, at: string|null, error: string|null}> */
    public function stages(): array
    {
        $stages = [];

        foreach (PipelineStage::cases() as $stage) {
            $stages[$stage->value] = $this->stage($stage);
        }

        return $stages;
    }

    public function markIncomplete(): self
    {
        $this->data['incomplete'] = true;

        return $this;
    }

    public function incomplete(): bool
    {
        return ($this->data['incomplete'] ?? false) === true;
    }

    /** @return list<string> */
    public function manualFields(): array
    {
        $fields = $this->data['manual_fields'] ?? [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    /** @param  list<string>  $fields */
    public function addManualFields(array $fields): self
    {
        $this->data['manual_fields'] = array_values(array_unique([...$this->manualFields(), ...$fields]));

        return $this;
    }

    /** @return list<string> */
    public function aiFields(): array
    {
        $fields = $this->data['ai_fields'] ?? [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    /** @param  list<string>  $fields */
    public function setAiFields(array $fields): self
    {
        $this->data['ai_fields'] = array_values(array_unique($fields));

        return $this;
    }

    public function value(string $key): ?string
    {
        $value = $this->data['values'][$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function put(string $key, ?string $value): self
    {
        $values = is_array($this->data['values'] ?? null) ? $this->data['values'] : [];
        $values[$key] = $value;
        $this->data['values'] = $values;

        return $this;
    }

    public function republished(): int
    {
        return (int) ($this->data['republished'] ?? 0);
    }

    public function bumpRepublished(): self
    {
        $this->data['republished'] = $this->republished() + 1;

        return $this;
    }

    public function resetForRevision(bool $skipExtract): self
    {
        $manual = $this->manualFields();
        $ai = $this->aiFields();
        $fresh = self::initial($skipExtract);
        $fresh->data['manual_fields'] = $manual;
        $fresh->data['ai_fields'] = $ai;

        if (is_array($this->data['values'] ?? null)) {
            $fresh->data['values'] = $this->data['values'];
        }

        return $fresh;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function writeTo(PaymentRequest $request): void
    {
        $request->setAttribute('pipeline', $this->data);
    }
}
