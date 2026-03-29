<?php

declare(strict_types=1);

namespace Codeblick\NextcloudFal\Command;

use Codeblick\NextcloudFal\Client\NextcloudClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class TestConnectionCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Test the NextCloud WebDAV connection for a configured storage')
            ->addOption('storage', 's', InputOption::VALUE_REQUIRED, 'UID of the sys_file_storage record');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('NextCloud FAL – Connection Test');

        $storageUid = (int)$input->getOption('storage');
        if ($storageUid === 0) {
            // Find all NextcloudFal storages
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_file_storage');
            $rows = $connection->select(
                ['uid', 'name', 'configuration'],
                'sys_file_storage',
                ['driver' => 'NextcloudFal']
            )->fetchAllAssociative();

            if (empty($rows)) {
                $io->error('No NextCloud storages found. Create one in the TYPO3 backend first.');
                return Command::FAILURE;
            }

            $io->section('Available NextCloud Storages');
            foreach ($rows as $row) {
                $io->writeln(sprintf('  [%d] %s', $row['uid'], $row['name']));
            }

            if (count($rows) === 1) {
                $storageUid = (int)$rows[0]['uid'];
                $io->writeln('');
                $io->writeln(sprintf('Using storage #%d automatically.', $storageUid));
            } else {
                $io->error('Multiple storages found. Please specify one with --storage=<uid>');
                return Command::FAILURE;
            }
        }

        // Load storage configuration
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_storage');
        $row = $connection->select(
            ['*'],
            'sys_file_storage',
            ['uid' => $storageUid, 'driver' => 'NextcloudFal']
        )->fetchAssociative();

        if ($row === false) {
            $io->error(sprintf('Storage #%d not found or not a NextCloud storage.', $storageUid));
            return Command::FAILURE;
        }

        // Parse FlexForm configuration
        $configuration = $this->parseFlexFormConfiguration($row['configuration'] ?? '');

        $io->section('Storage Configuration');
        $io->definitionList(
            ['Name' => $row['name']],
            ['UID' => $row['uid']],
            ['Base URL' => $configuration['baseUrl'] ?? '(empty)'],
            ['Username' => $configuration['username'] ?? '(empty)'],
            ['Password' => !empty($configuration['password']) ? '***set***' : '(empty)'],
        );

        $baseUrl = trim($configuration['baseUrl'] ?? '');
        $username = trim($configuration['username'] ?? '');
        $password = $configuration['password'] ?? '';

        if ($baseUrl === '' || $username === '') {
            $io->error('Base URL and/or username are empty. Configure the storage first.');
            return Command::FAILURE;
        }

        $io->section('Testing Connection');

        try {
            $client = new NextcloudClient($baseUrl, $username, $password);
        } catch (\InvalidArgumentException $e) {
            $io->error('Invalid configuration: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $result = $client->testConnection();

        $io->definitionList(
            ['WebDAV Path' => $result['webdavPath']],
            ['HTTP Status' => $result['status']],
            ['Result' => $result['message']],
        );

        if (!$result['success']) {
            $io->error('Connection FAILED');
            if (!empty($result['responseBody'])) {
                $io->section('Response Body');
                $io->writeln($result['responseBody']);
            }
            return Command::FAILURE;
        }

        $io->success('Connection successful!');

        // List root directory
        $io->section('Root Directory Contents');
        $entries = $client->propfind('/', 1);
        if (empty($entries)) {
            $io->warning('No entries returned from PROPFIND.');
        } else {
            $tableRows = [];
            foreach ($entries as $entry) {
                $tableRows[] = [
                    $entry['path'],
                    $entry['is_directory'] ? 'DIR' : 'FILE',
                    $entry['size'],
                    $entry['mtime'] ? date('Y-m-d H:i:s', $entry['mtime']) : '-',
                ];
            }
            $io->table(['Path', 'Type', 'Size', 'Modified'], $tableRows);
        }

        return Command::SUCCESS;
    }

    private function parseFlexFormConfiguration(string $flexFormXml): array
    {
        if ($flexFormXml === '') {
            return [];
        }

        $xml = @simplexml_load_string($flexFormXml);
        if ($xml === false) {
            return [];
        }

        $config = [];
        $fields = $xml->xpath('//data/sheet[@index="sDEF"]/language[@index="lDEF"]/field');

        foreach ($fields as $field) {
            $fieldName = (string)$field['index'];
            $value = '';
            // Access <value index="vDEF">...</value>
            foreach ($field->children() as $valueNode) {
                if ((string)$valueNode['index'] === 'vDEF') {
                    $value = (string)$valueNode;
                    break;
                }
            }
            $config[$fieldName] = $value;
        }

        return $config;
    }
}
