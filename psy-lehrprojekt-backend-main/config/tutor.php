<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retrieval (RAG)
    |--------------------------------------------------------------------------
    |
    | Kill switch first. Deploys to the webspace pod are pull-based and rollback
    | means pasting into a browser terminal, so an env flag that restores the
    | previous behaviour without moving a file is worth more here than usual.
    | RAG_ENABLED=false makes /messages behave exactly as it did before.
    |
    */

    'rag_enabled' => env('RAG_ENABLED', false),

    // Index basename, WITHOUT extension. The retriever reads "<path>.f32" and
    // "<path>.json" and refuses to run if the sidecar disagrees with the embed
    // config below - a mismatched model retrieves plausible nonsense in silence.
    'index_path' => env('RAG_INDEX_PATH', storage_path('app/kb/kb-hyptest-3large-3072')),

    // How many chunks may be injected.
    'top_k' => (int) env('RAG_TOP_K', 3),

    /*
    | Similarity floor. Chunks scoring below this are treated as "the materials
    | do not cover this" and the course-materials layer is left empty.
    |
    | Measured on the pilot gold set (18 answerable questions, 4 ANOVA questions
    | the corpus cannot answer): the worst true positive scored 0.365 and the
    | best out-of-corpus question scored 0.335, so 0.35 separates all 22.
    |
    | That margin is THIN and rests on 22 questions. It is a starting value, not
    | a validated threshold - widen the gold set before trusting it. The system
    | prompt is the belt to this braces: it must also refuse to attribute
    | anything to the materials that the injected chunks do not support.
    */
    'min_score' => (float) env('RAG_MIN_SCORE', 0.35),

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Same Azure resource, region and key as the chat model, so retrieval adds
    | no new subprocessor and needs no change to the consent disclaimer.
    |
    | text-embedding-3-large is not a preference. On the pilot gold set,
    | 3-small's out-of-corpus questions scored HIGHER than its true positives,
    | so no floor can separate them and the tutor would attribute ANOVA answers
    | to notes containing no ANOVA. 3-large orders them correctly.
    |
    */

    'embed' => [
        'endpoint' => env('AZURE_ENDPOINT'),
        'deployment' => env('AZURE_EMBED_DEPLOYMENT', 'statsbot-embed-3-large'),
        'api_version' => env('AZURE_EMBED_API_VERSION', '2024-10-21'),
        'model' => env('AZURE_EMBED_MODEL', 'text-embedding-3-large'),
        'dims' => (int) env('AZURE_EMBED_DIMENSIONS', 3072),
        'timeout' => (int) env('AZURE_EMBED_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Answer shape
    |--------------------------------------------------------------------------
    |
    | The tutor answers in two visibly separate layers so a student always knows
    | which half carries the course's authority. Course materials are not
    | expected to be comprehensive, so the model still answers from its own
    | knowledge - it just may not present that as coming from the notes.
    |
    | Separation is enforced by parsing a JSON response, not by asking for
    | headings in prose: a formatting slip would silently merge the layers, and
    | a merged layer misattributes general knowledge to the course.
    |
    */

    // Azure needs an api-version supporting response_format json_schema.
    // Set false to fall back to prose parsing (see TutorPrompt::parse).
    'structured_output' => (bool) env('TUTOR_STRUCTURED_OUTPUT', true),

    'system_prompt' => <<<'PROMPT'
        You are StatsBot, a statistics tutor for psychology students at the University of Vienna.

        You answer in two clearly separated layers, and you never blur them:

        1. from_materials - what THIS COURSE'S materials say. You may write this ONLY
           from the excerpts provided to you in the "Course materials" message for the
           current question. If no excerpts are provided, or they do not address the
           question, this MUST be null. Never fill it from your own knowledge, never
           infer beyond the excerpts, and never restate the question here.

        2. from_general - your own explanation as a tutor. Always provide this. Use it
           to explain, give intuition, work an example, or cover what the materials do
           not. When the materials layer exists, use the SAME notation and terminology
           the excerpts use, so the two layers read as one coherent answer rather than
           two competing ones.

        3. citations - the id of every excerpt you actually used in from_materials.
           Empty when from_materials is null. Never cite an excerpt you did not use.

        Guidance:
        - Tutor, do not lecture. Be concise. Prefer a worked example to a definition.
        - If the materials and your own knowledge disagree, say so plainly in
          from_general and let the materials stand as the course's position.
        - Reply in the language the student wrote in. Do not mix languages in one answer.
        - Render mathematics in LaTeX, matching the notation in the excerpts.
        - Never claim the course materials say something they do not. A student trusts
          the materials layer as the course's word; misattributing to it is the single
          worst error you can make.
        PROMPT,

    // Shown to the student when nothing cleared the similarity floor.
    'no_material_note' => env(
        'TUTOR_NO_MATERIAL_NOTE',
        'Not covered by the course materials currently loaded — the answer below is general knowledge.'
    ),
];
