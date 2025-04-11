<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class AzureDocumentService
{
    private $endpoint;
    private $apiKey;
    private $apiVersion;

    public function __construct()
    {
        $this->endpoint = env('AZURE_DI_ENDPOINT');
        $this->apiKey = env('AZURE_DI_API_KEY');
        $this->apiVersion = env('AZURE_DI_VERSION');
    }

    public function analyzeDocument($ocr_path, $documentModel, $docType)
    {
        // Get the file's contents using an absolute path.
        $fileContent = file_get_contents(storage_path($ocr_path));

        // Determine the Content-Type header based on file type.
        $contentType = $this->getContentType($docType);
        if (!$contentType) {
            throw new \Exception("Unsupported document type: {$docType}");
        }

        // Start analysis and retrieve the operation ID.
        $operationId = $this->startAnalysis($documentModel, $fileContent, $contentType);
        if (!$operationId) {
            throw new \Exception("Failed to retrieve operation ID from Azure response.");
        }

        // Poll for the results until the analysis is completed.
        return $this->pollAnalysisResult($documentModel, $operationId);
    }

    private function getContentType($docType)
    {
        $types = [
            'jpg' => 'Content-Type: image/jpeg',
            'jpeg' => 'Content-Type: image/jpeg',
            'pdf' => 'Content-Type: application/pdf',
            'png' => 'Content-Type: image/png',
        ];

        return $types[strtolower($docType)] ?? null;
    }

    private function startAnalysis($documentModel, $fileContent, $contentType)
    {
        $url = "{$this->endpoint}/documentintelligence/documentModels/{$documentModel}:analyze?api-version={$this->apiVersion}&features=queryFields&queryFields=PurchaseOrder";

        $headers = [
            $contentType,
            "Ocp-Apim-Subscription-Key: {$this->apiKey}"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        curl_close($ch);
        dd($response); // Debug point: Remove dd() after testing.

        if (!$response) {
            throw new \Exception("No response from Azure API.");
        }

        // Extract the Operation ID from the response.
        preg_match('/\b[\d\D]{8}-[\d\D]{4}-[\d\D]{4}-[\d\D]{4}-[\d\D]{12}\b/', $response, $matches);
        return $matches[0] ?? null;
    }

    private function pollAnalysisResult($documentModel, $operationId)
    {
        $url = "{$this->endpoint}/documentintelligence/documentModels/{$documentModel}/analyzeResults/{$operationId}?api-version={$this->apiVersion}";

        do {
            sleep(1);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Ocp-Apim-Subscription-Key: {$this->apiKey}"
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($response, true);
            if (!$result || !isset($result['status'])) {
                throw new \Exception("Invalid response from Azure API: " . json_encode($response));
            }
        } while (in_array($result['status'], ['running', 'notStarted']));

        if ($result['status'] !== 'succeeded') {
            throw new \Exception("Analysis failed with status: {$result['status']}");
        }

        return $result;
    }
}
