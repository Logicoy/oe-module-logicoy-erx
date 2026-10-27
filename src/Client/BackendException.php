<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Client;

use RuntimeException;
use Throwable;

class BackendException extends RuntimeException
{
    private int $httpStatus;

    /** @var array<string, mixed> */
    private array $context;

    private ?string $userMessage;

    /**
     * @param string $message what went wrong, for the log: endpoint, HTTP
     *                        status and the backend's words
     * @param array<string, mixed> $context
     * @param string|null $userMessage the backend's own words for the screen,
     *                                 when they differ from the log message
     */
    public function __construct(
        string $message,
        int $httpStatus = 0,
        array $context = [],
        ?Throwable $previous = null,
        ?string $userMessage = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->context = $context;
        $this->userMessage = $userMessage;
    }

    /**
     * The message for a prescriber or admin: what the LogiCoy backend said
     * went wrong, without the endpoint and HTTP status that belong in the
     * log. Falls back to the log message, which for failures raised on this
     * side is already written for the screen.
     */
    public function getUserMessage(): string
    {
        return $this->userMessage !== null && trim($this->userMessage) !== ''
            ? $this->userMessage
            : $this->getMessage();
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * A transient failure is worth another queue attempt; a 4xx is not.
     */
    public function isRetryable(): bool
    {
        if ($this->httpStatus === 0) {
            return true; // network level failure
        }
        if ($this->httpStatus === 408 || $this->httpStatus === 429) {
            return true;
        }
        return $this->httpStatus >= 500;
    }
}
