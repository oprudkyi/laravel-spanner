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

namespace Colopl\Spanner\Concerns;

use Closure;
use Colopl\Spanner\EmulatorLock\EmulatorLockInterface;
use InvalidArgumentException;

/**
 * Helper functions to handle Spanner Emulator limits
 */
trait ManagesEmulator
{
    /**
     * @var array<string, array{'locker': EmulatorLockInterface, 'locked' : bool}>
     */
    protected static $emulatorLocks = [];

    protected function getEmulatorHost(): ?string
    {
        return $this->config['client']['hasEmulator'] ?? getenv('SPANNER_EMULATOR_HOST');
    }

    /**
     * Handles emulator lock
     * @template T
     * @param  Closure(): T $callback
     * @return T
     */
    protected function withEmulatorLockHandling(Closure $callback)
    {
        $emulatorHost = $this->getEmulatorHost();
        $hasEmulator = (bool) $emulatorHost;
        // ignore nested transactions and real spanner
        if ($this->transactions > 0 || !$hasEmulator) {
            return $callback();
        }

        $emulatorHost = (string) $emulatorHost;

        $lockConfig = $this->config['emulatorLock'] ?? [];
        $lockerClass = $lockConfig['lockerClass'] ?? '';
        // ignore empty locker
        if (!$lockerClass) {
            return $callback();
        }

        if (!isset(self::$emulatorLocks[$emulatorHost])) {
            $locker = new $lockerClass();

            if (! $locker instanceOf EmulatorLockInterface) {
                throw new InvalidArgumentException("locker class '{$lockerClass}' should implement EmulatorLockInterface.");
            }
            self::$emulatorLocks[$emulatorHost] = [
                'locker' => $locker,
                'locked' => false,
            ];
        }

        // re-entry
        if (self::$emulatorLocks[$emulatorHost]['locked']) {
            return $callback();
        }

        try {
            self::$emulatorLocks[$emulatorHost]['locked'] = true;
            return self::$emulatorLocks[$emulatorHost]['locker']
                ->block($callback, $emulatorHost, $lockConfig['lockTime'] ?? 60, $lockConfig['lockWaitTime'] ?? 30);
        } finally {
            self::$emulatorLocks[$emulatorHost]['locked'] = false;
        }
    }

}
