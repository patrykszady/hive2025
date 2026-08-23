<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

        return self::SUCCESS;
    }
}
