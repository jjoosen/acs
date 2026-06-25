<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migreert de legacy Innomedio BaseBundle CMS-database naar Sulu.
 *
 * Bron: MySQL-dump van `ID137795_prodacsac`, geladen in een DBAL-bron
 *       (MySQL of — in de sandbox — SQLite) via --source-dsn.
 * Doel: Sulu PHPCR-documenten in de webspace `acs` (locale nl).
 *
 * STATUS: pagina-boom + SEO + de geporte bloktypes worden gemigreerd.
 *         Nog niet: geneste child-blokken, media-koppeling en de bloktypes
 *         die nog geen Sulu-template hebben (zie MIGRATION-BLUEPRINT.md).
 */
#[AsCommand(name: 'app:migrate', description: 'Migreer legacy ACS-CMS naar Sulu')]
final class MigrateCommand extends Command
{
    private const WEBSPACE = 'acs';
    private const LOCALE = 'nl';
    private const HOME_PATH = '/cmf/acs/contents';

    /**
     * Legacy page_block.tag => Sulu block type (zoals gedefinieerd in content.xml).
     * Vul aan naarmate bloktypes geport worden.
     */
    private const BLOCK_MAP = [
        'text' => 'text',
        'banner' => 'banner',
        'text_image' => 'text_image',
        'usps' => 'usps',
        'image' => 'image',
        'quote' => 'quote',
        // ... resterende bloktypes, zie blok-inventaris in de blueprint
    ];

    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-dsn', null, InputOption::VALUE_REQUIRED, 'Doctrine DBAL DSN naar de geladen legacy-dump')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Toon wat er zou gebeuren, schrijf niets weg')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Beperk aantal pagina\'s (test)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dsn = (string) $input->getOption('source-dsn');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        if ('' === $dsn) {
            $io->error('Geef --source-dsn op (DBAL DSN naar de geladen legacy-dump).');

            return Command::FAILURE;
        }

        $source = $this->connect($dsn);
        $io->title('ACS legacy -> Sulu migratie' . ($dryRun ? ' (DRY-RUN)' : ''));

        // Pagina's in boom-volgorde (nested set: lft). Alleen met nl-vertaling.
        $sql = 'SELECT p.id, p.parent_id, p.active, p.homepage, p.content_type_tag,
                       t.name, t.url, t.full_slug, t.meta_title, t.meta_description
                FROM page p
                INNER JOIN page_translation t ON t.page_id = p.id AND t.language_id = :loc
                ORDER BY p.homepage DESC, p.lft ASC';
        $pages = $source->executeQuery($sql, ['loc' => self::LOCALE])->fetchAllAssociative();
        if ($limit > 0) {
            $pages = \array_slice($pages, 0, $limit);
        }
        $io->writeln(\sprintf('%d pagina\'s (met nl-vertaling) gevonden.', \count($pages)));

        $homeDocument = $this->documentManager->find(self::HOME_PATH, self::LOCALE);

        // legacy page.id => Sulu document (voor parent-koppeling).
        $idToDoc = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $blockStats = [];

        foreach ($pages as $row) {
            $legacyId = (int) $row['id'];
            $title = \trim((string) ($row['name'] ?? ''));
            if ('' === $title) {
                ++$skipped;
                continue;
            }
            $isHome = 1 === (int) $row['homepage'];
            $slug = $isHome ? '/' : $this->resolveUrl($row);

            $blocks = $this->mapBlocks($source, $legacyId, $blockStats);

            if ($dryRun) {
                $io->writeln(\sprintf('  - [%d] %s (%s) — %d blokken', $legacyId, $title, $slug, \count($blocks)));
                continue;
            }

            try {
                if ($isHome) {
                    /** @var \Sulu\Component\Content\Document\Behavior\StructureBehavior $doc */
                    $doc = $homeDocument;
                    $doc->setTitle($title);
                } else {
                    $doc = $this->documentManager->create('page');
                    $doc->setTitle($title);
                    $doc->setResourceSegment($slug);
                    $parent = $idToDoc[(int) $row['parent_id']] ?? $homeDocument;
                    $doc->setParent($parent);
                }

                $doc->setStructureType('content');
                $doc->setWorkflowStage(
                    1 === (int) $row['active'] ? WorkflowStage::PUBLISHED : WorkflowStage::TEST
                );

                $doc->getStructure()->bind([
                    'title' => $title,
                    'url' => $slug,
                    'blocks' => $blocks,
                ], false);

                $doc->setExtensionsData([
                    'seo' => [
                        'title' => (string) ($row['meta_title'] ?? ''),
                        'description' => (string) ($row['meta_description'] ?? ''),
                    ],
                ]);

                $this->documentManager->persist($doc, self::LOCALE, [
                    'parent_path' => self::HOME_PATH,
                ]);
                if (1 === (int) $row['active']) {
                    $this->documentManager->publish($doc, self::LOCALE);
                }
                $this->documentManager->flush();

                $idToDoc[$legacyId] = $doc;
                $isHome ? $updated++ : $created++;
            } catch (\Throwable $e) {
                ++$skipped;
                $io->writeln(\sprintf('  ! overslaan [%d] %s: %s', $legacyId, $title, $e->getMessage()));
                $this->documentManager->clear();
                $homeDocument = $this->documentManager->find(self::HOME_PATH, self::LOCALE);
            }
        }

        if (!$dryRun) {
            $this->documentManager->flush();
        }

        $io->section('Blok-mapping');
        foreach ($blockStats as $tag => $n) {
            $io->writeln(\sprintf('  %-22s %d', $tag, $n));
        }

        $io->success(\sprintf(
            '%d aangemaakt, %d bijgewerkt (home), %d overgeslagen.',
            $created, $updated, $skipped
        ));

        return Command::SUCCESS;
    }

    /**
     * Bouwt de Sulu block-array uit page_block: alleen actieve root-blokken
     * (parent_id IS NULL) van bloktypes die al een Sulu-template hebben.
     * Geneste children en media-velden volgen iteratief.
     *
     * @param array<string,int> $stats
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapBlocks(Connection $source, int $pageId, array &$stats): array
    {
        $rows = $source->executeQuery(
            'SELECT tag, fields FROM page_block
             WHERE page_id = :pid AND parent_id IS NULL AND active = 1
             ORDER BY sort_order ASC',
            ['pid' => $pageId]
        )->fetchAllAssociative();

        $blocks = [];
        foreach ($rows as $row) {
            $tag = (string) $row['tag'];
            $type = self::BLOCK_MAP[$tag] ?? null;
            $stats[$tag ?: '(leeg)'] = ($stats[$tag ?: '(leeg)'] ?? 0) + 1;
            if (null === $type) {
                continue; // bloktype nog niet geport
            }

            $fields = $this->unserializeFields($row['fields'] ?? null);
            $block = ['type' => $type];
            foreach ($fields as $key => $value) {
                if (\is_array($value)) {
                    continue; // media-/child-refs: nog niet gekoppeld
                }
                $block[$key] = $value;
            }
            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * Legacy `fields` is een PHP-geserialiseerde array, meestal per-taal:
     *   a:1:{s:5:"title";a:1:{s:2:"nl";s:5:"Hallo";}}
     * We platten naar de nl-waarde.
     *
     * @return array<string, mixed>
     */
    private function unserializeFields(mixed $raw): array
    {
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!\is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (\is_array($value) && \array_key_exists(self::LOCALE, $value)) {
                $out[$key] = $value[self::LOCALE];
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function resolveUrl(array $row): string
    {
        $slug = (string) ($row['full_slug'] ?? '');
        if ('' === $slug) {
            $slug = (string) ($row['url'] ?? '');
        }
        $slug = '/' . \ltrim($slug, '/');

        return '/' === $slug ? '/' : \rtrim($slug, '/');
    }

    private function connect(string $dsn): Connection
    {
        return DriverManager::getConnection(['url' => $dsn]);
    }
}
