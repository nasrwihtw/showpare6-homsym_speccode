<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Shopware\Core\Content\ImportExport\Aggregate\ImportExportFile\ImportExportFileEntity;
use Shopware\Core\Content\ImportExport\Aggregate\ImportExportLog\ImportExportLogEntity;
use Shopware\Core\Content\ImportExport\Event\EnrichExportCriteriaEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportBeforeExportRecordEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportBeforeImportRecordEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportBeforeImportRowEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportExceptionExportRecordEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportExceptionImportRecordEvent;
use Shopware\Core\Content\ImportExport\Processing\Mapping\CriteriaBuilder;
use Shopware\Core\Content\ImportExport\Processing\Pipe\AbstractPipe;
use Shopware\Core\Content\ImportExport\Processing\Reader\AbstractReader;
use Shopware\Core\Content\ImportExport\Processing\Writer\AbstractWriter;
use Shopware\Core\Content\ImportExport\Service\AbstractFileService;
use Shopware\Core\Content\ImportExport\Service\ImportExportService;
use Shopware\Core\Content\ImportExport\Strategy\Import\ImportStrategyService;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\WriteCommandExceptionEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @phpstan-type ImportData array{record: array<string, mixed>, original: array<string, mixed>}
 *
 * @deprecated tag:v6.7.0 - reason:becomes-internal
 */
#[Package('fundamentals@after-sales')]
class ImportExport
{
    private const PART_FILE_SUFFIX = '.offset_';

    private ?int $total = null;

    /**
     * @var WriteCommand[]
     */
    private array $failedWriteCommands = [];

    /**
     * @internal
     */
    public function __construct(
        private readonly ImportExportService $importExportService,
        private ImportExportLogEntity $logEntity,
        private readonly FilesystemOperator $filesystem,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Connection $connection,
        private readonly EntityRepository $repository,
        private readonly AbstractPipe $pipe,
        private readonly AbstractReader $reader,
        private readonly AbstractWriter $writer,
        private readonly AbstractFileService $fileService,
        private readonly ImportStrategyService $importStrategyService,
        private readonly int $importLimit = 250,
        private readonly int $exportLimit = 250
    ) {
    }

    public function import(Context $context, int $offset = 0): Progress
    {
        // We fetch a fresh version of the log entity to have the most recent results
        $this->logEntity = $this->importExportService->findLog($context, $this->logEntity->getId());

        $progress = $this->importExportService->getProgress($this->logEntity->getId(), $offset);

        $file = $this->logEntity->getFile();
        \assert($file instanceof ImportExportFileEntity);

        $progress->setTotal($file->getSize());

        if ($progress->isFinished()) {
            return $progress;
        }

        $processed = 0;
        $results = [];
        $failedRecords = [];
        $this->failedWriteCommands = [];

        $path = $file->getPath();
        $progress->setTotal($this->filesystem->fileSize($path));
        $invalidRecordsProgress = null;

        $resource = $this->filesystem->readStream($path);
        $config = Config::fromLog($this->logEntity);
        $overallResults = $this->logEntity->getResult();

        $this->eventDispatcher->addListener(WriteCommandExceptionEvent::class, $this->onWriteException(...));

        $context->addState(Context::SKIP_TRIGGER_FLOW);

        if ($this->logEntity->getActivity() === ImportExportLogEntity::ACTIVITY_DRYRUN) {
            $this->connection->setNestTransactionsWithSavepoints(true);
            $this->connection->beginTransaction();
        }

        foreach ($this->reader->read($config, $resource, $offset) as $row) {
            $event = new ImportExportBeforeImportRowEvent($row, $config, $context);
            $this->eventDispatcher->dispatch($event);
            $row = $event->getRow();

            // empty csv lines were already skipped by the reader.
            // defaults are added to the raw csv row
            $this->addUserDefaults($row, $config);

            $record = [];
            foreach ($this->pipe->out($config, $row) as $key => $value) {
                $record[$key] = $value;
            }

            if (empty($record)) {
                continue;
            }

            try {
                if (isset($record['_error']) && $record['_error'] instanceof \Throwable) {
                    throw $record['_error'];
                }

                // ensure that the raw csv row has all the fields, which are marked as required by the user.
                $this->ensureUserRequiredFields($row, $config);

                $record = $this->ensurePrimaryKeys($record);

                $event = new ImportExportBeforeImportRecordEvent($record, $row, $config, $context);
                $this->eventDispatcher->dispatch($event);

                $record = $event->getRecord();

                $importResult = $this->importStrategyService->import($record, $row, $config, $progress, $context);

                $results = array_merge($results, $importResult->results);
                $failedRecords = array_merge($failedRecords, $importResult->failedRecords);
            } catch (\Throwable $exception) {
                $event = new ImportExportExceptionImportRecordEvent($exception, $record, $row, $config, $context);
                $this->eventDispatcher->dispatch($event);

                $importException = $event->getException();

                if ($importException) {
                    $record['_error'] = mb_convert_encoding($importException->getMessage(), 'UTF-8', 'UTF-8');
                    $failedRecords[] = $record;
                }
            }

            ++$processed;
            if ($this->importLimit > 0 && $processed >= $this->importLimit) {
                break;
            }
        }

        $importResult = $this->importStrategyService->commit($config, $progress, $context);

        $results = array_merge($results, $importResult->results);
        $failedRecords = array_merge($failedRecords, $importResult->failedRecords);

        if ($this->logEntity->getActivity() === ImportExportLogEntity::ACTIVITY_DRYRUN) {
            $this->connection->rollBack();
        }

        $overallResults = $this->logResults($overallResults, $results, $failedRecords, $this->repository->getDefinition()->getEntityName());

        $progress->setOffset($this->reader->getOffset());

        $this->eventDispatcher->removeListener(WriteCommandExceptionEvent::class, $this->onWriteException(...));

        if (!empty($failedRecords)) {
            $invalidRecordsProgress = $this->exportInvalid($context, $failedRecords);
            $progress->setInvalidRecordsLogId($invalidRecordsProgress->getLogId());
        }

        // importing the file is complete
        if ($this->reader->getOffset() === $this->filesystem->fileSize($path)) {
            if ($this->logEntity->getInvalidRecordsLog() instanceof ImportExportLogEntity) {
                $invalidLog = $this->logEntity->getInvalidRecordsLog();
                $invalidRecordsProgress ??= $this->importExportService->getProgress($invalidLog->getId(), $invalidLog->getRecords());

                // complete invalid records export
                $this->mergePartFiles($invalidLog, $invalidRecordsProgress);
                $this->importExportService->saveProgress($invalidRecordsProgress);
            }

            $progress->setState($invalidRecordsProgress === null ? Progress::STATE_SUCCEEDED : Progress::STATE_FAILED);
        }

        $this->importExportService->saveProgress($progress, $overallResults);

        return $progress;
    }

