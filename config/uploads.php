<?php

/*
| Limits for books uploaded by students (UploadController). Sizes are in
| kilobytes. Governors and admins are exempt from the per-user caps.
*/
return [
    'pdf_max_kb' => 10 * 1024,
    'page_max_kb' => 5 * 1024,
    'max_pages' => 60,
    'cover_max_kb' => 2 * 1024,

    // Longest side of a stored scanned page, in pixels, and its JPEG quality.
    'page_max_pixels' => 2000,
    'page_quality' => 80,

    'per_day' => 10,
    'pending' => 20,

    // Text read on the uploader's device, in characters.
    'text_max_chars' => 300_000,

    // Optional virus scanning: the path to `clamdscan` or `clamscan`.
    // Leave empty where ClamAV isn't installed (shared hosting).
    'clamav' => env('UPLOADS_CLAMAV'),
];
