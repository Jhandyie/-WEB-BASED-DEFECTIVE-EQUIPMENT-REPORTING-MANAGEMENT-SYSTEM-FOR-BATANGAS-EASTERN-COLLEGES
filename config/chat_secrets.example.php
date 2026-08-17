<?php

return [
    // THIS FILE IS COMMITTED TO GIT. Never put a real key here.
    // Copy it to config/chat_secrets.php (gitignored) and put the real key in
    // THAT file. Editing this one does nothing — no code reads it.
    //
    // Becca runs on Google Gemini's free tier. Get a key at
    // https://aistudio.google.com/apikey — no card required.
    'gemini_api_key' => 'your-gemini-api-key-here',

    // Optional. Leave blank to use the default (gemini-3.6-flash).
    // Model IDs retire — gemini-2.5-flash was closed to new keys in Aug 2026 and
    // every request started failing into the local fallback, which reads as
    // "Becca got dumb" rather than an outage. Run
    //   c:\xampp\php\php.exe scripts\check_ai_key.php
    // before a demo; it lists what this key can actually reach.
    // Set 'gemini-flash-latest' to never touch this again, accepting that the
    // model behind it can change without warning.
    'gemini_model' => '',

    // Optional. Thinking is disabled by default because Becca answers short
    // support questions and thinking spends output tokens and latency for no
    // gain here. Override only if you want it back — the field name differs by
    // model family: ['thinkingBudget' => 1024] on the 2.5 series,
    // ['thinkingLevel' => 'low'] on the 3.x series.
    // 'gemini_thinking' => ['thinkingBudget' => 0],
];