    public function export(Context $context, ?Criteria $criteria = null, int $offset = 0): Progress
    {
        $progress = $this->importExportService->getProgress($this->logEntity->getId(), $offset);

        if ($progress->isFinished()) {
            return $progress;
        }

        $config = Config::fromLog($this->logEntity);
        $criteriaBuilder = new CriteriaBuilder($this->repository->getDefinition());

        $criteria = $criteria === null ? new Criteria() : clone $criteria;
        $criteriaBuilder->enrichCriteria($config, $criteria);

        $enrichEvent = new EnrichExportCriteriaEvent($criteria, $this->logEntity);
        $this->eventDispatcher->dispatch($enrichEvent);

        if ($criteria->getSorting() === []) {
            // default sorting
            $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        }
        $criteria->addSorting(new FieldSorting('id'));

        $criteria->setOffset($offset);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $criteria->setLimit($this->exportLimit <= 0 ? 250 : $this->exportLimit);
        $fullExport = $this->exportLimit <= 0;

        $file = $this->logEntity->getFile();
        \assert($file instanceof ImportExportFileEntity);
        $targetFile = $this->getPartFilePath($file->getPath(), $offset);

        $failedRecords = [];

        do {
            $result = $this->repository->search($criteria, $context);
            if ($this->total === null) {
                $this->total = $result->getTotal();
                $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);
            }

            $entities = $result->getEntities();
            if (\count($entities) === 0) {
                // this can happen if entities are deleted while we export
                $progress->setTotal($progress->getOffset());

                break;
            }

            $progress = $this->exportChunk($config, $entities, $progress, $targetFile, $context, false, $failedRecords);

            $criteria->setOffset($criteria->getOffset() + $criteria->getLimit());
        } while ($fullExport && $progress->getOffset() < $progress->getTotal());

        if (!empty($failedRecords)) {
            $progress->setInvalidRecordsLogId($this->exportInvalid($context, $failedRecords)->getLogId());
            $this->importExportService->saveProgress($progress);
        }

        if ($progress->getTotal() > $progress->getOffset()) {
            return $progress;
        }

        $this->writer->finish($config, $targetFile);

        if (!$this->logEntity->getInvalidRecordsLog() instanceof ImportExportLogEntity) {
            return $this->mergePartFiles($this->logEntity, $progress);
        }

