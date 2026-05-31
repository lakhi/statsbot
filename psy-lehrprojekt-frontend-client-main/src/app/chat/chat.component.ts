import { Component, computed, ElementRef, model, OnInit, signal, Signal, ViewChild } from '@angular/core';
import { DataService } from '../data.service';
import { Message } from '../models/message';
import { History } from '../models/history';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatToolbarModule } from '@angular/material/toolbar';
import { HighlightJsDirective } from 'ngx-highlight-js';
import { Student } from '../models/student';
import {MatSidenavModule} from '@angular/material/sidenav';
import { DatePipe } from '@angular/common';
import { MatSnackBar } from '@angular/material/snack-bar';

@Component({
  selector: 'app-chat',
  standalone: true,
  imports: [MatToolbarModule, MatIconModule, MatInputModule, MatFormFieldModule, MatButtonModule, MatCardModule, MatProgressBarModule, FormsModule, HighlightJsDirective, MatSidenavModule, DatePipe],
  templateUrl: './chat.component.html',
  styleUrl: './chat.component.css'
})
export class ChatComponent implements OnInit {

  //reference to dom element for automatic scrolling
  @ViewChild('scroll', { static: true }) scroll?: ElementRef;

  //reference to message input field to set focus
  @ViewChild('newMsg', { static: true }) newMessageField?: ElementRef;

  student = model<Student>(new Student());

  //flag for state of progress bar
  progressMode = signal<boolean>(false);

  //list of chat messages
  messages = signal<Message[]>([]);

  //list of old chats
  history = signal<History[]>([]);

  newMessage = signal("");

  tokenLeft: Signal<number> = computed(() => this.student().token_left/this.student().token_limit*100);

  //costs of current chat
  costs = signal<number>(0);

  //timestamp start of chatsession to group messages to a chat-history
  started = -1;

  //flag to switch between history view and chat view
  isHistoryShown = false;

  startMessage = ""

  constructor(private dataService: DataService, private snackBar: MatSnackBar){

  }

  ngOnInit(): void {

    //set focus on message input field
    this.newMessageField?.nativeElement.focus();

    //create timestamp to group messages
    this.started = Date.now().valueOf();

    //create static tutor message
    let msg = new Message();
    msg.role="assistant";

     msg.content = "Hello " + this.student().firstname + ", I am your Statistics tutor. What question do you have?";

      this.messages.update( messages => (
        [
          ...messages,
          msg
        ]
      ));
  }

  //show overview of old chats
  showHistory(){

    if(this.isHistoryShown){
      this.isHistoryShown = false;
    }
    else{
      this.dataService.getHistory().subscribe(
        data => {this.history.set(data);
        this.isHistoryShown = true;
        }
      )
    }

  }

  //load old chat
  loadThread(started: number){

    this.progressMode.set(true);

      this.dataService.loadThread(started).subscribe(

        data => {

          //restore chat state of old chat

          this.progressMode.set(false);

          this.messages.set([]);

          let tempMessages: Message[] = [];
          let tempCosts = 0;

          //set static start message of tutor
          let msg = new Message();
          msg.role="assistant";
          msg.content = "Hello " + this.student().firstname + ", I am your Statistics tutor. What question do you have?";

          tempMessages.push(msg);

          this.started = data[0].started;

          //restore old messages of chat
          data.forEach(history => {

              let messageSent = new Message();
              messageSent.content = history.sent;
              messageSent.role = "user";
              tempMessages.push(messageSent);

              let messageReceived = new Message();
              messageReceived.content = history.received;
              messageReceived.role = "assistant";
              tempMessages.push(messageReceived);

              //resore costs of old chat
              tempCosts = tempCosts + history.total_tokens;

          });

          this.isHistoryShown = false;

          this.messages.set(tempMessages);
          this.costs.set(tempCosts);

          this.newMessageField?.nativeElement.focus();


        }

      );



  }

  //start new chat topic
  newTopic(){

    this.progressMode.set(false);
    this.isHistoryShown=false;

    this.messages.set([]);

    //create static first tutor message
    let msg = new Message();
    msg.role="assistant";
    msg.content = "Ok, " + this.student().firstname + ", let us change the topic. \nHow can I help you?";

          this.messages.update( messages => (
            [
              ...messages,
              msg
            ]
    ));

    //reset costs
    this.costs.set(0);

    //set timestamp for new chat
    this.started = Date.now().valueOf();

    //set focus to message input field
    this.newMessageField?.nativeElement.focus();

  }


  //send message via backend to GPT model
  sendMessage(){

    //check if new message is not empty
    if(this.newMessage().trim()!=""){

      this.progressMode.set(true);

      //add new message to chat
      let msg = new Message();
      msg.role = 'user';
      msg.content = this.newMessage();

      this.messages.update(messages => ([
        ...messages,
        msg
      ]))

      this.newMessage.set("");

      //send new message to backend
      this.dataService.sendMessages(this.messages(), this.started).subscribe(

        data => {

          //handle answer message and add message to chat
          let answerMessage = new Message();
          answerMessage.content = data.content;
          answerMessage.role = "assistant";

            this.messages.update(messages => ([
              ...messages,
              answerMessage
            ]));


          if(data.token_left<0){data.token_left = 0;}


            //update costs and limits
            this.costs.update(costs => costs + data.costs);

              this.student.update(student => ({
                ...student,
                token_left: data.token_left
              }))

              //scroll to end of chat
              this.scrollToEnd();

              //show cost warning for long chats
              if(this.messages().length>4){

              this.snackBar.open("Hint: Longer dialogues cost more. If you don't need the previous prompt, start a new topic!", "", {
                duration: 7000, panelClass: "customSnackBar"
              });
            }

            this.progressMode.set(false);


      });
    }
  }

    scrollToEnd(){


      //scroll to end with short delay
      setTimeout(() => {

        this.scroll!.nativeElement.lastElementChild.scrollIntoView();

      }, 10)



      }


  }



