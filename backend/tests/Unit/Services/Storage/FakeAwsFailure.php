<?php

namespace Tests\Unit\Services\Storage;

use RuntimeException;
use Throwable;

/**
 * Stands in for `Aws\Exception\AwsException` without pulling the SDK into the
 * tests.
 *
 * {@see \App\Services\Storage\StorageWriteRetrier} reads `getStatusCode()` and
 * `getAwsErrorCode()` through `method_exists()` rather than type-checking an AWS
 * class, so that it keeps working with a mocked disk and with non-S3 drivers.
 * This class is what proves that duck typing holds — if the retrier ever
 * narrowed to a concrete AWS type, every test using it would fail.
 *
 * It lives in its own file rather than beside a test class so it autoloads when
 * a single test file is run in isolation.
 */
class FakeAwsFailure extends RuntimeException
{
    public function __construct(
        string $message,
        private ?int $statusCode = null,
        private ?string $awsErrorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getAwsErrorCode(): ?string
    {
        return $this->awsErrorCode;
    }
}
