//A course-material passage the tutor cited in its "from materials" layer.
//Mirrors the `sources` entries returned by POST /messages.
export class Source{

  //chunk id, e.g. "hyptest-003"
  id!: string;

  //heading chain within the document, e.g. "5 Type I and Type II Errors › Type I Error"
  heading!: string;

  doc_title!: string;

  page_start!: number;
  page_end!: number;

  //cosine similarity to the question; kept for the pilot's evaluation, not shown to students
  score!: number;

}
