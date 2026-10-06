<?php

namespace App\Support\Uploads;

use RuntimeException;

/**
 * An uploaded image GD can't decode.
 */
class UnreadableImage extends RuntimeException {}
