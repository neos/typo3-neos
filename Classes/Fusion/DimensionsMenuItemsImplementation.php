<?php
namespace Neos\Neos\Fusion;

use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ConfigurationContentDimensionPresetSource;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;

/**
 * Fusion implementation for a dimensions menu items.
 *
 * The items generated by this menu will be all possible variants (according to the configured dimensions
 * and presets) of the given node (including the given node).
 *
 * If a 'dimension' is configured via Fusion, only the possible variants for that dimension will
 * be included in the menu, any other dimensions will be kept from the current context.
 *
 * Main Options:
 * - dimension (optional, string): name of the dimension which this menu should be limited to. Example: "language".
 * - presets (optional, array): If set, the presets are not loaded from the Settings, but instead taken from this property. Must be used with "dimension" set.
 */
class DimensionsMenuItemsImplementation extends AbstractMenuItemsImplementation
{

    /**
     * @Flow\Inject
     * @var ConfigurationContentDimensionPresetSource
     */
    protected $configurationContentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @return string
     */
    public function getDimension()
    {
        return $this->fusionValue('dimension');
    }

    /**
     * @return array
     */
    public function getPresets()
    {
        return $this->fusionValue('presets');
    }

    /**
     * @return array
     */
    public function getIncludeAllPresets()
    {
        return $this->fusionValue('includeAllPresets');
    }

    /**
     * Builds the array of Menu items for this variant menu
     */
    protected function buildItems()
    {
        $menuItems = [];
        $targetDimensionsToMatch = [];
        $allDimensionPresets = $this->configurationContentDimensionPresetSource->getAllPresets();
        $includeAllPresets = $this->getIncludeAllPresets();
        $pinnedDimensionValues = $this->getPresets();

        $pinnedDimensionName = $this->getDimension();
        if ($pinnedDimensionName !== null) {
            $targetDimensionsToMatch = $this->currentNode->getContext()->getTargetDimensions();
            unset($targetDimensionsToMatch[$pinnedDimensionName]);
        }

        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $allowedCombination) {
            $targetDimensions = $this->calculateTargetDimensionsForCombination($allowedCombination);

            if ($pinnedDimensionName !== null && is_array($pinnedDimensionValues)) {
                if (!in_array($targetDimensions[$pinnedDimensionName], $pinnedDimensionValues)) {
                    continue;
                }
            }

            // skip variants not matching the current target dimensions (except the dimension this menu covers)
            if ($targetDimensionsToMatch !== []) {
                foreach ($targetDimensionsToMatch as $dimensionName => $dimensionValue) {
                    if ($targetDimensions[$dimensionName] !== $dimensionValue) {
                        continue 2;
                    }
                }
            }

            $nodeInDimensions = $this->getNodeInDimensions($allowedCombination, $targetDimensions);

            // no match, so we look further...
            if ($nodeInDimensions === null && $includeAllPresets) {
                $nodeInDimensions = $this->findAcceptableNode($allowedCombination, $allDimensionPresets);
            }

            if ($nodeInDimensions !== null && ($this->isNodeHidden($nodeInDimensions) || $this->hasHiddenNodeParent($nodeInDimensions))) {
                $nodeInDimensions = null;
            }

            // determine metadata for target dimensions of node
            array_walk($targetDimensions, static function (&$dimensionValue, $dimensionName, $allDimensionPresets) use ($pinnedDimensionName) {
                $dimensionValue = [
                    'value' => $dimensionValue,
                    'label' => $allDimensionPresets[$dimensionName]['presets'][$dimensionValue]['label'],
                    'isPinnedDimension' => $pinnedDimensionName === null || $dimensionName == $pinnedDimensionName
                ];
            }, $allDimensionPresets);

            $menuItems[] = [
                'node' => $nodeInDimensions,
                'state' => $this->calculateItemState($nodeInDimensions),
                'label' => $this->itemLabel($pinnedDimensionName, $nodeInDimensions, $targetDimensions),
                'dimensions' => $allowedCombination,
                'targetDimensions' => $targetDimensions
            ];
        }

