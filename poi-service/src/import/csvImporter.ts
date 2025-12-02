import fs from 'node:fs';
import { parse } from 'csv-parse';
import { prisma } from '../prisma';
import { mapCsvTypeToCategory } from '../categories';
import { NormalizedPoi } from '../providers/types';
import { isDuplicatePoi, mergePois, normalizedToPoiData, passesRatingFilter } from '../poiUtils';

interface CsvRow {
  Country: string;
  City: string;
  Name: string;
  Address: string;
  Latitude: string;
  Longitude: string;
  Rating?: string;
  Type?: string;
  PlaceSource?: string;
  Website?: string;
  Phone?: string;
  PhotoURL?: string;
  PhotoSuggestedFilename?: string;
  PhotoLicense?: string;
}

function mapRowToPoi(row: CsvRow): NormalizedPoi | null {
  const category = mapCsvTypeToCategory(row.Type);
  if (!category) return null;
  const rating = row.Rating ? Number(row.Rating) : undefined;
  return {
    name: row.Name,
    lat: Number(row.Latitude),
    lon: Number(row.Longitude),
    address: row.Address,
    city: row.City,
    country: row.Country,
    category,
    rating: isNaN(rating as number) ? undefined : rating,
    ratingSource: row.PlaceSource?.toLowerCase() ?? 'manual_import',
    website: row.Website,
    phone: row.Phone,
    photoUrl: row.PhotoURL,
    photoFilename: row.PhotoSuggestedFilename,
    photoLicense: row.PhotoLicense,
    source: row.PlaceSource?.toLowerCase() ?? 'manual_import',
    raw: row,
  };
}

export async function importCsv(filePath: string) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const records: CsvRow[] = await new Promise((resolve, reject) => {
    parse(content, { columns: true, skip_empty_lines: true }, (err, output) => {
      if (err) reject(err);
      else resolve(output as CsvRow[]);
    });
  });

  const normalized = records
    .map(mapRowToPoi)
    .filter((poi): poi is NormalizedPoi => !!poi)
    .filter(passesRatingFilter);

  const existing = await prisma.poi.findMany();
  for (const poi of normalized) {
    const duplicate = existing.find((item) => isDuplicatePoi(item, poi));
    if (duplicate) {
      const merged = mergePois(duplicate, poi);
      const updated = await prisma.poi.update({ where: { id: duplicate.id }, data: merged });
      replace(existing, updated);
    } else {
      const created = await prisma.poi.create({ data: normalizedToPoiData(poi) });
      existing.push(created);
    }
  }
}

function replace(list: any[], updated: any) {
  const idx = list.findIndex((item) => item.id === updated.id);
  if (idx >= 0) list[idx] = updated;
}

// CLI usage
if (process.argv[1] === new URL(import.meta.url).pathname) {
  const file = process.argv[2];
  if (!file) {
    console.error('Usage: ts-node src/import/csvImporter.ts <file.csv>');
    process.exit(1);
  }
  importCsv(file)
    .then(() => {
      console.log('CSV import completed');
      process.exit(0);
    })
    .catch((error) => {
      console.error('Failed to import CSV', error);
      process.exit(1);
    });
}
