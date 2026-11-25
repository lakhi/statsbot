import { Source } from './source';

export class History{

  id!: number;
  //sent message to GPT via backend
  sent!: string;

  //received message from GTP via backend
  received!: string;

  //timestamp start of a chat session
  started!: number;

  created_at!: Date;

  //thread view only: what a salient-arm answer showed under it, replayed from
  //history.sources_shown. Absent for every other row.
  sources?: Source[];

}
