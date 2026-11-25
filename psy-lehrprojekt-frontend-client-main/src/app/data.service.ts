import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../environments/environment';
import { catchError, Observable, of, throwError } from 'rxjs';
import { Message } from './models/message';
import { Answer } from './models/answer';
import { Student } from './models/student';
import { History } from './models/history';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TestArm, testArmFromPath } from './test-arm';


@Injectable({
  providedIn: 'root',
})
export class DataService {

  url = environment.url;

  //arm pinned by an internal test URL; null everywhere else, always on the live build
  testArm: TestArm | null = testArmFromPath(window.location.pathname);

  constructor(private http: HttpClient, private snackBar: MatSnackBar) {



   }

    getStudent(): Observable<Student>{

      return this.http.get<Student>(this.url+"student").pipe(
        catchError(this.handleError)
      );

    }

    registerStudent(student: Partial<Student>){

      return this.http.post<Student>(this.url+"register", {"register": student}).pipe(
        catchError(this.handleError)
      );

    }

    sendMessages(messages: Message[], started: number): Observable<Answer>{

      //drop the locally-generated tutor greeting: it is UI copy, never part of
      //the dialogue the backend stores or sends on to the model
      let messagesToSend = [...messages];
      messagesToSend.shift();

      //only role + content go to the backend; `sources` on assistant turns is
      //display state and would fail the route's validation
      const payload = messagesToSend.map(m => ({role: m.role, content: m.content}));

      const body: {messages: typeof payload, started: number, test_arm?: string} = {"messages": payload, "started": started};
      if(this.testArm){
        body.test_arm = this.testArm.arm;
      }

      return this.http.post<Answer>(this.url+"messages", body).pipe(
        catchError(this.handleError)
      );

    }

    getHistory(): Observable<History[]>{

      return this.http.get<History[]>(this.url+"history").pipe(
        catchError(this.handleError)
      );;

    }


    loadThread(started: number): Observable<History[]>{

      return this.http.get<History[]>(this.url+"history/"+started).pipe(
        catchError(this.handleError)
      );;

    }



    private handleError = (error: HttpErrorResponse) =>  {

      this.snackBar.open('Something went wrong. Please reload StatsBot in browser and try again!', '', {
        duration: 5000
      });

      //rethrow the original response, not a generic Error: callers need the
      //status (ChatComponent re-reads the budget on a 403)
      return throwError(() => error);
    }


}
