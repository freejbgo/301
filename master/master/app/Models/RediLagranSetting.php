<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RediLagranSetting extends Model
{
    protected $table = 'redi_lagran_settings';

    protected $fillable = [
        'enabled',
        'syn_ack',
        'ack',
        'window_scale',
        'confusion_times',
        'split_number',
        'window_after_split',
        'window_confusion_last',
        'window_80',
        'window_443',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'syn_ack' => 'boolean',
        'ack' => 'boolean',
        'window_scale' => 'integer',
        'confusion_times' => 'integer',
        'split_number' => 'integer',
        'window_after_split' => 'integer',
        'window_confusion_last' => 'integer',
        'window_80' => 'integer',
        'window_443' => 'integer',
    ];

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'syn_ack' => true,
            'ack' => true,
            'window_scale' => 7,
            'confusion_times' => 0,
            'split_number' => 7,
            'window_after_split' => 28960,
            'window_confusion_last' => 65535,
            'window_80' => 1,
            'window_443' => 1,
        ];
    }

    public static function current(): self
    {
        $model = new self();
        if (! Schema::hasTable($model->getTable())) {
            $model->forceFill(self::defaults());

            return $model;
        }
        $row = self::query()->orderBy('id')->first();
        if ($row) {
            return $row;
        }
        $model->forceFill(self::defaults());

        return $model;
    }

    public static function store(array $input): self
    {
        if (! Schema::hasTable((new self())->getTable())) {
            throw ValidationException::withMessages([
                'enabled' => __('redi-lagran.messages.missing_table'),
            ]);
        }
        $row = self::current();
        $row->fill(self::validated($input));
        $row->save();

        return $row;
    }

    public static function validated(array $input): array
    {
        return [
            'enabled' => self::asBool($input, 'enabled'),
            'syn_ack' => self::asBool($input, 'syn_ack'),
            'ack' => self::asBool($input, 'ack'),
            'window_scale' => self::boundedInt($input['window_scale'] ?? null, 0, 14, 'window_scale'),
            'confusion_times' => self::boundedInt($input['confusion_times'] ?? null, 0, 64, 'confusion_times'),
            'split_number' => self::boundedInt($input['split_number'] ?? null, 0, 1000000, 'split_number'),
            'window_after_split' => self::boundedInt($input['window_after_split'] ?? null, 1, 65535, 'window_after_split'),
            'window_confusion_last' => self::boundedInt($input['window_confusion_last'] ?? null, 1, 65535, 'window_confusion_last'),
            'window_80' => self::boundedInt($input['window_80'] ?? null, 1, 65535, 'window_80'),
            'window_443' => self::boundedInt($input['window_443'] ?? null, 1, 65535, 'window_443'),
        ];
    }

    public function toAgentPayload(): array
    {
        return [
            'enabled' => $this->boolAttr('enabled'),
            'syn_ack' => $this->boolAttr('syn_ack'),
            'ack' => $this->boolAttr('ack'),
            'window_scale' => $this->intAttr('window_scale'),
            'confusion_times' => $this->intAttr('confusion_times'),
            'split_number' => $this->intAttr('split_number'),
            'window_after_split' => $this->intAttr('window_after_split'),
            'window_confusion_last' => $this->intAttr('window_confusion_last'),
            'window' => [
                '80' => $this->intAttr('window_80'),
                '443' => $this->intAttr('window_443'),
            ],
        ];
    }

    public function toFormValues(): array
    {
        $payload = $this->toAgentPayload();

        return [
            'enabled' => $payload['enabled'] ? 1 : 0,
            'syn_ack' => $payload['syn_ack'] ? 1 : 0,
            'ack' => $payload['ack'] ? 1 : 0,
            'window_scale' => $payload['window_scale'],
            'confusion_times' => $payload['confusion_times'],
            'split_number' => $payload['split_number'],
            'window_after_split' => $payload['window_after_split'],
            'window_confusion_last' => $payload['window_confusion_last'],
            'window_80' => $payload['window']['80'],
            'window_443' => $payload['window']['443'],
        ];
    }

    private function boolAttr(string $key): bool
    {
        $value = $this->getAttribute($key);
        if ($value === null) {
            return (bool) self::defaults()[$key];
        }

        return (bool) $value;
    }

    private function intAttr(string $key): int
    {
        $value = $this->getAttribute($key);
        if ($value === null || $value === '') {
            return (int) self::defaults()[$key];
        }

        return (int) $value;
    }

    private static function asBool(array $input, string $key): bool
    {
        if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return false;
        }
        $parsed = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw ValidationException::withMessages([
                $key => __('redi-lagran.messages.invalid_bool'),
            ]);
        }

        return $parsed;
    }

    private static function boundedInt($value, int $min, int $max, string $field): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || is_array($value)) {
            throw ValidationException::withMessages([
                $field => __('redi-lagran.invalid.'.$field),
            ]);
        }
        if (is_string($value)) {
            if (preg_match('/^-?\d+$/', $value) !== 1 || strlen(ltrim($value, '-')) > 8) {
                throw ValidationException::withMessages([
                    $field => __('redi-lagran.invalid.'.$field),
                ]);
            }
            $value = (int) $value;
        }
        if (is_float($value)) {
            if ($value !== (float) (int) $value) {
                throw ValidationException::withMessages([
                    $field => __('redi-lagran.invalid.'.$field),
                ]);
            }
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $min || $value > $max) {
            throw ValidationException::withMessages([
                $field => __('redi-lagran.invalid.'.$field),
            ]);
        }

        return $value;
    }
}
