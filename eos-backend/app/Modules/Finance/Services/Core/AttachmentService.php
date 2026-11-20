<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\Attachment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoice and receipt scans on any finance record (vouchers, cash counts, site requests, assets). Files live on the
 * private `local` disk under finance/ and are only downloaded through the API.
 */
class AttachmentService
{
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const MAX_KB = 10240;

    public const MAX_PER_RECORD = 10;

    public function __construct(private AuditLogger $audit) {}

    public function store(Model $attachable, UploadedFile $file, User $actor): Attachment
    {
        if (! in_array(strtolower((string) $file->getClientOriginalExtension()), self::MIMES, true)
            || ! in_array(strtolower((string) $file->guessExtension()), self::MIMES, true) || $file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['file' => ['المرفق صورة (jpg, png, webp) أو PDF بحجم 10 ميغابايت على الأكثر.']]);
        }
        if (Attachment::query()->where('attachable_type', $attachable->getMorphClass())->where('attachable_id', $attachable->getKey())->count() >= self::MAX_PER_RECORD) {
            throw ValidationException::withMessages(['file' => ['الحد الأقصى '.self::MAX_PER_RECORD.' مرفقات للسجل.']]);
        }
        $path = $file->store('finance/'.$attachable->getMorphClass().'/'.$attachable->getKey(), 'local');
        $attachment = Attachment::create(['attachable_type' => $attachable->getMorphClass(), 'attachable_id' => $attachable->getKey(), 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(), 'uploaded_by' => $actor->id]);
        $this->audit->log('finance.attachment_added', $attachable, null, ['attachment_id' => $attachment->id, 'name' => $attachment->original_name], $actor->id);

        return $attachment;
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'الملف غير موجود.');

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime],
            str_starts_with($attachment->mime, 'image/') || $attachment->mime === 'application/pdf' ? 'inline' : 'attachment');
    }

    public function delete(Attachment $attachment, User $actor): void
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
        $this->audit->log('finance.attachment_removed', null, ['attachment_id' => $attachment->id, 'name' => $attachment->original_name,
            'attachable' => $attachment->attachable_type.':'.$attachment->attachable_id], null, $actor->id);
    }

    /** Removes every attachment of a record (a deleted draft). */
    public function deleteAll(Model $attachable): void
    {
        $rows = Attachment::query()->where('attachable_type', $attachable->getMorphClass())->where('attachable_id', $attachable->getKey())->get();
        foreach ($rows as $row) {
            Storage::disk('local')->delete($row->path);
            $row->delete();
        }
    }
}
