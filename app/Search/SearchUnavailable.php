<?php

namespace App\Search;

use RuntimeException;

/**
 * Search couldn't run at all (the question can't be matched by meaning or by keywords), as opposed to finding nothing.
 */
class SearchUnavailable extends RuntimeException {}
