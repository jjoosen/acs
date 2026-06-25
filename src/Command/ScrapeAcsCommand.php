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
 * Scrapet per pagina de hero-/headerafbeelding van de live acs.be en downloadt
 * die lokaal. Schrijft een map slug => lokaal-pad naar var/scraped-heroes.json.
 * De Twig-functie scraped_hero() toont ze in de header.
 *
 * Bedoeld om op de GitHub-runner te draaien (die acs.be wél kan bereiken),
 * waarna public/scraped/ + var/scraped-heroes.json naar de server gersynct worden.
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'app:scrape-acs', description: 'Download hero-afbeeldingen van acs.be per pagina')]
final class ScrapeAcsCommand extends Command
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

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

        $map = [];
        $ok = 0;
        $miss = 0;
        foreach ($slugs as $slug) {
            try {
                $html = $this->fetch($base . $slug);
            } catch (\Throwable $e) {
                ++$miss;
                continue;
            }
            if (null === $html) {
                ++$miss;
                continue;
            }
            $imgUrl = $this->extractHero($html, $base);
            if (null === $imgUrl) {
                ++$miss;
                continue;
            }
            $local = $this->download($imgUrl, $out);
            if (null === $local) {
                ++$miss;
                continue;
            }
            // pad relatief t.o.v. public/ -> begint met /scraped/...
            $map[$slug] = '/' . \ltrim(\substr($local, \strlen('public/')), '/');
            ++$ok;
            if (0 === $ok % 20) {
                $io->writeln("  ... $ok gedownload");
            }
        }

        @mkdir('var', 0775, true);
        \file_put_contents('var/scraped-heroes.json', \json_encode($map, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $io->success(\sprintf('%d hero-beelden gedownload, %d zonder beeld. Map: var/scraped-heroes.json', $ok, $miss));

        return Command::SUCCESS;
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
        // 1) header__video-container met background-image
        if (\preg_match('#header__video-container[^>]*background-image:\s*url\((["\']?)([^"\')]+)\1\)#i', $html, $m)) {
            return $this->abs($m[2], $base);
        }
        // 2) eerste <img> binnen <header ...> ... </header>
        if (\preg_match('#<header\b[^>]*>(.*?)</header>#is', $html, $h)
            && \preg_match('#<img[^>]+src=(["\'])([^"\']+)\1#i', $h[1], $m)) {
            return $this->abs($m[2], $base);
        }
        // 3) og:image
        if (\preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=(["\'])([^"\']+)\1#i', $html, $m)) {
            return $this->abs($m[2], $base);
        }

        return null;
    }

    private function abs(string $url, string $base): string
    {
        if (\str_starts_with($url, 'http')) {
            return $url;
        }

        return $base . '/' . \ltrim($url, '/');
    }

    private function download(string $url, string $out): ?string
    {
        try {
            $resp = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => self::UA],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);
            if (200 !== $resp->getStatusCode()) {
                return null;
            }
            $data = $resp->getContent(false);
        } catch (\Throwable $e) {
            return null;
        }
        if ('' === $data || \strlen($data) < 200) {
            return null;
        }
        $ext = \pathinfo(\parse_url($url, \PHP_URL_PATH) ?: '', \PATHINFO_EXTENSION) ?: 'jpg';
        $name = \substr(\sha1($url), 0, 16) . '.' . \strtolower($ext);
        $path = $out . '/' . $name;
        \file_put_contents($path, $data);

        return $path;
    }
}
