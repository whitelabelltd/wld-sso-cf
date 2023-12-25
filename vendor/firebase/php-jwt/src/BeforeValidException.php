<?php
/**
 * @license BSD-3-Clause
 *
 * Modified by __root__ on 23-December-2023 using Strauss.
 * @see https://github.com/BrianHenryIE/strauss
 */

namespace WLD_SSO_CF\Dependencies\Firebase\JWT;

class BeforeValidException extends \UnexpectedValueException implements JWTExceptionWithPayloadInterface
{
    private object $payload;

    public function setPayload(object $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): object
    {
        return $this->payload;
    }
}
