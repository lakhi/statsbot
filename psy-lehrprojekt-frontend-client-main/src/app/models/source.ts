//A course-material source a salient-arm answer cites, shown as a chip.
//Mirrors the `sources` entries returned by POST /messages and GET /history/{started}.
export class Source{

  //chunk id, e.g. "hyptest-003"
  id!: string;

  //what the student sees, e.g. "Vorlesung 14: t-Test I, Folie 12". Written by
  //the corpus pipeline; the hyptest pilot falls back to "<heading>, p. <page>".
  label!: string;

}
