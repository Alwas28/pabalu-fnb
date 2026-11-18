<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * Catat otomatis perubahan kolom yang diaudit ke audit_logs.
 *
 * Model pemakai mendefinisikan:
 * - auditedAttributes(): list kolom yang dicatat;
 * - auditBusinessId(): id bisnis pemilik;
 * - auditLabel(): nama objek yang mudah dibaca.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAudit('created', collect($model->auditedAttributes())
                ->mapWithKeys(fn ($key) => [$key => [null, $model->auditValue($model->getAttribute($key))]])
                ->reject(fn ($pair) => $pair[1] === null)
                ->all());
        });

        static::updated(function ($model) {
            $changes = [];
            foreach ($model->auditedAttributes() as $key) {
                if ($model->wasChanged($key)) {
                    $changes[$key] = [$model->auditValue($model->getOriginal($key)), $model->auditValue($model->getAttribute($key))];
                }
            }

            if ($changes !== []) {
                $model->writeAudit('updated', $changes);
            }
        });

        static::deleted(fn ($model) => $model->writeAudit('deleted', null));
    }

    /** @param array<string, array{0: mixed, 1: mixed}>|null $changes */
    protected function writeAudit(string $event, ?array $changes): void
    {
        $businessId = $this->auditBusinessId();

        if ($businessId) {
            AuditLog::record($businessId, $this, $event, $this->auditLabel(), $changes);
        }
    }

    protected function auditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof Collection => $value->map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v)->all(),
            is_bool($value) => $value,
            is_numeric($value) && ! is_string($value) => $value + 0,
            is_string($value) && is_numeric($value) => $value + 0,
            default => $value,
        };
    }

    /** @return list<string> */
    abstract public function auditedAttributes(): array;

    abstract public function auditBusinessId(): ?int;

    abstract public function auditLabel(): string;
}
