<?php

return [

    /*
    | Live results are a small static JSON file per election, served by the
    | web server and polled by the election page (cheap on shared hosting:
    | no PHP or database work per poll).
    */
    'live_path' => env('ELECTION_LIVE_PATH', public_path('live')),

    // How often open election pages check for new results (plus jitter).
    // Pages that see no change slow down step by step to the maximum, and
    // speed up again on the next change. A check that finds nothing new is
    // a 304 from the web server, with no PHP or database work.
    'poll_seconds' => 5,
    'poll_max_seconds' => 30,

    // Candidate photos: the largest file accepted, and the side of the
    // square stored (shown at most about 160 px wide, on HiDPI screens).
    'photo_max_kb' => 8192,
    'photo_side' => 480,

];
