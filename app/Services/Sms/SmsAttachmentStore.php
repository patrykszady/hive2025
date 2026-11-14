<?php

namespace App\Services\Sms;

use App\Services\ProjectImageImporter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Where a text's photos and videos are kept until they are sent — for the
 * web composer (App\Livewire\Sms\SmsConversation) and the Hive Contractors
 * app (Api\App\V1\ThreadMessageController) alike.
 *
 * An iPhone photo comes as HEIC, which no phone network carries in an MMS
 * and which the attachment rule used to refuse outright (TestFlight
 * 2026-11-14: "Unable to add an image from iPhone — shouldn't we convert to
 * jpg like we do on the web?"). It is turned into a JPEG here, at the door,
 * with the converter project photos already use, so everything after this
 * is a format Telnyx sends.
 */
class SmsAttachmentStore
{
    /** The extensions an iPhone photo comes as. */
    public const HEIC_EXTENSIONS = ['heic', 'heif'];

    /**
     * @param  string  $field  the form field an unconvertible photo is reported against
     * @return string the '/storage/sms-attachments/…' path the message keeps
     *
     * @throws ValidationException when an iPhone photo cannot be turned into a JPEG
     */
    public function store(UploadedFile $file, string $field = 'media'): string
    {
        if (! $this->isHeic($file)) {
            // A JPEG iOS made, still called IMG_1234.HEIC, is kept under a name that says what it is.
            if (in_array(strtolower($file->getClientOriginalExtension()), self::HEIC_EXTENSIONS, true)) {
                $extension = MimeTypes::getDefault()->getExtensions($this->contentType($file))[0] ?? 'jpg';

                return '/storage/'.$file->storeAs('sms-attachments', Str::random(40).'.'.($extension === 'jpeg' ? 'jpg' : $extension), 'public');
            }

            return '/storage/'.$file->store('sms-attachments', 'public');
        }

        $jpeg = ProjectImageImporter::convertHeicToJpeg((string) $file->getRealPath());

        if ($jpeg === null) {
            throw ValidationException::withMessages([
                $field => 'This iPhone photo (HEIC) could not be turned into a JPG to send. Try it again, or send a screenshot of it.',
            ]);
        }

        try {
            return '/storage/'.Storage::disk('public')->putFileAs('sms-attachments', new File($jpeg), Str::random(40).'.jpg');
        } finally {
            @unlink($jpeg);
        }
    }

    /**
     * An iPhone photo, by what the file is. Its name counts only when the
     * contents say nothing: iOS hands over a photo it already made a JPEG
     * still called IMG_1234.HEIC.
     */
    public function isHeic(UploadedFile $file): bool
    {
        $mime = $this->contentType($file);

        if (str_starts_with($mime, 'image/heic') || str_starts_with($mime, 'image/heif')) {
            return true;
        }

        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            return false;
        }

        return in_array(strtolower($file->getClientOriginalExtension()), self::HEIC_EXTENSIONS, true);
    }

    /** What the bytes are, read here: an upload's own getMimeType() may go by the name it was given. */
    protected function contentType(UploadedFile $file): string
    {
        $path = (string) $file->getRealPath();

        return strtolower((string) ($path !== '' && is_file($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : $file->getMimeType()));
    }
}
