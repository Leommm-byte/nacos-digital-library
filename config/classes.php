<?php

/*
| How classes are formed: programmes, how many years each lasts at each
| stage, and the courses (arms) a stage is split into. A class is one
| programme, level and, where the stage has arms, arm: "HND1 SWD
| Full-time". Change it here when the school changes (a new arm, HND for
| CODFEL); the roll, accounts, elections, timetables and the new session
| all follow.
*/

return [

    // Years at each stage (ND, HND) per programme. A stage that isn't
    // listed isn't offered (CODFEL has no HND for now).
    'years' => [
        'full_time' => ['ND' => 2, 'HND' => 2],
        'part_time' => ['ND' => 3, 'HND' => 3],
        'codfel' => ['ND' => 2],
    ],

    // Courses within a stage, told apart by one digit of the matric
    // number's seven: F/HD/24/3211001 is SWD, F/HD/24/3212001 is NCC (the
    // 4th digit). A stage without arms is one class per level.
    'arm_digit' => 4,

    'arms' => [
        'swd' => ['stage' => 'HND', 'digit' => '1', 'short' => 'SWD', 'name' => 'Software and Web Development'],
        'ncc' => ['stage' => 'HND', 'digit' => '2', 'short' => 'NCC', 'name' => 'Networking and Cloud Computing'],
    ],

];
