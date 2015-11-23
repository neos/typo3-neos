<?php
namespace TYPO3\Neos\Controller\Module\Administration;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Error\Message;
use TYPO3\Flow\Log\SystemLoggerInterface;
use TYPO3\Flow\Package\PackageManagerInterface;
use TYPO3\Flow\Property\PropertyMapper;
use TYPO3\Neos\Controller\Module\AbstractModuleController;
use TYPO3\Neos\Domain\Model\Domain;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\Neos\Domain\Repository\DomainRepository;
use TYPO3\Neos\Domain\Repository\SiteRepository;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\Neos\Domain\Service\SiteImportService;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;

/**
 * The Neos Sites Management module controller
 */
class SitesController extends AbstractModuleController
{
    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var PackageManagerInterface
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var SiteImportService
     */
    protected $siteImportService;

    /**
     * @Flow\Inject
     * @var PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Session\SessionInterface
     */
    protected $session;

    /**
     * @return void
     */
    public function indexAction()
    {
        $this->view->assign('sites', $this->siteRepository->findAll());
    }

    /**
     * A edit view for a site and its settings.
     *
     * @param Site $site Site to view
     * @Flow\IgnoreValidation("$site")
     * @return void
     */
    public function editAction(Site $site)
    {
        try {
            $sitePackage = $this->packageManager->getPackage($site->getSiteResourcesPackageKey());
        } catch (\Exception $e) {
            $this->addFlashMessage('The site package with key "%s" was not found.', 'Site package not found', Message::SEVERITY_ERROR, array(htmlspecialchars($site->getSiteResourcesPackageKey())));
        }

        $this->view->assignMultiple(array(
            'site' => $site,
            'sitePackageMetaData' => isset($sitePackage) ? $sitePackage->getPackageMetaData() : array(),
            'domains' => $this->domainRepository->findBySite($site)
        ));
    }

    /**
     * Update a site
     *
     * @param Site $site A site to update
     * @param string $newSiteNodeName A new site node name
     * @return void
     * @Flow\Validate(argumentName="$site", type="UniqueEntity")
     * @Flow\Validate(argumentName="$newSiteNodeName", type="NotEmpty")
     * @Flow\Validate(argumentName="$newSiteNodeName", type="StringLength", options={ "minimum"=1, "maximum"=250 })
     * @Flow\Validate(argumentName="$newSiteNodeName", type="TYPO3.Neos:NodeName")
     */
    public function updateSiteAction(Site $site, $newSiteNodeName)
    {
        if ($site->getNodeName() !== $newSiteNodeName) {
            $oldSiteNodePath = '/sites/' . $site->getNodeName();
            $newSiteNodePath = '/sites/' . $newSiteNodeName;
            /** @var $workspace Workspace */
            foreach ($this->workspaceRepository->findAll() as $workspace) {
                $siteNode = $this->nodeDataRepository->findOneByPath($oldSiteNodePath, $workspace);
                if ($siteNode !== null) {
                    $siteNode->setPath($newSiteNodePath);
                }
            }
            $site->setNodeName($newSiteNodeName);
            $this->nodeDataRepository->persistEntities();
        }
        $this->siteRepository->update($site);
        $this->addFlashMessage('The site "%s" has been updated.', 'Update', htmlspecialchars($site->getName()));
        $this->unsetLastVisitedNodeAndRedirect('index');
    }

    /**
     * Create a new site form.
     *
     * @param Site $site Site to create
     * @Flow\IgnoreValidation("$site")
     * @return void
     */
    public function newSiteAction(Site $site = null)
    {
        $sitePackages = $this->packageManager->getFilteredPackages('available', null, 'typo3-flow-site');
        $this->view->assignMultiple(array(
            'sitePackages' => $sitePackages,
            'site' => $site,
            'generatorServiceIsAvailable' => $this->packageManager->isPackageActive('TYPO3.Neos.Kickstarter')
        ));
    }