        // sort/limit according to configured "presets" if needed
        if ($pinnedDimensionName !== null && is_array($pinnedDimensionValues)) {
            $sortedMenuItems = [];
            foreach ($pinnedDimensionValues as $pinnedDimensionValue) {
                foreach ($menuItems as $menuItemKey => $menuItem) {
                    if ($menuItem['targetDimensions'][$pinnedDimensionName]['value'] === $pinnedDimensionValue) {
                        $sortedMenuItems[$menuItemKey] = $menuItem;
                    }
                }
            }

            return $sortedMenuItems;
        }

        return $menuItems;
    }

    /**
     * Render and return a label for the $nodeInDimensions in the built menu item.
     *
     * @param string|null $pinnedDimensionName
     * @param NodeInterface|null $nodeInDimensions
     * @param array|null $targetDimensions
     * @return string
     */
    protected function itemLabel(string $pinnedDimensionName = null, NodeInterface $nodeInDimensions = null, array $targetDimensions = null): string
    {
        if ($nodeInDimensions === null && $pinnedDimensionName === null) {
            $itemLabel = '';
            foreach ($targetDimensions as $item) {
                $itemLabel .= $item['label'] . ' - ';
            }

            return trim($itemLabel, ' -');
        }

        if ($nodeInDimensions instanceof NodeInterface && $pinnedDimensionName === null) {
            return $nodeInDimensions->getLabel();
        }

        return $targetDimensions[$pinnedDimensionName]['label'];
    }

    /**
     * Get the current node in the given dimensions.
     * If it doesn't exist the method returns null.
     *
     * @param array $dimensions
     * @param array $targetDimensions
     * @return NodeInterface|null
     */
    protected function getNodeInDimensions(array $dimensions, array $targetDimensions)
    {
        if ($this->currentNode === null) {
            return null;
        }

        $q = new FlowQuery([$this->currentNode]);

        return $q->context([
            'dimensions' => $dimensions,
            'targetDimensions' => $targetDimensions
        ])->get(0);
    }

    /**
     *
     * @param array $allowedCombination
     * @param $allDimensionPresets
     * @return null|NodeInterface
     */
    protected function findAcceptableNode(array $allowedCombination, array $allDimensionPresets)
    {
        $pinnedDimensionName = $this->getDimension();
        foreach ($allowedCombination[$pinnedDimensionName] as $allowedPresetIdentifier) {
            $acceptableCombination = [$pinnedDimensionName => $allDimensionPresets[$pinnedDimensionName]['presets'][$allowedPresetIdentifier]['values']];
            $allowedAdditionalPresets = $this->configurationContentDimensionPresetSource->getAllowedDimensionPresetsAccordingToPreselection('country', [$pinnedDimensionName => $allowedPresetIdentifier]);
            foreach ($allowedAdditionalPresets as $allowedAdditionalDimensionName => $allowedAdditionalPreset) {
                $acceptableCombination[$allowedAdditionalDimensionName] = $allowedAdditionalPreset['presets'][$allowedAdditionalPreset['defaultPreset']]['values'];
            }
            $nodeInDimensions = $this->getNodeInDimensions($acceptableCombination, []);
            if ($nodeInDimensions !== null) {
                return $nodeInDimensions;
            }
        }

        return null;
    }

    /**
     * Calculates the target dimensions for a given dimension combination.
     *
     * @param array $dimensionCombination
     * @return array
     */
    protected function calculateTargetDimensionsForCombination(array $dimensionCombination)
    {
        $targetDimensions = [];
        foreach ($dimensionCombination as $dimensionName => $dimensionValues) {
            $targetDimensions[$dimensionName] = reset($dimensionValues);
        }

        return $targetDimensions;
    }

    /**
     * Returns TRUE if the node has a inaccessible parent.
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function hasHiddenNodeParent(NodeInterface $node): bool
    {
        $rootNode = $node->getContext()->getRootNode();
        $nodesOnPath = $node->getContext()->getNodesOnPath($rootNode, $node);

        return count($nodesOnPath) < $node->getDepth();
    }
}
