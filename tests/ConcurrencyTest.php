<?php

declare(strict_types=1);

namespace FormGuard\Laravel\Tests;

use PHPUnit\Framework\TestCase;

final class ConcurrencyTest extends TestCase
{
    public function test_only_one_worker_can_reserve_an_action(): void
    {
        $root = dirname(__DIR__);
        $directory = $root . '/build/concurrent-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $worker = $directory . '/worker.php';
        file_put_contents($worker, '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; $store = new FormGuard\\Laravel\\ClaimStore($argv[1]); echo $store->claim("parallel",str_repeat("a",32),time()+7200) ? "1" : "0";');
        $workers = [];
        for ($i = 0;$i < 12;$i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY,$worker,$directory . '/claims'], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process,$pipes];
        }
        $accepted = 0;
        foreach ($workers as [$process,$pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('', $errors);
            self::assertContains($output, ['0','1']);
            $accepted += (int)$output;
        }
        self::assertSame(1, $accepted, 'Parallel requests must not execute the same action twice.');
    }
}
