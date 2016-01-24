<?php

namespace Mrf\Synchro\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Surf\Exception\TaskExecutionException;

/**
 * @Flow\Scope("singleton")
 */
class SynchroCommandController extends \TYPO3\Flow\Cli\CommandController
{

    /**
     * Name of the temporary subdirectory in FLOW_PATH_DATA
     */
    const DATA_DIRECTORY_NAME = 'Synchro';

    /**
     * @Flow\Inject
     * @var \TYPO3\Surf\Domain\Service\DeploymentService
     */
    protected $deploymentService;

    /**
     * @Flow\Inject
     * @var \TYPO3\Surf\Command\SurfCommandController
     */
    protected $surfCommandController;

    /**
     * @Flow\Inject
     * @var \TYPO3\Surf\Domain\Service\ShellCommandService
     */
    protected $shell;

    /**
     * @var \TYPO3\Surf\Domain\Model\Deployment
     */
    protected $deployment;

    /**
     * @var \TYPO3\Surf\Application\TYPO3\Flow
     */
    protected $application;

    /**
     * @var \TYPO3\Surf\Domain\Model\Node
     */
    protected $remoteNode;

    /**
     * @var \TYPO3\Surf\Domain\Model\Node
     */
    protected $localNode;

    /**
     * Unique identifier for command
     *
     * @var string
     */
    protected $identifier;

    /**
     * @param string $deploymentName The deployment name
     * @param string $configurationPath Path for deployment configuration files
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    public function pullCommand($deploymentName, $configurationPath = null)
    {
        try {
            $this->initializeDeployment($deploymentName, $configurationPath);
            $this->log('Executing database and resources synchronization.');
            $this->run();
        } catch (TaskExecutionException $e) {
            $this->log('An error occurred during the synchronization: '.$e->getMessage(), LOG_ERR);
        }
    }

    /**
     * @param string $deploymentName The deployment name
     * @param string $configurationPath Path for deployment configuration files
     *
     * @return void
     */
    public function simulateCommand($deploymentName, $configurationPath = null)
    {
        try {
            $this->initializeDeployment($deploymentName, $configurationPath, LOG_DEBUG);
            $this->deployment->setDryRun(true);
            $this->log('Simulating database and resources synchronization.');
            $this->run();
        } catch (TaskExecutionException $e) {
            $this->log('An error occurred during the simulation of the synchronization: '.$e->getMessage(), LOG_ERR);
        }
    }

    /**
     * @param string $deploymentName The deployment name
     * @param string $configurationPath Path for deployment configuration files
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function initializeDeployment($deploymentName, $configurationPath = null, $logLevel = LOG_INFO)
    {
        $this->deployment = $this->deploymentService->getDeployment($deploymentName, $configurationPath);
        if ($this->deployment->getLogger() === null) {
            $logger = $this->surfCommandController->createDefaultLogger($deploymentName, $logLevel);
            $this->deployment->setLogger($logger);
        }
    }

    /**
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function run()
    {
        $this->setIdentifier();
        $this->setApplication($this->deployment);
        $this->setRemoteNode($this->application);
        $this->setLocalNode();

        // export site on remote node
        $exportFile = $this->exportRemoteSite();

        // download site to local
        $this->createLocalDataSynchroDir();
        $this->downloadFiles(
            dirname($exportFile).'/',
            $this->getDownloadPath()
        );

        // import site
        $this->importSite($this->getDownloadPath().basename($exportFile));

        $this->response->setExitCode(0);
    }

    /**
     * Sets unique identifier for pull command
     *
     * @return void
     */
    protected function setIdentifier()
    {
        $this->identifier = sha1(uniqid().time());
    }

