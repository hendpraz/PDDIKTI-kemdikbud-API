package main

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const (
	diktiURL   = "aHR0cHM6Ly9hcGktcGRkaWt0aS5rZW1kaWt0aXNhaW50ZWsuZ28uaWQ="
	host       = "YXBpLXBkZGlrdGkua2VtZGlrdGlzYWludGVrLmdvLmlk"
	origin     = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZA=="
	referer    = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZC8="
	fallbackIP = "MTAzLjQ3LjEzMi4yOQ=="
)

var defaultOutputFile = filepath.Join("pddiktigo", "mahasiswa_itb.txt")

type mahasiswaSearchResult struct {
	ID string `json:"id"`
}

type scrapeOptions struct {
	queries    []string
	outputFile string
	delayMs    int
	limit      int
	headers    map[string]string
}

type scrapeResult struct {
	queryCount  int
	detailCount int
	outputFile  string
}

var httpClient = &http.Client{Timeout: 30 * time.Second}

func endpoint() string {
	return decodeBase64(diktiURL)
}

func decodeBase64(value string) string {
	decoded, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return ""
	}

	return string(decoded)
}

func parseURLSegment(value string) string {
	return strings.ReplaceAll(url.PathEscape(value), "%2F", "/")
}

func getIP() string {
	body, err := requestText("https://api.ipify.org?format=json", map[string]string{})
	if err != nil {
		fmt.Fprintf(os.Stderr, "Failed to load IP address, using fallback: %v\n", err)
		return decodeBase64(fallbackIP)
	}

	var data struct {
		IP string `json:"ip"`
	}
	if err := json.Unmarshal([]byte(body), &data); err != nil || data.IP == "" {
		return decodeBase64(fallbackIP)
	}

	return data.IP
}

func buildHeaders() map[string]string {
	return buildHeadersWithIP(getIP())
}

func buildHeadersWithIP(ip string) map[string]string {
	return map[string]string{
		"Accept":             "application/json, text/plain, */*",
		"Accept-Encoding":    "gzip, deflate, br, zstd",
		"Accept-Language":    "en-US,en;q=0.9,mt;q=0.8",
		"Connection":         "keep-alive",
		"DNT":                "1",
		"Host":               decodeBase64(host),
		"Origin":             decodeBase64(origin),
		"Referer":            decodeBase64(referer),
		"Sec-Fetch-Dest":     "empty",
		"Sec-Fetch-Mode":     "cors",
		"Sec-Fetch-Site":     "same-site",
		"User-Agent":         "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0",
		"X-User-IP":          ip,
		"sec-ch-ua":          `"Microsoft Edge";v="131", "Chromium";v="131", "Not_A Brand";v="24"`,
		"sec-ch-ua-mobile":   "?0",
		"sec-ch-ua-platform": `"Windows"`,
	}
}

func searchMahasiswa(searchQuery string, headers map[string]string) ([]mahasiswaSearchResult, error) {
	searchURL := endpoint() + "/pencarian/mhs/" + parseURLSegment(searchQuery)
	body, err := requestText(searchURL, headers)
	if err != nil {
		return nil, err
	}

	var results []mahasiswaSearchResult
	if err := json.Unmarshal([]byte(body), &results); err != nil {
		return nil, err
	}

	return results, nil
}

func getMhsDetail(mahasiswaID string, headers map[string]string) (json.RawMessage, error) {
	detailURL := endpoint() + "/detail/mhs/" + parseURLSegment(mahasiswaID)
	body, err := requestText(detailURL, headers)
	if err != nil {
		return nil, err
	}

	return json.RawMessage(body), nil
}

func scrapeMahasiswaDetails(options scrapeOptions) (scrapeResult, error) {
	if options.queries == nil {
		options.queries = mahasiswaQueries
	}
	if options.outputFile == "" {
		options.outputFile = defaultOutputFile
	}
	if options.delayMs < 0 {
		options.delayMs = 0
	}
	if options.headers == nil {
		options.headers = buildHeaders()
	}

	selectedQueries := options.queries
	if options.limit >= 0 && options.limit < len(selectedQueries) {
		selectedQueries = selectedQueries[:options.limit]
	}

	result := scrapeResult{outputFile: options.outputFile}
	for _, query := range selectedQueries {
		result.queryCount++

		results, err := searchMahasiswa(query, options.headers)
		if err != nil {
			fmt.Fprintf(os.Stderr, "Error while processing %q: %v\n", query, err)
			sleep(options.delayMs)
			continue
		}

		for _, mahasiswa := range results {
			if mahasiswa.ID == "" {
				continue
			}

			detail, err := getMhsDetail(mahasiswa.ID, options.headers)
			if err != nil {
				fmt.Fprintf(os.Stderr, "Error while fetching detail for %q: %v\n", mahasiswa.ID, err)
				continue
			}

			result.detailCount++
			if err := appendJSONLine(options.outputFile, detail); err != nil {
				return result, err
			}
		}

		sleep(options.delayMs)
	}

	return result, nil
}

func requestText(requestURL string, headers map[string]string) (string, error) {
	req, err := http.NewRequest(http.MethodGet, requestURL, nil)
	if err != nil {
		return "", err
	}

	for name, value := range headers {
		normalizedName := strings.ToLower(name)
		if normalizedName == "accept-encoding" || normalizedName == "connection" || normalizedName == "host" {
			continue
		}

		req.Header.Set(name, value)
	}

	response, err := httpClient.Do(req)
	if err != nil {
		return "", err
	}
	defer response.Body.Close()

	body, err := io.ReadAll(response.Body)
	if err != nil {
		return "", err
	}

	if response.StatusCode < http.StatusOK || response.StatusCode >= http.StatusMultipleChoices {
		return "", fmt.Errorf("request failed with HTTP %d: %s", response.StatusCode, requestURL)
	}

	return string(body), nil
}

func appendJSONLine(filePath string, data json.RawMessage) error {
	if filePath == "" {
		return errors.New("output file path is required")
	}

	if err := os.MkdirAll(filepath.Dir(filePath), 0o755); err != nil {
		return err
	}

	file, err := os.OpenFile(filePath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return err
	}
	defer file.Close()

	_, err = file.Write(append(data, '\n'))
	return err
}

func sleep(delayMs int) {
	if delayMs > 0 {
		time.Sleep(time.Duration(delayMs) * time.Millisecond)
	}
}

func main() {
	outputFile := flag.String("output", defaultOutputFile, "Output file for JSON lines")
	delayMs := flag.Int("delay", 20, "Delay between search queries in milliseconds")
	limit := flag.Int("limit", -1, "Process only the first N queries")
	flag.Parse()

	result, err := scrapeMahasiswaDetails(scrapeOptions{
		queries:    mahasiswaQueries,
		outputFile: *outputFile,
		delayMs:    *delayMs,
		limit:      *limit,
	})
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}

	fmt.Printf(
		"Done. Processed %d queries and wrote %d details to %s\n",
		result.queryCount,
		result.detailCount,
		result.outputFile,
	)
}
