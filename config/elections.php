<?php

return [

    /*
    | Live results are a small static JSON file per election, served by the
    | web server and polled by the election page (cheap on shared hosting:
    | no PHP or database work per poll).
    */
    'live_path' => env('ELECTION_LIVE_PATH', public_path('live')),

    /*
    | The file is only rewritten once this many new ballots have come in, and
    | at most once per `min_interval` seconds, so a change in the totals
    | can't be traced to one person's vote. The final results are published
    | in full when the election closes.
    */
    'batch' => (int) env('ELECTION_RESULTS_BATCH', 5),

    'min_interval' => (int) env('ELECTION_RESULTS_INTERVAL', 5),

    // How often open election pages check for new results (plus jitter).
    'poll_seconds' => 15,

];
