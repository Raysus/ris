<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupRis extends Command
{
    protected $signature = 'ris:backup {--keep=14 : Días de retención de respaldos locales}';

    protected $description = 'Respalda PostgreSQL y storage/app/public (sin Docker)';

    public function handle(): int
    {
        $backupRoot = env('BACKUP_PATH', storage_path('backups'));
        $timestamp = now()->format('Y-m-d_His');
        $targetDir = $backupRoot . DIRECTORY_SEPARATOR . $timestamp;

        if (!File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $dbOk = $this->backupDatabase($targetDir);
        $storageOk = $this->backupStorage($targetDir);

        if (!$dbOk && !$storageOk) {
            $this->error('No se pudo generar ningún respaldo.');
            return self::FAILURE;
        }

        $this->pruneOldBackups($backupRoot, (int) $this->option('keep'));
        $this->info("Respaldo listo en {$targetDir}");

        return self::SUCCESS;
    }

    private function backupDatabase(string $targetDir): bool
    {
        if (config('database.default') !== 'pgsql') {
            $this->warn('BACKUP DB: solo PostgreSQL está automatizado en este comando.');
            return false;
        }

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $outputFile = $targetDir . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $pgDump = env('PG_DUMP_PATH', 'pg_dump');

        $command = sprintf(
            '%s --host=%s --port=%s --username=%s --format=plain --no-owner --no-acl %s',
            escapeshellarg($pgDump),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv() ?: [], ['PGPASSWORD' => $password]);
        $process = proc_open($command, $descriptorSpec, $pipes, null, $env);

        if (!is_resource($process)) {
            $this->error('No se pudo ejecutar pg_dump.');
            return false;
        }

        fclose($pipes[0]);
        $sql = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $sql === false) {
            $this->error('pg_dump falló: ' . trim($stderr));
            return false;
        }

        file_put_contents($outputFile, gzencode($sql, 9));
        $this->line('✓ Base de datos respaldada.');

        return true;
    }

    private function backupStorage(string $targetDir): bool
    {
        $source = storage_path('app/public');

        if (!File::isDirectory($source)) {
            $this->warn('No existe storage/app/public para respaldar.');
            return false;
        }

        $archive = $targetDir . DIRECTORY_SEPARATOR . 'storage-public.tar.gz';

        if (PHP_OS_FAMILY === 'Windows') {
            $this->warn('En Windows copie storage/app/public manualmente o use WSL/tar.');
            return false;
        }

        $command = sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($archive),
            escapeshellarg($source),
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('tar falló al respaldar storage.');
            return false;
        }

        $this->line('✓ storage/app/public respaldado.');
        return true;
    }

    private function pruneOldBackups(string $backupRoot, int $keepDays): void
    {
        if ($keepDays < 1 || !File::isDirectory($backupRoot)) {
            return;
        }

        $threshold = now()->subDays($keepDays)->getTimestamp();

        foreach (File::directories($backupRoot) as $dir) {
            if (File::lastModified($dir) < $threshold) {
                File::deleteDirectory($dir);
                $this->line('Eliminado respaldo antiguo: ' . basename($dir));
            }
        }
    }
}
