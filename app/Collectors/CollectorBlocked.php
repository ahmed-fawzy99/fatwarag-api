<?php

namespace App\Collectors;

use RuntimeException;

/**
 * The source refused us (403, 429 or a challenge page). The collector stops and the next run resumes.
 */
class CollectorBlocked extends RuntimeException {}
