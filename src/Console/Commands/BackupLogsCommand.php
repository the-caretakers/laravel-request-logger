<?php

namespace TheCaretakers\RequestLogger\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Throwable;

class BackupLogsCommand extends Command
{
    protected $signature = 'request-logger:backup-logs
                            {--date= : The date of the logs to transfer (YYYY-MM-DD). Defaults to yesterday.}
                            {--delete-source : Delete the local log file after successful transfer.}';

    protected $description = 'Transfers request logs from the local disk to the configured backup disk.';

    public function handle()
    {
        if (! config('request-logger.enabled')) {
            $this->info('Request logging is disabled. No logs to transfer.');
            return 0;
        }

        $sourceDiskName = config('request-logger.disk');
        $destinationDiskName = config('request-logger.backup_disk');

        if (! $destinationDiskName) {
            $this->info('Backup disk is not configured (request-logger.backup_disk is null). Skipping transfer.');

            return 0;
        }

        if ($sourceDiskName === $destinationDiskName) {
            $this->error('Source and destination disks are the same. No transfer will be performed.');
            return 1;
        }

        $dateToTransfer = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();
        $logPathStructure = Config::get('request-logger.log_path_structure', 'http-logs/{Y}-{m}-{d}.log');

        $sourceFilePath = $this->generateFilePath($logPathStructure, $dateToTransfer);

        $sourceDisk = Storage::disk($sourceDiskName);
        $destinationDisk = Storage::disk($destinationDiskName);

        if (! $sourceDisk->exists($sourceFilePath)) {
            $this->info("Log file for {$dateToTransfer->toDateString()} not found on disk '{$sourceDiskName}' at path '{$sourceFilePath}'.");
            return 0;
        }

        $this->info("Attempting to transfer log file: {$sourceFilePath} from '{$sourceDiskName}' to '{$destinationDiskName}'...");

        // Determine the final destination file path, handling potential name collisions
        $baseDestinationDirectory = dirname($sourceFilePath);
        if ($baseDestinationDirectory === '.') {
            $baseDestinationDirectory = ''; // Store in root of the disk if no directory in source path
        }
        $originalFilename = basename($sourceFilePath);

        $attempt = 0;
        $finalDestinationFilePath = '';

        while (true) {
            $currentFilenameToTry = $originalFilename . ($attempt > 0 ? '.' . $attempt : '');
            $potentialPath = ($baseDestinationDirectory ? $baseDestinationDirectory . '/' : '') . $currentFilenameToTry;

            if (!$destinationDisk->exists($potentialPath)) {
                $finalDestinationFilePath = $potentialPath;
                break;
            }
            $attempt++;
        }

        $this->info("Destination path will be: {$finalDestinationFilePath}");

        try {
            // Ensure the destination directory exists if the path includes directories
            $actualDestinationDirectory = dirname($finalDestinationFilePath);
            if ($actualDestinationDirectory !== '.' && $actualDestinationDirectory !== '' && !$destinationDisk->exists($actualDestinationDirectory)) {
                $destinationDisk->makeDirectory($actualDestinationDirectory);
                $this->info("Created directory {$actualDestinationDirectory} on disk '{$destinationDiskName}'.");
            }

            // Stream copy for potentially large files
            $stream = $sourceDisk->readStream($sourceFilePath);
            $destinationDisk->put($finalDestinationFilePath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->info("Successfully transferred {$sourceFilePath} to {$finalDestinationFilePath} on disk '{$destinationDiskName}'.");

            if ($this->option('delete-source')) {
                $sourceDisk->delete($sourceFilePath);
                $this->info("Deleted source log file: {$sourceFilePath} from disk '{$sourceDiskName}'.");
            }
        } catch (Throwable $e) {
            $this->error("Failed to transfer log file: {$e->getMessage()}");
            Log::error('RequestLogger: Failed to transfer log file', [
                'source_disk' => $sourceDiskName,
                'destination_disk' => $destinationDiskName,
                'file_path' => $sourceFilePath,
                'exception' => $e,
            ]);
            return 1;
        }

        return 0;
    }

    protected function generateFilePath(string $pathTemplate, Carbon $date): string
    {
        $replacements = [
            '{Y}'    => $date->format('Y'),
            '{m}'    => $date->format('m'),
            '{d}'    => $date->format('d'),
            '{H}'    => $date->format('H'), // Included for completeness, though daily logs might not use it
            // '{uuid}' is not typically used for daily aggregate logs, but for individual ones
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pathTemplate);
    }
}
