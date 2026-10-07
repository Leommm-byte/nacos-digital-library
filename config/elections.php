<?php

return [

    /*
    | Live results are a small static JSON file per election, served by the
    | web server and polled by the election page (cheap on shared hosting:
    | no PHP or database work per poll).
    */
    'live_path' => env('ELECTION_LIVE_PATH', public_path('live')),

    // How often open election pages check for new results (plus jitter).
    'poll_seconds' => 10,

];
