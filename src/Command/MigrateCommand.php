<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sulu\Bundle\PageBundle\Document\PageDocument;
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
 * Bron: MySQL-dump van `ID137795_prodacsac` (geladen in een tijdelijke DB,
 *       connectie via --source-dsn, bv. mysql://user:pass@127.0.0.1:3306/acs_legacy).
 * Doel: Sulu PHPCR-documenten in de webspace `acs` (locale nl).
 *
 * STATUS: skelet. Pagina-boom + basis-mapping staan; per-blok veld-mapping
 *         en media-koppeling worden iteratief aangevuld (zie MIGRATION-BLUEPRINT.md).
 */
#[AsCommand(name: 'app:migrate', description: 'Migreer legacy ACS-CMS naar Sulu')]
final class MigrateCommand extends Command
{
    private const WEBSPACE = 'acs';
    private const LOCALE = 'nl';

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
        // ... resterende 42 tags, zie blok-inventaris
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

        // 1) Pagina's ophalen in boom-volgorde (nested set: lft).
        $sql = 'SELECT p.*, t.name, t.url, t.full_slug, t.meta_title, t.meta_description
                FROM page p
                LEFT JOIN page_translation t ON t.page_id = p.id AND t.language_id = :loc
                ORDER BY p.lft ASC';
        $pages = $source->executeQuery($sql, ['loc' => self::LOCALE])->fetchAllAssociative();
        if ($limit > 0) {
            $pages = \array_slice($pages, 0, $limit);
        }
        $io->writeln(\sprintf('%d pagina\'s gevonden.', \count($pages)));

        // legacy page.id => Sulu document uuid, voor parent-koppeling.
        $idToUuid = [];
        $created = 0;

        foreach ($pages as $row) {
            $legacyId = (int) $row['id'];
            $title = (string) ($row['name'] ?? 'Zonder titel');
            $slug = $this->resolveUrl($row);

            $io->writeln(\sprintf('  - [%d] %s  (%s)', $legacyId, $title, $slug));

            if ($dryRun) {
                continue;
            }

            /** @var PageDocument $doc */
            $doc = $this->documentManager->create('page');
            $doc->setTitle($title);
            $doc->setResourceSegment($slug);
            $doc->setStructureType('content');
            $doc->setWorkflowStage(
                (int) $row['active'] === 1 ? WorkflowStage::PUBLISHED : WorkflowStage::TEST
            );

            $structure = $doc->getStructure();
            $structure->bind([
                'title' => $title,
                'url' => $slug,
                'blocks' => $this->mapBlocks($source, $legacyId),
            ], false);

            // Extension: SEO (meta_title / meta_description)
            $doc->setExtensionsData([
                'seo' => [
                    'title' => (string) ($row['meta_title'] ?? ''),
                    'description' => (string) ($row['meta_description'] ?? ''),
                ],
            ]);

            $parentUuid = isset($row['parent_id'], $idToUuid[(int) $row['parent_id']])
                ? $idToUuid[(int) $row['parent_id']]
                : null;

            $this->documentManager->persist($doc, self::LOCALE, [
                'parent_path' => $parentUuid ? null : '/cmf/' . self::WEBSPACE . '/contents',
                'parent_document' => $parentUuid,
            ]);
            $this->documentManager->publish($doc, self::LOCALE);

            $idToUuid[$legacyId] = $doc->getUuid();
            ++$created;
        }

        $this->documentManager->flush();

        $io->success(\sprintf('%d pagina\'s gemigreerd.', $created));

        return Command::SUCCESS;
    }

    /**
     * Bouwt de Sulu block-array uit page_block (incl. nesting via parent_id),
     * met uitgelezen (PHP-geserialiseerde) velden.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapBlocks(Connection $source, int $pageId): array
    {
        $rows = $source->executeQuery(
            'SELECT * FROM page_block WHERE page_id = :pid AND parent_id IS NULL ORDER BY sort_order ASC',
            ['pid' => $pageId]
        )->fetchAllAssociative();

        $blocks = [];
        foreach ($rows as $row) {
            $tag = (string) $row['tag'];
            $type = self::BLOCK_MAP[$tag] ?? null;
            if (null === $type) {
                continue; // nog niet geport; wordt aangevuld
            }

            $fields = $this->unserializeFields($row['fields'] ?? null);
            $block = ['type' => $type];
            foreach ($fields as $key => $value) {
                // velden komen 1:1 over; media-refs worden later naar Sulu media-id's vertaald
                $block[$key] = $this->normalizeFieldValue($value);
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * Legacy `fields` is een PHP-geserialiseerde array, soms per-taal:
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

    private function normalizeFieldValue(mixed $value): mixed
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (null === $value) {
            return '';
        }

        return $value;
    }

    private function resolveUrl(array $row): string
    {
        $slug = (string) ($row['full_slug'] ?? $row['url'] ?? '');
        $slug = '/' . \ltrim($slug, '/');

        return '/' === $slug ? '/' : \rtrim($slug, '/');
    }

    private function connect(string $dsn): Connection
    {
        return DriverManager::getConnection(['url' => $dsn]);
    }
}
