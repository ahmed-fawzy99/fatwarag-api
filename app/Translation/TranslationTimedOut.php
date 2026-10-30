<?php

namespace App\Translation;

use RuntimeException;

/** The translation did not finish within the time allowed to a reader waiting for it. */
class TranslationTimedOut extends RuntimeException {}
