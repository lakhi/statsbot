import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';

import { ChatComponent } from './chat.component';
import { Student } from '../models/student';

function student(token_left: number, token_limit: number): Student {
  return { uid: 'u1', firstname: 'Ada', lastname: 'L', token_left, token_limit, registered: true, activated: true };
}

describe('ChatComponent', () => {
  let component: ChatComponent;
  let fixture: ComponentFixture<ChatComponent>;
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ChatComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideNoopAnimations()]
    })
    .compileComponents();

    fixture = TestBed.createComponent(ChatComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  function render(left: number, limit: number) {
    fixture.componentRef.setInput('student', student(left, limit));
    fixture.detectChanges();
  }

  function notice(): string | null {
    const el: HTMLElement | null = fixture.nativeElement.querySelector('.budget-notice');
    return el ? el.textContent!.replace(/\s+/g, ' ').trim() : null;
  }

  async function typeMessage(text: string) {
    component.newMessage.set(text);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function sendButton(): HTMLButtonElement {
    return fixture.nativeElement.querySelector('.send-container button');
  }

  it('should create', () => {
    fixture.detectChanges();
    expect(component).toBeTruthy();
    expect(notice()).toBeNull();
  });

  describe('budget display', () => {

    it('never shows the budget itself', () => {
      render(250000, 500000);
      const text: string = fixture.nativeElement.textContent;
      expect(text).not.toContain('250000');
      expect(text).not.toContain('500000');
      expect(text).not.toMatch(/credits|costs/i);
    });

    it('shows nothing below 90% used', () => {
      render(50001, 500000); // 89.9998% used
      expect(notice()).toBeNull();
    });

    it('flags exactly 90% used', () => {
      render(50000, 500000);
      expect(notice()).toContain('You have used 90% of your StatsBot budget.');
    });

    it('rounds down, so it never reads 100% while tokens are left', () => {
      render(2000, 500000); // 99.6% used
      expect(notice()).toContain('99%');
      expect(component.budgetExhausted()).toBeFalse();
    });

    it('says the budget is used up at 0 and links the contact', () => {
      render(0, 500000);
      expect(notice()).toContain('Your StatsBot budget is used up.');
      expect(notice()).toContain('Akshay Lakhi');
      const link: HTMLAnchorElement = fixture.nativeElement.querySelector('.budget-notice a');
      expect(link.getAttribute('href')).toBe('mailto:akshay.lakhi@univie.ac.at');
      expect(link.textContent).toBe('akshay.lakhi@univie.ac.at');
    });

    it('treats a limit of 0 as used up, not NaN%', () => {
      render(0, 0);
      expect(notice()).toContain('used up');
      expect(fixture.nativeElement.textContent).not.toContain('NaN');
    });

    it('hides the progress bar while idle', () => {
      render(500000, 500000);
      const bar: HTMLElement = fixture.nativeElement.querySelector('mat-progress-bar');
      expect(bar.style.visibility).toBe('hidden');
    });
  });

  describe('sending', () => {

    it('enables Send with budget left, even when the topic already cost more than is left', async () => {
      render(60000, 500000);
      await typeMessage('What is a p-value?');
      expect(sendButton().disabled).toBeFalse();
    });

    it('disables Send, and ignores Enter, once the budget is used up', async () => {
      render(0, 500000);
      await typeMessage('What is a p-value?');
      expect(sendButton().disabled).toBeTrue();

      component.sendMessage();
      httpMock.expectNone(r => r.url.endsWith('messages'));
    });

    it('raises the notice live when an answer crosses 90%', () => {
      render(60000, 500000);
      spyOn(component, 'scrollToEnd');
      expect(notice()).toBeNull();

      component.newMessage.set('What is a p-value?');
      component.sendMessage();
      httpMock.expectOne(r => r.url.endsWith('messages'))
        .flush({ content: 'An answer', sources: [], grounded: false, token_left: 40000, costs: 20000 });
      fixture.detectChanges();

      expect(notice()).toContain('92%');
      expect(component.progressMode()).toBeFalse();
    });

    it('on a failure, stops loading and hands the message back for a retry', () => {
      render(500000, 500000);
      component.newMessage.set('What is a p-value?');
      component.sendMessage();
      expect(component.progressMode()).toBeTrue();

      httpMock.expectOne(r => r.url.endsWith('messages'))
        .flush('boom', { status: 500, statusText: 'Server Error' });

      expect(component.progressMode()).toBeFalse();
      expect(component.messages().map(m => m.role)).toEqual(['assistant']);
      expect(component.newMessage()).toBe('What is a p-value?');
      httpMock.expectNone(r => r.url.endsWith('student'));
    });

    it('on a 403, re-reads the budget so the notice explains why', () => {
      render(30000, 500000); // used up in another tab meanwhile
      component.newMessage.set('What is a p-value?');
      component.sendMessage();

      httpMock.expectOne(r => r.url.endsWith('messages'))
        .flush({ message: 'no tokens left' }, { status: 403, statusText: 'Forbidden' });
      httpMock.expectOne(r => r.url.endsWith('student'))
        .flush(student(-1200, 500000));
      fixture.detectChanges();

      expect(component.progressMode()).toBeFalse();
      expect(notice()).toContain('used up');
    });

    it('sends on Enter without leaking the newline into the next message', async () => {
      render(500000, 500000);
      spyOn(component, 'scrollToEnd');
      await typeMessage('What is a p-value?');

      const enter = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
      fixture.nativeElement.querySelector('textarea').dispatchEvent(enter);

      expect(enter.defaultPrevented).toBeTrue();
      const req = httpMock.expectOne(r => r.url.endsWith('messages'));
      expect(req.request.body.messages.at(-1).content).toBe('What is a p-value?');
      req.flush({ content: 'An answer', sources: [], grounded: false, token_left: 490000, costs: 10000 });
    });

    it('leaves Shift+Enter alone, so students can still break a line', async () => {
      render(500000, 500000);
      await typeMessage('line one');

      const shiftEnter = new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, cancelable: true });
      fixture.nativeElement.querySelector('textarea').dispatchEvent(shiftEnter);

      expect(shiftEnter.defaultPrevented).toBeFalse();
      httpMock.expectNone(r => r.url.endsWith('messages'));
    });
  });

  describe('loading an old thread', () => {

    it('stops loading when the request fails', () => {
      render(500000, 500000);
      component.loadThread(1700000000000);
      expect(component.progressMode()).toBeTrue();

      httpMock.expectOne(r => r.url.endsWith('history/1700000000000'))
        .flush('boom', { status: 500, statusText: 'Server Error' });

      expect(component.progressMode()).toBeFalse();
      expect(component.messages().length).toBe(1); // the greeting, untouched
    });
  });
});
