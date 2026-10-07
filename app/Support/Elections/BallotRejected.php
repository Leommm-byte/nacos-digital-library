<?php

namespace App\Support\Elections;

use RuntimeException;

/**
 * A ballot that can't be counted; the message is shown to the voter.
 */
class BallotRejected extends RuntimeException {}
