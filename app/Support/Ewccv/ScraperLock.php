<?php

namespace App\Support\Ewccv;

use Illuminate\Support\Facades\Storage;

/**
 * The one-at-a-time lock every EWCCV scraper run holds (ewccv:scrape-tracking).
 * Two runs would share the Chrome profile and the session; on 2026-10-12 an
 * overlapping pair re-posted a subscription and stamped a fresh policy as
 * failed. The bridge asks here whether a run is on before minting another
 * credential, and the hand-off job before starting another run.
 *
 * An OS file lock (flock) on a file under storage, not a cache lock: prod
 * caches to files and every deploy's optimize:clear emptied the cache — and
 * with it the lock — so three runs started on top of each other on
 * 2026-10-13 01:13–01:16. A flock is released by the kernel the moment the
 * holding process ends, however it ends.
 */
class ScraperLock
{
    public const NAME = 'ewccv:scraper';

    public const FILE = 'ewccv/scraper.lock';

    /** @var resource|null */
    protected $handle = null;

    /**
     * Take the lock, waiting up to $waitSeconds for a run in progress to end.
     */
    public function acquire(int $waitSeconds = 0): bool
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::FILE)) {
            $disk->put(self::FILE, '');
        }
        $path = $disk->path(self::FILE);
        $deadline = microtime(true) + $waitSeconds;

        do {
            $handle = fopen($path, 'c');
            if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
                ftruncate($handle, 0);
                fwrite($handle, (string) getmypid());
                fflush($handle);
                $this->handle = $handle;

                return true;
            }
            if ($handle) {
                fclose($handle);
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(250_000);
        } while (true);
    }

    public function release(): void
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /** Is a scraper run in progress right now? */
    public static function isHeld(): bool
    {
        $probe = new self;
        if ($probe->acquire(0)) {
            $probe->release();

            return false;
        }

        return true;
    }
}
