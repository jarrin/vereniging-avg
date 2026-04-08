<?php

namespace App\Services;

/**
 * CSRF Token Service
 * Handles generation, storage, and validation of CSRF tokens
 */
class CsrfToken
{
    protected const TOKEN_LENGTH = 32;
    protected const TOKEN_NAME = 'csrf_token';
    protected const TOKEN_FIELD_NAME = '_csrf_token';

    /**
     * Generate a new CSRF token and store it in the session
     *
     * @return string The generated CSRF token
     */
    public static function generate(): string
    {
        // Only generate a new token if one doesn't exist
        if (empty($_SESSION[self::TOKEN_NAME])) {
            $_SESSION[self::TOKEN_NAME] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }
        
        return $_SESSION[self::TOKEN_NAME];
    }

    /**
     * Get the current CSRF token
     *
     * @return string|null The CSRF token or null if not set
     */
    public static function get(): ?string
    {
        return $_SESSION[self::TOKEN_NAME] ?? null;
    }

    /**
     * Get the CSRF token field name (for form inputs)
     *
     * @return string The field name to use in forms
     */
    public static function getFieldName(): string
    {
        return self::TOKEN_FIELD_NAME;
    }

    /**
     * Validate a CSRF token from POST/GET request
     *
     * @param string|null $token The token to validate (from user input)
     * @return bool True if valid, false otherwise
     */
    public static function validate(?string $token = null): bool
    {
        // Get token from parameter or POST data
        if ($token === null) {
            $token = $_POST[self::TOKEN_FIELD_NAME] ?? $_GET[self::TOKEN_FIELD_NAME] ?? null;
        }

        // Token must exist
        if ($token === null) {
            return false;
        }

        // Token must exist in session
        if (empty($_SESSION[self::TOKEN_NAME])) {
            return false;
        }

        // Token must match exactly (using hash_equals for timing-safe comparison)
        return hash_equals($_SESSION[self::TOKEN_NAME], $token);
    }

    /**
     * Regenerate the CSRF token (useful after login for security)
     *
     * @return string The new CSRF token
     */
    public static function regenerate(): string
    {
        unset($_SESSION[self::TOKEN_NAME]);
        return self::generate();
    }

    /**
     * Generate HTML for a hidden input field containing the CSRF token
     * Can be used in Twig templates
     *
     * @return string HTML hidden input element
     */
    public static function field(): string
    {
        $token = self::generate();
        $fieldName = self::getFieldName();
        return sprintf('<input type="hidden" name="%s" value="%s">', 
            htmlspecialchars($fieldName), 
            htmlspecialchars($token)
        );
    }
}
