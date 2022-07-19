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

namespace Neos\Neos\FrontendRouting;

use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\SharedModel\NodeAddressCannotBeSerializedException;
use Neos\ContentRepository\SharedModel\NodeAddress;
use Neos\ContentRepository\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ValueObject\ContentRepositoryIdentifier;
use Neos\Flow\Mvc\Routing\RoutingMiddleware;
use Neos\Neos\Domain\Model\SiteIdentifier;
use Neos\Neos\Domain\Service\NodeShortcutResolver;
use Neos\Neos\EventSourcedRouting\Exception\InvalidShortcutException;
use Neos\Neos\EventSourcedRouting\Projection\DocumentUriPathFinder;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Mvc\Routing\AbstractRoutePart;
use Neos\Flow\Mvc\Routing\Dto\MatchResult;
use Neos\Flow\Mvc\Routing\Dto\ResolveResult;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\Dto\UriConstraints;
use Neos\Flow\Mvc\Routing\DynamicRoutePartInterface;
use Neos\Flow\Mvc\Routing\ParameterAwareRoutePartInterface;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\FrontendRouting\CrossSiteLinking\CrossSiteLinker;
use Neos\Neos\FrontendRouting\DimensionResolution\DelegatingResolver;
use Neos\Neos\FrontendRouting\DimensionResolution\DimensionResolverContext;
use Neos\Neos\FrontendRouting\DimensionResolution\DimensionResolverInterface;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionMiddleware;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\UriInterface;

/**
 * A route part handler for finding nodes in the website's frontend. Like every RoutePartHandler,
 * this handles both directions:
 * - from URL to NodeAddress (via {@see EventSourcedFrontendNodeRoutePartHandler::matchWithParameters})
 * - from NodeAddress to URL (via {@see EventSourcedFrontendNodeRoutePartHandler::resolveWithParameters})
 *
 * For performance reasons, this uses a special projection {@see DocumentUriPathFinder}, and
 * does NOT use the graph projection in any way.
 *
 *
 * ## Match Direction (URL to NodeAddress)
 *
 * This is usually simply triggered ONCE per request, before the controller starts working.
 * The RoutePartHandler is invoked from {@see RoutingMiddleware} (which handles the routing).
 *
 * The overall process is as follows:
 *
 * ```
 *  (*) = Extension Point               ┌───────────────────────────────────────────────┐
 * ┌──────────────┐                     │   EventSourcedFrontendNodeRoutePartHandler    │
 * │SiteDetection │                     │ ┌─────────────────────┐                       │
 * │Middleware (*)│────────────────────▶│ │DimensionResolver (*)│─────▶ Finding the    ─┼─▶NodeAddress
 * └──────────────┘ current site        │ └─────────────────────┘       NodeIdentifier  │
 *                                      └───────────────────────────────────────────────┘
 *                  current Content                              current
 *                  Repository                                   DimensionSpacePoint
 * ```
 *
 *
 * ### {@see SiteDetectionMiddleware}: Multi-Site Support (implemented) and Multiple Content Repository Support (planned)
 *
 * The Dimension Resolving configuration might be site-specific, f.e. one site maps a subdomain to a different language;
 * and another site which wants to use the UriPathSegment. Additionally, we soon want to support using different
 * content repositories for different sites (e.g. to have different NodeTypes configured, or differing dimension configuration).
 *
 * Thus, the {@see DimensionResolverInterface} and the frontend routing in general needs the result of the site
 * detection as input.
 *
 * Because of this, the site detection is done before the routing; inside the {@see SiteDetectionMiddleware}, which
 * runs the routing. **Feel free to replace the Site Detection with your own custom Middleware (it's very little code).**
 *
 * The Site Detection is done at **every** request.
 *
 *
 * ### {@see DimensionResolverInterface}: Custom Dimension Resolving
 *
 * Especially the {@see DimensionSpacePoint} matching must be very extensible, because
 * people might want to map domains, subdomains, URL slugs, ... to different dimensions; and
 * maybe even handle every dimension individually.
 *
 * This is why the {@see EventSourcedFrontendNodeRoutePartHandler} calls the {@see DelegatingResolver},
 * which calls potentially multiple {@see DimensionResolverInterface}s.
 * // TODO: Dimension Match?? -> Naming -- mit "resolveWithParameters" -- falsche Richtung??
 *
 * **For details on how to customize the Dimension Resolving, please see {@see DimensionResolverInterface}.**
 *
 * Because the Dimension Resolving runs inside the RoutePartHandler, this is all cached (via the Routing Cache).
 *
 *
 * ### Reading the Uri Path Segment and finding the node
 *
 * This is the core capability of this class (the {@see EventSourcedFrontendNodeRoutePartHandler}).
 *
 *
 * ### Result of the Routing
 *
 * The **result** of the {@see EventSourcedFrontendNodeRoutePartHandler::matchWithParameters} call is a
 * {@see NodeAddress} (wrapped in a {@see MatchResult}); so to build the NodeAddress, we need:
 * - the {@see WorkspaceName} (which is always **live** in our case)
 * - the {@see ContentStreamIdentifier} of the Live workspace
 * - The {@see DimensionSpacePoint} we want to see the page in (i.e. in language=de) - resolved by {@see DimensionResolverInterface}
 * - The {@see NodeAggregateIdentifier} (of the Document Node we want to show) - resolved by {@see EventSourcedFrontendNodeRoutePartHandler}
 *
 *
 * ## Resolve Direction
 *
 * TODO EXPLAIN
 *
 * @Flow\Scope("singleton")
 */
