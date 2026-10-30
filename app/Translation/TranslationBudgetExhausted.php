<?php

namespace App\Translation;

use RuntimeException;

/** The monthly AI budget ran out before the translation finished. */
class TranslationBudgetExhausted extends RuntimeException {}
