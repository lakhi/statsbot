//A course-material source a salient-arm answer cites, shown as a chip.
//Mirrors the `sources` entries returned by POST /messages and GET /history/{started}.
export class Source{

  //chunk id, e.g. "stat1-v14-011"
  id!: string;

  //the full citation, e.g. "Statistik I · Vorlesung 14: t-Test · Folie 11".
  //Written by the corpus pipeline; the hyptest pilot falls back to
  //"<heading>, p. <page>". Shown on hover, and in full when a chip is tapped.
  label!: string;

  //the label in parts, when the index has them, so a chip that runs out of
  //room shortens only the topic - never the course, document or slide a
  //student needs to find it
  cite?: Cite;

}

export interface Cite{
  course: string;        //"Statistik I"
  doc: string;           //"Vorlesung 14"
  topic: string | null;  //"t-Test" (none for exams, which mix topics)
  pos: string;           //"Folie 11"
}
