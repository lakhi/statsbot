import { Source } from './source';

//Response of POST /messages.
//
//The tutor writes ONE coherent answer. `sources` are the passages it declared
//it drew on - kept as a separate field so grounding stays measurable even
//though the prose is unified. `sources` is empty in the no-rag arm, and also in
//the rag arm whenever nothing cleared the similarity floor.
export class Answer{

    content!: string;

    //passages the answer cited; empty when it cited none
    sources!: Source[];

    grounded!: boolean;

    token_left!: number;
    costs!: number;

}
