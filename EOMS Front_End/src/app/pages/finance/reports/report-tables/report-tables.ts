import { NgTemplateOutlet } from '@angular/common';
import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../../../core/ui';
import { ReportView } from '../report-views';

/** Renders one report: its headline figures, any warnings, and its tables (one per currency), totals in bold. */
@Component({
  selector: 'app-report-tables',
  imports: [RouterLink, NgTemplateOutlet],
  templateUrl: './report-tables.html',
  styleUrl: './report-tables.scss',
})
export class ReportTables extends Ui {
  readonly view = input.required<ReportView>();
}
