import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';

import { DataService } from './data.service';
import { Message } from './models/message';

describe('DataService', () => {
  let service: DataService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideNoopAnimations()]
    });
    service = TestBed.inject(DataService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  function dialogue(): Message[] {
    const greeting = Object.assign(new Message(), { role: 'assistant', content: 'Hello' });
    const question = Object.assign(new Message(), { role: 'user', content: 'What is a p-value?' });
    return [greeting, question];
  }

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('pins no arm outside a test URL', () => {
    // Karma serves from /context.html, like a student on the live site
    expect(service.testArm).toBeNull();

    service.sendMessages(dialogue(), 1).subscribe();
    const req = httpMock.expectOne(r => r.url.endsWith('messages'));
    expect('test_arm' in req.request.body).toBeFalse();
    req.flush({ content: 'a', token_left: 1 });
  });

  it('sends the pinned arm with every message on a test URL', () => {
    service.testArm = { arm: 'rag_salient', label: 'Salient RAG' };

    service.sendMessages(dialogue(), 1).subscribe();
    const req = httpMock.expectOne(r => r.url.endsWith('messages'));
    expect(req.request.body.test_arm).toBe('rag_salient');
    // the greeting is UI copy and never leaves the browser
    expect(req.request.body.messages).toEqual([{ role: 'user', content: 'What is a p-value?' }]);
    req.flush({ content: 'a', token_left: 1, sources: [] });
  });
});
