<?php

declare(strict_types=1);

namespace Codeblick\NextcloudFal\Index;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\Index\ExtractorInterface;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NextcloudImageMetaDataExtractor implements ExtractorInterface
{
    public function getFileTypeRestrictions(): array
    {
        return [FileType::IMAGE];
    }

    public function getDriverRestrictions(): array
    {
        return ['NextcloudFal'];
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getExecutionPriority(): int
    {
        return 50;
    }

    public function canProcess(File $file): bool
    {
        return $file->isImage();
    }

    public function extractMetaData(File $file, array $previousExtractedData = []): array
    {
        $localPath = $file->getForLocalProcessing(false);
        $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $localPath);

        $width = $imageInfo->getWidth();
        $height = $imageInfo->getHeight();

        if ($width === 0 || $height === 0) {
            return [];
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }
}
