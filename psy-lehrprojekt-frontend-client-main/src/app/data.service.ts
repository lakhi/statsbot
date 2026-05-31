import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from '../environments/environment.development';
import { catchError, Observable, of, throwError } from 'rxjs';
import { Message } from './models/message';
import { Answer } from './models/answer';
import { Student } from './models/student';
import { History } from './models/history';
import { MatSnackBar } from '@angular/material/snack-bar';


@Injectable({
  providedIn: 'root',
})
export class DataService {

  url = environment.url;

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

    sendMessages(messages: Message[], started: number): Observable<any>{

      let messagesToSend = [...messages];
      messagesToSend.shift();

      return this.http.post<Answer>(this.url+"messages", {"messages": messagesToSend, "started": started}).pipe(
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

      return throwError(() => new Error('Something bad happened; please try again later.'));
    }


}
