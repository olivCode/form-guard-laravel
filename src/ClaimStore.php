<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

/** Persistent action reservations survive expired session leases and stale session snapshots. */
final class ClaimStore
{
    public function __construct(private readonly string $directory, private readonly int $maxEntries = 10000)
    {
        if ($maxEntries < 1 || $maxEntries > 100000) {
            throw new \InvalidArgumentException('Invalid action reservation capacity.');
        }
    }

    /** @return resource|false */
    private function createExclusive(string $path): mixed
    {
        $previous = umask(0077);
        try {
            return @fopen($path, 'x+b');
        } finally {
            umask($previous);
        }
    }

    private function locked(callable $operation): mixed
    {
        if ($this->directory === '' || str_contains($this->directory, "\0") || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $this->directory)) {
            throw new ProtectionUnavailable('Invalid claims directory.');
        }
        for ($path = $this->directory; $path !== dirname($path); $path = dirname($path)) {
            if (is_link($path)) {
                throw new ProtectionUnavailable('Linked claims paths are not allowed.');
            }
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new ProtectionUnavailable('Claims storage is unavailable.');
        }
        $owner = @fileowner($this->directory);
        if (function_exists('posix_geteuid') && $owner !== posix_geteuid()) {
            throw new ProtectionUnavailable('Claims storage has an unsafe owner.');
        }
        $mode = @fileperms($this->directory);
        if ($mode === false || (DIRECTORY_SEPARATOR !== '\\' && ($mode & 0077) !== 0)) {
            throw new ProtectionUnavailable('Claims storage must be private.');
        }
        $lockPath = $this->directory . '/claims.lock';
        if (!file_exists($lockPath)) {
            $created = $this->createExclusive($lockPath);
            if ($created) {
                @chmod($lockPath, 0600);
                fclose($created);
            }
        }
        $stat = @lstat($lockPath);
        if (!$stat || is_link($lockPath) || $stat['nlink'] !== 1 || ($stat['mode'] & 0170000) !== 0100000
            || (DIRECTORY_SEPARATOR !== '\\' && ($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new ProtectionUnavailable('Unsafe claims lock.');
        }
        $lock = @fopen($lockPath, 'r+b');
        if (!$lock) {
            throw new ProtectionUnavailable('Claims storage is unavailable.');
        }
        try {
            $opened = fstat($lock);
            if (!$opened || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || !flock($lock, LOCK_EX)) {
                throw new ProtectionUnavailable('Claims lock failed.');
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function claim(string $scope, string $id, int $expires): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $expires < time()) {
            throw new ProtectionUnavailable('Expired or invalid action reservation.');
        }
        return $this->locked(function () use ($scope, $id, $expires): bool {
            $path = $this->directory . '/' . hash('sha256', $scope . "\0" . $id) . '.claim';
            if (file_exists($path) || is_link($path)) {
                return false;
            }
            $count = 0;
            foreach (new \DirectoryIterator($this->directory) as $entry) {
                if (preg_match('/^[a-f0-9]{64}\.claim$/D', $entry->getFilename()) && ++$count >= $this->maxEntries) {
                    throw new ProtectionUnavailable('Claims storage capacity reached; run maintenance.');
                }
            }
            $file = $this->createExclusive($path);
            if (!$file) {
                throw new ProtectionUnavailable('Cannot reserve the action.');
            }
            try {
                @chmod($path, 0600);
                $value = (string) $expires;
                if (fwrite($file, $value) !== strlen($value) || !fflush($file) || (function_exists('fsync') && !fsync($file))) {
                    throw new ProtectionUnavailable('Cannot persist the action reservation.');
                }
            } finally {
                fclose($file);
            }
            return true;
        });
    }

    public function prune(): int
    {
        return $this->locked(function (): int {
            $removed = 0;
            foreach (new \DirectoryIterator($this->directory) as $entry) {
                if (!preg_match('/^[a-f0-9]{64}\.claim$/D', $entry->getFilename()) || $entry->isLink() || !$entry->isFile()) {
                    continue;
                }
                $value = file_get_contents($entry->getPathname(), false, null, 0, 20);
                if (is_string($value) && preg_match('/^[0-9]{1,12}$/D', $value) && (int) $value < time() - 60) {
                    if (!unlink($entry->getPathname())) {
                        throw new ProtectionUnavailable('Cannot prune action reservations.');
                    }
                    $removed++;
                }
            }
            return $removed;
        });
    }
}
