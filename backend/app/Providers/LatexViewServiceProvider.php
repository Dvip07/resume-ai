<?php

namespace App\Providers;

use App\Services\Latex\LatexBladeCompiler;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;

/**
 * Wires the LaTeX template dialect into Laravel's view layer (Requirement 6.1).
 *
 * Two separate things are set up here, and they are separate on purpose:
 *
 *  1. **A second view engine** keyed to the `tex.blade.php` extension, backed by
 *     {@see LatexBladeCompiler}. Because the engine is selected by extension,
 *     `.blade.php` web views keep stock Blade delimiters and stock HTML escaping
 *     — nothing about this registration can affect them. That is the whole
 *     reason the alternative (mutating the shared `blade.compiler`) was rejected.
 *
 *  2. **A `latex` view namespace** over `resources/views/latex`. This is a
 *     containment measure, not convenience: LaTeX templates are `\input`-capable
 *     documents built to be compiled by an external binary, and they must never
 *     be reachable as an ordinary web view. Living under a namespace means they
 *     resolve only as `latex::resume` — a bare `view('resume')` cannot find
 *     them, and neither can anything that turns a user-supplied string into a
 *     view name.
 *
 * Registered in `bootstrap/providers.php`.
 */
class LatexViewServiceProvider extends ServiceProvider
{
    /**
     * The view engine key. Namespaced with a `latex.` prefix so it cannot
     * collide with the `blade`, `php` or `file` engines Laravel registers.
     */
    private const ENGINE = 'latex.blade';

    /**
     * The template file extension.
     *
     * Two dots is not a problem for the view finder: it matches names by
     * concatenating `name . '.' . extension`, so `latex::resume` finds
     * `resume.tex.blade.php`. Keeping `.tex` in the name is what makes the file
     * open as LaTeX in an editor, which is the point of using real `.tex`
     * sources instead of a string-building service.
     */
    private const EXTENSION = 'tex.blade.php';

    public function register(): void
    {
        // The compiler is a singleton because it owns the compiled-view cache
        // bookkeeping; one per worker, mirroring how Laravel binds
        // `blade.compiler`. Constructor arguments follow the framework's own
        // BladeCompiler registration so cache invalidation, the compiled path
        // and relative-hash behaviour match stock Blade.
        $this->app->singleton(LatexBladeCompiler::class, function ($app) {
            return new LatexBladeCompiler(
                $app['files'],
                $app['config']['view.compiled'],
                $app['config']->get('view.relative_hash', false) ? $app->basePath() : '',
                $app['config']->get('view.cache', true),
                $app['config']->get('view.compiled_extension', 'php'),
                $app['config']->get('view.check_cache_timestamps', true),
            );
        });
    }

    public function boot(): void
    {
        $factory = $this->app['view'];

        // Resolved lazily by the engine resolver: nothing here touches the
        // filesystem or builds a compiler unless a LaTeX view is actually
        // rendered, so a plain web request pays nothing for this provider.
        $factory->addExtension(self::EXTENSION, self::ENGINE, function () {
            return new CompilerEngine(
                $this->app->make(LatexBladeCompiler::class),
                $this->app['files'],
            );
        });

        $factory->addNamespace('latex', resource_path('views/latex'));
    }
}
