export default function removeAccents(input: string): string {
  return input.normalize('NFD').replace(/\p{Diacritic}/gu, '');
}
