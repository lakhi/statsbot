import { Source } from './source';

export class Message{

  role?: "assistant" | "user";
  content = "";

  //Present only on assistant turns that came from a live /messages call.
  //Messages restored from history carry `content` alone, and render without
  //source chips.
  sources?: Source[];

}
