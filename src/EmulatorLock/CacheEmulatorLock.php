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

namespace Colopl\Spanner\EmulatorLock;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Uses default cache locking
 * see https://laravel.com/docs/9.x/cache#atomic-locks for details
 *
 * WARN: most drivers are limited to application/process,
 * so it wouldn't work for many processes and different laravel apps that use the same spanner emulator
 */
class CacheEmulatorLock implements EmulatorLockInterface
{
    /**
     * @var array<string, Lock>
     */
    protected static $emulatorLocks = [];

    /**
     * @inheritDoc
     * @template T
     * @param  Closure(): T $callback
     * @return T
     */
    public function block(Closure $callback, string $emulatorHost, int $lockTime, int $lockWaitTime)
    {
        if ($lockWaitTime <= 0) {
            throw new InvalidArgumentException("lockWaitTime should be greater then zero. Provided value : '{$lockWaitTime}'.");
        }
        if ($lockWaitTime >= $lockTime) {
            throw new InvalidArgumentException("lockWaitTime should be less then lockTime. Provided values : lockTime = '{$lockTime}', lockWaitTime = '{$lockWaitTime}'.");
        }

        if (!isset(self::$emulatorLocks[$emulatorHost])) {
            self::$emulatorLocks[$emulatorHost] = Cache::lock("spanner_emulator_lock_${emulatorHost}", $lockTime);
        }

        echo PHP_EOL . print_r(self::$emulatorLocks, true) . PHP_EOL;
        return self::$emulatorLocks[$emulatorHost]->block($lockWaitTime, $callback);
    }
}
