import { Source } from './source';

export class Message{

  role?: "assistant" | "user";
  content = "";

  //Assistant turns only, with the same three states as Answer.sources:
  //absent = render nothing, [] = the "no matching course material" note,
  //non-empty = chips. Restored threads replay what was shown live.
  sources?: Source[];

}
