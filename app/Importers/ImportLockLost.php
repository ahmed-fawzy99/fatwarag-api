<?php

namespace App\Importers;

use RuntimeException;

/**
 * Another process took this source's import lock (ours expired): the import stops instead of running alongside it.
 */
class ImportLockLost extends RuntimeException {}
