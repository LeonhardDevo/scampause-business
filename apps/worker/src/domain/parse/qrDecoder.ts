import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';

export interface QrDecoder {
  decode(image: Buffer): Promise<string[]>;
}

export const noQrDecoder: QrDecoder = { decode: async () => [] };

export class ZxingQrDecoder implements QrDecoder {
  private ready: Promise<void> | null = null;

  private init(): Promise<void> {
    this.ready ??= (async () => {
      const require = createRequire(import.meta.url);
      const wasm = await readFile(require.resolve('zxing-wasm/reader/zxing_reader.wasm'));
      const wasmBinary = wasm.buffer.slice(wasm.byteOffset, wasm.byteOffset + wasm.byteLength);
      await prepareZXingModule({ overrides: { wasmBinary }, fireImmediately: true });
    })();
    return this.ready;
  }

  async decode(image: Buffer): Promise<string[]> {
    await this.init();
    const results = await readBarcodes(new Uint8Array(image), { formats: ['QRCode'], tryHarder: true, maxNumberOfSymbols: 4 });
    return results.filter((result) => result.isValid && result.text !== '').map((result) => result.text);
  }
}
