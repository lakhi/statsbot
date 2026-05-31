export class History{

  id!: number;
  //sent message to GPT via backend
  sent!: string;

  //received message from GTP via backend
  received!: string;

  //sum of tokens for message + response
  total_tokens!: number;

  //timestamp start of a chat session
  started!: number;

  created_at!: Date;

}
