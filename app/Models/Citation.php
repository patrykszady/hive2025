<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ported verbatim from gsc's/dawnsellshomes' app/Models/Citation.php.
 * One directory / business profile the citation builder maintains.
 *
 * Status lifecycle:
 *   planned → running → needs_human → submitted → pending_verification → live
 *   any → failed (run error) | declined (we chose not to) | no_mechanism
 *   (the site turned out to have no way to list a business) | unreachable
 */
class Citation extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_NEEDS_HUMAN = 'needs_human';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_LIVE = 'live';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_NO_MECHANISM = 'no_mechanism';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUSES = [
        self::STATUS_PLANNED, self::STATUS_RUNNING, self::STATUS_NEEDS_HUMAN, self::STATUS_SUBMITTED,
        self::STATUS_PENDING_VERIFICATION, self::STATUS_LIVE, self::STATUS_FAILED, self::STATUS_DECLINED,
        self::STATUS_NO_MECHANISM, self::STATUS_UNREACHABLE,
    ];

    protected $fillable = [
        'slug', 'name', 'tier', 'mechanism', 'homepage', 'start_url', 'listing_url', 'status',
        'account_email', 'account_password', 'photos_uploaded', 'links_to_us', 'nofollow', 'human_reason', 'note',
        'log', 'screenshots', 'verification', 'last_run_at', 'submitted_at', 'live_at', 'last_checked_at',
    ];

    protected $casts = [
        'tier' => 'integer',
        'photos_uploaded' => 'integer',
        'links_to_us' => 'boolean',
        'nofollow' => 'boolean',
        'log' => 'array',
        'screenshots' => 'array',
        'verification' => 'array',
        'account_password' => 'encrypted',
        'last_run_at' => 'datetime',
        'submitted_at' => 'datetime',
        'live_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    protected $hidden = ['account_password'];

    /** The registry entry behind this row (config/citations.php). */
    public function definition(): array
    {
        return (array) config("citations.directories.{$this->slug}", []);
    }

    /** Append a log line (kept to the last 200). */
    public function addLog(string $message, ?string $step = null): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toDateTimeString(), 'step' => $step, 'message' => mb_substr($message, 0, 500)];
        $this->log = array_slice($log, -200);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_LIVE, self::STATUS_DECLINED, self::STATUS_NO_MECHANISM], true);
    }
}
