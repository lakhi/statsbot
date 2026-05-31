import { Component, ElementRef, Signal, SimpleChanges, ViewChild, computed, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import {MatToolbarModule} from '@angular/material/toolbar';
import {MatIconModule} from '@angular/material/icon';
import { DataService } from './data.service';
import { ChatComponent } from "./chat/chat.component";
import { RegisterComponent } from './register/register.component';
import { Student } from './models/student';
import { MatCardModule } from '@angular/material/card';


@Component({
  selector: 'app-root',
  standalone: true,
  imports: [ChatComponent, RegisterComponent, MatToolbarModule, MatIconModule, MatCardModule],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css'
})
export class AppComponent {

  @ViewChild('scroll', { static: true }) scroll?: ElementRef;

  ready = signal(false);
  student = signal<Student>(new Student());
  tokenLeft: Signal<number> = computed(() => this.student().token_left/this.student().token_limit*100);



  constructor(private dataService: DataService){


    //get and set authenticated student and calculate tokens on startup
    this.dataService.getStudent().subscribe(
      data => {

        if(data.token_left<0){data.token_left = 0;}

        this.student.set(data);
        this.ready.set(true);


  })

  }

  registerComplete(student: Student){

    this.student.set(student);

  }


}








