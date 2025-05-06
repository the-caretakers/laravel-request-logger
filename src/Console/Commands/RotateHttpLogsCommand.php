```php
<?php

namespace TheCaretakers\RequestLogger\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RotateHttpLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'request-logger:rotate
                            {--days=30 : The number of days of logs to keep.}
                            {--disk= : Specify the disk (overrides config).}
                            {--dry-run : Simulate the rotation without deleting files.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate HTTP request logs by deleting old files based on date.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysToKeep = (int) $this->option('days');
        if ($daysToKeep <= 0) {
            $this->error('The --days option must be a positive integer.');

            return Command::FAILURE;
        }

        $diskName = $this->option('disk') ?: config('request-logger.disk');
        if (! $diskName) {
            $this->error('Log rotation disk is not configured. Set REQUEST_LOGGER_DISK in .env or request-logger.disk in config.');

            return Command::FAILURE;
        }

        $pathTemplate = config('request-logger.log_path_structure', 'http-logs/{Y}-{m}-{d}.log');
        $baseLogPath = $this->getBasePathFromTemplate($pathTemplate);

        if (! $baseLogPath) {
            $this->error('Could not determine base log path from structure: '.$pathTemplate);

            return Command::FAILURE;
        }

        $isDryRun = $this->option('dry-run');
        $cutoffDate = Carbon::now()->subDays($daysToKeep)->startOfDay();

        $this->info(sprintf(
            'Rotating logs on disk \'%s\' in path \'%s/\'. Keeping logs from the last %d days (since %s).',
            $diskName,
            rtrim($baseLogPath, '/'),
            $daysToKeep,
            $cutoffDate->toDateString()
        ));
        if ($isDryRun) {
            $this->warn('Dry run mode enabled. No files will be deleted.');
        }

        try {
            $disk = Storage::disk($diskName);
            $allFiles = $disk->allFiles($baseLogPath);
            $deletedCount = 0;
            $keptCount = 0;

            if (empty($allFiles)) {
                $this->info('No log files found in the specified path.');

                return Command::SUCCESS;
            }

            foreach ($allFiles as $filePath) {
                // Attempt to extract date from filename/path based on common patterns
                $fileDate = $this->extractDateFromPath($filePath, $pathTemplate);

                if ($fileDate && $fileDate->lt($cutoffDate)) {
                    $this->line(sprintf('- Deleting %s (Date: %s)', $filePath, $fileDate->toDateString()));
                    if (! $isDryRun) {
                        if (! $disk->delete($filePath)) {
                            $this->warn("  Failed to delete: {$filePath}");
                        } else {
                            $deletedCount++;
                        }
                    } else {
                        // Count as deleted in dry run
                        $deletedCount++;
                    }
                } else {
                    $keptCount++;
                    if ($this->output->isVerbose()) {
                        $dateStr = $fileDate ? $fileDate->toDateString() : 'Unknown Date';
                        $this->line(sprintf('- Keeping %s (Date: %s)', $filePath, $dateStr), null, 'v');
                    }
                }
            }

            $this->info(sprintf(
                'Rotation complete. %d files processed. %d files deleted (or would be deleted). %d files kept.',
                count($allFiles),
                $deletedCount,
                $keptCount
            ));
        } catch (FileNotFoundException $e) {
            $this->warn(sprintf('Base log path \'%s\' not found on disk \'%s\'. No logs to rotate.', $baseLogPath, $diskName));

            // Not a failure if the directory just doesn't exist yet
            return Command::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Error during request log rotation: '.$e->getMessage(), ['exception' => $e]);
            $this->error('An error occurred during log rotation: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Extracts the base path before any date placeholders.
     */
    protected function getBasePathFromTemplate(string $template): ?string
    {
        $placeholderPosition = strpos($template, '{');
        if ($placeholderPosition === false) {
            // Assume the template is the base path if no placeholders
            // Get directory part
            return dirname($template);
        }

        return rtrim(substr($template, 0, $placeholderPosition), '/');
    }

    /**
     * Tries to extract a Carbon date instance from a file path, matching common patterns.
     * This is a basic implementation and might need refinement based on the exact log_path_structure.
     */
    protected function extractDateFromPath(string $filePath, string $template): ?Carbon
    {
        // Try to match YYYY-MM-DD or YYYY/MM/DD patterns in the path
        if (preg_match('#(\d{4})[-/](\d{2})[-/](\d{2})#', $filePath, $matches)) {
            try {
                return Carbon::createFromFormat('Y-m-d', "{$matches[1]}-{$matches[2]}-{$matches[3]}")->startOfDay();
            } catch (Throwable $e) {
                // Ignore invalid date format
            }
        }

        // Could not determine date
        return null;
    }
}
