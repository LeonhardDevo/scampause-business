export const PARSE_LIMITS = Object.freeze({
  maxImagePixels: 40_000_000,
  maxPages: 50,
  maxPagePoints: 2000,
  pageTimeoutMs: 30_000,
  jobTimeoutMs: 180_000,
  maxFileBytes: 15 * 1024 * 1024,
  previewPages: 10,
  minPageTextChars: 20,
});

export class FileTooComplexError extends Error {
  readonly retryable = false;
  readonly code = 'file_too_complex';

  constructor(message: string) {
    super(message);
    this.name = 'FileTooComplexError';
  }
}

export interface ImageSize {
  width: number;
  height: number;
}

function pngSize(bytes: Buffer): ImageSize | null {
  if (bytes.length < 24 || bytes.readUInt32BE(0) !== 0x89504e47 || bytes.toString('latin1', 12, 16) !== 'IHDR') return null;
  return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) };
}

function jpegSize(bytes: Buffer): ImageSize | null {
  if (bytes.length < 4 || bytes[0] !== 0xff || bytes[1] !== 0xd8) return null;
  let offset = 2;
  while (offset + 9 < bytes.length) {
    if (bytes[offset] !== 0xff) {
      offset++;
      continue;
    }
    const marker = bytes[offset + 1] ?? 0;
    if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
      offset += 2;
      continue;
    }
    const length = bytes.readUInt16BE(offset + 2);
    if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
      return { height: bytes.readUInt16BE(offset + 5), width: bytes.readUInt16BE(offset + 7) };
    }
    offset += 2 + length;
  }
  return null;
}

function webpSize(bytes: Buffer): ImageSize | null {
  if (bytes.length < 30 || bytes.toString('latin1', 0, 4) !== 'RIFF' || bytes.toString('latin1', 8, 12) !== 'WEBP') return null;
  const chunk = bytes.toString('latin1', 12, 16);
  if (chunk === 'VP8X') return { width: 1 + bytes.readUIntLE(24, 3), height: 1 + bytes.readUIntLE(27, 3) };
  if (chunk === 'VP8L') {
    const bits = bytes.readUInt32LE(21);
    return { width: 1 + (bits & 0x3fff), height: 1 + ((bits >> 14) & 0x3fff) };
  }
  if (chunk === 'VP8 ') return { width: bytes.readUInt16LE(26) & 0x3fff, height: bytes.readUInt16LE(28) & 0x3fff };
  return null;
}

export function imageSize(bytes: Buffer): ImageSize | null {
  return pngSize(bytes) ?? jpegSize(bytes) ?? webpSize(bytes);
}

export function assertImageWithinLimits(bytes: Buffer, maxPixels = PARSE_LIMITS.maxImagePixels): ImageSize {
  const size = imageSize(bytes);
  if (!size || size.width <= 0 || size.height <= 0) throw new FileTooComplexError('unreadable image header');
  if (size.width * size.height > maxPixels) throw new FileTooComplexError(`image exceeds ${maxPixels} pixels`);
  return size;
}

export function sniffMime(bytes: Buffer, declared: string): string {
  if (bytes.subarray(0, 5).toString('latin1') === '%PDF-') return 'application/pdf';
  if (bytes.length >= 8 && bytes.readUInt32BE(0) === 0x89504e47) return 'image/png';
  if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return 'image/jpeg';
  if (bytes.length >= 12 && bytes.toString('latin1', 0, 4) === 'RIFF' && bytes.toString('latin1', 8, 12) === 'WEBP') return 'image/webp';
  return declared.toLowerCase();
}
