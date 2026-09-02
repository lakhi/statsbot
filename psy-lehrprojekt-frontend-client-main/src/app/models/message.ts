import { Source } from './source';

export class Message{

  role?: "assistant" | "user";
  content = "";

  //Two-layer answer fields, present only on assistant turns that came from a
  //live /messages call. Messages restored from history carry `content` alone,
  //so the template falls back to rendering that.
  from_materials?: string | null;
  from_general?: string;
  sources?: Source[];
  materials_note?: string | null;

}
