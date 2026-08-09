export const t = (text: string): string => window.wp?.i18n?.__(text, 'stockino') ?? text;
