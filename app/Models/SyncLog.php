<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $table = 'sync_logs';

    protected $fillable = [
        'provider_id',
        'provider_code',
        'operation',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'request_summary',
        'response_summary',
        'error_class',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'request_summary' => 'array',
        'response_summary' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopeForProvider(Builder $query, string $code): Builder
    {
        return $query->where('provider_code', $code);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function markCompleted(string $status = 'succeeded', ?array $responseSummary = null): void
    {
        $now = Carbon::now();

        $this->finished_at = $now;
        $this->duration_ms = $this->started_at !== null
            ? (int) $this->started_at->diffInMilliseconds($now)
            : null;
        $this->status = $status;

        if ($responseSummary !== null) {
            $this->response_summary = $responseSummary;
        }

        $this->save();
    }
}
