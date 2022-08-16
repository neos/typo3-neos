<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\Service;

use Neos\ContentRepository\ContentRepository;
use Neos\ContentRepository\SharedModel\Workspace\ContentStreamIdentifier;
use Neos\ContentRepository\SharedModel\NodeAddressFactory;
use Neos\ContentRepository\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\ContentRepository\Security\Service\AuthorizationService;
use Neos\Fusion\Service\HtmlAugmenter as FusionHtmlAugmenter;

/**
 * The content element editable service adds the necessary markup around
 * a content element such that it can be edited using the inline editing
 * of the Neos Backend.
 *
 * @Flow\Scope("singleton")
 */
class ContentElementEditableService
{
    /**
     * @Flow\Inject
     * @var PrivilegeManagerInterface
     */
    protected $privilegeManager;

    /**
     * @Flow\Inject
     * @var AuthorizationService
     */
    protected $nodeAuthorizationService;

    /**
     * @Flow\Inject
     * @var FusionHtmlAugmenter
     */
    protected $htmlAugmenter;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @throws \Neos\ContentRepository\SharedModel\NodeAddressCannotBeSerializedException
     */
    public function wrapContentProperty(Node $node, string $property, string $content): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get(
            $node->subgraphIdentity->contentRepositoryIdentifier
        );

        if (
            $this->isContentStreamOfLiveWorkspace(
                $node->subgraphIdentity->contentStreamIdentifier,
                $contentRepository
            )
        ) {
            return $content;
        }

        // TODO: permissions
        //if (!$this->nodeAuthorizationService->isGrantedToEditNode($node)) {
        //    return $content;
        //}

        $attributes = [
            'data-__neos-property' => $property,
            'data-__neos-editable-node-contextpath' => NodeAddressFactory::create($contentRepository)
                ->createFromNode($node)
                ->serializeForUri()
        ];

        return $this->htmlAugmenter->addAttributes($content, $attributes, 'span');
    }

    private function isContentStreamOfLiveWorkspace(
        ContentStreamIdentifier $contentStreamIdentifier,
        ContentRepository $contentRepository
    ): bool {
        return $contentRepository->getWorkspaceFinder()
            ->findOneByCurrentContentStreamIdentifier($contentStreamIdentifier)
            ?->getWorkspaceName()->isLive() ?: false;
    }
}
