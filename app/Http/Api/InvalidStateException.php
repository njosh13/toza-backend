<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 409: the action is not allowed in the record's current state. The frontend
 * renders exactly `allowed_actions`; it never encodes transition rules.
 */
final class InvalidStateException extends RuntimeException
{
    /** @param  list<string>  $allowedActions */
    public function __construct(
        string $message,
        public readonly string $currentStatus,
        public readonly array $allowedActions = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_status' => $this->currentStatus,
            'allowed_actions' => $this->allowedActions,
        ], 409);
    }
}