    /**
     * Create a new site.
     *
     * @param string $site Site to import
     * @param string $packageKey Package Name to create
     * @param string $siteName Site Name to create
     * @Flow\Validate(argumentName="$packageKey", type="\TYPO3\Neos\Validation\Validator\PackageKeyValidator")
     * @return void
     */
    public function createSiteAction($site, $packageKey = '', $siteName = '')
    {
        if ($packageKey !== '' && $this->packageManager->isPackageActive('TYPO3.Neos.Kickstarter')) {
            if ($this->packageManager->isPackageAvailable($packageKey)) {
                $this->addFlashMessage('The package key "%s" already exists.', 'Invalid package key', Message::SEVERITY_ERROR, array(htmlspecialchars($packageKey)));
                $this->redirect('index');
            }

            $generatorService = $this->objectManager->get('TYPO3\Neos\Kickstarter\Service\GeneratorService');
            $generatorService->generateSitePackage($packageKey, $siteName);
            $this->packageManager->activatePackage($packageKey);
        } else {
            $packageKey = $site;
        }

        if ($packageKey !== '') {
            try {
                /** @var $contentContext ContentContext */
                $contentContext = $this->contextFactory->create(array(
                    'workspaceName' => 'live',
                    'invisibleContentShown' => true,
                    'inaccessibleContentShown' => true
                ));
                $this->siteImportService->importFromPackage($packageKey, $contentContext);
                $this->addFlashMessage('The site has been created.');
            } catch (\Exception $exception) {
                $this->systemLogger->logException($exception);
                $this->addFlashMessage('Error: During the import of the "Sites.xml" from the package "%s" an exception occurred: %s', 'Import error', Message::SEVERITY_ERROR, array(htmlspecialchars($packageKey), htmlspecialchars($exception->getMessage())));
            }
        } else {
            $this->addFlashMessage('No site selected for import and no package name provided.', 'No site selected', Message::SEVERITY_ERROR);
            $this->redirect('newSite');
        }

        $this->unsetLastVisitedNodeAndRedirect('index');
    }

    /**
     * Delete a site.
     *
     * @param Site $site Site to create
     * @Flow\IgnoreValidation("$site")
     * @return void
     */
    public function deleteSiteAction(Site $site)
    {
        $domains = $this->domainRepository->findBySite($site);
        if (count($domains) > 0) {
            foreach ($domains as $domain) {
                $this->domainRepository->remove($domain);
            }
        }
        $this->siteRepository->remove($site);
        $siteNode = $this->propertyMapper->convert('/sites/' . $site->getNodeName(), 'TYPO3\TYPO3CR\Domain\Model\NodeInterface');
        $siteNode->remove();
        $this->addFlashMessage('The site "%s" has been deleted.', 'Site deleted', Message::SEVERITY_OK, array(htmlspecialchars($site->getName())));
        $this->unsetLastVisitedNodeAndRedirect('index');
    }

    /**
     * Activates a site
     *
     * @param Site $site Site to update
     * @return void
     */
    public function activateSiteAction(Site $site)
    {
        $site->setState($site::STATE_ONLINE);
        $this->siteRepository->update($site);
        $this->addFlashMessage('The site "%s" has been activated.', 'Site activated', Message::SEVERITY_OK, array(htmlspecialchars($site->getName())));
        $this->unsetLastVisitedNodeAndRedirect('index');
    }

    /**
     * Deactivates a site
     *
     * @param Site $site Site to deactivate
     * @return void
     */
    public function deactivateSiteAction(Site $site)
    {
        $site->setState($site::STATE_OFFLINE);
        $this->siteRepository->update($site);
        $this->addFlashMessage('The site "%s" has been deactivated.', 'Site deactivated', Message::SEVERITY_OK, array(htmlspecialchars($site->getName())));
        $this->unsetLastVisitedNodeAndRedirect('index');
    }

