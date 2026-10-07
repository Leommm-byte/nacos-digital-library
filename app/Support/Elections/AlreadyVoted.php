<?php

namespace App\Support\Elections;

class AlreadyVoted extends BallotRejected
{
    public function __construct()
    {
        parent::__construct('You have already voted in this election.');
    }
}
