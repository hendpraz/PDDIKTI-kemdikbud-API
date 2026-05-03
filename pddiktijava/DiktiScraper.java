import java.io.IOException;
import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.util.ArrayList;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class DiktiScraper {
    private static final String DIKTI_URL = "aHR0cHM6Ly9hcGktcGRkaWt0aS5rZW1kaWt0aXNhaW50ZWsuZ28uaWQ=";
    private static final String HOST = "YXBpLXBkZGlrdGkua2VtZGlrdGlzYWludGVrLmdvLmlk";
    private static final String ORIGIN = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZA==";
    private static final String REFERER = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZC8=";
    private static final String FALLBACK_IP = "MTAzLjQ3LjEzMi4yOQ==";

    private static final Path DEFAULT_OUTPUT_FILE = Path.of("pddiktijava", "mahasiswa_itb.txt");
    private static final Pattern ID_PATTERN = Pattern.compile("\"id\"\\s*:\\s*\"((?:\\\\.|[^\"])*)\"");

    private static final HttpClient HTTP_CLIENT = HttpClient.newBuilder()
        .followRedirects(HttpClient.Redirect.NORMAL)
        .connectTimeout(Duration.ofSeconds(30))
        .build();

    private DiktiScraper() {
    }

    public record ScrapeOptions(
        List<String> queries,
        Path outputFile,
        int delayMs,
        Integer limit,
        Map<String, String> headers
    ) {
        public ScrapeOptions {
            queries = queries == null ? AllMahasiswaItb.MAHASISWA : queries;
            outputFile = outputFile == null ? DEFAULT_OUTPUT_FILE : outputFile;
            delayMs = delayMs < 0 ? 0 : delayMs;
            headers = headers == null ? buildHeaders() : headers;
        }
    }

    public record ScrapeResult(int queryCount, int detailCount, Path outputFile) {
    }

    public static String endpoint() {
        return decodeBase64(DIKTI_URL);
    }

    public static String getIp() {
        try {
            String body = requestText("https://api.ipify.org?format=json", Map.of());
            Matcher matcher = Pattern.compile("\"ip\"\\s*:\\s*\"([^\"]+)\"").matcher(body);
            return matcher.find() ? matcher.group(1) : decodeBase64(FALLBACK_IP);
        } catch (Exception error) {
            System.err.println("Failed to load IP address, using fallback: " + errorMessage(error));
            return decodeBase64(FALLBACK_IP);
        }
    }

    public static Map<String, String> buildHeaders() {
        return buildHeaders(getIp());
    }

    public static Map<String, String> buildHeaders(String ip) {
        Map<String, String> headers = new LinkedHashMap<>();
        headers.put("Accept", "application/json, text/plain, */*");
        headers.put("Accept-Encoding", "gzip, deflate, br, zstd");
        headers.put("Accept-Language", "en-US,en;q=0.9,mt;q=0.8");
        headers.put("Connection", "keep-alive");
        headers.put("DNT", "1");
        headers.put("Host", decodeBase64(HOST));
        headers.put("Origin", decodeBase64(ORIGIN));
        headers.put("Referer", decodeBase64(REFERER));
        headers.put("Sec-Fetch-Dest", "empty");
        headers.put("Sec-Fetch-Mode", "cors");
        headers.put("Sec-Fetch-Site", "same-site");
        headers.put(
            "User-Agent",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0"
        );
        headers.put("X-User-IP", ip);
        headers.put("sec-ch-ua", "\"Microsoft Edge\";v=\"131\", \"Chromium\";v=\"131\", \"Not_A Brand\";v=\"24\"");
        headers.put("sec-ch-ua-mobile", "?0");
        headers.put("sec-ch-ua-platform", "\"Windows\"");
        return headers;
    }

    public static String searchMahasiswa(String searchQuery, Map<String, String> headers)
        throws IOException, InterruptedException {
        String searchUrl = endpoint() + "/pencarian/mhs/" + parseUrlSegment(searchQuery);
        return requestText(searchUrl, headers);
    }

    public static String getMhsDetail(String mahasiswaId, Map<String, String> headers)
        throws IOException, InterruptedException {
        String detailUrl = endpoint() + "/detail/mhs/" + parseUrlSegment(mahasiswaId);
        return requestText(detailUrl, headers);
    }

    public static ScrapeResult scrapeMahasiswaDetails(ScrapeOptions options)
        throws IOException, InterruptedException {
        ScrapeOptions resolvedOptions = options == null
            ? new ScrapeOptions(null, null, 20, null, null)
            : options;
        List<String> selectedQueries = resolvedOptions.limit() == null
            ? resolvedOptions.queries()
            : resolvedOptions.queries().subList(0, Math.min(resolvedOptions.limit(), resolvedOptions.queries().size()));
        int queryCount = 0;
        int detailCount = 0;

        for (String query : selectedQueries) {
            queryCount += 1;

            try {
                String mahasiswaData = searchMahasiswa(query, resolvedOptions.headers());
                for (String mahasiswaId : extractIds(mahasiswaData)) {
                    String detail = getMhsDetail(mahasiswaId, resolvedOptions.headers());
                    detailCount += 1;
                    appendJsonLine(resolvedOptions.outputFile(), detail);
                }
            } catch (Exception error) {
                System.err.println("Error while processing \"" + query + "\": " + error.getMessage());
            }

            if (resolvedOptions.delayMs() > 0) {
                Thread.sleep(resolvedOptions.delayMs());
            }
        }

        return new ScrapeResult(queryCount, detailCount, resolvedOptions.outputFile());
    }

    public static List<String> loadQueries() {
        return AllMahasiswaItb.MAHASISWA;
    }

    private static String requestText(String url, Map<String, String> headers)
        throws IOException, InterruptedException {
        HttpRequest.Builder builder = HttpRequest.newBuilder()
            .uri(URI.create(url))
            .timeout(Duration.ofSeconds(30))
            .GET();

        headers.forEach((name, value) -> {
            String normalizedName = name.toLowerCase();
            if (
                !"accept-encoding".equals(normalizedName)
                    && !"connection".equals(normalizedName)
                    && !"host".equals(normalizedName)
            ) {
                builder.header(name, value);
            }
        });

        HttpResponse<String> response = HTTP_CLIENT.send(
            builder.build(),
            HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8)
        );

        if (response.statusCode() < 200 || response.statusCode() >= 300) {
            throw new IOException("Request failed with HTTP " + response.statusCode() + ": " + url);
        }

        return response.body();
    }

    private static void appendJsonLine(Path filePath, String data) throws IOException {
        Path parent = filePath.getParent();
        if (parent != null) {
            Files.createDirectories(parent);
        }

        Files.writeString(
            filePath,
            data + System.lineSeparator(),
            StandardCharsets.UTF_8,
            Files.exists(filePath)
                ? java.nio.file.StandardOpenOption.APPEND
                : java.nio.file.StandardOpenOption.CREATE
        );
    }

    private static List<String> extractIds(String json) {
        List<String> ids = new ArrayList<>();
        Matcher matcher = ID_PATTERN.matcher(json);

        while (matcher.find()) {
            ids.add(unescapeJsonString(matcher.group(1)));
        }

        return ids;
    }

    private static String parseUrlSegment(String value) {
        return URLEncoder.encode(value, StandardCharsets.UTF_8)
            .replace("+", "%20")
            .replace("%2F", "/");
    }

    private static String decodeBase64(String value) {
        return new String(Base64.getDecoder().decode(value), StandardCharsets.UTF_8);
    }

    private static String unescapeJsonString(String value) {
        return value
            .replace("\\\"", "\"")
            .replace("\\\\", "\\")
            .replace("\\/", "/")
            .replace("\\b", "\b")
            .replace("\\f", "\f")
            .replace("\\n", "\n")
            .replace("\\r", "\r")
            .replace("\\t", "\t");
    }

    private static String errorMessage(Exception error) {
        return error.getMessage() == null
            ? error.getClass().getSimpleName()
            : error.getMessage();
    }

    private static CliArgs parseArgs(String[] argv) {
        CliArgs args = new CliArgs(DEFAULT_OUTPUT_FILE, 20, null, false);

        for (int index = 0; index < argv.length; index++) {
            String arg = argv[index];
            Optional<String> next = index + 1 < argv.length
                ? Optional.of(argv[index + 1])
                : Optional.empty();

            if ("--output".equals(arg) && next.isPresent()) {
                args.outputFile = Path.of(next.get());
                index++;
            } else if ("--delay".equals(arg) && next.isPresent()) {
                args.delayMs = Integer.parseInt(next.get());
                index++;
            } else if ("--limit".equals(arg) && next.isPresent()) {
                args.limit = Integer.parseInt(next.get());
                index++;
            } else if ("--help".equals(arg)) {
                args.help = true;
            }
        }

        return args;
    }

    private static void printHelp() {
        System.out.println("""
            Usage: java -cp pddiktijava DiktiScraper [options]

            Options:
              --output <path>      Output file for JSON lines
              --delay <ms>         Delay between search queries (default: 20)
              --limit <number>     Process only the first N queries
              --help               Show this help message
            """);
    }

    public static void main(String[] argv) throws IOException, InterruptedException {
        CliArgs args = parseArgs(argv);
        if (args.help) {
            printHelp();
            return;
        }

        ScrapeResult result = scrapeMahasiswaDetails(new ScrapeOptions(
            loadQueries(),
            args.outputFile,
            args.delayMs,
            args.limit,
            null
        ));

        System.out.println(
            "Done. Processed " + result.queryCount()
                + " queries and wrote " + result.detailCount()
                + " details to " + result.outputFile()
        );
    }

    private static final class CliArgs {
        private Path outputFile;
        private int delayMs;
        private Integer limit;
        private boolean help;

        private CliArgs(Path outputFile, int delayMs, Integer limit, boolean help) {
            this.outputFile = outputFile;
            this.delayMs = delayMs;
            this.limit = limit;
            this.help = help;
        }
    }
}
