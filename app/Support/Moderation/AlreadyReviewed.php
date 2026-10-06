<?php

namespace App\Support\Moderation;

use RuntimeException;

/**
 * The book was no longer waiting for review (someone else got there first).
 */
class AlreadyReviewed extends RuntimeException {}
