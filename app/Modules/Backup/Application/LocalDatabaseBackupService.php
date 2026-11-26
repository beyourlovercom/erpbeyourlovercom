<?php

namespace App\Modules\Backup\Application;

use RuntimeException;

final class LocalDatabaseBackupService
{
    public function __construct(private readonly DatabaseDumpAdapter $dumper) {}

    /** @return array{path:string,filename:string,size:int,sha256:string,stream:resource} */
    public function create(): array
    {
        $root = $this->root(true);
        $lock = fopen($root.'/.backup.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Backup lock unavailable.');
        }
        $temp = null;
        $path = null;
        $stream = null;
        $committed = false;
        $previousExecutionLimit = (int) ini_get('max_execution_time');
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Backup already running.');
            }
            if ($previousExecutionLimit > 0 && $previousExecutionLimit < 120) {
                set_time_limit(120);
            }
            $temp = tempnam($root, '.pending-');
            if ($temp === false) {
                throw new RuntimeException('Cannot stage backup.');
            }
            chmod($temp, 0600);
            $this->dumper->dump($temp);
            clearstatcache(true, $temp);
            if (! is_file($temp) || filesize($temp) < 20) {
                throw new RuntimeException('Empty database backup.');
            }
            $this->verifyGzip($temp);
            $sha = hash_file('sha256', $temp);
            if ($sha === false) {
                throw new RuntimeException('Cannot checksum backup.');
            }
            $filename = 'erp-db-'.now('Asia/Shanghai')->format('Ymd-His-u').'-'.bin2hex(random_bytes(4)).'.sql.gz';
            $path = $root.'/'.$filename;
            if (! chmod($temp, 0600) || ! rename($temp, $path)) {
                throw new RuntimeException('Cannot publish backup.');
            }
            if (! hash_equals($sha, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Backup checksum mismatch.');
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Cannot open backup download.');
            }
            $size = filesize($path);
            if ($size === false) {
                throw new RuntimeException('Cannot size backup.');
            }
            $committed = true;
            $files = array_values(array_filter(glob($root.'/erp-db-*.sql.gz') ?: [], static fn ($file) => is_file($file) && ! is_link($file) && preg_match('/^erp-db-\d{8}-\d{6}-\d{6}-[a-f0-9]{8}\.sql\.gz$/D', basename($file))));
            $files = array_values(array_filter($files, static fn ($file) => $file !== $path));
            rsort($files, SORT_STRING);
            foreach (array_slice($files, 2) as $old) {
                if (! unlink($old)) {
                    throw new RuntimeException('Backup retention failed.');
                }
            }

            return ['path' => $path, 'filename' => $filename, 'size' => $size, 'sha256' => $sha, 'stream' => $stream];
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $committed && $path !== null && is_file($path)) {
                @unlink($path);
            }
            throw $e;
        } finally {
            if (is_string($temp)) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
                if (is_file($temp.'.gz')) {
                    @unlink($temp.'.gz');
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($previousExecutionLimit > 0 && $previousExecutionLimit < 120) {
                set_time_limit($previousExecutionLimit);
            }
        }
    }

    private function root(bool $create = false): ?string
    {
        $directory = (string) config('backup.local.directory', base_path('backup'));
        if (! is_dir($directory)) {
            if (! $create) {
                return null;
            }
            if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Backup directory unavailable.');
            }
        }
        $root = realpath($directory);
        $public = realpath(public_path());
        if ($root === false || $public === false || $root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Backup directory must be private.');
        }
        if ($create && ! chmod($root, 0700)) {
            throw new RuntimeException('Backup directory permissions failed.');
        }

        return $root;
    }

    private function validFilename(string $filename): bool
    {
        return preg_match('/^erp-db-\d{8}-\d{6}-\d{6}-[a-f0-9]{8}\.sql\.gz$/D', $filename) === 1;
    }

    public function recent(): array
    {
        $root = $this->root();
        if ($root === null) {
            return [];
        }
        $files = array_filter(glob($root.'/erp-db-*.sql.gz') ?: [], fn ($file) => $this->validFilename(basename($file)) && is_file($file) && ! is_link($file));
        rsort($files, SORT_STRING);
        $rows = [];
        foreach (array_slice($files, 0, 3) as $file) {
            $size = filesize($file);
            if ($size !== false && $size >= 20) {
                $rows[] = ['filename' => basename($file), 'size' => $size];
            }
        }

        return $rows;
    }

    /** Open only a retained, verified backup; never accept a filesystem path. */
    public function open(string $filename): ?array
    {
        if (! $this->validFilename($filename)) {
            return null;
        }
        $root = $this->root();
        if ($root === null || ! in_array($filename, array_column($this->recent(), 'filename'), true)) {
            return null;
        }
        $path = $root.'/'.$filename;
        if (is_link($path) || ! is_file($path) || str_replace('\\', '/', (string) realpath($path)) !== str_replace('\\', '/', $path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            $this->verifyGzip($path);
            $sha = hash_file('sha256', $path);
            $size = fstat($stream)['size'] ?? false;
            if ($sha === false || $size === false || $size < 20) {
                throw new RuntimeException('Backup integrity check failed.');
            }

            return ['filename' => $filename, 'size' => $size, 'sha256' => $sha, 'stream' => $stream];
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    /** Verify gzip payload and trailer with PHP zlib, including on Windows. */
    private function verifyGzip(string $path): void
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open compressed database backup.');
        }
        try {
            $inflater = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflater === false) {
                throw new RuntimeException('Cannot initialize gzip verifier.');
            }
            $read = 0;
            while (! feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read compressed backup.');
                }
                if ($chunk === '') {
                    break;
                }
                if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                    throw new RuntimeException('Unexpected bytes after gzip trailer.');
                }
                $read += strlen($chunk);
                if (@inflate_add($inflater, $chunk, ZLIB_NO_FLUSH) === false) {
                    throw new RuntimeException('Invalid compressed backup.');
                }
            }
            if (inflate_get_status($inflater) !== ZLIB_STREAM_END || inflate_get_read_len($inflater) !== $read) {
                throw new RuntimeException('Incomplete or invalid gzip trailer.');
            }
        } finally {
            fclose($input);
        }
    }
}
