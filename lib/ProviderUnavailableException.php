<?php

namespace Noiiolelo;

/**
 * Thrown by getProvider() when a search provider's backend cannot be reached
 * or initialized (connection refused, no alive nodes, missing credentials, ...).
 *
 * Web entry points catch this and render a clear "provider unavailable"
 * response (see lib/web_error.php) instead of letting the raw transport
 * exception fatal the request with a blank screen. CLI consumers of
 * ProviderFactory (save/createindex scripts) intentionally still fail loudly
 * and are unaffected — only getProvider() wraps.
 */
class ProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        private readonly string $providerName,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Canonical name of the provider that is down ('Elasticsearch', ...). */
    public function getProviderName(): string
    {
        return $this->providerName;
    }
}
