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

/**
 * Describes a emulator lock interface.
 */
interface EmulatorLockInterface
{
    /**
     * Attempt to acquire the lock and run callback.
     *
     * @template T
     * @param  Closure(): T $callback
     * @param string $emulatorHost used to limit lock to single host
     * @param int $lockTime  time of lock, should be longer then longest query/transaction
     * @param int $lockWaitTime max time of attempting to acquire lock
     * @return T
     */
    public function block(Closure $callback, string $emulatorHost, int $lockTime, int $lockWaitTime);
}
