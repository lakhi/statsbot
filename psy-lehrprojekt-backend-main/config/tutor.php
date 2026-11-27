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
    // The name is stable across corpus versions (the version lives in the
    // sidecar and is stamped on every history row as kb_version), so a new
    // corpus never needs a .env edit. It arrives encrypted - see tools/kb/README.md.
    'index_path' => env('RAG_INDEX_PATH', storage_path('app/kb/kb-corpus')),

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
    | Two-step scan (see KbRetriever::rank). Every chunk is first scored on its
    | leading `coarse_dims` values - text-embedding-3-* is Matryoshka-trained,
    | so a renormalised prefix is a usable embedding - and only the best
    | `rescore_pool` are scored at full width. Final scores stay exact, so the
    | floor above keeps its meaning. On PHP 8.2 with 2,500 chunks this cut two
    | queries from ~450 ms to ~60 ms. Indexes no larger than the pool are
    | scanned exactly; coarse_dims = 0 disables the first pass.
    */
    'coarse_dims' => (int) env('RAG_COARSE_DIMS', 256),
    'rescore_pool' => (int) env('RAG_RESCORE_POOL', 100),

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
    | The tutor writes ONE coherent answer. Citations are still returned as a
    | separate field, so which excerpts an answer actually drew on stays
    | measurable even though the prose is unified.
    |
    | That is a deliberate trade. The earlier two-layer shape made
    | misattribution structurally impossible: a materials layer could not exist
    | without retrieved passages, whatever the model claimed. One coherent
    | answer gives that up - the model can now blend course material and its own
    | knowledge invisibly. Keeping citations means misattribution can still be
    | DETECTED after the fact (compare cited ids against history.kb_chunks); it
    | can no longer be PREVENTED. The system prompt is the only guard left.
    |
    */

    // Azure needs an api-version supporting response_format json_schema.
    // Set false to fall back to prose parsing (see TutorPrompt::parse).
    'structured_output' => (bool) env('TUTOR_STRUCTURED_OUTPUT', true),

    /*
    | Salient arm only: the most citation chips one answer shows. One chip per
    | distinct label (two chunks of the same slide are one chip), highest
    | retrieval score first. Every valid citation is still stored, uncapped, in
    | history.cited; history.sources_shown records exactly what was displayed.
    |
    | Fixed for the study (preregistered as 3), so change it only between
    | cohorts. Values below 1 are treated as 1.
    */
    'max_citations' => (int) env('TUTOR_MAX_CITATIONS', 3),

    /*
    |--------------------------------------------------------------------------
    | System prompt
    |--------------------------------------------------------------------------
    |
    | Split in two so the experimental contrast is auditable. Every arm gets
    | `base`, byte-identical. Both RAG arms get `base` + "\n\n" + `materials` -
    | the SAME prompt for salient and non-salient, because whether citations are
    | shown is decided only after the model has answered. Diff the no-RAG and RAG
    | prompts and the diff IS the retrieval treatment: one contiguous appended
    | block, with every pedagogical, tone, language and notation instruction
    | shared.
    |
    | The materials block is attached by ARM, not by whether this particular
    | turn retrieved anything. Two reasons: the system prompt has to be a stable
    | cacheable prefix (see TutorPrompt's header), and a student should meet one
    | consistent tutor rather than one whose instructions change turn to turn
    | depending on a similarity score. The block handles the empty-retrieval
    | case itself, in its last line.
    |
    */

    'system_prompt_base' => <<<'PROMPT'
        You are StatsBot, a statistics tutor for psychology students at the University of Vienna.

        Answer the student's question as one continuous, coherent explanation. Do not split your
        reply into labelled sections, and do not separate it by where the information came from.

        Guidance:
        - Tutor, do not lecture. Be concise. Prefer a worked example to a definition.
        - Reply in the language the student wrote in. Do not mix languages in one answer.
        - Render mathematics in LaTeX.
        - If you are not confident about something, say so rather than stating it flatly.

        Return JSON with two fields:
        - answer: your complete explanation.
        - citations: an array of excerpt ids. Leave it empty unless you have been given
          excerpts to cite.
        PROMPT,

    'system_prompt_materials' => <<<'PROMPT'
        This course has its own materials. When a "Course materials" message appears for the
        current question, it contains excerpts from them.

        - Prefer the excerpts over your own knowledge wherever they address the question, and
          use the notation and terminology they use, so your answer matches what the student
          has already read.
        - Put the id of every excerpt you actually drew on in citations. Never cite one you did
          not use, and never cite one to support a claim it does not make.
        - Refer to the course materials only through citations. In the answer itself, never
          mention the materials, the excerpts, or the lectures, slides, exercises or exams they
          come from - no "as covered in your lecture", no "siehe Folie 12". The answer has to
          read the same whether or not its citations are ever shown.
        - The materials are not comprehensive. Where they do not cover the question, answer from
          your own knowledge as usual - do not say the materials are missing, do not apologise
          for them, and do not describe what they do or do not contain.
        - Never present your own knowledge as something the course materials say. A student
          treats anything attributed to the materials as the course's word; misattributing to
          them is the single worst error you can make.
        - If no "Course materials" message appears, answer normally and cite nothing.
        PROMPT,

];
