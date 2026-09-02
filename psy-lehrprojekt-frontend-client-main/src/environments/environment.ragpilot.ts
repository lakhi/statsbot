// RAG pilot build. Points at the PARALLEL backend, never the live one.
//
// Swapped in by the "rag-pilot" configuration in angular.json, and served from
// /rag-pilot-test/ so the live SPA at the docroot is untouched.
export const environment = {
  production: true,
  url: "https://statsbot.univie.ac.at/rag-pilot-test-api/api/"
};
