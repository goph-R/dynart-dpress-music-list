<?php

namespace Dynart\MusicList\Test;

use Dynart\Dpress\Content\Shortcodes;
use Dynart\MusicList\MusicList;
use Dynart\MusicList\MusicListPlugin;
use PHPUnit\Framework\TestCase;

/**
 * What the plugin declares, checked against the files it declares it about
 *
 * A declaration is a list of names, and a name that drifts from the file or the class it means
 * fails late and quietly: a stylesheet that never loads, a shortcode whose handler the container
 * cannot build. These are the cheap ways of finding that out early.
 *
 * @covers \Dynart\MusicList\MusicListPlugin
 */
class MusicListPluginTest extends TestCase {

    private function root(): string {
        return dirname(__DIR__);
    }

    /**
     * The script and the stylesheet are loaded by a substring of the finished page. If the
     * template stops writing it, the player arrives unstyled and silent and nothing says why
     */
    public function testThePlayerWritesTheAttributeItsAssetsAreLoadedBy(): void {
        $template = (string)file_get_contents($this->root().'/views/player.phtml');
        $this->assertStringContainsString(' '.MusicList::NEEDLE, $template);
        foreach ((new MusicListPlugin())->pageAssets() as $file => $needle) {
            $this->assertSame(MusicList::NEEDLE, $needle, $file);
            $this->assertFileExists($this->root().'/assets/'.$file);
        }
    }

    /**
     * A handler, a controller and a command are Micro callables: the container builds the class
     * when it is needed, and can only build what `services()` told it about
     */
    public function testEveryClassTheContainerHasToBuildIsAService(): void {
        $plugin = new MusicListPlugin();
        $services = $plugin->services();
        [$handler, $kind] = $plugin->shortcodes()[MusicList::SHORTCODE];
        $this->assertArrayHasKey($handler[0], $services);
        $this->assertTrue(method_exists($handler[0], $handler[1]));
        $this->assertSame(Shortcodes::BLOCK, $kind, 'a player cannot sit inside a paragraph');

        foreach ($plugin->commands() as $name => $command) {
            $this->assertArrayHasKey($command['callable'][0], $services, $name);
            $this->assertTrue(method_exists(...$command['callable']), $name);
        }
    }

    public function testTheAdminSectionHasItsIconAndItsPermission(): void {
        $plugin = new MusicListPlugin();
        $section = $plugin->adminSections()[MusicList::ADMIN_SECTION];
        $this->assertFileExists($this->root().'/'.$section['icon']);
        $this->assertArrayHasKey($section['permission'], $plugin->permissions());
    }

    public function testTheViewFolderIsThere(): void {
        $views = (new MusicListPlugin())->views();
        $this->assertDirectoryExists($views[MusicList::VIEWS]);
        foreach (['player', 'admin/tracks', 'admin/albums', 'admin/edit'] as $template) {
            $this->assertFileExists($views[MusicList::VIEWS].'/'.$template.'.phtml');
        }
    }

    /**
     * The select the player shows and the orders the script knows have to be the same four
     */
    public function testEveryOrderIsOfferedAndUnderstood(): void {
        $template = (string)file_get_contents($this->root().'/views/player.phtml');
        $script = (string)file_get_contents($this->root().'/assets/music-list.js');
        foreach (MusicList::SORTS as $sort) {
            $this->assertStringContainsString("'".$sort."'", $template, $sort);
            $this->assertStringContainsString("'".$sort."'", $script, $sort);
        }
    }

    public function testTheManifestNamesThePluginClass(): void {
        $manifest = parse_ini_file($this->root().'/plugin.ini');
        $this->assertSame(MusicListPlugin::class, $manifest['class']);
        $this->assertStringStartsWith($manifest['namespace'].'\\', MusicListPlugin::class);
    }

    /**
     * The full-window page: a name a page can store, and a view that is really in the folder
     * `views()` names - the two are declared in different methods and nothing else ties them
     */
    public function testThePageTemplateNamesAViewThatExists(): void {
        $plugin = new MusicListPlugin();
        $templates = $plugin->pageTemplates();
        $this->assertSame([MusicList::PAGE_TEMPLATE], array_keys($templates));
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,63}$/', MusicList::PAGE_TEMPLATE);
        [$namespace, $view] = explode(':', $templates[MusicList::PAGE_TEMPLATE]['template']);
        $this->assertFileExists($plugin->views()[$namespace].'/'.$view.'.phtml');
        $this->assertNotSame('', $templates[MusicList::PAGE_TEMPLATE]['label']);
    }

    /** A page holding one shortcode is all lead, and a template printing only the body is empty */
    public function testThePageTemplatePrintsTheLeadAndHasAHeadForTheAssets(): void {
        $source = (string)file_get_contents(dirname(__DIR__).'/views/page-player.phtml');
        $this->assertStringContainsString('$content->lead_html', $source);
        $this->assertStringContainsString('</head>', $source);
    }
}
