<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Copy the production database into the local dev database, in one stream —
 * no dump files to manage, no HeidiSQL, no wrong-connection accidents (this
 * command only ever writes to the LOCAL side).
 *
 *   php artisan db:pull-production
 */
class PullProductionDatabase extends Command
{
    protected $signature = 'db:pull-production
        {--host=hive-prod : SSH host alias for the production server}
        {--remote-path=hive.contractors : App directory on the server (holds the .env with DB creds)}';

    protected $description = 'Overwrite the local database with a fresh copy of production';

    /**
     * Every column stored through Crypt::encryptString — table => [columns].
     * These arrive encrypted with PRODUCTION's APP_KEY, which this machine does
     * not have, so they are re-encrypted with the local key after the import
     * (see reencryptProductionSecrets). Keep in step with the models:
     * PlatformSetting::\$casts, OAuthToken's token accessors, Citation::\$casts.
     */
    protected const ENCRYPTED_COLUMNS = [
        'platform_settings' => ['value'],
        'oauth_tokens' => ['access_token', 'refresh_token'],
        'citations' => ['account_password'],
    ];

    public function handle(): int
    {
        // The whole point of this command is that it can never write to prod
        // — refuse to exist anywhere near it.
        if (app()->environment('production')) {
            $this->error('This command never runs in production.');

            return self::FAILURE;
        }

        $local = config('database.connections.mysql');
        $host = (string) $this->option('host');
        $remotePath = trim((string) $this->option('remote-path'), '/');

        // Remote side reads its own .env so rotated prod credentials never
        // need to exist on this machine.
        $remote = 'cd ~/'.escapeshellarg($remotePath).' && '
            .'U=$(grep "^DB_USERNAME=" .env | cut -d= -f2) && '
            .'P=$(grep "^DB_PASSWORD=" .env | cut -d= -f2 | tr -d \'"\') && '
            .'D=$(grep "^DB_DATABASE=" .env | cut -d= -f2) && '
            .'MYSQL_PWD="$P" mysqldump -u"$U" --single-transaction --quick --routines "$D" | gzip';

        // /bin/sh returns only the LAST command's status, so a failed ssh or a
        // truncated gunzip still exits 0 once mysql accepts the empty stream —
        // the command then reports "Done." over a pull that never happened.
        // Ubuntu's /bin/sh is dash, which has no `set -o pipefail`, so the whole
        // pipeline runs under bash explicitly.
        $pipeline = sprintf(
            'ssh -o ConnectTimeout=10 -o BatchMode=yes %s %s | gunzip | MYSQL_PWD=%s mysql -h%s -u%s %s',
            escapeshellarg($host),
            escapeshellarg($remote),
            escapeshellarg((string) $local['password']),
            escapeshellarg((string) $local['host']),
            escapeshellarg((string) $local['username']),
            escapeshellarg((string) $local['database']),
        );

        $pipeline = 'bash -o pipefail -c '.escapeshellarg($pipeline);

        $this->info('Streaming production → local (no intermediate files)…');

        $process = Process::fromShellCommandline($pipeline, timeout: 1800);
        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->getOutput()->write($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('Import failed — local database may be partially written. Re-run, or restore from a dump.');

            return self::FAILURE;
        }

        // A sanity number beats "command exited 0".
        $tables = \DB::select('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ?', [$local['database']])[0]->n;
        $this->info("Done. {$tables} tables in {$local['database']}.");

        // Settings, OAuth tokens and citation passwords arrive sealed with
        // production's key: re-seal them with this machine's, or the first
        // read here deletes them (PlatformSetting::get purges what it cannot
        // decrypt) and the Platforms screen reads as never set up.
        $this->reencryptProductionSecrets($host, $remotePath);

        // The local cache was built from the database this command just
        // replaced (jpeterson's SEO snapshot is cached for 15 minutes, for
        // one), so a pull that left it in place kept showing the old
        // numbers and read as "the pull did nothing". Cleared here rather
        // than by the caller, so the four-command sequence in every README
        // stays as it is.
        $this->call('cache:clear');

        // The Menards browser flags live only in prod's cache, so the pull
        // cannot carry them and the clear above dropped any copy. Without
        // them the sidebar's "Menards — Sign-in" badge never shows here.
        $this->call('menards:mirror-prod-flags', ['--host' => $host, '--remote-path' => $remotePath]);

        return self::SUCCESS;
    }

