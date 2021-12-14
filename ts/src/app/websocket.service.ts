import { Injectable } from '@angular/core';
import {Stomp} from '@stomp/stompjs';
import * as SockJS from 'sockjs-client';
import {Subject} from 'rxjs';

@Injectable({
  providedIn: 'root'
})
export class WebsocketService {
  constructor() {
    this.initializeWebSocketConnection();
  }

  public stompClient;
  public msg = [];
  message$ = new Subject();

  initializeWebSocketConnection(): void {

    const serverUrl = 'http://52.29.90.252:2020/holter-data';
    const ws = new SockJS(serverUrl);
    this.stompClient = Stomp.over(() => ws);

    this.stompClient.connect({}, (frame) => {
      console.log('Connected: ' + frame);
      this.stompClient.subscribe('/topic/holter-1', (message) => {
        this.msg = JSON.parse(message.body);
        this.message$.next(this.msg);
       /* if (message.body) {
          this.msg.push(message.body);
          console.log(this.msg)
        }*/
      });
      this.stompClient.reconnect_delay = 5000;
    });

  }

}
