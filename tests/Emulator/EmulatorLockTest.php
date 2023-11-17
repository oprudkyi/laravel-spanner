<?php
/**
 * Copyright 2023 Colopl Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Colopl\Spanner\Tests\Emulator;

use Colopl\Spanner\Connection;
use Colopl\Spanner\Session\CacheSessionPool;
use Colopl\Spanner\EmulatorLock\CacheEmulatorLock;
use Colopl\Spanner\Tests\TestCase;
use Exception;
use Google\Cloud\Core\Exception\AbortedException;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Process\Process;

class EmulatorLockTest extends TestCase
{
    /*
     * Separate connection
     */
    private function getConnectionWithEmulatorLock(string $lockerClass): Connection
    {
        $config = $this->app['config']->get('database.connections.main');
        $config['emulatorLock'] = [
            'lockerClass' => $lockerClass,
            'lockTime' => 1200,
            'lockWaitTime' => 600,
        ];

        $cacheItemPool = new ArrayAdapter();
        $cacheSessionPool = new CacheSessionPool($cacheItemPool);
        $conn = new Connection($config['instance'], $config['database'], '', $config, null, $cacheSessionPool);

        $this->setUpDatabaseOnce($conn);

        return $conn;
    }

    /**
     * Code that produces exception
    protected function emulatorTwoTransactions($conn, $conn2)
    {
        $tableName = self::TABLE_NAME_USER;

        $insertRow = [
            'userId' => $this->generateUuid(),
            'name' => 'test',
        ];
        $insertRow2 = [
            'userId' => $this->generateUuid(),
            'name' => 'test2',
        ];
        $qb = $conn->table($tableName);
        $qb2 = $conn2->table($tableName);

        $qb->insert($insertRow);
        $mutation = ['userId' => $insertRow['userId'], 'name' => 'updated'];

        $caughtException = null;
        try {
            $conn->transaction(function () use ($conn2, $qb, $qb2, $mutation) {
                // SELECTing within a read-write transaction causes row to acquire shared lock
                 $qb->where('userId', $mutation['userId'])->first();

                $conn2->transaction(function () use ($qb2, $mutation) {
                    // This will time out and result in AbortedException since row is locked
                    $qb2->where('userId', $mutation['userId'])->update(['name' => $mutation['name']]);
                }, 1);
            }, 1);
        } catch (Exception $ex) {
            $caughtException = $ex;
        }

        return $caughtException;
    }
    */

    /**
     * The emulator only allows one read-write transaction
     *
     * https://github.com/GoogleCloudPlatform/cloud-spanner-emulator#features-and-limitations
     */
    public function skiptestEmulatorTwoTransactionsFail(): void
    {
        if (!getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot test EmulatorLock without spanner emulator');
        }

        $conn = $this->getDefaultConnection();
        $conn2 = $this->getAlternativeConnection();

        $caughtException = $this->emulatorTwoTransactions($conn, $conn2);

        $this->assertInstanceOf(AbortedException::class, $caughtException);
        $this->assertStringContainsString('The emulator only supports one transaction at a time', $caughtException->getMessage());
    }

    public function skiptestCacheEmulatorLock(): void
    {
        if (!getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot test EmulatorLock without spanner emulator');
        }

        $conn = $this->getConnectionWithEmulatorLock(CacheEmulatorLock::class);
        $conn2 = $this->getConnectionWithEmulatorLock(CacheEmulatorLock::class);

        $caughtException = $this->emulatorTwoTransactions($conn, $conn2);

        $this->assertNull($caughtException);
    }

    public function testTwoTransaction(): void
    {
        if (!getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot test EmulatorLock without spanner emulator');
        }

        $conn = $this->getDefaultConnection();

        // second (background) transaction that locks spanner emulator
        $this->runSlowTransactionInBackground();

        $conn->transaction(function () use ($conn) {
            $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
        });
        sleep(5);
        echo PHP_EOL . "<<<<<<<<<" . PHP_EOL;
        echo PHP_EOL . file_get_contents("/tmp/ttt") . PHP_EOL;
        echo PHP_EOL . file_get_contents("/tmp/err1") . PHP_EOL;
        echo PHP_EOL . file_get_contents("/tmp/out1") . PHP_EOL;
        echo PHP_EOL . ">>>>>>>>>>" . PHP_EOL;
    }

    /*
     * run second instance of phpunit with single test, that contains slow transaction
     */
    protected function runSlowTransactionInBackground()
    {
        global $argv;

        $process = new Process(
            [
                $argv[0],
                '--filter=testSlowTransaction',
            ],
            null,
            [
                'RUN_SLOW_TRANSACTION' => 1,
            ]
        );

        \pcntl_async_signals(true);


        // wait till transaction is started
        $transactionStarted = false;
        \pcntl_signal(SIGUSR1, function($signal) use (&$transactionStarted) {
            $transactionStarted = true;
        });

        $process->start();

        $timeout = 60;
        $endTime = time() + $timeout;
        while (
            time() < $endTime
            && !$transactionStarted
            && $process->isRunning()
        ) {
            sleep(1);
        }
        $this->assertTrue($transactionStarted, "Transaction is not started in background process in allotted time : {$timeout} sec");
        $this->assertTrue(
            $process->isRunning(),
            'Background process was unexpectedly stopped.'  . PHP_EOL
            . "stdout : {$process->getOutput()},"  . PHP_EOL
            . "stderr : {$process->getErrorOutput()}, "  . PHP_EOL
            . "exit : {$process->getExitCode()}"
        );
        echo PHP_EOL . "Background process has been started" . PHP_EOL;
        exit;

        #shell_exec("RUN_SLOW_TRANSACTION=1 {$argv[0]} --filter=testSlowTransaction 2>/dev/null >/dev/null &");
        shell_exec("echo 1 > /tmp/ttt");
        shell_exec("RUN_SLOW_TRANSACTION=1 {$argv[0]} --filter=testSlowTransaction 2>/tmp/err1 >/tmp/out1 &");
        usleep(100000); // allow process to start
        sleep(10);
        shell_exec("echo 2 > /tmp/ttt");
        #echo shell_exec("RUN_SLOW_TRANSACTION=1 {$argv[0]} --filter=testSlowTransaction");
    }

    public function testSlowTransaction(): void
    {
        global $argv;
        echo PHP_EOL . print_r($argv, true) . PHP_EOL;
        echo PHP_EOL . getenv('RUN_SLOW_TRANSACTION') . PHP_EOL;

        if (!getenv('SPANNER_EMULATOR_HOST')) {
            $this->markTestSkipped('Cannot test EmulatorLock without spanner emulator');
        }
        if (!getenv('RUN_SLOW_TRANSACTION')) {
            $this->markTestSkipped('Can run only when RUN_SLOW_TRANSACTION is set');
        }
        $conn = $this->getDefaultConnection();
        $conn->transaction(function () use ($conn) {
            $this->assertEquals([12345], $conn->selectOne('SELECT 12345'));
            // inform parent process
            \posix_kill (\posix_getppid() , SIGUSR1);
            sleep(40);
            echo PHP_EOL . "Slipped" . PHP_EOL;
        });
    }
}

