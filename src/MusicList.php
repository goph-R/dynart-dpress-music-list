<?php

namespace Dynart\MusicList;

/**
 * The names this plugin answers to, in one place
 */
class MusicList {

    /** The one permission: whoever may arrange the music may do all of it */
    const PERMISSION_MANAGE = 'music.manage';

    /** The key of the admin section, and what the controller answers from `section()` */
    const ADMIN_SECTION = 'music';

    /** The view namespace of the plugin's templates */
    const VIEWS = 'music_list';

    const QUERY_TRACKS = 'music_track_list';
    const QUERY_ALBUMS = 'music_album_list';

    const FORM_TRACK = 'music_track';
    const FORM_ALBUM = 'music_album';

    /** `{{ music_list() }}` */
    const SHORTCODE = 'music_list';

    /**
     * The attribute the player's root carries, and so the needle its script and stylesheet are
     * loaded by: a page without a player on it loads neither
     */
    /** The page template's name, as a page stores it */
    const PAGE_TEMPLATE = 'music-player';

    const NEEDLE = 'data-music-list';

    /** What a list may be ordered by, as the shortcode and the player's select both name them */
    const SORTS = ['created-desc', 'created-asc', 'title-asc', 'title-desc'];

    const DEFAULT_SORT = 'created-desc';
}
