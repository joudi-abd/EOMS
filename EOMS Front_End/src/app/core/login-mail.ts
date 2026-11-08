const domain = '@ufuq.com';
const localPattern = /^[A-Za-z0-9._-]+$/;

/** `@` and other symbols are not part of the username; the domain is shown beside the field. */
export function loginUserRejected(value: string): boolean {
  return value.includes('@') || (value.trim() !== '' && !localPattern.test(value.trim()));
}

/** A login address is the typed username completed with the company domain. */
export function loginMail(value: string): string {
  const local = value.trim();
  if (!localPattern.test(local)) return '';
  return `${local}${domain}`;
}
