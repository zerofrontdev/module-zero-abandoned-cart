<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Controller\Unsubscribe;

use Magento\Customer\Model\Session as CustomerSession;

/**
 * The customer session as an array: setData()/unsetData() are magic (__call
 * to the storage), so PHPUnit cannot mock them.
 */
class FakeSession extends CustomerSession
{
    /** @var array<string, mixed> */
    public array $data = [];
    /** @var array<int, array<int, mixed>> [method, key, value?] in order */
    public array $calls = [];

    public function __construct()
    {
        // No storage, no PHP session.
    }

    /**
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $this->calls[] = [$method, ...$args];
        if ($method === 'setData') {
            $this->data[$args[0]] = $args[1];
        } elseif ($method === 'unsetData') {
            unset($this->data[$args[0]]);
        }

        return $this;
    }

    /**
     * @param string $key
     * @param bool $clear
     * @return mixed
     */
    public function getData($key = '', $clear = false)
    {
        $this->calls[] = ['getData', $key];

        return $this->data[$key] ?? null;
    }
}
