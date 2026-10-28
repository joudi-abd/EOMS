/** Base URL of the EOMS backend. A deployment may define `window.EOMS_API` before the app boots to point elsewhere. */
export const apiBase: string = (globalThis as { EOMS_API?: string }).EOMS_API ?? 'http://127.0.0.1:8000/api/v1';
