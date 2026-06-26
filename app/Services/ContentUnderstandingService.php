<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContentUnderstandingService
{
    private string $endpoint;
    private string $apiKey;
    private string $apiVersion;
    private string $analyzerId;

    public function __construct()
    {
        $this->endpoint    = rtrim((string) config('services.azure_cu.endpoint'), '/');
        $this->apiKey      = (string) config('services.azure_cu.api_key');
        $this->apiVersion  = (string) config('services.azure_cu.api_version');
        $this->analyzerId  = (string) config('services.azure_cu.analyzer_id');
    }

    /**
     * Analyze a document stored on the 'files' disk.
     * Returns a normalised analyzeResult array compatible with
     * extractReceipt() and ProcessesVendorDocs.
     *
     * @param  string       $filePath    Relative path on the 'files' disk (e.g. '_temp_ocr/foo.pdf')
     * @param  string       $docType     'pdf' | 'jpg' | 'jpeg' | 'png'
     * @param  string       $logChannel  Laravel log channel name
     * @param  string|null  $analyzerId  Override the default analyzer (e.g. for COI extraction)
     * @return array{analyzeResult: array}
     */
    public function analyze(string $filePath, string $docType, string $logChannel = 'vendor_docs', ?string $analyzerId = null): array
    {
        $analyzerId  = $analyzerId ?? $this->analyzerId;
        $fileContent = Storage::disk('files')->get($filePath);

        if (empty($fileContent)) {
            Log::channel($logChannel)->error('CU: File is empty or unreadable', [
                'file_path' => $filePath,
            ]);
            throw new \RuntimeException("ContentUnderstanding: file is empty or unreadable: {$filePath}");
        }

        // Submit as raw binary — CU only runs OCR on octet-stream uploads.
        $submitUrl = "https://{$this->endpoint}/contentunderstanding/analyzers/{$analyzerId}:analyzeBinary?api-version={$this->apiVersion}";

        $submitResponse = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
            'Content-Type'              => 'application/octet-stream',
        ])->withBody($fileContent, 'application/octet-stream')
          ->post($submitUrl);

        if ($submitResponse->status() !== 202) {
            $status = $submitResponse->status();
            $commonContext = [
                'file_path' => $filePath,
                'status' => $status,
                'endpoint' => $this->endpoint,
                'api_version' => $this->apiVersion,
                'analyzer_id' => $analyzerId,
                'body' => substr($submitResponse->body(), 0, 2000),
            ];

            if (in_array($status, [401, 403], true)) {
                Log::channel($logChannel)->error('CU: Submit auth/authorization failure', $commonContext + [
                    'hint' => 'Check AZURE_CU_ENDPOINT/AZURE_CU_API_KEY permissions and restart queue workers after key rotation.',
                ]);
            } else {
                Log::channel($logChannel)->error('CU: Submit returned unexpected status', $commonContext);
            }

            throw new \RuntimeException("ContentUnderstanding: submit returned HTTP {$status}");
        }

        // The operation URL is in the Operation-Location header
        $operationUrl = $submitResponse->header('Operation-Location');

        if (empty($operationUrl)) {
            Log::channel($logChannel)->error('CU: Missing Operation-Location header', [
                'file_path' => $filePath,
                'headers'   => $submitResponse->headers(),
            ]);
            throw new \RuntimeException('ContentUnderstanding: missing Operation-Location header');
        }

        // Poll until complete
        $result = $this->poll($operationUrl, $logChannel);

        // Normalise the Content Understanding response to the same shape
        // that ReceiptController::extractReceipt() uses:
        //   $result['analyzeResult']['documents'][0]['fields']
        //   $result['analyzeResult']['content']
        //   $result['analyzeResult']['keyValuePairs']   (optional)
        //   $result['analyzeResult']['styles']          (optional)
        return $this->normalise($result);
    }

    /**
     * Poll the operation URL until the job is no longer running.
     */
    private function poll(string $operationUrl, string $logChannel): array
    {
        $maxRetries = 300;  // ~5 minutes
        $attempt    = 0;

        sleep(2);

        while ($attempt < $maxRetries) {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->apiKey,
            ])->get($operationUrl);

            if (! $response->successful()) {
                Log::channel($logChannel)->warning('CU: Poll returned non-2xx', [
                    'attempt' => $attempt,
                    'status'  => $response->status(),
                ]);
                $attempt++;
                sleep(1);
                continue;
            }

            $body = $response->json();

            $status = $body['status'] ?? 'unknown';

            if (! in_array($status, ['Running', 'NotStarted'], true)) {
                if ($status !== 'Succeeded') {
                    $errorDetail = json_encode($body['error'] ?? $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    Log::channel($logChannel)->error('CU: Analysis failed', [
                        'status' => $status,
                        'body'   => substr($errorDetail, 0, 3000),
                    ]);
                    throw new \RuntimeException("ContentUnderstanding: analysis failed with status: {$status}");
                }

                return $body;
            }

            $attempt++;
            sleep(1);
        }

        throw new \RuntimeException('ContentUnderstanding: timed out waiting for analysis result');
    }

    /**
     * Normalise the Content Understanding result to the analyzeResult shape
     * that ReceiptController::extractReceipt() reads.
     *
     * CU response shape (result.contents[0]):
     *   fields       → same field names as DI, but wrapped differently
     *   content      → raw text
     *   keyValuePairs (if present)
     *   styles        (if present)
     */
    private function normalise(array $body): array
    {
        // CU returns: { status, result: { contents: [ { fields, markdown, ... } ] } }
        // Multi-page PDFs may produce multiple contents entries — one per page.
        // Map ALL of them into documents so consumers can merge as needed
        // (similar to how receipt OCR merges all pages).
        $contents = $body['result']['contents'] ?? [];

        $documents     = [];
        $allMarkdown   = [];
        $keyValuePairs = null;
        $styles        = [];

        foreach ($contents as $page) {
            $documents[] = ['fields' => $page['fields'] ?? []];

            if ($md = $page['markdown'] ?? $page['content'] ?? '') {
                $allMarkdown[] = $md;
            }

            // Take keyValuePairs / styles from the first page that has them
            if (! $keyValuePairs && ! empty($page['keyValuePairs'])) {
                $keyValuePairs = $page['keyValuePairs'];
            }
            if (empty($styles) && ! empty($page['styles'])) {
                $styles = $page['styles'];
            }
        }

        if (empty($documents)) {
            $documents = [['fields' => []]];
        }

        return [
            'analyzeResult' => [
                'documents'     => $documents,
                'content'       => implode("\n\n", $allMarkdown),
                'keyValuePairs' => $keyValuePairs ? (array) $keyValuePairs : [],
                'styles'        => $styles,
            ],
        ];
    }


    /**
     * Detect MIME type from file header magic bytes, falling back to $docType.
     */
    private function mimeType(string $docType, string $content): string
    {
        $header = substr($content, 0, 8);

        if (str_starts_with($header, '%PDF-')) {
            return 'application/pdf';
        }

        if (str_starts_with($header, "\x89PNG")) {
            return 'image/png';
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        return match (strtolower($docType)) {
            'pdf'           => 'application/pdf',
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            default         => 'application/octet-stream',
        };
    }

    // -------------------------------------------------------------------------
    // Analyzer management
    // -------------------------------------------------------------------------

    /**
     * Fetch the current definition of any analyzer (prebuilt or custom).
     */
    public function getAnalyzerDefinition(string $analyzerId): array
    {
        $url = "https://{$this->endpoint}/contentunderstanding/analyzers/{$analyzerId}?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
        ])->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("CU: getAnalyzerDefinition({$analyzerId}) returned HTTP {$response->status()}: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Create or replace an analyzer with the given definition.
     * Uses PUT which is idempotent — safe to run repeatedly.
     */
    public function putAnalyzer(string $analyzerId, array $definition): array
    {
        $url = "https://{$this->endpoint}/contentunderstanding/analyzers/{$analyzerId}?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
            'Content-Type'              => 'application/json',
        ])->put($url, $definition);

        if ($response->status() === 202) {
            // Async creation — poll until done
            $operationUrl = $response->header('Operation-Location');
            if (! empty($operationUrl)) {
                return $this->poll($operationUrl, 'horizon');
            }
        }

        if (! $response->successful()) {
            throw new \RuntimeException("CU: putAnalyzer({$analyzerId}) returned HTTP {$response->status()}: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Delete an existing analyzer by ID.
     */
    public function deleteAnalyzer(string $analyzerId): void
    {
        $url = "https://{$this->endpoint}/contentunderstanding/analyzers/{$analyzerId}?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
        ])->delete($url);

        if (! $response->successful() && $response->status() !== 404) {
            throw new \RuntimeException("CU: deleteAnalyzer({$analyzerId}) returned HTTP {$response->status()}: " . $response->body());
        }
    }

    /**
     * List all analyzers on the resource.
     */
    public function listAnalyzers(): array
    {
        $url = "https://{$this->endpoint}/contentunderstanding/analyzers?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
        ])->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("CU: listAnalyzers returned HTTP {$response->status()}: " . $response->body());
        }

        return $response->json()['value'] ?? [];
    }

    /**
     * Set default model deployments for the resource.
     * Must be called before creating analyzers on a freshly provisioned resource.
     * $defaults example: ['completion' => ['deploymentName' => 'gpt-4o-mini']]
     *
     * @param  array<string, mixed>  $defaults
     */
    public function setDefaults(array $defaults): array
    {
        $url = "https://{$this->endpoint}/contentunderstanding/defaults?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
            'Content-Type'              => 'application/merge-patch+json',
        ])->patch($url, $defaults);

        if (! $response->successful()) {
            throw new \RuntimeException("CU: setDefaults returned HTTP {$response->status()}: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Get current defaults for the resource.
     */
    public function getDefaults(): array
    {
        $url = "https://{$this->endpoint}/contentunderstanding/defaults?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
        ])->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("CU: getDefaults returned HTTP {$response->status()}: " . $response->body());
        }

        return $response->json() ?? [];
    }
}