final class EventSourcedFrontendNodeRoutePartHandler extends AbstractRoutePart implements
    DynamicRoutePartInterface,
    ParameterAwareRoutePartInterface,
    FrontendNodeRoutePartHandlerInterface
{
    private string $splitString = '';

    /**
     * @Flow\Inject
     * @var DocumentUriPathFinder
     */
    protected $documentUriPathFinder;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var NodeShortcutResolver
     */
    protected $nodeShortcutResolver;

    /**
     * @Flow\Inject
     * @var DelegatingResolver
     */
    protected $delegatingResolver;

    /**
     * @Flow\Inject
     * @var CrossSiteLinker
     */
    protected $crossSiteLinker;

    /**
     * Incoming URLs
     *
     * @param mixed $requestPath
     * @param RouteParameters $parameters
     * @return bool|MatchResult
     * @throws NodeAddressCannotBeSerializedException
     */
    public function matchWithParameters(&$requestPath, RouteParameters $parameters)
    {
        if (!is_string($requestPath)) {
            return false;
        }

        $remainingRequestPath = $this->truncateRequestPathAndReturnRemainder($requestPath);

        $dimensionResolvingResult = $this->delegatingResolver->resolveDimensionSpacePoint(DimensionResolverContext::fromUriPathAndRouteParameters($requestPath, $parameters));
        $dimensionSpacePoint = $dimensionResolvingResult->resolvedDimensionSpacePoint;
        // TODO Validate for full context
        // TODO validate dsp == complete (ContentDimensionZookeeper::getAllowedDimensionSubspace()->contains()...)
        // if incomplete -> no match + log

        $siteDetectionResult = SiteDetectionResult::fromRouteParameters($parameters);

        try {
            $matchResult = $this->matchUriPath(
                $dimensionResolvingResult->remainingUriPath,
                $dimensionSpacePoint,
                $siteDetectionResult
            );
        } catch (NodeNotFoundException $exception) {
            // we silently swallow the Node Not Found case, as you'll see this in the server log if it interests you
            // (and other routes could still handle this).
            return false;
        }

        $requestPath = $remainingRequestPath;

        return $matchResult;
    }


    /**
     * @param string $uriPath
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @param string $host
     * @return MatchResult
     * @throws NodeNotFoundException | NodeAddressCannotBeSerializedException
     */
    private function matchUriPath(string $uriPath, DimensionSpacePoint $dimensionSpacePoint, SiteDetectionResult $siteDetectionResult): MatchResult
    {
        $uriPath = trim($uriPath, '/');
        $nodeInfo = $this->documentUriPathFinder->getEnabledBySiteNodeNameUriPathAndDimensionSpacePointHash(
            $siteDetectionResult->siteIdentifier->asNodeName(),
            $uriPath,
            $dimensionSpacePoint->hash
        );
        $nodeAddress = new NodeAddress(
            $this->documentUriPathFinder->getLiveContentStreamIdentifier(),
            $dimensionSpacePoint,
            $nodeInfo->getNodeAggregateIdentifier(),
            WorkspaceName::forLive()
        );
        return new MatchResult($nodeAddress->serializeForUri(), $nodeInfo->getRouteTags());
    }

    /**
     * Outgoing URLs (link generation)
     *
     * @param array<string,mixed> &$routeValues
     * @throws InvalidContentDimensionValueUriProcessorException
     */
    public function resolveWithParameters(array &$routeValues, RouteParameters $parameters): ResolveResult|false
    {
        if ($this->name === null || $this->name === '' || !\array_key_exists($this->name, $routeValues)) {
            return false;
        }
        // TODO!!!
        //if (!$parameters->has('requestUriHost')) {
        //    return false;
        //}
        $currentRequestSiteDetectionResult = SiteDetectionResult::fromRouteParameters($parameters);

        $nodeAddress = $routeValues[$this->name];
        // TODO: for cross-CR links: NodeAddressInContentRepository as a new value object
        if (!$nodeAddress instanceof NodeAddress) {
            return false;
        }

        try {
            $resolveResult = $this->resolveNodeAddress($nodeAddress, $currentRequestSiteDetectionResult);
        } catch (NodeNotFoundException | InvalidShortcutException $exception) {
            // TODO log exception
            return false;
        }

        unset($routeValues[$this->name]);
        return $resolveResult;
    }

    /**
     * @param NodeAddress $nodeAddress
     * @param SiteDetectionResult $currentRequestSiteDetectionResult
     * @return ResolveResult
     * @throws InvalidShortcutException
     * @throws NodeNotFoundException
     */
    private function resolveNodeAddress(NodeAddress $nodeAddress, SiteDetectionResult $currentRequestSiteDetectionResult): ResolveResult
    {
        $nodeInfo = $this->documentUriPathFinder->getByIdAndDimensionSpacePointHash(
            $nodeAddress->nodeAggregateIdentifier,
            $nodeAddress->dimensionSpacePoint->hash
        );
        if ($nodeInfo->isDisabled()) {
            throw new NodeNotFoundException(sprintf(
                'The resolved node for address %s is disabled',
                $nodeAddress
            ), 1599668357);
        }
        if ($nodeInfo->isShortcut()) {
            $nodeInfo = $this->nodeShortcutResolver->resolveNode($nodeInfo);
            if ($nodeInfo instanceof UriInterface) {
                return $this->buildResolveResultFromUri($nodeInfo);
            }
            $nodeAddress = $nodeAddress->withNodeAggregateIdentifier($nodeInfo->getNodeAggregateIdentifier());
        }

        $uriConstraints = $this->crossSiteLinker->createCrossSiteLink($nodeInfo, $currentRequestSiteDetectionResult);
        $uriConstraints = $this->delegatingResolver->fromDimensionSpacePointToUriConstraints($nodeAddress->dimensionSpacePoint, SiteIdentifier::fromNodeName($nodeInfo->getSiteNodeName()), $uriConstraints);

        if (!empty($this->options['uriSuffix']) && $nodeInfo->hasUriPath()) {
            $uriConstraints = $uriConstraints->withPathSuffix($this->options['uriSuffix']);
        }
        return new ResolveResult($nodeInfo->getUriPath(), $uriConstraints, $nodeInfo->getRouteTags());
    }



    // TODO
    private function truncateRequestPathAndReturnRemainder(string &$requestPath): string
    {
        if (!empty($this->options['uriSuffix'])) {
            $suffixPosition = strpos($requestPath, $this->options['uriSuffix']);
            if ($suffixPosition === false) {
                return '';
            }
            $requestPath = substr($requestPath, 0, $suffixPosition);
        }
        if ($this->splitString === '' || $this->splitString === '/') {
            return '';
        }
        $splitStringPosition = strpos($requestPath, $this->splitString);
        if ($splitStringPosition === false) {
            return '';
        }
        $fullRequestPath = $requestPath;
        $requestPath = substr($requestPath, 0, $splitStringPosition);

        return substr($fullRequestPath, $splitStringPosition);
    }


    private function buildResolveResultFromUri(UriInterface $uri): ResolveResult
    {
        $uriConstraints = UriConstraints::create();
        if (!empty($uri->getScheme())) {
            $uriConstraints = $uriConstraints->withScheme($uri->getScheme());
        }
        if (!empty($uri->getHost())) {
            $uriConstraints = $uriConstraints->withHost($uri->getHost());
        }
        if ($uri->getPort() !== null) {
            $uriConstraints = $uriConstraints->withPort($uri->getPort());
        } elseif (!empty($uri->getScheme())) {
            $uriConstraints = $uriConstraints->withPort($uri->getScheme() === 'https' ? 443 : 80);
        }
        if (!empty($uri->getQuery())) {
            $uriConstraints = $uriConstraints->withQueryString($uri->getQuery());
        }
        if (!empty($uri->getFragment())) {
            $uriConstraints = $uriConstraints->withFragment($uri->getFragment());
        }
        return new ResolveResult($uri->getPath(), $uriConstraints);
    }

    // TODO: STILL NEEDED???
    public function setSplitString($splitString): void
    {
        $this->splitString = $splitString;
    }

    public function match(&$routePath): bool
    {
        return false;
        /*
        throw new \BadMethodCallException(
            'match() is not supported by this Route Part Handler, use "matchWithParameters" instead',
            1568287772
        );*/
    }

    /**
     * @param array<int|string,mixed> $routeValues
     */
    public function resolve(array &$routeValues): bool
    {
        return false;
        /*
        throw new \BadMethodCallException(
            'resolve() is not supported by this Route Part Handler, use "resolveWithParameters" instead',
            1611600169
        );*/
    }
}
