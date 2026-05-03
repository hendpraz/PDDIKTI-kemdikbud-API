import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

type HeadersMap = Record<string, string>;

type MahasiswaSearchResult = {
  id?: string;
  [key: string]: unknown;
};

type DetailHandler = (
  detail: unknown,
  mahasiswa: MahasiswaSearchResult,
  query: string,
) => Promise<void> | void;

type ScrapeOptions = {
  queryFile?: string;
  outputFile?: string;
  delayMs?: number;
  limit?: number;
  headers?: HeadersMap;
  onDetail?: DetailHandler;
};

type ScrapeResult = {
  queryCount: number;
  detailCount: number;
  outputFile: string;
};

type CliArgs = {
  queryFile: string;
  outputFile: string;
  delayMs: number;
  limit?: number;
  help?: boolean;
};

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const DIKTI_URL = "aHR0cHM6Ly9hcGktcGRkaWt0aS5rZW1kaWt0aXNhaW50ZWsuZ28uaWQ=";
const HOST = "YXBpLXBkZGlrdGkua2VtZGlrdGlzYWludGVrLmdvLmlk";
const ORIGIN = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZA==";
const REFERER = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZC8=";
const FALLBACK_IP = "MTAzLjQ3LjEzMi4yOQ==";

const DEFAULT_QUERY_FILE = path.resolve(__dirname, "all_mahasiswa_itb.ts");
const DEFAULT_OUTPUT_FILE = path.resolve(__dirname, "mahasiswa_itb.txt");

function decodeBase64(value: string): string {
  return Buffer.from(value, "base64").toString("utf8");
}

export function endpoint(): string {
  return decodeBase64(DIKTI_URL);
}

function parseUrlSegment(value: string | number): string {
  return encodeURI(String(value));
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

export async function getIp(): Promise<string> {
  try {
    const response = await fetch("https://api.ipify.org?format=json");
    if (!response.ok) {
      throw new Error(`IP lookup failed with HTTP ${response.status}`);
    }

    const data = (await response.json()) as { ip?: string };
    return data.ip || decodeBase64(FALLBACK_IP);
  } catch (error) {
    console.warn(`Failed to load IP address, using fallback: ${errorMessage(error)}`);
    return decodeBase64(FALLBACK_IP);
  }
}

export async function buildHeaders(ip: string | Promise<string> = getIp()): Promise<HeadersMap> {
  const userIp = await ip;

  return {
    Accept: "application/json, text/plain, */*",
    "Accept-Encoding": "gzip, deflate, br, zstd",
    "Accept-Language": "en-US,en;q=0.9,mt;q=0.8",
    Connection: "keep-alive",
    DNT: "1",
    Host: decodeBase64(HOST),
    Origin: decodeBase64(ORIGIN),
    Referer: decodeBase64(REFERER),
    "Sec-Fetch-Dest": "empty",
    "Sec-Fetch-Mode": "cors",
    "Sec-Fetch-Site": "same-site",
    "User-Agent":
      "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0",
    "X-User-IP": userIp,
    "sec-ch-ua":
      '"Microsoft Edge";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
    "sec-ch-ua-mobile": "?0",
    "sec-ch-ua-platform": '"Windows"',
  };
}

export async function requestJson<T = unknown>(
  url: string,
  headers: HeadersMap,
): Promise<T | null> {
  const response = await fetch(url, { headers });
  if (!response.ok) {
    throw new Error(`Request failed with HTTP ${response.status}: ${url}`);
  }

  const body = await response.text();
  return body ? (JSON.parse(body) as T) : null;
}

export async function searchMahasiswa(
  searchQuery: string,
  headers: HeadersMap,
): Promise<MahasiswaSearchResult[] | null> {
  const searchUrl = `${endpoint()}/pencarian/mhs/${parseUrlSegment(searchQuery)}`;
  return requestJson<MahasiswaSearchResult[]>(searchUrl, headers);
}

export async function getMhsDetail(
  mahasiswaId: string,
  headers: HeadersMap,
): Promise<unknown> {
  const detailUrl = `${endpoint()}/detail/mhs/${parseUrlSegment(mahasiswaId)}`;
  return requestJson(detailUrl, headers);
}

export async function loadQueries(queryFile = DEFAULT_QUERY_FILE): Promise<string[]> {
  const resolvedQueryFile = path.resolve(queryFile);
  const moduleUrl = `${pathToFileURL(resolvedQueryFile).href}?updated=${Date.now()}`;
  const queryModule = (await import(moduleUrl)) as {
    default?: unknown;
    mahasiswa?: unknown;
    queries?: unknown;
  };
  const queries = queryModule.default ?? queryModule.mahasiswa ?? queryModule.queries;

  if (!Array.isArray(queries)) {
    throw new TypeError(`${resolvedQueryFile} must export an array of query strings`);
  }

  return queries.map((query) => String(query));
}

async function appendJsonLine(filePath: string, data: unknown): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.appendFile(filePath, `${JSON.stringify(data)}\n`, "utf8");
}