    /**
     * Re-encrypt production's secrets with THIS machine's key.
     *
     * platform_settings values, OAuth tokens and citation passwords are stored
     * with Crypt::encryptString, so they arrive bound to production's APP_KEY.
     * Without this pass they are not merely unreadable — PlatformSetting::get()
     * treats an unreadable row as corrupt and DELETES it, so simply opening the
     * admin wiped every connected platform and the screen reported a site that
     * had never been set up.
     *
     * Production's key is read over the same ssh channel already used for its
     * database credentials, held only for this pass, and never written to disk
     * or printed. Rows already readable here are left untouched, so running the
     * command twice is harmless, and nothing is ever written to production.
     */
    protected function reencryptProductionSecrets(string $host, string $remotePath): void
    {
        $tables = array_filter(
            self::ENCRYPTED_COLUMNS,
            fn (string $table) => Schema::hasTable($table),
            ARRAY_FILTER_USE_KEY
        );

        if ($tables === []) {
            return;
        }

        $remoteKey = $this->fetchProductionAppKey($host, $remotePath);

        if ($remoteKey === null) {
            $this->warn('Could not read production\'s APP_KEY, so encrypted rows were left as they arrived.');

            return;
        }

        $production = $this->encrypterFor($remoteKey);

        if ($production === null) {
            $this->warn('Production\'s APP_KEY is not in a format this app understands; encrypted rows left as they arrived.');

            return;
        }

        $rewritten = 0;
        $unreadable = [];

        foreach ($tables as $table => $columns) {
            foreach (DB::table($table)->get() as $row) {
                $updates = [];

                foreach ($columns as $column) {
                    $value = $row->{$column} ?? null;

                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    // Already ours (same key, or a second run) — leave it alone.
                    try {
                        Crypt::decryptString($value);

                        continue;
                    } catch (DecryptException) {
                        // falls through to the re-encrypt below
                    }

                    try {
                        $updates[$column] = Crypt::encryptString($production->decryptString($value));
                    } catch (\Throwable $e) {
                        // Neither key opens it. Say which row, and why: a row
                        // left like this is purged by the first read on this
                        // machine (PlatformSetting::get), and that used to
                        // happen silently.
                        $unreadable[] = sprintf('%s#%s %s (%s): %s', $table, $row->id, $column, $row->key ?? '', $e->getMessage());
                    }
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                    $rewritten += count($updates);
                }
            }
        }

        foreach ($unreadable as $line) {
            $this->warn('Could not re-encrypt '.$line);
        }
        if ($rewritten > 0) {
            $this->info("Re-encrypted {$rewritten} secret(s) with this machine's key — connected platforms work here too.");
        }
    }

    /** Production's APP_KEY, read over ssh and never persisted. Null if unreachable. */
    protected function fetchProductionAppKey(string $host, string $remotePath): ?string
    {
        $process = Process::fromShellCommandline(sprintf(
            'ssh -o ConnectTimeout=10 -o BatchMode=yes %s %s',
            escapeshellarg($host),
            escapeshellarg('cd ~/'.escapeshellarg($remotePath).' && grep "^APP_KEY=" .env | head -1 | cut -d= -f2- | tr -d \'"\''),
        ), timeout: 60);

        $process->run();

        $key = trim($process->getOutput());

        return $process->isSuccessful() && $key !== '' ? $key : null;
    }

    /** An Encrypter for a base64:-prefixed (or raw) APP_KEY, or null if unusable. */
    protected function encrypterFor(string $key): ?Encrypter
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new Encrypter($raw, (string) config('app.cipher'));
        } catch (\Throwable) {
            return null;
        }
    }
}