    /**
     * Edit a domain
     *
     * @param Domain $domain Domain to edit
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function editDomainAction(Domain $domain)
    {
        $this->view->assign('domain', $domain);
    }

    /**
     * Update a domain
     *
     * @param Domain $domain Domain to update
     * @Flow\Validate(argumentName="$domain", type="UniqueEntity")
     * @return void
     */
    public function updateDomainAction(Domain $domain)
    {
        $this->domainRepository->update($domain);
        $this->addFlashMessage('The domain "%s" has been updated.', 'Domain updated', Message::SEVERITY_OK, array(htmlspecialchars($domain->getHostPattern())));
        $this->unsetLastVisitedNodeAndRedirect('edit', null, null, array('site' => $domain->getSite()));
    }

    /**
     * The create a new domain action.
     *
     * @param Domain $domain
     * @param Site $site
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function newDomainAction(Domain $domain = null, Site $site = null)
    {
        $this->view->assignMultiple(array(
            'domain' => $domain,
            'site' => $site
        ));
    }

    /**
     * Create a domain
     *
     * @param Domain $domain Domain to create
     * @Flow\Validate(argumentName="$domain", type="UniqueEntity")
     * @return void
     */
    public function createDomainAction(Domain $domain)
    {
        $this->domainRepository->add($domain);
        $this->addFlashMessage('The domain "%s" has been created.', 'Domain created', Message::SEVERITY_OK, array(htmlspecialchars($domain->getHostPattern())));
        $this->unsetLastVisitedNodeAndRedirect('edit', null, null, array('site' => $domain->getSite()));
    }

    /**
     * Deletes a domain attached to a site
     *
     * @param Domain $domain A domain to delete
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function deleteDomainAction(Domain $domain)
    {
        $this->domainRepository->remove($domain);
        $this->addFlashMessage('The domain "%s" has been deleted.', 'Domain deleted', Message::SEVERITY_OK, array(htmlspecialchars($domain->getHostPattern())));
        $this->unsetLastVisitedNodeAndRedirect('edit', null, null, array('site' => $domain->getSite()));
    }

    /**
     * Activates a domain
     *
     * @param Domain $domain Domain to activate
     * @return void
     */
    public function activateDomainAction(Domain $domain)
    {
        $domain->setActive(true);
        $this->domainRepository->update($domain);
        $this->addFlashMessage('The domain "%s" has been activated.', 'Domain activated', Message::SEVERITY_OK, array(htmlspecialchars($domain->getHostPattern())));
        $this->unsetLastVisitedNodeAndRedirect('edit', null, null, array('site' => $domain->getSite()));
    }

    /**
     * Deactivates a domain
     *
     * @param Domain $domain Domain to deactivate
     * @return void
     */
    public function deactivateDomainAction(Domain $domain)
    {
        $domain->setActive(false);
        $this->domainRepository->update($domain);
        $this->addFlashMessage('The domain "%s" has been deactivated.', 'Domain deactivated', Message::SEVERITY_OK, array(htmlspecialchars($domain->getHostPattern())));
        $this->unsetLastVisitedNodeAndRedirect('edit', null, null, array('site' => $domain->getSite()));
    }

    /**
     * @param string $actionName Name of the action to forward to
     * @param string $controllerName Unqualified object name of the controller to forward to. If not specified, the current controller is used.
     * @param string $packageKey Key of the package containing the controller to forward to. If not specified, the current package is assumed.
     * @param array $arguments Array of arguments for the target action
     * @param integer $delay (optional) The delay in seconds. Default is no delay.
     * @param integer $statusCode (optional) The HTTP status code for the redirect. Default is "303 See Other"
     * @param string $format The format to use for the redirect URI
     * @return void
     */
    protected function unsetLastVisitedNodeAndRedirect($actionName, $controllerName = null, $packageKey = null, array $arguments = null, $delay = 0, $statusCode = 303, $format = null)
    {
        $this->session->putData('lastVisitedNode', null);
        parent::redirect($actionName, $controllerName, $packageKey, $arguments, $delay, $statusCode, $format);
    }
}