        $progress->setState(Progress::STATE_FAILED);
        $this->importExportService->saveProgress($progress);

        $invalidLog = $this->logEntity->getInvalidRecordsLog();
        $invalidRecordsProgress = $this->importExportService->getProgress($invalidLog->getId(), $invalidLog->getRecords());

        // complete invalid records export
        $this->mergePartFiles($invalidLog, $invalidRecordsProgress);

        $this->importExportService->saveProgress($invalidRecordsProgress);

        return $progress;
    }

    /**
     * @param array<int|string, mixed> $exceptions
     *
     * @internal
     */
    public function exportExceptions(Context $context, array $exceptions): Progress
    {
        $progress = $this->importExportService->getProgress($this->logEntity->getId(), 0);

        $originalConfig = $this->logEntity->getConfig();

        try {
            $this->logEntity->setConfig([]);

            $progress->setInvalidRecordsLogId($this->exportInvalid($context, $exceptions)->getLogId());

            $this->logEntity->setConfig($originalConfig);

            $invalidLog = $this->logEntity->getInvalidRecordsLog();
            \assert($invalidLog instanceof ImportExportLogEntity);
            $invalidRecordsProgress = $this->importExportService->getProgress($invalidLog->getId(), $invalidLog->getRecords());

            // complete invalid records export
            $this->mergePartFiles($invalidLog, $invalidRecordsProgress);
            $this->importExportService->saveProgress($invalidRecordsProgress);
        } finally {
            // assure that the config is restored
            if ($this->logEntity->getConfig() !== $originalConfig) {
                $this->logEntity->setConfig($originalConfig);
            }
        }

        $progress->setState(Progress::STATE_FAILED);
        $this->importExportService->saveProgress($progress);

        return $progress;
    }

    public function abort(): void
    {
        $invalidLog = $this->logEntity->getInvalidRecordsLog();
        if ($invalidLog !== null) {
            $invalidRecordsProgress = $this->importExportService->getProgress($invalidLog->getId(), $invalidLog->getRecords());

            // complete invalid records export
            $this->mergePartFiles($invalidLog, $invalidRecordsProgress);

            $invalidRecordsProgress->setState(Progress::STATE_SUCCEEDED);
            $this->importExportService->saveProgress($invalidRecordsProgress);
        }
    }

    public function getLogEntity(): ImportExportLogEntity
    {
        return $this->logEntity;
    }

    public function onWriteException(WriteCommandExceptionEvent $event): void
    {
        $this->failedWriteCommands = array_merge($this->failedWriteCommands, $event->getCommands());
    }

    private function getPartFilePath(string $targetPath, int $offset): string
    {
        return $targetPath . self::PART_FILE_SUFFIX . $offset;
    }

    /**
     * flysystem does not support appending to existing files. Therefore we need to export multiple files and merge them
     * into the complete export file at the end.
     */
    private function mergePartFiles(ImportExportLogEntity $logEntity, Progress $progress): Progress
    {
        $progress->setState(Progress::STATE_MERGING_FILES);
        $this->importExportService->saveProgress($progress);

        $tmpFile = tempnam(sys_get_temp_dir(), '');
        $tmp = fopen($tmpFile ?: '', 'w+');
        \assert(\is_resource($tmp));

        $file = $logEntity->getFile();
        \assert($file instanceof ImportExportFileEntity);
        $target = $file->getPath();

        $dir = \dirname($target);

        $partFilePrefix = $target . self::PART_FILE_SUFFIX;

        $partFiles = [];

        foreach ($this->filesystem->listContents($dir) as $meta) {
            if ($meta->type() !== 'file'
                || $meta->path() === $target
                || !str_starts_with($meta->path(), $partFilePrefix)) {
                continue;
            }

            $partFiles[] = $meta->path();
        }

        // sort by offset
        natsort($partFiles);

        // concatenate all part files into a temporary file
        foreach ($partFiles as $partFile) {
            $stream = $this->filesystem->readStream($partFile);
            if (stream_copy_to_stream($stream, $tmp) === false) {
                throw ImportExportException::processingError('Failed to merge files');
            }
        }

        // copy final file into filesystem
        $this->filesystem->writeStream($target, $tmp);

        // The Google Cloud Storage filesystem closes the stream even though it should not. To prevent a fatal
        // error, we therefore need to check whether the stream has been closed yet.
        if (\is_resource($tmp)) {
            fclose($tmp);
        }

        if ($tmpFile) {
            unlink($tmpFile);
        }

        foreach ($partFiles as $p) {
            $this->filesystem->delete($p);
        }

        $progress->setState(Progress::STATE_SUCCEEDED);
        $this->importExportService->saveProgress($progress);

        $fileId = $logEntity->getFileId();
        if ($fileId === null) {
            throw ImportExportException::processingError('log does not have a file id');
        }

        $this->fileService->updateFile(
            Context::createDefaultContext(),
            $fileId,
            ['size' => $this->filesystem->fileSize($target)]
        );

        return $progress;
    }

    /**
     * @param array<Entity|array<int|string, mixed>> $records
     * @param array<int, array<mixed>> $failedRecords
     */
    private function exportChunk(
        Config $config,
        iterable $records,
        Progress $progress,
        string $targetFile,
        Context $context,
        bool $allowErrors = false,
        array &$failedRecords = []
    ): Progress {
        $exportedRecords = 0;
        $offset = $progress->getOffset();
        foreach ($records as $originalRecord) {
            $originalRecord = $originalRecord instanceof Entity
                ? $originalRecord->jsonSerialize()
                : $originalRecord;

            $mappedRecord = [];
            $exportExceptions = [];

            foreach ($this->pipe->in($config, $originalRecord) as $key => $value) {
                if (\is_object($value) && !method_exists($value, '__toString')) {
                    if (!$allowErrors) {
                        $exportExceptions[$key] = ImportExportException::fieldCannotBeExported($value::class);

                        continue;
                    }

                    $mappedRecord[$key] = '#ERROR#';

                    continue;
                }

                $value = (string) $value;
                $mappedRecord[$key] = $value;
            }

            if ($exportExceptions) {
                $event = new ImportExportExceptionExportRecordEvent($exportExceptions, $mappedRecord, $config, $context);
                $this->eventDispatcher->dispatch($event);

                $exceptions = $event->getExceptions();

                if ($exceptions) {
                    $originalRecord['_error'] = json_encode(
                        \array_map(
                            fn ($exception) => \mb_convert_encoding($exception->getMessage(), 'UTF-8', 'UTF-8'),
                            $exceptions
                        )
                    );
                    $failedRecords[] = $originalRecord;
                }
            }

            if ($mappedRecord !== [] && !$exportExceptions) {
                $event = new ImportExportBeforeExportRecordEvent($config, $mappedRecord, $originalRecord);
                $this->eventDispatcher->dispatch($event);

                $importRecord = $event->getRecord();

                $this->writer->append($config, $importRecord, $offset);
                ++$exportedRecords;
            }

            ++$offset;
        }

        $this->writer->flush($config, $targetFile);

        $progress->setState(Progress::STATE_PROGRESS);
        $progress->setOffset($offset);
        $progress->setTotal($this->total);
        $progress->addProcessedRecords($exportedRecords);

        $this->importExportService->saveProgress($progress);

        return $progress;
    }

    /**
     * In case we failed to import some invalid records, we export them as a new csv with the same format and
     * an additional _error column.
     *
     * @param array<array<int|string, mixed>> $failedRecords
     */
    private function exportInvalid(Context $context, array $failedRecords): Progress
    {
        $file = $this->logEntity->getFile();

        if (
            !$this->logEntity->getInvalidRecordsLogId()
            && $file instanceof ImportExportFileEntity
        ) {
            $failedImportLogEntity = $this->createInvalidRecordsLog($context, $file);
            \assert($failedImportLogEntity instanceof ImportExportLogEntity);
            $this->logEntity->setInvalidRecordsLog($failedImportLogEntity);
            $this->logEntity->setInvalidRecordsLogId($failedImportLogEntity->getId());
        }

        $failedImportLogEntity = $this->logEntity->getInvalidRecordsLog();
        \assert($failedImportLogEntity instanceof ImportExportLogEntity);
        $config = Config::fromLog($failedImportLogEntity);

        $offset = $failedImportLogEntity->getRecords();

        $failedImportLogFile = $failedImportLogEntity->getFile();
        \assert($failedImportLogFile instanceof ImportExportFileEntity);
        $targetFile = $this->getPartFilePath($failedImportLogFile->getPath(), $offset);

        $progress = $this->importExportService->getProgress($failedImportLogEntity->getId(), $offset);

        $progress = $this->exportChunk(
            $config,
            $failedRecords,
            $progress,
            $targetFile,
            $context,
            true
        );

        $this->writer->finish($config, $targetFile);

        return $progress;
    }

    private function createInvalidRecordsLog(Context $context, ImportExportFileEntity $file): ?ImportExportLogEntity
    {
        if (!$this->logEntity->getProfileId()) {
            return null;
        }

        $pathInfo = pathinfo($file->getOriginalName());
        $newName = $pathInfo['filename'] . '_failed.' . ($pathInfo['extension'] ?? '');

        $newPath = $file->getPath() . '_invalid';

        $config = $this->logEntity->getConfig();
        $config['mapping'][] = [
            'key' => '_error',
            'mappedKey' => '_error',
        ];
        $config = new Config($config['mapping'], $config['parameters'] ?? [], $config['updateBy'] ?? []);

        return $this->importExportService->prepareExport(
            $context,
            $this->logEntity->getProfileId(),
            $file->getExpireDate(),
            $newName,
            $config->jsonSerialize(),
            $newPath,
            ImportExportLogEntity::ACTIVITY_INVALID_RECORDS_EXPORT
        );
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    private function ensurePrimaryKeys(array $data): array
    {
        foreach ($this->repository->getDefinition()->getPrimaryKeys() as $primaryKey) {
            if (!($primaryKey instanceof IdField)) {
                continue;
            }

            if (!isset($data[$primaryKey->getPropertyName()])) {
                $data[$primaryKey->getPropertyName()] = Uuid::randomHex();
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addUserDefaults(array &$row, Config $config): void
    {
        $mappings = $config->getMapping()->getElements();

        foreach ($mappings as $mapping) {
            $csvKey = $mapping->getMappedKey();

            if (!$mapping->isUseDefaultValue()) {
                continue;
            }

            if (!\array_key_exists($csvKey, $row) || empty($row[$csvKey])) {
                $row[$csvKey] = $mapping->getDefaultValue();
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function ensureUserRequiredFields(array &$row, Config $config): void
    {
        $mappings = $config->getMapping()->getElements();

        foreach ($mappings as $mapping) {
            $csvKey = $mapping->getMappedKey();

            if (!$mapping->isRequiredByUser()) {
                continue;
            }

            if (!\array_key_exists($csvKey, $row) || empty($row[$csvKey])) {
                throw ImportExportException::requiredByUser($csvKey);
            }
        }
    }

    /**
     * @param array<string, mixed> $overallResults
     * @param EntityWrittenContainerEvent[] $results
     * @param array<int, array<int|string, mixed>> $failedRecords
     *
     * @return array<string, mixed>
     */
    private function logResults(
        array $overallResults,
        array $results,
        array $failedRecords,
        string $entityName
    ): array {
        $defaultTemplate = [
            \sprintf('%sSkip', EntityWriteResult::OPERATION_INSERT) => 0,
            \sprintf('%sSkip', EntityWriteResult::OPERATION_UPDATE) => 0,
            \sprintf('%sError', EntityWriteResult::OPERATION_INSERT) => 0,
            \sprintf('%sError', EntityWriteResult::OPERATION_UPDATE) => 0,
            'otherError' => 0,
            EntityWriteResult::OPERATION_INSERT => 0,
            EntityWriteResult::OPERATION_UPDATE => 0,
        ];

        foreach ($results as $result) {
            if ($result->getEvents() === null) {
                continue;
            }

            foreach ($result->getEvents() as $event) {
                if (!$event instanceof EntityWrittenEvent) {
                    continue;
                }

                foreach ($event->getWriteResults() as $writeResult) {
                    $entityResult = $overallResults[$writeResult->getEntityName()] ?? $defaultTemplate;

                    ++$entityResult[$writeResult->getOperation()];

                    $overallResults[$writeResult->getEntityName()] = $entityResult;
                }
            }
        }

        foreach ($this->failedWriteCommands as $writeCommand) {
            if (!$writeCommand instanceof WriteCommand) {
                continue;
            }

            $entityName = $writeCommand->getEntityName();

            $entityResult = $overallResults[$entityName] ?? $defaultTemplate;

            $operation = $writeCommand->getEntityExistence()->exists()
                ? EntityWriteResult::OPERATION_UPDATE
                : EntityWriteResult::OPERATION_INSERT;

            $type = $writeCommand->isFailed() ? 'Error' : 'Skip';

            ++$entityResult[\sprintf('%s%s', $operation, $type)];

            $overallResults[$entityName] = $entityResult;
        }

        // The entries present in the failed records failed either via failed write commands or some other errors.
        // As we already logged the failed write commands we still need to log the remaining failed records.
        $entityResult = $overallResults[$entityName] ?? $defaultTemplate;

        $entityResult['otherError'] += \count($failedRecords) - \count($this->failedWriteCommands);

        $overallResults[$entityName] = $entityResult;

        return $overallResults;
    }
}
