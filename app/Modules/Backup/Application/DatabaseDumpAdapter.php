<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\DatabaseDumper;
use RuntimeException;

class DatabaseDumpAdapter implements DatabaseDumper
{
    public function dump(string $destination): void
    {
        $connection = config('database.default');
        $settings = config("database.connections.{$connection}");
        if (($settings['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backup database dump requires the MySQL mysqldump adapter.');
        }

        $binary = (string) config('backup.tooling.mysqldump', 'mysqldump');
        $transport = ! empty($settings['unix_socket'])
            ? ['--protocol=SOCKET', '--socket='.$settings['unix_socket']]
            : ['--host='.($settings['host'] ?? '127.0.0.1'), '--port='.($settings['port'] ?? 3306)];
        $command = [$binary, '--single-transaction', '--quick', '--no-tablespaces', '--set-gtid-purged=OFF', '--routines', '--triggers', ...$transport, '--user='.($settings['username'] ?? ''), (string) ($settings['database'] ?? '')];
        $this->run($command, $destination, (string) ($settings['password'] ?? ''));
        $this->gzip($destination);
    }

    /** @param list<string> $command */
    protected function run(array $command, string $destination, string $password): void
    {
        $descriptor = [1 => ['file', $destination, 'wb'], 2 => ['pipe', 'w']];
        $environment = ['MYSQL_PWD' => $password];
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $key) {
                $value = getenv($key);
                if ($value !== false && $value !== '') {
                    $environment[$key] = $value;
                }
            }
        }
        $process = proc_open($command, $descriptor, $pipes, base_path(), $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start mysqldump.');
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            @unlink($destination);
            throw new RuntimeException('mysqldump failed without exposing command credentials: '.trim((string) $error));
        }
    }

    protected function gzip(string $destination): void
    {
        $input = fopen($destination, 'rb');
        // Fast compression keeps interactive backups within the HTTP request budget.
        $output = gzopen($destination.'.gz', 'wb1');
        if (! is_resource($input) || $output === false) {
            throw new RuntimeException('Unable to gzip database dump.');
        }
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false || gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Unable to compress database dump.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
        if (! rename($destination.'.gz', $destination)) {
            throw new RuntimeException('Unable to finalize compressed dump.');
        }
    }
}
