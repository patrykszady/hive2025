<?php

namespace App\Support\Ewccv;

use App\Services\NylasService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The folders EWCCV's mail is filed into in the certificates mailbox, by
 * what each message is: sign-in links and subscription confirmations have
 * their own subfolders under "Workers Comp", so the parent keeps only the
 * notices a person should read (cancellations, reinstatements) and whatever
 * the parser could not place.
 *
 *   Workers Comp
 *   ├── Sign-in requests        (NoticeParser::KIND_SIGN_IN)
 *   └── Subscription addition   (NoticeParser::KIND_CONFIRMATION)
 *
 * Folder ids are looked up once and kept in a storage file; a move that
 * fails refreshes them, since Outlook keeps ids stable but a folder can be
 * renamed or recreated by hand.
 */
class MailFolders
{
    public const PARENT = 'Workers Comp';

    public const SIGN_IN = 'Sign-in requests';

    public const SUBSCRIPTIONS = 'Subscription addition';

    public const FILE = 'ewccv/mail-folders.json';

    public function __construct(protected NylasService $nylas) {}

    /** The folder a message of this kind belongs in. */
    public static function folderFor(string $kind): string
    {
        return match ($kind) {
            NoticeParser::KIND_SIGN_IN => self::SIGN_IN,
            NoticeParser::KIND_CONFIRMATION => self::SUBSCRIPTIONS,
            default => self::PARENT,
        };
    }

    /**
     * Folder name => id for the three folders, creating the missing ones.
     *
     * @return array<string, string>
     */
    public function ids(string $grantId, bool $refresh = false): array
    {
        $disk = Storage::disk('local');
        if (! $refresh && $disk->exists(self::FILE)) {
            $cached = json_decode((string) $disk->get(self::FILE), true);
            if (is_array($cached) && isset($cached[self::PARENT], $cached[self::SIGN_IN], $cached[self::SUBSCRIPTIONS])) {
                return $cached;
            }
        }

        $folders = $this->list($grantId);
        $byKey = fn (string $name, ?string $parentId) => collect($folders)
            ->first(fn (array $f) => strcasecmp((string) ($f['name'] ?? ''), $name) === 0 && ($parentId === null || ($f['parent_id'] ?? null) === $parentId));

        $parent = $byKey(self::PARENT, null) ?? $this->create($grantId, self::PARENT, null);
        if (! $parent) {
            return [];
        }
        $ids = [self::PARENT => (string) $parent['id']];
        foreach ([self::SIGN_IN, self::SUBSCRIPTIONS] as $name) {
            $folder = $byKey($name, $ids[self::PARENT]) ?? $this->create($grantId, $name, $ids[self::PARENT]);
            if (! $folder) {
                return [];
            }
            $ids[$name] = (string) $folder['id'];
        }

        $disk->put(self::FILE, json_encode($ids));

        return $ids;
    }

    /**
     * File a message where its kind belongs; true when it is there now.
     *
     * @param  array<string, mixed>  $message  a Nylas message (id, folders)
     */
    public function file(string $grantId, array $message, string $kind): bool
    {
        $ids = $this->ids($grantId);
        $target = $ids[self::folderFor($kind)] ?? null;
        if (! $target || empty($message['id'])) {
            return false;
        }
        if (in_array($target, (array) ($message['folders'] ?? []), true)) {
            return true;
        }
        if ($this->nylas->moveEmailToFolder((string) $message['id'], $target, $grantId)) {
            return true;
        }

        // A stale id: look the folders up again and try once more.
        $ids = $this->ids($grantId, true);
        $target = $ids[self::folderFor($kind)] ?? null;

        return $target !== null && $this->nylas->moveEmailToFolder((string) $message['id'], $target, $grantId);
    }

    /** @return list<array<string, mixed>> */
    protected function list(string $grantId): array
    {
        $response = $this->nylas->getFolders($grantId);
        $data = $response['data'] ?? [];
        $list = is_array($data['data'] ?? null) ? $data['data'] : $data;

        return array_values(array_filter((array) $list, 'is_array'));
    }

    /** @return array<string, mixed>|null */
    protected function create(string $grantId, string $name, ?string $parentId): ?array
    {
        $response = $this->nylas->createFolder($grantId, $name, $parentId);
        $data = $response['data'] ?? [];
        $folder = is_array($data['data'] ?? null) ? $data['data'] : $data;
        if (! is_array($folder) || empty($folder['id'])) {
            Log::warning('EWCCV mail folder could not be created', ['name' => $name, 'parent' => $parentId, 'error' => $response['error'] ?? null]);

            return null;
        }

        return $folder;
    }
}
