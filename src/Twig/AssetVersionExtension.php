<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Cache-busting for the static assets served straight from public/ (this project has no Webpack
 * Encore/asset pipeline). {@see assetVer()} appends the file's last-modified time as a query string,
 * so a changed CSS/JS gets a new URL and the browser fetches it instead of serving a stale cached
 * copy — the recurring "I changed help.js but the browser shows the old one" problem.
 *
 * Use in templates as {@code <script src="{{ asset_ver('/js/help.js') }}"></script>}.
 */
class AssetVersionExtension extends AbstractExtension
{
    /**
     * Memoised path → versioned-path, so each file's mtime is read once per request.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * @param string $publicDir absolute path to the web root (public/), where the assets live
     */
    public function __construct(private readonly string $publicDir)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_ver', $this->assetVer(...)),
        ];
    }

    /**
     * The asset path with a {@code ?v=<mtime>} suffix. Falls back to the bare path if the file does
     * not exist (so a typo or a missing file never breaks the page, just loses cache-busting).
     *
     * @param string $path the absolute-from-web-root asset path, e.g. "/js/help.js"
     *
     * @return string the same path with a version query string when the file can be stat'd
     */
    public function assetVer(string $path): string
    {
        if (!isset($this->cache[$path])) {
            $file = $this->publicDir.$path;
            $mtime = is_file($file) ? filemtime($file) : false;
            $this->cache[$path] = false !== $mtime ? $path.'?v='.$mtime : $path;
        }

        return $this->cache[$path];
    }
}
