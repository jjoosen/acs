<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scrapet de live acs.be en downloadt alle relevante beelden lokaal.
 *
 * Twee resultaten per pagina:
 *   1. de hero-/headerafbeelding        -> var/scraped-heroes.json (slug => url)
 *   2. alle blok-afbeeldingen per blok  -> var/block-images.json   (block_id => [url,...])
 *
 * De legacy /upload/...-paden bestaan niet meer op acs.be (404); de live site
 * serveert beelden via een eigen (resize-)pad. We lezen daarom de echte URL's
 * rechtstreeks uit de gerenderde HTML. Elke afbeelding wordt gekoppeld aan het
 * dichtstbijzijnde voorafgaande `id="block-<id>"`, exact zoals de oude
 * ACS-templates die uitschrijven — zo weten we wélke afbeelding bij wélk blok
 * (en welk child-blok) hoort.
 *
 * Bedoeld om op de GitHub-runner te draaien (die acs.be wél kan bereiken),
 * waarna public/uploads/scraped/ + de twee json-maps naar de server gersynct
 * worden.
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'app:scrape-acs', description: 'Download hero- én blok-afbeeldingen van acs.be')]
final class ScrapeAcsCommand extends Command
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** @var array<string, ?string> url => lokaal pad (download-cache over pagina's heen) */
    private array $downloaded = [];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sqlite', null, InputOption::VALUE_REQUIRED, 'Pad naar legacy sqlite (slugs)', 'deploy/seed/legacy.db')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Basis-URL live site', 'https://www.acs.be')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output-map voor beelden', 'public/uploads/scraped')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max pagina\'s', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sqlite = (string) $input->getOption('sqlite');
        $base = \rtrim((string) $input->getOption('base'), '/');
        $out = \rtrim((string) $input->getOption('out'), '/');
        $limit = (int) $input->getOption('limit');

        if (!\is_file($sqlite)) {
            $io->error("Sqlite niet gevonden: $sqlite");

            return Command::FAILURE;
        }
        @mkdir($out, 0775, true);

        $pdo = new \PDO('sqlite:' . $sqlite);
        $rows = $pdo->query(
            "SELECT p.homepage, t.full_slug, t.url
             FROM page p INNER JOIN page_translation t ON t.page_id=p.id AND t.language_id='nl'
             WHERE p.active=1"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $slugs = [];
        foreach ($rows as $r) {
            $slug = 1 === (int) $r['homepage'] ? '/' : '/' . \ltrim((string) ($r['full_slug'] ?: $r['url']), '/');
            $slug = '/' === $slug ? '/' : \rtrim($slug, '/');
            $slugs[$slug] = true;
        }
        $slugs = \array_keys($slugs);
        if ($limit > 0) {
            $slugs = \array_slice($slugs, 0, $limit);
        }
        $io->writeln(\sprintf('%d pagina\'s te scrapen op %s', \count($slugs), $base));

        $heroMap = [];
        $blockImages = [];
        $okHero = 0;
        $imgCount = 0;
        $pagesDone = 0;
        foreach ($slugs as $slug) {
            try {
                $html = $this->fetch($base . $slug);
            } catch (\Throwable $e) {
                continue;
            }
            if (null === $html) {
                continue;
            }
            ++$pagesDone;

            // 1) hero (page-brede fallback)
            $imgUrl = $this->extractHero($html, $base);
            if (null !== $imgUrl) {
                $local = $this->download($imgUrl, $out);
                if (null !== $local) {
                    $heroMap[$slug] = $this->publicUrl($local);
                    ++$okHero;
                }
            }

            // 2) per blok-id alle beelden
            foreach ($this->extractBlockImages($html, $base) as $blockId => $urls) {
                foreach ($urls as $u) {
                    $local = $this->download($u, $out);
                    if (null === $local) {
                        continue;
                    }
                    $pub = $this->publicUrl($local);
                    $blockImages[$blockId] ??= [];
                    if (!\in_array($pub, $blockImages[$blockId], true)) {
                        $blockImages[$blockId][] = $pub;
                        ++$imgCount;
                    }
                }
            }

            if (0 === $pagesDone % 25) {
                $io->writeln("  ... $pagesDone pagina's, $imgCount blok-beelden, $okHero hero's");
            }
        }

        @mkdir('var', 0775, true);
        \file_put_contents('var/scraped-heroes.json', \json_encode($heroMap, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        \ksort($blockImages);
        \file_put_contents('var/block-images.json', \json_encode($blockImages, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        $io->success(\sprintf(
            '%d pagina\'s, %d hero-beelden, %d blok-beelden over %d blokken. '
            . 'Maps: var/scraped-heroes.json + var/block-images.json',
            $pagesDone, $okHero, $imgCount, \count($blockImages)
        ));

        return Command::SUCCESS;
    }

    private function publicUrl(string $localPath): string
    {
        return '/' . \ltrim(\substr($localPath, \strlen('public/')), '/');
    }

    private function fetch(string $url): ?string
    {
        $resp = $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => self::UA, 'Accept' => 'text/html'],
            'timeout' => 25,
            'max_redirects' => 5,
        ]);
        if (200 !== $resp->getStatusCode()) {
            return null;
        }

        return $resp->getContent(false);
    }

    /** Haalt de hero-achtergrond uit de header (background-image:url(...)) of og:image. */
    private function extractHero(string $html, string $base): ?string
    {
        if (\preg_match('#header__video-container[^>]*background-image:\s*url\((["\']?)([^"\')]+)\1\)#i', $html, $m)) {
            return $this->abs($m[2], $base);
        }
        if (\preg_match('#<header\b[^>]*>(.*?)</header>#is', $html, $h)
            && \preg_match('#<img[^>]+src=(["\'])([^"\']+)\1#i', $h[1], $m)) {
            return $this->abs($m[2], $base);
        }
        if (\preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=(["\'])([^"\']+)\1#i', $html, $m)) {
            return $this->abs($m[2], $base);
        }

        return null;
    }

    /**
     * Verzamelt per blok-id de afbeeldings-URL's, in documentvolgorde. Elke
     * gevonden afbeelding wordt toegewezen aan het dichtstbijzijnde
     * voorafgaande `id="block-<id>"`. Child-blokken renderen hun beelden binnen
     * de id-zone van hun parent, dus die belanden (in volgorde) bij de parent —
     * de migratie verdeelt ze weer over de children.
     *
     * @return array<int, list<string>>
     */
    private function extractBlockImages(string $html, string $base): array
    {
        // Alle blok-ankers met positie.
        \preg_match_all('#id=["\']block-(\d+)["\']#i', $html, $am, \PREG_OFFSET_CAPTURE);
        $anchors = [];
        foreach ($am[1] as $i => $cap) {
            $anchors[] = ['id' => (int) $cap[0], 'pos' => $am[0][$i][1]];
        }
        if ([] === $anchors) {
            return [];
        }

        // Alle afbeeldingen met positie: <img src|data-src> en background-image:url().
        $images = [];
        if (\preg_match_all('#<img\b[^>]*?\b(?:data-src|src)=(["\'])([^"\']+)\1#i', $html, $im, \PREG_OFFSET_CAPTURE)) {
            foreach ($im[2] as $cap) {
                $images[] = ['url' => $cap[0], 'pos' => $cap[1]];
            }
        }
        if (\preg_match_all('#background-image:\s*url\((["\']?)([^"\')]+)\1\)#i', $html, $bm, \PREG_OFFSET_CAPTURE)) {
            foreach ($bm[2] as $cap) {
                $images[] = ['url' => $cap[0], 'pos' => $cap[1]];
            }
        }
        if ([] === $images) {
            return [];
        }
        \usort($images, static fn ($a, $b) => $a['pos'] <=> $b['pos']);

        $result = [];
        foreach ($images as $img) {
            $url = $img['url'];
            // sla data-URI's, svg-iconen en sprites/placeholders over
            if (\str_starts_with($url, 'data:')
                || \str_contains($url, 'placeholder')
                || \str_ends_with(\strtolower(\parse_url($url, \PHP_URL_PATH) ?: ''), '.svg')) {
                continue;
            }
            // dichtstbijzijnde voorafgaande blok-anker
            $owner = null;
            foreach ($anchors as $a) {
                if ($a['pos'] <= $img['pos']) {
                    $owner = $a['id'];
                } else {
                    break;
                }
            }
            if (null === $owner) {
                continue;
            }
            $abs = $this->abs($url, $base);
            $result[$owner] ??= [];
            if (!\in_array($abs, $result[$owner], true)) {
                $result[$owner][] = $abs;
            }
        }

        return $result;
    }

    private function abs(string $url, string $base): string
    {
        if (\str_starts_with($url, 'http')) {
            return $url;
        }
        if (\str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $base . '/' . \ltrim($url, '/');
    }

    private function download(string $url, string $out): ?string
    {
        if (\array_key_exists($url, $this->downloaded)) {
            return $this->downloaded[$url];
        }
        $result = null;
        try {
            $resp = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);
            if (200 === $resp->getStatusCode()) {
                $data = $resp->getContent(false);
                if ('' !== $data && \strlen($data) >= 200) {
                    $ext = \pathinfo(\parse_url($url, \PHP_URL_PATH) ?: '', \PATHINFO_EXTENSION) ?: 'jpg';
                    $ext = \strtolower(\preg_replace('#[^a-z0-9]#i', '', $ext) ?: 'jpg');
                    $name = \substr(\sha1($url), 0, 16) . '.' . $ext;
                    $path = $out . '/' . $name;
                    \file_put_contents($path, $data);
                    $result = $path;
                }
            }
        } catch (\Throwable $e) {
            $result = null;
        }
        $this->downloaded[$url] = $result;

        return $result;
    }
}
