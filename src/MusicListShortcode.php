<?php

namespace Dynart\MusicList;

use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Content\Dates;

/**
 * `{{ music_list() }}` - the player, with every track in it
 *
 * | written | lists |
 * |---|---|
 * | `{{ music_list() }}` | every track, newest first |
 * | `{{ music_list(album='coolfox-ost') }}` | one album's, named by its slug |
 * | `{{ music_list(sort='title-asc') }}` | in another order to begin with |
 *
 * `sort` is one of `created-desc` (the default), `created-asc`, `title-asc`, `title-desc` - the
 * order the list **starts** in. The visitor's own select changes it from there.
 *
 * **The list is rendered here and not fetched by the script.** A row is a title, a date and a
 * download link before any JavaScript has run, so the page is readable without it and a search
 * engine sees the tracks; the script turns the rows it finds into a player. There is no second
 * request for a JSON file, and nothing for a cache to serve a stale copy of.
 */
class MusicListShortcode {

    public function __construct(
        protected ViewInterface $view,
        protected MusicService $music,
        protected Dates $dates,
    ) {}

    public function render(array $arguments): string {
        $sort = (string)($arguments['sort'] ?? MusicList::DEFAULT_SORT);
        if (!in_array($sort, MusicList::SORTS, true)) {
            $sort = MusicList::DEFAULT_SORT;
        }
        // named, or the first thing in the brackets: `music_list('coolfox-ost')` reads as well
        $album = trim((string)($arguments['album'] ?? $arguments[0] ?? ''));
        return $this->view->fetch(MusicList::VIEWS.':player', [
            'tracks' => $this->music->playlist(['album' => $album, 'sort' => $sort]),
            'sort'   => $sort,
            'dates'  => $this->dates,
        ]);
    }
}
