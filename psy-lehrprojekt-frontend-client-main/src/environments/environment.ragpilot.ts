// RAG pilot build. Points at the PARALLEL backend, never the live one.
//
// Swapped in by the "rag-pilot" configuration in angular.json, and served from
// /rag-pilot-test/ so the live SPA at the docroot is untouched.
export const environment = {
  production: true,
  url: "https://statsbot.univie.ac.at/rag-pilot-test-api/api/",
  //Read /rag-pilot-test/<no-rag|non-salient|salient>/ and pin that arm per
  //message (see app/test-arm.ts). The backend honours it only on a test stack.
  armOverride: true
};
