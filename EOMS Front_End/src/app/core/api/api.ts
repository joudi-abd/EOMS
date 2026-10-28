import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { apiBase } from './config';
import { ApiPage } from './types';

type Query = Record<string, string | number>;

@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);

  get<T>(path: string, params: Query = {}): Promise<T> {
    return firstValueFrom(this.http.get<T>(apiBase + path, { params }));
  }

  post<T>(path: string, body: unknown = {}): Promise<T> {
    return firstValueFrom(this.http.post<T>(apiBase + path, body));
  }

  put<T>(path: string, body: unknown = {}): Promise<T> {
    return firstValueFrom(this.http.put<T>(apiBase + path, body));
  }

  patch<T>(path: string, body: unknown = {}): Promise<T> {
    return firstValueFrom(this.http.patch<T>(apiBase + path, body));
  }

  delete<T>(path: string): Promise<T> {
    return firstValueFrom(this.http.delete<T>(apiBase + path));
  }

  /** Walks every page of a paginated list. */
  async all<T>(path: string, params: Query = {}): Promise<T[]> {
    const rows: T[] = [];
    for (let page = 1; page <= 500; page++) {
      const result = await this.get<ApiPage<T>>(path, { ...params, page });
      rows.push(...result.data);
      if (result.meta.current_page >= result.meta.last_page) break;
    }
    return rows;
  }
}

export function statusOf(error: unknown): number {
  return error instanceof HttpErrorResponse ? error.status : -1;
}

/** The server's own wording: the first validation message, else the error message. Empty when the server was unreachable. */
export function serverMessage(error: unknown): string {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return '';
  const body = error.error as { message?: unknown; errors?: Record<string, unknown> } | null;
  const first = body?.errors ? Object.values(body.errors).flat().find((item) => typeof item === 'string') : undefined;
  if (typeof first === 'string') return first;
  return typeof body?.message === 'string' ? body.message : '';
}
