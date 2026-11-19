<?php

namespace Dynart\MusicList;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Service\MediaService;
use Dynart\MusicList\Entity\Album;
use Dynart\MusicList\Entity\Track;

/**
 * The Music section: tracks and albums, as the two tabs of one screen
 *
 * Every action is behind `music.manage`. One permission rather than a view/create/update/delete
 * set: the people who arrange a site's music are the people who arrange it, and four boxes in
 * the role editor for one small list is a question nobody needed asking four times.
 *
 * A list is for finding things and the editor is for changing them, as everywhere else in the
 * admin: the title is the way into the editor, and Delete is the one row action.
 */
class MusicAdminController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected MusicService $music,
        protected MediaService $media,
        protected MediaView $mediaView,
        protected Dates $dates,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return MusicList::ADMIN_SECTION;
    }

    protected function musicTabs(string $current): array {
        return $this->tabs([
            'tracks' => ['label' => 'Tracks', 'route' => '/admin/music', 'permission' => MusicList::PERMISSION_MANAGE],
            'albums' => ['label' => 'Albums', 'route' => '/admin/music/albums', 'permission' => MusicList::PERMISSION_MANAGE],
        ], $current);
    }

    /**
     * The form, from the factory, with its builder registered the first time it is asked for
     *
     * Here and not while the plugin loads: the form factory is the web app's, and a plugin that
     * asks for it while loading fails in every `dpress` command.
     */
    protected function form(string $name, array $context): DpressForm {
        if (!$this->forms->has($name)) {
            $this->forms->add($name, [MusicForms::class, $name === MusicList::FORM_ALBUM ? 'album' : 'track']);
        }
        return $this->forms->create($name, $context);
    }

    // --- tracks ---

    #[Route('GET', '/admin/music')]
    public function tracks(): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $config = [
            'endpoint' => $this->router->url('/admin/music/list'),
            'orderBy'  => 'released_at',
            'orderDir' => 'desc',
            'columns'  => [
                'id'          => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                'cover_html'  => ['label' => 'Cover', 'view' => 'html', 'sortable' => false, 'width' => '54px'],
                'title'       => ['label' => 'Title', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'album'       => ['label' => 'Album', 'sortable' => false],
                'released_at' => ['label' => 'Released', 'view' => 'dateTime'],
                'slug'        => ['label' => 'Slug'],
            ],
            'rowActions'   => $this->deleteRowAction('/admin/music/delete/', MusicList::PERMISSION_MANAGE,
                'Delete this track? Its audio file and its cover stay in the library.'),
            'groupActions' => [],
        ];
        $config['firstPage'] = $this->trackPage(
            $this->firstPageContext($config, MusicQueries::TRACK_SORTABLE, ['search', 'album_id'])
        );
        return $this->admin(MusicList::VIEWS.':admin/tracks', [
            'title'       => 'Music',
            'new_url'     => $this->router->url('/admin/music/new'),
            'tabs'        => $this->musicTabs('tracks'),
            'albums'      => $this->albumOptions(),
            'list_id'     => 'music-track-list',
            'list_config' => $config,
        ]);
    }

    #[Route('GET', '/admin/music/list')]
    public function trackRows(): array {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        return $this->trackPage($this->list->context(MusicQueries::TRACK_SORTABLE, ['search', 'album_id']));
    }

    /**
     * One page of tracks, for the endpoint and for the screen that seeds its first page
     *
     * The fields are named, never the row handed over: a list answers with what its columns show.
     * A track whose file is gone says so in its title - it is the row somebody came here to find,
     * since the public list silently leaves it out.
     */
    protected function trackPage(array $context): array {
        $tracks = $this->music->tracks($context);
        $albums = $this->albumOptions();
        $mediaIds = [];
        foreach ($tracks as $track) {
            $mediaIds[] = (int)$track['audio_media_id'];
            $mediaIds[] = (int)($track['cover_media_id'] ?? 0);
        }
        $media = $this->media->findByIds($mediaIds);
        $rows = [];
        foreach ($tracks as $track) {
            $cover = $media[(int)($track['cover_media_id'] ?? 0)] ?? null;
            $missing = !isset($media[(int)$track['audio_media_id']]);
            $rows[] = [
                'id'          => (int)$track['id'],
                'cover_html'  => $cover === null ? '' : $this->mediaView->tag($cover, MusicService::COVER_SMALL),
                'title'       => $track['title'].($missing ? ' (the audio file is gone)' : ''),
                'album'       => (string)($albums[(int)($track['album_id'] ?? 0)] ?? ''),
                'released_at' => $track['released_at'],
                'slug'        => $track['slug'],
                'edit_url'    => $this->router->url('/admin/music/edit/'.$track['id']),
            ];
        }
        return $this->rows($rows, $this->music->countTracks($context));
    }

    #[Route('GET', '/admin/music/new')]
    #[Route('POST', '/admin/music/new')]
    public function createTrack(): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $form = $this->form(MusicList::FORM_TRACK, ['albums' => $this->albumOptions()]);
        if ($form->process() && $this->saveTrack($form, null)) {
            $this->done('/admin/music', 'Created.');
            return '';
        }
        return $this->editor('New track', $form, '/admin/music');
    }

    #[Route('GET', '/admin/music/edit/?')]
    #[Route('POST', '/admin/music/edit/?')]
    public function editTrack(string $id): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $track = $this->found($this->music->findTrack((int)$id));
        $form = $this->form(MusicList::FORM_TRACK, [
            'track'          => $track,
            'albums'         => $this->albumOptions(),
            'audio_preview'  => $this->preview($track->audio_media_id),
            'cover_preview'  => $this->preview($track->cover_media_id),
            'released_input' => $this->dates->input($track->released_at),
        ]);
        if ($form->process() && $this->saveTrack($form, $track)) {
            $this->done('/admin/music', 'Saved.');
            return '';
        }
        return $this->editor('Edit track', $form, '/admin/music');
    }

    #[Route('POST', '/admin/music/delete/?')]
    public function deleteTrack(string $id): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $this->requireAction();
        $this->music->deleteTrack($this->found($this->music->findTrack((int)$id)));
        $this->done('/admin/music', 'Deleted.');
        return '';
    }

    /**
     * Writes the track, or puts on the form why it could not be
     *
     * The date is read first and **stops the save** when it cannot be: `done()` redirects, and a
     * track saved with everything but the date somebody typed is half a save reported as a whole
     * one. What the service refuses - a file of the wrong kind, an album that is gone - comes
     * back as a message on the form the same way.
     *
     * @return bool whether it was written
     */
    protected function saveTrack(DpressForm $form, ?Track $track): bool {
        $values = $form->values();
        $data = [
            'audio_media_id' => (int)($values['audio_media_id'] ?? 0),
            'title'          => (string)($values['title'] ?? ''),
            'slug'           => (string)($values['slug'] ?? ''),
            'album_id'       => (int)($values['album_id'] ?? 0),
            'cover_media_id' => (int)($values['cover_media_id'] ?? 0),
        ];
        $typed = trim((string)($values['released_at'] ?? ''));
        if ($typed !== '') {
            $released = $this->dates->parse($typed);
            if ($released === null) {
                $form->addFieldError('released_at', 'That is not a date: write it as 2026-05-25, or 2026-05-25 22:14:49.');
                return false;
            }
            $data['released_at'] = $released;
        } elseif ($track !== null) {
            // emptied on purpose: "empty is now", as the field says
            $data['released_at'] = gmdate('Y-m-d H:i:s');
        }
        try {
            $form->handle(function () use ($track, $data) {
                if ($track === null) {
                    return $this->music->createTrack($data);
                }
                $this->music->updateTrack($track, $data);
                return $track;
            });
        } catch (DpressException $e) {
            $form->addError($e->getMessage());
            return false;
        }
        return true;
    }

    // --- albums ---

    #[Route('GET', '/admin/music/albums')]
    public function albums(): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $config = [
            'endpoint' => $this->router->url('/admin/music/albums/list'),
            'orderBy'  => 'title',
            'columns'  => [
                'id'         => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                'cover_html' => ['label' => 'Cover', 'view' => 'html', 'sortable' => false, 'width' => '54px'],
                'title'      => ['label' => 'Title', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'slug'       => ['label' => 'Slug'],
            ],
            'rowActions'   => $this->deleteRowAction('/admin/music/albums/delete/', MusicList::PERMISSION_MANAGE,
                'Delete this album? Its tracks stay, and stop belonging to an album.'),
            'groupActions' => [],
        ];
        $config['firstPage'] = $this->albumPage(
            $this->firstPageContext($config, MusicQueries::ALBUM_SORTABLE, ['search'])
        );
        return $this->admin(MusicList::VIEWS.':admin/albums', [
            'title'       => 'Music',
            'new_url'     => $this->router->url('/admin/music/albums/new'),
            'tabs'        => $this->musicTabs('albums'),
            'list_id'     => 'music-album-list',
            'list_config' => $config,
        ]);
    }

    #[Route('GET', '/admin/music/albums/list')]
    public function albumRows(): array {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        return $this->albumPage($this->list->context(MusicQueries::ALBUM_SORTABLE, ['search']));
    }

    protected function albumPage(array $context): array {
        $albums = $this->music->albums($context);
        $media = $this->media->findByIds(array_map(fn(array $album) => (int)($album['cover_media_id'] ?? 0), $albums));
        $rows = [];
        foreach ($albums as $album) {
            $cover = $media[(int)($album['cover_media_id'] ?? 0)] ?? null;
            $rows[] = [
                'id'         => (int)$album['id'],
                'cover_html' => $cover === null ? '' : $this->mediaView->tag($cover, MusicService::COVER_SMALL),
                'title'      => $album['title'],
                'slug'       => $album['slug'],
                'edit_url'   => $this->router->url('/admin/music/albums/edit/'.$album['id']),
            ];
        }
        return $this->rows($rows, $this->music->countAlbums($context));
    }

    #[Route('GET', '/admin/music/albums/new')]
    #[Route('POST', '/admin/music/albums/new')]
    public function createAlbum(): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $form = $this->form(MusicList::FORM_ALBUM, []);
        if ($form->process() && $this->saveAlbum($form, null)) {
            $this->done('/admin/music/albums', 'Created.');
            return '';
        }
        return $this->editor('New album', $form, '/admin/music/albums');
    }

    #[Route('GET', '/admin/music/albums/edit/?')]
    #[Route('POST', '/admin/music/albums/edit/?')]
    public function editAlbum(string $id): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $album = $this->found($this->music->findAlbum((int)$id));
        $form = $this->form(MusicList::FORM_ALBUM, [
            'album'         => $album,
            'cover_preview' => $this->preview($album->cover_media_id),
        ]);
        if ($form->process() && $this->saveAlbum($form, $album)) {
            $this->done('/admin/music/albums', 'Saved.');
            return '';
        }
        return $this->editor('Edit album', $form, '/admin/music/albums');
    }

    #[Route('POST', '/admin/music/albums/delete/?')]
    public function deleteAlbum(string $id): string {
        $this->requirePermission(MusicList::PERMISSION_MANAGE);
        $this->requireAction();
        $this->music->deleteAlbum($this->found($this->music->findAlbum((int)$id)));
        $this->done('/admin/music/albums', 'Deleted.');
        return '';
    }

    protected function saveAlbum(DpressForm $form, ?Album $album): bool {
        $values = $form->values();
        $data = [
            'title'          => (string)($values['title'] ?? ''),
            'slug'           => (string)($values['slug'] ?? ''),
            'cover_media_id' => (int)($values['cover_media_id'] ?? 0),
        ];
        try {
            $form->handle(function () use ($album, $data) {
                if ($album === null) {
                    return $this->music->createAlbum($data);
                }
                $this->music->updateAlbum($album, $data);
                return $album;
            });
        } catch (DpressException $e) {
            $form->addError($e->getMessage());
            return false;
        }
        return true;
    }

    // --- shared ---

    protected function editor(string $title, DpressForm $form, string $backRoute): string {
        return $this->admin(MusicList::VIEWS.':admin/edit', [
            'title'    => $title,
            'form'     => $form,
            'back_url' => $this->router->url($backRoute),
        ]);
    }

    /**
     * Every album, as the options of a select: id => title
     *
     * @return array<int, string>
     */
    protected function albumOptions(): array {
        $options = [];
        foreach ($this->music->albums() as $album) {
            $options[(int)$album['id']] = (string)$album['title'];
        }
        return $options;
    }

    /**
     * What a media field shows for the item already chosen: the picture, or the file's name
     *
     * Rendered here because a form builder is handed an array and asks no service anything. An
     * audio file has no thumbnail, so its name is the preview - escaped, since it is whatever
     * the file was called when somebody uploaded it.
     */
    protected function preview(?int $mediaId): string {
        $media = $mediaId === null || $mediaId < 1 ? null : $this->media->findById($mediaId);
        if ($media === null) {
            return '';
        }
        return $media->isImage()
            ? $this->mediaView->tag($media, MusicService::COVER_SMALL)
            : htmlspecialchars($media->label(), ENT_QUOTES);
    }
}
