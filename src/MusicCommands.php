<?php

namespace Dynart\MusicList;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\Cli\AbstractCommands;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\UserService;

/**
 * `dpress music:import` - a MusicList folder, brought into the site
 *
 * MusicList is the static player this plugin grew out of: a `tracks.json` beside a `music/` and
 * a `covers/` folder. This reads that list and makes of each entry what the admin would have: the
 * mp3 and the cover as media items, the album if it names one, and a track pointing at them.
 *
 * **Safe to run again.** A track is known by its slug, which is the entry's `id`, so a second run
 * imports what the first did not and touches nothing else - which is also how to bring over the
 * tracks added to the old list since.
 *
 * **The `id` is kept as the slug** rather than made again from the title, because it is what the
 * share links people already posted carry: `?track=area-67` has to keep meaning AREA 67.
 */
class MusicCommands extends AbstractCommands {

    const LIST_FILE = 'tracks.json';

    public function __construct(
        CliOutputInterface $output,
        protected MusicService $music,
        protected MediaService $media,
        protected UserService $users,
        protected Slugger $slugger,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress music:import -source ../MusicList -user admin@example.com [-check]`
     *
     * `-check` reads the list and says what would happen, and writes nothing.
     */
    public function import(array $params = []): int {
        $source = realpath($this->param($params, 'source', '.'));
        if ($source === false || !is_file($source.'/'.self::LIST_FILE)) {
            return $this->fail('A -source folder with a '.self::LIST_FILE.' in it is required.');
        }
        $user = $this->users->findByEmail($this->users->normalizeEmail($this->param($params, 'user')));
        if ($user === null) {
            return $this->fail('A -user email is required, and it has to exist: the files are uploaded as somebody.');
        }
        $entries = self::readList((string)file_get_contents($source.'/'.self::LIST_FILE));
        if ($entries === null) {
            return $this->fail(self::LIST_FILE.' is not a list of tracks.');
        }
        $check = $this->flag($params, 'check');

        $added = 0;
        $known = 0;
        $skipped = 0;
        foreach ($entries as $entry) {
            $slug = $this->slugger->slugify((string)($entry['id'] ?? $entry['title'] ?? ''));
            $title = trim((string)($entry['title'] ?? ''));
            if ($slug === '') {
                $skipped++;
                $this->note(CliOutput::YELLOW, '  ?  an entry with neither an id nor a title was left out');
                continue;
            }
            if ($this->music->findTrackBySlug($slug) !== null) {
                $known++;
                continue;
            }
            $audio = self::fileIn($source, (string)($entry['url'] ?? ''));
            if ($audio === null) {
                $skipped++;
                $this->note(CliOutput::YELLOW, "  ?  $slug: there is no file at '".($entry['url'] ?? '')."'");
                continue;
            }
            $cover = self::fileIn($source, (string)($entry['coverUrl'] ?? ''));
            if ($check) {
                $added++;
                $this->note(null, "  +  $slug".($cover === null ? '  (no cover)' : ''));
                continue;
            }
            try {
                $this->importOne($entry, $slug, $title, $audio, $cover, (int)$user->id);
                $added++;
                $this->note(null, "  +  $slug");
            } catch (DpressException $e) {
                $skipped++;
                $this->note(CliOutput::RED, "  !  $slug: ".$e->getMessage());
            }
        }

        $summary = ($check ? 'Would import ' : 'Imported ').$added.', '.$known.' already here, '.$skipped.' left out.';
        // anything left out is a list that did not come over whole, and a deploy script should
        // hear about it - a green line over fifty red ones is how that goes unnoticed
        return $skipped > 0 ? $this->fail($summary) : $this->success($summary);
    }

    /**
     * One entry: the two files, the album, the track
     *
     * A cover that cannot be imported costs the cover and not the track - it is the lesser file,
     * and a track without one still plays. The audio failing is the track failing.
     */
    protected function importOne(array $entry, string $slug, string $title, string $audio, ?string $cover, int $userId): void {
        $audioMedia = $this->media->importFile($audio, $userId, ['title' => $title]);
        $coverId = 0;
        if ($cover !== null) {
            try {
                $coverId = $this->media->importFile($cover, $userId, ['title' => $title])->id;
            } catch (DpressException $e) {
                $this->note(CliOutput::YELLOW, "  ?  $slug: the cover was left out - ".$e->getMessage());
            }
        }
        $albumTitle = trim((string)($entry['albumTitle'] ?? ''));
        $data = [
            'audio_media_id' => $audioMedia->id,
            'cover_media_id' => $coverId,
            'title'          => $title,
            'slug'           => $slug,
            'album_id'       => $albumTitle === '' ? 0 : $this->music->findOrCreateAlbum($albumTitle)->id,
        ];
        $released = self::storedMoment((string)($entry['createdOn'] ?? ''));
        if ($released !== null) {
            $data['released_at'] = $released;
        }
        $this->music->createTrack($data);
    }

    /**
     * The entries of a `tracks.json`, or null when it is not one
     *
     * The byte order mark is taken off first: the file MusicList ships starts with one, and
     * `json_decode` reads a BOM as a syntax error rather than as nothing.
     *
     * @return array[]|null
     */
    public static function readList(string $json): ?array {
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }
        $list = json_decode($json, true);
        // a list, not an object: `array_is_list()` is PHP 8.1 and Dpress still runs on 8.0
        if (!is_array($list) || ($list !== [] && array_keys($list) !== range(0, count($list) - 1))) {
            return null;
        }
        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * The file a list entry names, if it is a file inside the folder
     *
     * The entry holds a URL path - `music/AREA%2067.mp3` - so it is decoded before it is looked
     * for. **Inside the folder** is checked on the resolved path: a list is a file somebody
     * handed over, and `../../etc/passwd` in one is not a thing to upload into a media library.
     */
    public static function fileIn(string $folder, string $url): ?string {
        $relative = rawurldecode(trim($url));
        if ($relative === '') {
            return null;
        }
        $path = realpath($folder.'/'.$relative);
        if ($path === false || !is_file($path) || !str_starts_with($path, rtrim($folder, '/\\').DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $path;
    }

    /**
     * `2026-05-25T22:14:49.000Z` as the UTC moment the database stores, or null
     */
    public static function storedMoment(string $iso): ?string {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        try {
            $moment = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    protected function note(?int $color, string $text): void {
        $this->output->setColor($color);
        $this->output->writeLine($text);
        $this->output->setColor(null);
    }
}
