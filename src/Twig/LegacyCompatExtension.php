<?php

declare(strict_types=1);

namespace App\Twig;

use Sulu\Bundle\MediaBundle\Api\Media;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Compat-laag voor de legacy Innomedio-BaseBundle theme.
 *
 * De overgezette theme (templates/frontend/*) gebruikt een handvol globale
 * Twig-functies die in het oude CMS door de BaseBundle werden geleverd:
 * setting(), img(), navigation(), view_file_link(), margin_bottom(), redirect(),
 * count_jobs(), latest_jobs().
 *
 * Hier worden ze Sulu-vriendelijk geherimplementeerd zodat de theme rendert.
 * Onderdelen die nog een echte databron nodig hebben (settings, navigatie,
 * jobs) geven voorlopig een veilige lege/standaardwaarde terug; ze worden
 * iteratief op Sulu-bronnen aangesloten (zie MIGRATION-BLUEPRINT.md, roadmap).
 */
final class LegacyCompatExtension extends AbstractExtension
{
    /**
     * @var array<string, mixed> in-memory settings (later: Sulu settings-snippet)
     */
    private array $settings;

    public function __construct()
    {
        // Minimale defaults; vervang door een echte settings-bron (snippet/DB).
        $this->settings = [
            'site_name' => 'ACS',
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('setting', [$this, 'setting']),
            new TwigFunction('img', [$this, 'img']),
            new TwigFunction('navigation', [$this, 'navigation']),
            new TwigFunction('scraped_hero', [$this, 'scrapedHero']),
            new TwigFunction('block_images', [$this, 'blockImages']),
            new TwigFunction('view_file_link', [$this, 'viewFileLink']),
            new TwigFunction('margin_bottom', [$this, 'marginBottom']),
            new TwigFunction('count_jobs', [$this, 'countJobs']),
            new TwigFunction('latest_jobs', [$this, 'latestJobs']),
        ];
    }

    /** Legacy: setting('key') -> waarde of null. */
    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }

    /**
     * Legacy: img(filename, width, height, type, position, quality) -> URL.
     *
     * In het oude CMS was dit een on-the-fly resize-endpoint op een
     * bestandsnaam. In Sulu zijn afbeeldingen media met vooraf gedefinieerde
     * formaten. We ondersteunen hier:
     *  - een Sulu Media-object  -> URL van het best passende formaat
     *  - een pad/URL-string     -> ongewijzigd teruggegeven (assets/og-image)
     *  - null/leeg              -> lege string (geen fatale fout)
     *
     * Echte filename->media-resolutie volgt met de media-import (roadmap).
     */
    public function img(
        mixed $image,
        int $width = 0,
        int $height = 0,
        string $type = 'fill',
        string $position = 'smart',
        int|string $quality = 85,
    ): string {
        if ($image instanceof Media) {
            $format = $this->pickFormat($width, $height);
            $url = $image->getThumbnails()[$format] ?? $image->getUrl();

            return (string) $url;
        }

        if (\is_string($image) && '' !== $image) {
            return $image; // pad of absolute URL: ongewijzigd
        }

        return '';
    }

    /**
     * @var array<string, array<int, mixed>>|null in-memory cache van var/navigation.json
     */
    private ?array $navData = null;

    /**
     * Legacy: navigation(tag, withChildren, onlyActive) -> lijst nav-items.
     *
     * Leest de door de migratie geëxporteerde menustructuur uit
     * var/navigation.json (gegroepeerd per legacy-tag: main/top/footer1/2/3...).
     * Elk item heeft: name, url, tag (custom_tag, bv. button/jobs), children.
     *
     * @return array<int, mixed>
     */
    public function navigation(string $tag, bool $withChildren = true, bool $onlyActive = true): array
    {
        if (null === $this->navData) {
            $path = \dirname(__DIR__, 2) . '/var/navigation.json';
            $this->navData = \is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];
        }

        return $this->navData[$tag] ?? [];
    }

    /**
     * @var array<string,string>|null cache van var/scraped-heroes.json
     */
    private ?array $heroes = null;

    /**
     * Geeft het gescrapte hero-beeld (van acs.be) voor het opgegeven pad, of ''.
     */
    public function scrapedHero(string $path): string
    {
        if (null === $this->heroes) {
            $f = \dirname(__DIR__, 2) . '/var/scraped-heroes.json';
            $this->heroes = \is_file($f)
                ? (json_decode((string) file_get_contents($f), true) ?: [])
                : [];
        }
        $key = '/' === $path ? '/' : \rtrim($path, '/');

        return (string) ($this->heroes[$key] ?? '');
    }

    /**
     * @var array<int, list<string>>|null cache van var/block-images.json
     */
    private ?array $blockImages = null;

    /**
     * Geeft de (gescrapete) afbeeldings-URL's van een legacy-blok terug, in
     * documentvolgorde. Gekoppeld op het legacy page_block.id, dat de migratie
     * in elk blok/child bewaart. Child-beelden staan bij hun parent-id; de
     * templates verdelen ze met de lus-index over de children.
     *
     * @return list<string>
     */
    public function blockImages(int|string|null $id): array
    {
        if (null === $id || '' === $id) {
            return [];
        }
        if (null === $this->blockImages) {
            $f = \dirname(__DIR__, 2) . '/var/block-images.json';
            $this->blockImages = \is_file($f)
                ? (json_decode((string) file_get_contents($f), true) ?: [])
                : [];
        }

        return $this->blockImages[(int) $id] ?? [];
    }

    /** Legacy: view_file_link(id) -> download/preview-URL. Stub tot media-import. */
    public function viewFileLink(int|string $id): string
    {
        return '#';
    }

    /** Legacy marge-helper: vaste Bootstrap-marge onderaan blokken. */
    public function marginBottom(): string
    {
        return 'mb-3 mb-lg-5';
    }

    /** Aantal vacatures; aansluiten op jobs-content-type (roadmap). */
    public function countJobs(): int
    {
        return 0;
    }

    /**
     * Laatste vacatures; aansluiten op jobs-content-type (roadmap).
     *
     * @return array<int, mixed>
     */
    public function latestJobs(int $limit = 5): array
    {
        return [];
    }

    /** Kies het Sulu-mediaformaat dat het dichtst bij de gevraagde maat ligt. */
    private function pickFormat(int $width, int $height): string
    {
        // Sulu-standaardformaten uit config/packages/sulu_media.yaml.
        if ($width <= 0) {
            return 'sulu-400x400';
        }
        if ($width <= 100) {
            return 'sulu-100x100';
        }
        if ($width <= 170) {
            return 'sulu-170x170';
        }
        if ($width <= 400) {
            return 'sulu-400x400';
        }

        return 'sulu-2400x'; // grootste standaardformaat
    }
}
