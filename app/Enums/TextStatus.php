<?php

namespace App\Enums;

/**
 * Where a book file's searchable text came from.
 */
enum TextStatus: string
{
    /** No text (a PDF without a text layer, or reading was skipped). */
    case None = 'none';

    /** Read on the uploader's device (PDF text layer or on-device OCR). */
    case Device = 'device';

    /** Scanned pages waiting for, or going through, the AI pass. */
    case Queued = 'queued';

    /** The AI pass has finished. */
    case Done = 'done';
}