export async function scrapeMahasiswaDetails(
  options: ScrapeOptions = {},
): Promise<ScrapeResult> {
  const {
    queryFile = DEFAULT_QUERY_FILE,
    outputFile = DEFAULT_OUTPUT_FILE,
    delayMs = 20,
    limit,
    headers = await buildHeaders(),
    onDetail = (detail) => appendJsonLine(outputFile, detail),
  } = options;

  const queries = await loadQueries(queryFile);
  const selectedQueries = Number.isFinite(limit) ? queries.slice(0, limit) : queries;
  let queryCount = 0;
  let detailCount = 0;

  for (const query of selectedQueries) {
    queryCount += 1;

    try {
      const mahasiswaData = await searchMahasiswa(query, headers);
      if (Array.isArray(mahasiswaData)) {
        for (const mahasiswa of mahasiswaData) {
          if (!mahasiswa.id) {
            continue;
          }

          const detail = await getMhsDetail(mahasiswa.id, headers);
          detailCount += 1;
          await onDetail(detail, mahasiswa, query);
        }
      }
    } catch (error) {
      console.error(`Error while processing "${query}": ${errorMessage(error)}`);
    }

    if (delayMs > 0) {
      await sleep(delayMs);
    }
  }

  return { queryCount, detailCount, outputFile };
}

function parseArgs(argv: string[]): CliArgs {
  const args: CliArgs = {
    queryFile: DEFAULT_QUERY_FILE,
    outputFile: DEFAULT_OUTPUT_FILE,
    delayMs: 20,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    const next = argv[index + 1];

    if (arg === "--query-file" && next) {
      args.queryFile = path.resolve(next);
      index += 1;
    } else if (arg === "--output" && next) {
      args.outputFile = path.resolve(next);
      index += 1;
    } else if (arg === "--delay" && next) {
      args.delayMs = Number(next);
      index += 1;
    } else if (arg === "--limit" && next) {
      args.limit = Number(next);
      index += 1;
    } else if (arg === "--help") {
      args.help = true;
    }
  }

  return args;
}

function printHelp(): void {
  console.log(`Usage: node pddiktits/dikti_scraper.ts [options]

Options:
  --query-file <path>  TypeScript file exporting an array of query strings
  --output <path>      Output file for JSON lines
  --delay <ms>         Delay between search queries (default: 20)
  --limit <number>     Process only the first N queries
  --help               Show this help message`);
}

async function main(): Promise<void> {
  const args = parseArgs(process.argv.slice(2));
  if (args.help) {
    printHelp();
    return;
  }

  const result = await scrapeMahasiswaDetails(args);
  console.log(
    `Done. Processed ${result.queryCount} queries and wrote ${result.detailCount} details to ${result.outputFile}`,
  );
}

if (import.meta.url === pathToFileURL(process.argv[1] || "").href) {
  main().catch((error: unknown) => {
    console.error(error);
    process.exitCode = 1;
  });
}
