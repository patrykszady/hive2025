<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Support\ApiErrorFormatter;

use setasign\Fpdi\Fpdi;

use Exception;

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
            throw new Exception("Unsupported document type: {$docType}");
        }

        // Start analysis and retrieve the operation ID.
        $operationId = $this->startAnalysis($documentModel, $fileContent, $contentType);
        if (!$operationId) {
            throw new Exception("Failed to retrieve operation ID from Azure response.");
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

    public function getDocumentModel($file_path, $doc_type)
    {
        // Default to 'prebuilt-invoice'
        $document_model = 'prebuilt-invoice';

        try {
            // Ensure the path works with the 'files' disk
            $full_file_path = Storage::disk('files')->path($file_path);

            // Check if the file exists
            if (!file_exists($full_file_path)) {
                throw new Exception("File not found at path: " . $full_file_path);
            }

            if ($doc_type === 'pdf') {
                // Validate the file starts with a PDF header before attempting FPDI parsing
                $header = file_get_contents($full_file_path, false, null, 0, 5);
                if ($header !== '%PDF-') {
                    Log::warning('File is not a valid PDF (missing %PDF- header), using default model', [
                        'file_path' => $file_path,
                    ]);

                    return $document_model;
                }

                $pdf = new Fpdi();
                $pdf->setSourceFile($full_file_path);
                $pageId = $pdf->importPage(1);

                $width = $pdf->getTemplateSize($pageId)['width'];

                // Adjust document model based on width
                if ($width < 180) {
                    $document_model = 'prebuilt-receipt';
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in getDocumentModel', ApiErrorFormatter::format($e, [
                'file_path' => $file_path,
                'doc_type' => $doc_type,
            ]));
        }

        // Return the determined or default document model
        return $document_model;
    }
}
