<?php
declare(strict_types=1);

namespace Neos\Neos\AssetUsage\Dto;

use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class AssetUsageFilter
{

    private function __construct(
        public readonly ?string $assetIdentifier,
        public readonly ?ContentStreamId $contentStreamIdentifier,
        public readonly bool $groupByAsset,
        public readonly bool $groupByNode,
    ) {
    }

    public static function create(): self
    {
        return new self(null, null, false, false);
    }

    public function withAsset(string $assetIdentifier): self
    {
        return new self($assetIdentifier, $this->contentStreamIdentifier, $this->groupByAsset, $this->groupByNode);
    }

    public function withContentStream(ContentStreamId $contentStreamIdentifier): self
    {
        return new self($this->assetIdentifier, $contentStreamIdentifier, $this->groupByAsset, $this->groupByNode);
    }

    public function groupByAsset(): self
    {
        return new self($this->assetIdentifier, $this->contentStreamIdentifier, true, $this->groupByNode);
    }

    public function groupByNode(): self
    {
        return new self($this->assetIdentifier, $this->contentStreamIdentifier, $this->groupByAsset, true);
    }

    public function hasAssetIdentifier(): bool
    {
        return $this->assetIdentifier !== null;
    }

    public function hasContentStreamIdentifier(): bool
    {
        return $this->contentStreamIdentifier !== null;
    }
}
