<?php
/**
 * @license BSD-3-Clause
 *
 * Modified by __root__ on 23-December-2023 using Strauss.
 * @see https://github.com/BrianHenryIE/strauss
 */
namespace WLD_SSO_CF\Dependencies\Firebase\JWT;

interface JWTExceptionWithPayloadInterface
{
    /**
     * Get the payload that caused this exception.
     *
     * @return object
     */
    public function getPayload(): object;

    /**
     * Get the payload that caused this exception.
     *
     * @param object $payload
     * @return void
     */
    public function setPayload(object $payload): void;
}
