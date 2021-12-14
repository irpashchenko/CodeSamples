import { Component, ElementRef, OnInit, ViewChild } from '@angular/core';
import { ChartDataSets, ChartOptions, ChartTooltipOptions } from 'chart.js';

import { WebsocketService } from '../websocket.service';
import { BaseChartDirective, Color, Label } from 'ng2-charts';

@Component({
  selector: 'app-chart',
  templateUrl: './chart.component.html',
  styleUrls: ['./chart.component.scss']
})
export class ChartComponent implements OnInit {

  @ViewChild('chartWrapper') chartWrapper: ElementRef;
  @ViewChild('graph')
  graph: BaseChartDirective;

  @ViewChild('lineChart') private chartRef;
  @ViewChild(BaseChartDirective) chartDir: BaseChartDirective;
  chunk = 5;
  width = 800;
  chunks = [];
  lineChartData: ChartDataSets[] = [{
    pointRadius: 0.5,
    pointBorderWidth: 0.5,
    cubicInterpolationMode: 'default',
    fill: false,
    data: [], label: ''
  }];

  lineChartLabels: Label[] = [];
  lineChartLegend = false;
  lineChartType = 'line';
  lineChartPlugins = [];

  chartTooltipOptions: ChartTooltipOptions = {};

// Define colors of chart segments
  lineChartColors: Color[] = [
    { // red
      backgroundColor: 'rgba(255,0,0,0.3)',
      borderColor: 'red',
    }
  ];

  lineChartOptions: ChartOptions = {
    responsive: true,
    scales: {
      yAxes: [{
        ticks: {
          stepSize: 5,
          suggestedMin: 0,
          suggestedMax: 4095
        }
      }]
    },
    tooltips: {
      axis: 'x'
    },

    animation: {
      onComplete: function () {
        this.chart.update();
        if (!this.rectangleSet) {
          const scale = window.devicePixelRatio;

          const sourceCanvas = this.chart.canvas;
          const copyWidth = this.chart.chart.scales['y-axis-0'].width - 10;
          const copyHeight =
            this.chart.chart.scales['y-axis-0'].height + this.scales['y-axis-0'].top + 10;
          const target = document.getElementById('myChartAxis') as HTMLCanvasElement;

          const targetCtx = target.getContext('2d');

          targetCtx.scale(scale, scale);
          targetCtx.canvas.width = copyWidth * scale;
          targetCtx.canvas.height = copyHeight * scale;

          targetCtx.canvas.style.width = `${copyWidth}px`;
          targetCtx.canvas.style.height = `${copyHeight}px`;
          targetCtx.drawImage(
            sourceCanvas,
            0,
            0,
            copyWidth * scale,
            copyHeight * scale,
            0,
            0,
            copyWidth * scale,
            copyHeight * scale
          );

          const sourceCtx = sourceCanvas.getContext('2d');

          // Normalize coordinate system to use css pixels.

          sourceCtx.clearRect(0, 0, copyWidth * scale, copyHeight * scale);
          this.rectangleSet = true;
        }
      },
      onProgress: function () {
        if (this.rectangleSet === true) {
          const copyWidth = this.chart.chart.scales['y-axis-0'].width;
          const copyHeight =
            this.chart.chart.scales['y-axis-0'].height +
            this.chart.chart.scales['y-axis-0'].top +
            10;

          const sourceCtx = this.chart.canvas.getContext('2d');
          sourceCtx.clearRect(0, 0, copyWidth, copyHeight);
          this.chart.update();
        }
      },
    },
    maintainAspectRatio: false,

  };

  constructor(private wsService: WebsocketService) {
  }

  ngOnInit(): void {
    this.wsService.message$.subscribe((data: number[]) => {
      this.chunks.push(data);
      console.log(data);
      this.prepareChartData();
    });
  }

  prepareChartData(): void {
    if (this.chunks.length > +this.chunk) {
      //this.chunks.shift();
      this.chunks = this.chunks.slice(this.chunks.length - this.chunk);
    }
    let arr = [];
    for (const chunk of this.chunks) {
      arr = [...arr, ...chunk];
    }
    console.log('chunks', this.chunks);
    this.refreshData(arr);
  }

  onChunkSelect(): void {
    this.prepareChartData();
  }

  refreshData(newData): void {
    this.lineChartData[0].data = newData;
    this.lineChartLabels = Array(this.lineChartData[0].data.length).fill('-');
    this.width = newData.length > this.width ? newData.length / 2 : this.width;
    if (this.graph !== undefined) {
      this.graph.ngOnDestroy();
      this.graph.chart = this.graph.getChartBuilder(this.graph.ctx);
    }
  }

  clearChart(): void {
    this.lineChartData[0].data = [];
    this.lineChartLabels = Array(this.lineChartData[0].data.length).fill('-');
    this.width = 800;
    if (this.graph !== undefined) {
      this.graph.ngOnDestroy();
      this.graph.chart = this.graph.getChartBuilder(this.graph.ctx);
      this.chunks = [];
    }
  }
}
