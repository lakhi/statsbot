import { Source } from './source';

//Response of POST /messages.
//
//The tutor writes ONE coherent answer, and the text is the same in every arm.
//The arms differ only in `sources`, and the frontend never needs to know the
//arm - it renders whatever shape arrives:
//
//  absent  -> nothing (no-RAG and non-salient RAG, which look identical)
//  []      -> the salient arm's "No matching course material found" note
//  [...]   -> the salient arm's citation chips (at most 3)
export class Answer{

    content!: string;

    sources?: Source[];

    token_left!: number;

}
