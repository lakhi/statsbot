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
import { RichTextPipe } from './rich-text.pipe';
import { Student } from '../models/student';
import {MatSidenavModule} from '@angular/material/sidenav';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { TestArm } from '../test-arm';

//Share of the token budget used before the student is told about it at all.
//The budget is otherwise hidden during the trial: every turn resends the whole
//dialogue, and rag-arm turns add retrieved passages on top, so a visible
//counter would drain about twice as fast for one arm - a nudge on how much
//students ask, and a cue to which arm they are in.
const BUDGET_WARNING_PCT = 90;

@Component({
  selector: 'app-chat',
  standalone: true,
  imports: [MatToolbarModule, MatIconModule, MatInputModule, MatFormFieldModule, MatButtonModule, MatCardModule, MatProgressBarModule, FormsModule, RichTextPipe, MatSidenavModule, DatePipe],
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

  //share of the budget used, whole percent, rounded DOWN so the notice never
  //reads 100% while tokens are left. Integer arithmetic on purpose: (1 - x)*100
  //can land at 89.999... for exactly 90% used and floor to 89.
  budgetUsedPct: Signal<number> = computed(() => {
    const {token_left, token_limit} = this.student();
    return token_limit > 0 ? Math.floor((token_limit - token_left) * 100 / token_limit) : 0;
  });

  //same rule the backend refuses on (token_left <= 0)
  budgetExhausted: Signal<boolean> = computed(() => this.student().token_left <= 0);

  budgetWarning: Signal<boolean> = computed(() =>
    !this.budgetExhausted() && this.budgetUsedPct() >= BUDGET_WARNING_PCT
  );

  //timestamp start of chatsession to group messages to a chat-history
  started = -1;

  //flag to switch between history view and chat view
  isHistoryShown = false;

  startMessage = ""

  constructor(private dataService: DataService){

  }

  //which condition an internal test URL pinned, for the tester's tag; null for students
  get testArm(): TestArm | null {
    return this.dataService.testArm;
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

      this.dataService.loadThread(started).subscribe({

        next: data => {

          //restore chat state of old chat

          this.progressMode.set(false);

          this.messages.set([]);

          let tempMessages: Message[] = [];

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
              //replays what this answer showed live (salient rows only)
              messageReceived.sources = history.sources;
              tempMessages.push(messageReceived);

          });

          this.isHistoryShown = false;

          this.messages.set(tempMessages);

          this.newMessageField?.nativeElement.focus();


        },

        //nothing was replaced yet, so stopping the bar is all there is to undo;
        //DataService has already shown the "something went wrong" snackbar
        error: () => {
          this.progressMode.set(false);
        }

      });



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

    //set timestamp for new chat
    this.started = Date.now().valueOf();

    //set focus to message input field
    this.newMessageField?.nativeElement.focus();

  }


  //send message via backend to GPT model
  sendMessage(){

    //(keydown.enter) calls this directly, so the disabled Send button alone
    //does not stop a student with no budget left
    if(this.budgetExhausted()){
      return;
    }

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
      this.dataService.sendMessages(this.messages(), this.started).subscribe({

        next: data => {

          //handle answer message and add message to chat
          let answerMessage = new Message();
          answerMessage.content = data.content;
          answerMessage.role = "assistant";

          //absent, [] or chips - rendered as-is; see models/answer.ts
          answerMessage.sources = data.sources;

          this.messages.update(messages => ([
            ...messages,
            answerMessage
          ]));

          if(data.token_left<0){data.token_left = 0;}

          //update the remaining budget - drives the 90% notice, nothing else is shown
          this.student.update(student => ({
            ...student,
            token_left: data.token_left
          }))

          //scroll to end of chat
          this.scrollToEnd();

          this.progressMode.set(false);

        },

        error: (err: unknown) => {

          //The turn never happened, so take the unanswered message back out of
          //the chat and hand its text back for a retry - left in, it would be
          //resent next turn as a second user message the backend never stored.
          //Removed by identity, not position: "New topic" may have replaced the
          //list while the request was running. DataService has already shown
          //the "something went wrong" snackbar.
          this.messages.update(messages => messages.filter(m => m !== msg));

          if(this.newMessage().trim()===""){
            this.newMessage.set(msg.content);
          }

          this.progressMode.set(false);

          //403 = refused before the model was called. The usual cause is a
          //budget used up elsewhere (another tab), so re-read it from the
          //backend instead of trusting the local copy; the budget notice then
          //says why sending stopped.
          if(err instanceof HttpErrorResponse && err.status===403){
            this.dataService.getStudent().subscribe(fresh => {
              this.student.update(student => ({
                ...student,
                token_left: Math.max(fresh.token_left, 0),
                token_limit: fresh.token_limit
              }))
            });
          }

        }

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