    /**
     * @param \TYPO3\Surf\Domain\Model\Deployment $deployment
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function setApplication(\TYPO3\Surf\Domain\Model\Deployment $deployment)
    {
        $applications = $deployment->getApplications();

        if (count($applications) < 1) {
            throw new TaskExecutionException(
                "There is no application set for the deployment '{$deployment->getName()}'. Please check your TYPO3/Surf configuration."
            );
        }

        /** @var \TYPO3\Surf\Domain\Model\Application $application */
        $this->application = array_shift($applications);
    }

    /**
     * @param \TYPO3\Surf\Domain\Model\Application $application
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function setRemoteNode(\TYPO3\Surf\Domain\Model\Application $application)
    {
        $nodes = $application->getNodes();

        if (count($nodes) < 1) {
            throw new TaskExecutionException(
                "There is no node is set for application '{$application->getName()}'. Please check your TYPO3/Surf configuration."
            );
        }

        /** @var \TYPO3\Surf\Domain\Model\Node $node */
        $this->remoteNode = array_shift($nodes);
    }

    /**
     * @return void
     */
    protected function setLocalNode()
    {
        $this->localNode = new \TYPO3\Surf\Domain\Model\Node('localhost');
        $this->localNode->setHostname('localhost');
    }

    /**
     * Exports the site using Flow site:export command
     *
     * @return string Remote path to the exported site file
     *
     * @throws TaskExecutionException
     */
    protected function exportRemoteSite()
    {
        $currentPath = $this->application->getDeploymentPath().'/current';
        $dataSynchroDir = $currentPath.'/Data/'.self::DATA_DIRECTORY_NAME.'/'.$this->identifier; // on remote dir we don't use DIRECTORY_SEPARATOR constant
        $exportFilename = $dataSynchroDir.'/export_'.$this->identifier.'.xml';

        $cmds = array(
            "mkdir -p {$dataSynchroDir}",
            "FLOW_CONTEXT={$this->application->getContext()} {$currentPath}/flow site:export --filename {$exportFilename}",
        );

        $this->shell->executeOrSimulate(implode(' && ', $cmds), $this->remoteNode, $this->deployment);

        return $exportFilename;
    }

    /**
     * Downloads files from $remotePath to $localPath using rsync
     *
     * @param string $remotePath
     * @param string $localPath
     */
    protected function downloadFiles($remotePath, $localPath)
    {
        $cmd = sprintf(
            'rsync  -e "ssh -p %d" -avz %s@%s:%s %s',
            $this->remoteNode->getOption('port'),
            $this->remoteNode->getOption('username'),
            $this->remoteNode->getHostname(),
            $remotePath,
            $localPath
        );

        $this->shell->executeOrSimulate($cmd, $this->localNode, $this->deployment);
    }

    /**
     * @return string
     *
     * @throws TaskExecutionException
     */
    protected function getDownloadPath()
    {
        if (empty($this->identifier)) {
            throw new TaskExecutionException('Identifier has to be set to get download path');
        }

        return FLOW_PATH_DATA.self::DATA_DIRECTORY_NAME.DIRECTORY_SEPARATOR.$this->identifier.DIRECTORY_SEPARATOR;
    }

    /**
     * @return void
     */
    protected function createLocalDataSynchroDir()
    {
        if (!is_dir($this->getDownloadPath())) {
            $this->shell->executeOrSimulate(
                'mkdir -p -m 777 '.$this->getDownloadPath(),
                $this->localNode,
                $this->deployment
            );
        }
    }

    /**
     * Imports the site using Flow site:import command
     *
     * @return void
     */
    protected function importSite($filename)
    {
        $cmd = array();

        // depending on configuration, FLOW_CONTEXT may be required for connection to database
        $context = getenv('FLOW_CONTEXT');
        if ($context) {
            $cmd[] = "FLOW_CONTEXT={$context}";
        }
        $cmd[] = FLOW_PATH_ROOT.DIRECTORY_SEPARATOR."flow site:import --filename {$filename}";

        $this->shell->executeOrSimulate(implode(' ', $cmd), $this->localNode, $this->deployment);
    }

    /**
     * Writes the given message along with the additional information into the log.
     *
     * @param string $message The message to log
     * @param integer $severity An integer value, one of the LOG_* constants
     * @param mixed $additionalData A variable containing more information about the event to be logged
     * @param string $packageKey Key of the package triggering the log (determined automatically if not specified)
     * @param string $className Name of the class triggering the log (determined automatically if not specified)
     * @param string $methodName Name of the method triggering the log (determined automatically if not specified)
     * @return void
     * @api
     */
    public function log(
        $message,
        $severity = LOG_INFO,
        $additionalData = null,
        $packageKey = null,
        $className = null,
        $methodName = null
    ) {
        $this->deployment->getLogger()->log($message, $severity, $additionalData, $packageKey, $className, $methodName);
    }
}
