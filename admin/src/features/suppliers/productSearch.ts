export const shouldSearchProducts = (value: string): boolean => {
  const query = value.trim();
  return /^\d+$/.test(query) ? query.length >= 1 : query.length >= 2;
};
