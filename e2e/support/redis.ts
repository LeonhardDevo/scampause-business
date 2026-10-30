import { connect, type Socket } from 'node:net';

type Reply = string | number | null | Reply[];

function parse(buffer: Buffer, offset: number): { value: Reply; next: number } | null {
  if (offset >= buffer.length) return null;
  const end = buffer.indexOf('\r\n', offset);
  if (end === -1) return null;
  const type = String.fromCharCode(buffer[offset]!);
  const line = buffer.toString('utf8', offset + 1, end);
  switch (type) {
    case '+':
      return { value: line, next: end + 2 };
    case '-':
      throw new Error(`redis error: ${line}`);
    case ':':
      return { value: Number(line), next: end + 2 };
    case '$': {
      const length = Number(line);
      if (length === -1) return { value: null, next: end + 2 };
      if (buffer.length < end + 2 + length + 2) return null;
      return { value: buffer.toString('utf8', end + 2, end + 2 + length), next: end + 2 + length + 2 };
    }
    case '*': {
      const count = Number(line);
      if (count === -1) return { value: null, next: end + 2 };
      const items: Reply[] = [];
      let cursor = end + 2;
      for (let index = 0; index < count; index++) {
        const item = parse(buffer, cursor);
        if (!item) return null;
        items.push(item.value);
        cursor = item.next;
      }
      return { value: items, next: cursor };
    }
    default:
      throw new Error(`unexpected redis reply type "${type}"`);
  }
}

function encode(args: readonly string[]): string {
  return `*${args.length}\r\n${args.map((arg) => `$${Buffer.byteLength(arg)}\r\n${arg}\r\n`).join('')}`;
}

class RedisConnection {
  private buffer = Buffer.alloc(0);
  private waiting: Array<{ resolve: (value: Reply) => void; reject: (error: Error) => void }> = [];

  private readonly socket: Socket;

  constructor(socket: Socket) {
    this.socket = socket;
    socket.on('data', (chunk: Buffer) => {
      this.buffer = Buffer.concat([this.buffer, chunk]);
      this.drain();
    });
    socket.on('error', (error) => {
      for (const pending of this.waiting.splice(0)) pending.reject(error);
    });
  }

  private drain(): void {
    while (this.waiting.length > 0) {
      let parsed: { value: Reply; next: number } | null;
      try {
        parsed = parse(this.buffer, 0);
      } catch (error) {
        this.waiting.shift()!.reject(error as Error);
        this.buffer = Buffer.alloc(0);
        continue;
      }
      if (!parsed) return;
      this.buffer = this.buffer.subarray(parsed.next);
      this.waiting.shift()!.resolve(parsed.value);
    }
  }

  command(...args: string[]): Promise<Reply> {
    return new Promise((resolve, reject) => {
      this.waiting.push({ resolve, reject });
      this.socket.write(encode(args));
    });
  }

  close(): void {
    this.socket.end();
  }
}

function open(port: number, host: string): Promise<RedisConnection> {
  return new Promise((resolve, reject) => {
    const socket = connect(port, host, () => resolve(new RedisConnection(socket)));
    socket.setTimeout(5_000, () => socket.destroy(new Error(`redis ${host}:${port} timed out`)));
    socket.once('error', reject);
  });
}

export async function deleteKeysByPrefix(port: number, prefixes: readonly string[], host = '127.0.0.1'): Promise<number> {
  const redis = await open(port, host);
  let deleted = 0;
  try {
    for (const prefix of prefixes) {
      let cursor = '0';
      do {
        const reply = (await redis.command('SCAN', cursor, 'MATCH', `${prefix}*`, 'COUNT', '1000')) as [string, string[]];
        cursor = reply[0];
        const keys = reply[1];
        if (keys.length > 0) deleted += Number(await redis.command('UNLINK', ...keys));
      } while (cursor !== '0');
    }
  } finally {
    redis.close();
  }
  return deleted;
}
