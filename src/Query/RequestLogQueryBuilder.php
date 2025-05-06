<?php

namespace TheCaretakers\RequestLogger\Query;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RequestLogQueryBuilder
{
    protected ?string $disk = null;
    protected ?string $logPathStructure = null;
    protected ?string $logFormat = null;
    protected ?Carbon $dateConstraint = null; // Added for date filtering
    protected ?int $limit = null; // Keep for potential future use, though not implemented
    protected ?int $offset = null; // Keep for potential future use, though not implemented

    public function __construct()
    {
        // Initialize with default config values
        $this->disk = config('request-logger.disk');
        $this->logPathStructure = config('request-logger.log_path_structure');
        $this->logFormat = config('request-logger.log_format');
    }

    /**
     * Set the filesystem disk to query.
     *
     * @param string $disk
     * @return $this
     */
    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Set the log path structure to use.
     *
     * @param string $pathStructure
     * @return $this
     */
    public function logPathStructure(string $pathStructure): self
    {
        $this->logPathStructure = $pathStructure;

        return $this;
    }

    /**
     * Set the log format to parse.
     *
     * @param string $format Currently only 'json' is supported.
     * @return $this
     */
    public function logFormat(string $format): self
    {
        if (strtolower($format) !== 'json') {
            // Currently only supporting JSON line format from DefaultLogWriter
            throw new \InvalidArgumentException("Unsupported log format '{$format}'. Only 'json' is currently supported by the query builder.");
        }
        $this->logFormat = $format;
        return $this;
    }

