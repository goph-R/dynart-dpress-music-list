<?php

namespace Dynart\MusicList;

use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Form\DpressForm;

/**
 * The two editors' forms, built through the form factory
 *
 * Through the factory for the reason the core's are: a form built with `new` is one no other
 * plugin can ever add a field to. The context carries whatever needs a service to work out - the
 * album options, the previews, the date as it should be shown - because a builder is handed a
 * form and an array and asks nothing itself.
 */
class MusicForms {

    /**
     * @param array $context `track` (or none for a new one), `albums` (id => title),
     *                       `audio_preview`, `cover_preview` (markup), `released_input`
     */
    public function track(DpressForm $form, array $context): void {
        $track = $context['track'] ?? null;
        $form->addFields([
            // `category` narrows the picker to the one kind of file the field can use
            // (dpress 0.96.0; an older one ignores it and offers the whole library)
            'audio_media_id' => ['type' => 'media', 'label' => 'Audio file',
                                 'category' => Media::CATEGORY_AUDIO,
                                 'preview' => (string)($context['audio_preview'] ?? '')],
        ]);
        $form->addFields([
            'title' => ['type' => 'text', 'label' => 'Title', 'required' => false,
                        'description' => 'Empty takes the name of the file.'],
            'slug'  => ['type' => 'text', 'label' => 'Slug', 'required' => false,
                        'description' => 'What a share link names the track by. Changing it breaks'
                            .' the links already shared.'],
            'album_id' => ['type' => 'select', 'label' => 'Album', 'required' => false,
                           'options' => ['' => '(none)'] + ($context['albums'] ?? [])],
            'cover_media_id' => ['type' => 'media', 'label' => 'Cover', 'required' => false,
                                 'category' => Media::CATEGORY_IMAGE,
                                 'preview' => (string)($context['cover_preview'] ?? ''),
                                 'description' => 'Without one the track shows its album\'s cover,'
                                     .' and without that a drawn placeholder.'],
            'released_at' => ['type' => 'text', 'label' => 'Released', 'required' => false,
                              'description' => 'What "newest first" sorts by: `2026-05-25`, or with'
                                  .' a time, `2026-05-25 22:14:49`. Empty is now.'],
        ], false);
        if ($track !== null) {
            $form->addValues([
                'audio_media_id' => (string)$track->audio_media_id,
                'title'          => $track->title,
                'slug'           => $track->slug,
                'album_id'       => (string)($track->album_id ?? ''),
                'cover_media_id' => (string)($track->cover_media_id ?? ''),
                'released_at'    => (string)($context['released_input'] ?? ''),
            ]);
        }
    }

    /**
     * @param array $context `album` (or none), `cover_preview`
     */
    public function album(DpressForm $form, array $context): void {
        $album = $context['album'] ?? null;
        $form->addFields(['title' => ['type' => 'text', 'label' => 'Title']]);
        $form->addFields([
            'slug' => ['type' => 'text', 'label' => 'Slug', 'required' => false,
                       'description' => 'What `{{ music_list(album=\'...\') }}` names the album by.'],
            'cover_media_id' => ['type' => 'media', 'label' => 'Cover', 'required' => false,
                                 'category' => Media::CATEGORY_IMAGE,
                                 'preview' => (string)($context['cover_preview'] ?? ''),
                                 'description' => 'Shown by a track of the album that has no cover'
                                     .' of its own.'],
        ], false);
        if ($album !== null) {
            $form->addValues([
                'title'          => $album->title,
                'slug'           => $album->slug,
                'cover_media_id' => (string)($album->cover_media_id ?? ''),
            ]);
        }
    }
}
