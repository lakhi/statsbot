import { Source } from './source';

//Response of POST /messages.
//
//The tutor answers in two separate layers so a student can always tell which
//half carries the course's authority: `from_materials` is written only from
//retrieved course passages, `from_general` is the model's own explanation.
//`from_materials` is null whenever nothing relevant was retrieved - the backend
//enforces that structurally, so it can never be a claim the model invented.
export class Answer{

    //flattened rendering of both layers; kept for history rows and older clients
    content!: string;

    from_materials!: string | null;
    from_general!: string;

    //passages actually cited in from_materials; empty when it is null
    sources!: Source[];

    grounded!: boolean;

    //shown in place of the materials layer when nothing was retrieved
    materials_note!: string | null;

    token_left!: number;
    costs!: number;

}
