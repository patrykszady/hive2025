<?php

namespace App\Http\Controllers;

use File;
use Ilovepdf\Ilovepdf;
use Intervention\Image\Facades\Image;
use Response;

class VendorDocsController extends Controller
{
    public function audit_docs_pdf($files)
    {
        $filename = 'audit-'.auth()->user()->vendor->id.'-'.date('Y-m-d-h-m-s');

        //10-15-2023 Create cover page
        ///////cover page here/// use audit view? csv? table?

        $ilovepdf = new Ilovepdf(env('I_LOVE_PDF_PUBLIC'), env('I_LOVE_PDF_SECRET'));
        // Create a new task
        $myTaskMerge = $ilovepdf->newTask('merge');

        // Add files to task for upload
        foreach ($files as $key => $file) {
            ${'merged_'.$key} = $myTaskMerge->addFile($file);
        }

        // dd($myTaskMerge);
        // $file1 = $myTaskMerge->addFile('/home/vagrant/web/gs/storage/files/vendor_docs/elm_r3.pdf');
        // $file2 = $myTaskMerge->addFile('/home/vagrant/web/gs/storage/files/vendor_docs/elm_r3.pdf');
        // Execute the task
        $myTaskMerge->setOutputFilename($filename);
        $myTaskMerge->execute();
        // $myTaskMerge->download();
        // Download the package files
        $myTaskMerge->download(storage_path('files/vendor_docs/'));

        // //stream/download
        $path = storage_path('files/vendor_docs/'.$filename.'.pdf');
        // $response = Response::make(file_get_contents($path), 200, [
        //     'Content-Type' => 'application/pdf'
        // ]);

        // $response;
        return response()->download($path);
    }

    //1-18-2023 combine the next 2 functions into one. Pass type = original or temp
    //Show full-size receipt to anyone with a link
    // No Middleware or Policies
    //PUBLIC AS FUCK! BE CAREFUL!
    public function document($filename)
    {
        $path = storage_path('files/vendor_docs/'.$filename);

        if (File::extension($filename) == 'pdf') {
            $response = Response::make(file_get_contents($path), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        } else {
            $response = Image::make($path)->response();
        }

        return $response;
    }
}
