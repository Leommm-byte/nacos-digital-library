<?php

return [

    // Smart (AI) replies one student can get per day. The admin can change
    // it in Settings; 0 turns AI replies off. Without an Anthropic API key
    // (services.anthropic.key) every reply comes from the free helper.
    'daily_limit' => (int) env('ASSISTANT_DAILY_LIMIT', 30),

    // Messages kept per student; older ones are deleted as new ones arrive.
    'history' => 40,

    // Earlier messages sent to the AI with each question, for context.
    'context' => 10,

];
