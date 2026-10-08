import type { DbExplorerConfig } from './env';

const raw: DbExplorerConfig = window.DbExplorer;

/** Mount path without a trailing slash ("" when mounted at the root). */
export const basePath: string = raw.basePath.replace(/\/+$/, '');
export const csrfToken: string = raw.csrfToken;
export const user = raw.user;