    /**
     * Add a date constraint to filter log files.
     * This primarily helps in resolving which files to scan based on the path structure.
     *
     * @param string|\DateTimeInterface $date
     * @return $this
     */
    public function whereDate(string|\DateTimeInterface $date): self
    {
        try {
            $this->dateConstraint = Carbon::parse($date)->startOfDay();
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid date format provided to whereDate: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Execute the query and return a collection of results.
     * Further filtering should be applied to the returned collection.
     *
     * @return \Illuminate\Support\Collection
     */
    public function get(): Collection
    {
        $results = $this->fetchAndFilterLogs();

        return collect($results);
    }

    /**
     * Execute the query and return the first result.
     *
     * @return array
     */
    public function first(): ?array
    {
        $results = $this->fetchAndFilterLogs();

        // Return the first log entry or null if none found
        return $results[0] ?? null;
    }

    /**
     * Execute the query and return the first result.
     *
     * @return array
     */
    public function last(): ?array
    {
        $results = $this->fetchAndFilterLogs();

        // Return the last log entry or null if none found
        return $results[count($results) - 1] ?? null;
    }

    /**
     * Execute the query and return the total count of logs found in the matched files.
     *
     * @return int
     */
    public function count(): int
    {
        $results = $this->fetchAndFilterLogs();

        return count($results);
    }

    /**
     * Fetches logs from the filesystem based on the date constraint.
     *
     * @return array
     */
    protected function fetchAndFilterLogs(): array
    {
        if ($this->logFormat !== 'json') {
            throw new RuntimeException("Unsupported log format '{$this->logFormat}'. Only 'json' is currently supported.");
        }

        $filesToScan = $this->resolveFilesToScan();
        $allLogs = [];

        foreach ($filesToScan as $filePath) {
            if (! Storage::disk($this->disk)->exists($filePath)) {
                continue;
            }

            // Read file line by line (assuming JSON objects per line)
            $stream = Storage::disk($this->disk)->readStream($filePath);

            while (($line = fgets($stream)) !== false) {
                $trimmedLine = trim($line);
                if (empty($trimmedLine)) {
                    continue;
                }

                $logData = json_decode($trimmedLine, true);

                // Ensure json_decode was successful AND the result is an array
                if (json_last_error() === JSON_ERROR_NONE && is_array($logData)) {
                    $allLogs[] = $logData; // Add all valid JSON lines from matched files
                }
            }

            fclose($stream);
        }

        // Note: No sorting or further filtering is applied here.
        // Users should apply further filtering/sorting on the returned collection.

        return $allLogs;
    }

    /**
     * Determine which log files need to be scanned based on path structure and the date constraint.
     *
     * @return array
     */
    protected function resolveFilesToScan(): array
    {
        $path = $this->logPathStructure;

        // Use the date constraint if available
        if ($this->dateConstraint) {
            $path = str_replace(
                ['{Y}', '{m}', '{d}', '{H}'], // Note: {H} might not be useful with only a date constraint
                [$this->dateConstraint->format('Y'), $this->dateConstraint->format('m'), $this->dateConstraint->format('d'), '*'], // Replace H with wildcard if only date is known
                $path
            );
            // If the path now points to a specific file (unlikely with date only), return it
            if (Str::endsWith($path, ['.log', '.json']) && !Str::contains($path, ['{', '*'])) {
                return [$path];
            }
            // If it points to a directory or contains wildcards (e.g., daily folder), list files
            if (!Str::contains($path, ['{'])) { // Check if date placeholders resolved
                try {
                    // Separate directory and pattern for potentially better matching
                    $searchDir = dirname($path);
                    $searchPattern = basename($path);

                    if ($searchDir === '.') {
                        $searchDir = '';
                    } // Handle root case

                    // Use Storage::files with the directory and filter based on the pattern
                    // This handles cases like 'logs/YYYY/MM/DD/*.json'
                    return collect(Storage::disk($this->disk)->files($searchDir))
                        ->filter(fn ($file) => Str::is($path, $file)) // Use Str::is for wildcard matching
                        ->values()
                        ->all();

                } catch (\Exception $e) {
                    // Directory might not exist, return empty
                    return [];
                }
            }
        }

        // If no date constraint or path still contains placeholders/wildcards after date substitution
        if (Str::contains($path, ['{', '*'])) {
            // Try replacing remaining placeholders with wildcards for Storage::files()
            $searchPath = $path;
            $searchDir = dirname($searchPath);
            $searchPattern = basename($searchPath);

            // Basic wildcard replacement for common date patterns
            $searchPattern = str_replace(['{Y}', '{m}', '{d}', '{H}'], ['*', '*', '*', '*'], $searchPattern);
            $searchDir = str_replace(['{Y}', '{m}', '{d}', '{H}'], ['*', '*', '*', '*'], $searchDir);

            // Avoid searching from root if dir is just '*'
            if ($searchDir === '*' || $searchDir === '.') {
                $searchDir = '';
            }

            try {
                // If directory path still has wildcards, this might be very broad or fail depending on storage adapter.
                // A recursive search might be needed for full support, which Storage::files doesn't do directly.
                // For simplicity, we rely on Storage::files which might only work if wildcards are in the filename part.
                if (Str::contains($searchDir, '*')) {
                    // Log a warning or throw? For now, attempt the search but it might be incomplete.
                    // Consider requiring a more specific path or date constraint in this scenario.
                    // Let's try searching from the non-wildcard base path if possible.
                    $baseDir = Str::before($searchDir, '*');
                    if (strlen($baseDir) > 0 && $baseDir !== $searchDir) {
                        // Attempt search from the deepest known directory part
                        return collect(Storage::disk($this->disk)->allFiles($baseDir)) // Use allFiles for recursive
                            ->filter(fn ($file) => Str::is($searchDir . '/' . $searchPattern, $file))
                            ->values()
                            ->all();
                    } else {
                        // Searching recursively from root - potentially very slow!
                        return collect(Storage::disk($this->disk)->allFiles(''))
                            ->filter(fn ($file) => Str::is($searchDir . '/' . $searchPattern, $file))
                            ->values()
                            ->all();
                    }

                } else {
                    // No wildcards in directory, safe to use Storage::files
                    return collect(Storage::disk($this->disk)->files($searchDir))
                        ->filter(fn ($file) => Str::is($searchDir . '/' . $searchPattern, $file))
                        ->values()
                        ->all();
                }

            } catch (\Exception $e) {
                // Directory might not exist or other storage error
                return [];
            }
        }

        // If path seems fully resolved but isn't a directory structure like uuid.json
        if (Str::endsWith($path, ['.log', '.json'])) {
            return [$path];
        }

        // Fallback: If path is ambiguous after all attempts, return empty.
        // Consider logging a warning here.
        return [];
    }
}
