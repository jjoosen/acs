<?php

declare(strict_types=1);

namespace App\Command;

use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bulk-vertaling: vertaalt ALLE pagina's in één keer van een bron-taal naar een
 * doel-taal via DeepL (titels, teksten én alle blok-velden, met HTML-behoud).
 *
 * Gebruik: bin/console app:translate fr [--source=nl] [--limit=0] [--overwrite]
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'app:translate', description: 'Vertaalt alle content naar een doeltaal via DeepL')]
final class TranslateCommand extends Command
{
    private const HOME_PATH = '/cmf/acs/contents';

    /** @var array<string,string> in-memory vertaalcache (zelfde tekst niet 2x) */
    private array $cache = [];

    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        private readonly HttpClientInterface $httpClient,
        private readonly string $deeplApiKey = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'Doeltaal (bv. fr, en)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Brontaal', 'nl')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max aantal pagina\'s (test)', '0')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Bestaande vertalingen overschrijven');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = \strtolower((string) $input->getArgument('target'));
        $source = \strtolower((string) $input->getOption('source'));
        $limit = (int) $input->getOption('limit');
        $overwrite = (bool) $input->getOption('overwrite');

        if ('' === $this->deeplApiKey) {
            $io->error('DEEPL_API_KEY ontbreekt. Stel die in als environment-variabele/secret.');

            return Command::FAILURE;
        }
        if ($target === $source) {
            $io->error('Doeltaal mag niet gelijk zijn aan brontaal.');

            return Command::FAILURE;
        }

        $io->title(\sprintf('Bulk-vertaling %s -> %s (DeepL)', $source, $target));

        // Alle pagina's onder de homepage ophalen (PHPCR SQL2).
        $query = $this->documentManager->createQuery(
            "SELECT * FROM [nt:unstructured] AS p WHERE [jcr:mixinTypes] = 'sulu:page'",
            $source
        );
        /** @var object[] $docs */
        $docs = $query->execute();
        if ($limit > 0) {
            $docs = \array_slice($docs, 0, $limit);
        }
        $io->writeln(\sprintf('%d pagina\'s gevonden.', \count($docs)));

        $done = 0;
        $skipped = 0;
        foreach ($docs as $sourceDoc) {
            $uuid = $sourceDoc->getUuid();
            $title = (string) $sourceDoc->getTitle();

            try {
                // Bestaat de doel-taal al én niet overschrijven? -> skip.
                $targetDoc = $this->documentManager->find($uuid, $target);
                if (!$overwrite && $targetDoc->getTitle() && method_exists($targetDoc, 'getResourceSegment')
                    && $targetDoc->getResourceSegment()) {
                    ++$skipped;
                    continue;
                }

                $data = $sourceDoc->getStructure()->toArray();
                $translated = $this->translateData($data, $source, $target);

                $targetDoc->setStructureType($sourceDoc->getStructureType());
                $targetDoc->setTitle($translated['title'] ?? $title);
                if (method_exists($sourceDoc, 'getResourceSegment') && method_exists($targetDoc, 'setResourceSegment')) {
                    $targetDoc->setResourceSegment($sourceDoc->getResourceSegment());
                }
                if (method_exists($sourceDoc, 'getNavigationContexts') && method_exists($targetDoc, 'setNavigationContexts')) {
                    $targetDoc->setNavigationContexts($sourceDoc->getNavigationContexts() ?: []);
                }
                $targetDoc->setWorkflowStage(WorkflowStage::PUBLISHED);
                $targetDoc->getStructure()->bind($translated, false);

                // SEO mee vertalen.
                if (method_exists($sourceDoc, 'getExtensionsData')) {
                    $ext = $sourceDoc->getExtensionsData();
                    $seo = \is_array($ext['seo'] ?? null) ? $ext['seo'] : [];
                    $targetDoc->setExtensionsData([
                        'seo' => [
                            'title' => $this->t((string) ($seo['title'] ?? ''), $source, $target),
                            'description' => $this->t((string) ($seo['description'] ?? ''), $source, $target),
                        ],
                    ]);
                }

                $this->documentManager->persist($targetDoc, $target);
                $this->documentManager->publish($targetDoc, $target);
                $this->documentManager->flush();
                ++$done;
                if (0 === $done % 20) {
                    $io->writeln(\sprintf('  ... %d vertaald', $done));
                }
            } catch (\Throwable $e) {
                ++$skipped;
                $io->writeln(\sprintf('  ! overslaan %s (%s): %s', $title, $uuid, $e->getMessage()));
                $this->documentManager->clear();
            }
        }

        $io->success(\sprintf('%d vertaald, %d overgeslagen.', $done, $skipped));

        return Command::SUCCESS;
    }

    /**
     * Vertaalt recursief alle string-waarden in de structuur-array (titels,
     * teksten, blok-velden). HTML blijft behouden (DeepL tag_handling=html).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function translateData(array $data, string $source, string $target): array
    {
        $skipKeys = ['type', 'url', 'settings', 'youtube_id', 'iframe', 'bg_color', 'image', 'images', 'video'];

        $walk = function ($value, $key) use (&$walk, $source, $target, $skipKeys) {
            if (\is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = $walk($v, (string) $k);
                }

                return $out;
            }
            if (\is_string($value) && '' !== \trim($value)) {
                // Sla technische velden / niet-tekst over.
                if (\in_array($key, $skipKeys, true)) {
                    return $value;
                }
                // Sla pure getallen/ids/urls over.
                if (\preg_match('#^(/|https?://|\d+$)#', \trim($value)) && !\str_contains($value, ' ')) {
                    return $value;
                }

                return $this->t($value, $source, $target);
            }

            return $value;
        };

        return $walk($data, '');
    }

    /** Vertaalt één string via DeepL (met cache). */
    private function t(string $text, string $source, string $target): string
    {
        $text = (string) $text;
        if ('' === \trim($text)) {
            return $text;
        }
        $ck = $target . '|' . $text;
        if (isset($this->cache[$ck])) {
            return $this->cache[$ck];
        }

        $host = \str_ends_with($this->deeplApiKey, ':fx')
            ? 'https://api-free.deepl.com'
            : 'https://api.deepl.com';

        $resp = $this->httpClient->request('POST', $host . '/v2/translate', [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $this->deeplApiKey,
            ],
            'body' => [
                'text' => $text,
                'source_lang' => \strtoupper($source),
                'target_lang' => \strtoupper($target),
                'tag_handling' => 'html',
            ],
            'timeout' => 30,
        ]);
        $json = $resp->toArray(false);
        $out = (string) ($json['translations'][0]['text'] ?? $text);
        $this->cache[$ck] = $out;

        return $out;
    }
}
