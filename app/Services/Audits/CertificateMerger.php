<?php

namespace App\Services\Audits;

use Ilovepdf\Ilovepdf;

/**
 * Merges the policy certificates of an audit into one PDF (iLovePDF, as the
 * audit page always has). A class of its own so a test can stand in for it.
 */
class CertificateMerger
{
    /**
     * @param  list<string>  $files  absolute paths
     * @return string the merged PDF's bytes
     */
    public function merge(array $files, string $outputName): string
    {
        $ilovepdf = new Ilovepdf(env('I_LOVE_PDF_PUBLIC'), env('I_LOVE_PDF_SECRET'));
        $task = $ilovepdf->newTask('merge');
        foreach ($files as $file) {
            $task->addFile($file);
        }
        $task->setOutputFilename($outputName);
        $task->execute();

        return (string) $task->blob();
    }
}
