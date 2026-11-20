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

  /**
   * Files sit behind the bearer token, so they are fetched as a blob rather than linked. The result is a `File`
   * named after the server's `Content-Disposition` when it sends one.
   */
  async blob(path: string, params: Query = {}): Promise<Blob> {
    try {
      const response = await firstValueFrom(this.http.get(apiBase + path, { params, observe: 'response', responseType: 'blob' }));
      return named(response.body ?? new Blob(), response.headers.get('Content-Disposition'));
    } catch (error) {
      throw await revealed(error);
    }
  }

  /** A POST that returns a file. Sham Cash also sends the document id in `X-Doc-Id`. */
  async filePost(path: string, body: unknown = {}): Promise<{ blob: Blob; docId: string | null }> {
    try {
      const response = await firstValueFrom(this.http.post(apiBase + path, body, { observe: 'response', responseType: 'blob' }));
      return { blob: named(response.body ?? new Blob(), response.headers.get('Content-Disposition')), docId: response.headers.get('X-Doc-Id') };
    } catch (error) {
      throw await revealed(error);
    }
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

/** A file download reports validation errors as a JSON blob. Turn that back into a normal HTTP error. */
export async function revealed(error: unknown): Promise<unknown> {
  if (!(error instanceof HttpErrorResponse) || !(error.error instanceof Blob)) return error;
  try {
    return new HttpErrorResponse({
      status: error.status,
      statusText: error.statusText,
      url: error.url ?? undefined,
      error: JSON.parse(await error.error.text()) as unknown,
    });
  } catch {
    return error;
  }
}

/** The file name in a `Content-Disposition` header: the UTF-8 `filename*` form first, then plain `filename`. */
export function dispositionName(header: string | null): string {
  if (!header) return '';
  const encoded = /filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/.exec(header);
  if (encoded) {
    try {
      return decodeURIComponent(encoded[1].trim().replace(/^"|"$/g, ''));
    } catch {
      // A malformed escape falls through to the plain form.
    }
  }
  const plain = /filename\s*=\s*("([^"]*)"|[^;]+)/.exec(header);
  return (plain?.[2] ?? plain?.[1] ?? '').trim();
}

function named(blob: Blob, header: string | null): Blob {
  const name = dispositionName(header);
  return name ? new File([blob], name, { type: blob.type }) : blob;
}

/** Saves a downloaded file under the server's name when it gave one, else under `name`. */
export function saveFile(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = blob instanceof File && blob.name ? blob.name : name;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** The server's own wording: the first validation message, else the error message. Empty when the server was unreachable. */
export function serverMessage(error: unknown): string {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return '';
  const body = error.error as { message?: unknown; errors?: Record<string, unknown> } | null;
  const run = body?.errors?.['run'];
  if (Array.isArray(run) && run.some((item) => typeof item === 'string')) {
    return run.filter((item): item is string => typeof item === 'string').join(' · ');
  }
  const first = body?.errors ? Object.values(body.errors).flat().find((item) => typeof item === 'string') : undefined;
  if (typeof first === 'string') return first;
  return typeof body?.message === 'string' ? body.message : '';
}
