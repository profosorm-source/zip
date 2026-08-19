<?php

declare(strict_types=1);

namespace App\Adapters;

/**
 * KycFaceVerificationAdapter Interface
 * Handles AI-based validation of identity verification images.
 *
 * Implementations MUST wrap external AI microservice calls inside a CircuitBreaker and enforce a Timeout using try/catch.
 */
interface KycFaceVerificationAdapter
{
    /**
     * Verify an uploaded image containing a user face and ID document.
     *
     * @param string $absoluteFilePath The local path to the uploaded image.
     * @return array<string, mixed>
     */
    public function analyzeImage(string $absoluteFilePath): array;

    /**
     * Checks if the external AI microservice is properly configured to function.
     *
     * @return bool
     */
    public function isConfigured(): bool;
}


