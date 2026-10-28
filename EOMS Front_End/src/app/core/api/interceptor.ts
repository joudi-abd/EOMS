import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { Auth } from './auth';
import { apiBase } from './config';

/** Sends the bearer token to the backend and returns to the sign-in page once the server rejects it. */
export const apiInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith(apiBase)) {
    return next(request);
  }
  const auth = inject(Auth);
  const router = inject(Router);
  const token = auth.token();
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  return next(request.clone({ setHeaders: headers })).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401 && token && !request.url.endsWith('/auth/login')) {
        auth.clear();
        void router.navigate(['/login']);
      }
      return throwError(() => error);
    }),
  );
};
