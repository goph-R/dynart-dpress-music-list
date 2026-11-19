<?php

namespace Dynart\MusicList;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Micro;
use Dynart\Dpress\Content\Shortcodes;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Query\QueryFactory;

/**
 * A music player for tracks kept in the media library
 *
 * What MusicList - a static page reading a `tracks.json` - does, as a part of a site: the tracks
 * are rows somebody edits in the admin, the mp3s and the covers are media items, and the player
 * is `{{ music_list() }}` written on an ordinary page.
 *
 * **A shortcode on a page rather than a route of its own.** A page already has an address, a
 * place in a menu, a title, a draft and a published state and a line in the sitemap; a route
 * would have needed a setting for its path and a second answer to each of those.
 *
 * **Nothing here is loaded by a page without a player on it.** The script and the stylesheet are
 * registered against the attribute the player's root carries, the subscriptions are Micro
 * callables, and the shortcode's handler is built when a page containing one is rendered - so the
 * plugin enabled and unused costs a few array entries.
 */
class MusicListPlugin extends AbstractPlugin {

    public function services(): array {
        return [
            MusicService::class       => MusicService::class,
            MusicQueries::class       => MusicQueries::class,
            MusicForms::class         => MusicForms::class,
            MusicListShortcode::class => MusicListShortcode::class,
            MusicCommands::class      => MusicCommands::class,
        ];
    }

    public function controllers(): array {
        return [MusicAdminController::class];
    }

    public function entities(): array {
        return [Entity\Album::class, Entity\Track::class];
    }

    public function migrations(): array {
        return [Migration\CreateMusicTables::class];
    }

    public function views(): array {
        return [MusicList::VIEWS => dirname(__DIR__).'/views'];
    }

    public function permissions(): array {
        return [MusicList::PERMISSION_MANAGE => 'Music'];
    }

    /**
     * A block shortcode: the player is a section of a page and cannot sit inside a paragraph
     */
    public function shortcodes(): array {
        return [MusicList::SHORTCODE => [[MusicListShortcode::class, 'render'], Shortcodes::BLOCK]];
    }

    /**
     * A page that is the player and nothing around it, for the page editor's *Template* select
     *
     * Needs dpress 0.96.0, which is the release a plugin could first offer one in.
     */
    public function pageTemplates(): array {
        return [
            MusicList::PAGE_TEMPLATE => [
                'label'    => 'Music player, full window',
                'template' => MusicList::VIEWS.':page-player',
            ],
        ];
    }

    /**
     * On the pages with a player on them, and on no others
     */
    public function pageAssets(): array {
        return [
            'music-list.css' => MusicList::NEEDLE,
            'music-list.js'  => MusicList::NEEDLE,
        ];
    }

    /** After Media: it is the part of the library that plays */
    public function adminSections(): array {
        return [MusicList::ADMIN_SECTION => [
            'label'      => 'Music',
            'route'      => '/admin/music',
            'permission' => MusicList::PERMISSION_MANAGE,
            'icon'       => 'icons/music.svg',
            'after'      => 'media',
        ]];
    }

    public function commands(): array {
        return [
            'music:import' => [
                'callable'    => [MusicCommands::class, 'import'],
                'description' => 'Import the tracks of a MusicList folder (tracks.json, music/, covers/)',
                'params'      => ['source', 'user'],
                'flags'       => ['check'],
            ],
        ];
    }

    /**
     * The two listings, and the answer to "is this file used?"
     *
     * The queries go to the factory here because both apps have one - the import command lists
     * tracks too. The forms do not: the form factory is the web app's alone, so the controller
     * registers those the first time it needs one.
     *
     * `media:usage_counted` is written out rather than read from `MediaService`: the constant
     * arrived with the event (dpress 0.96.0), and naming it would make this plugin fail to load
     * on a Dpress from before it - for a warning that Dpress could not have shown anyway.
     */
    public function register(): void {
        $queries = Micro::get(QueryFactory::class);
        $queries->add(MusicList::QUERY_TRACKS, [MusicQueries::class, 'tracks']);
        $queries->add(MusicList::QUERY_ALBUMS, [MusicQueries::class, 'albums']);

        Micro::get(EventServiceInterface::class)->subscribe(
            'media:usage_counted', [MusicService::class, 'onMediaUsage']
        );
    }
}
