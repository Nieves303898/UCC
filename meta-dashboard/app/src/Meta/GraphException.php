<?php
declare(strict_types=1);

namespace App\Meta;

use RuntimeException;

final class GraphException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $graphCode = 0,
        public readonly int $subcode = 0,
        public readonly string $type = '',
        public readonly int $httpStatus = 0,
        public readonly ?string $fbtraceId = null,
    ) {
        parent::__construct($message, $graphCode);
    }

    /** Token vencido, revocado o sin permisos. */
    public function isAuthError(): bool
    {
        return $this->graphCode === 190 || $this->type === 'OAuthException' && in_array($this->graphCode, [102, 190, 200, 10], true);
    }

    /** Límite de llamadas alcanzado. */
    public function isRateLimit(): bool
    {
        return in_array($this->graphCode, [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80014], true);
    }

    /** Errores temporales que vale la pena reintentar. */
    public function isTransient(): bool
    {
        return in_array($this->graphCode, [1, 2], true) || $this->httpStatus >= 500;
    }

    /** Métrica de insights inexistente o deprecada. */
    public function isInvalidParam(): bool
    {
        return $this->graphCode === 100;
    }
}
