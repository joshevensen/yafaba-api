<?php

namespace App\Services\Enrichment;

use RuntimeException;

/**
 * Thrown when a Voyage AI embeddings request fails outright, or succeeds but
 * returns a vector count or dimensionality that doesn't match the request —
 * either way, the queued job's retry/backoff path should engage rather than
 * silently persisting an incomplete embedding.
 */
class VoyageEmbeddingException extends RuntimeException {}
