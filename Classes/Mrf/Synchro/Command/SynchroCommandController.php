<?php

namespace Mrf\Synchro\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\Surf\Domain\Model\Application;
use TYPO3\Surf\Domain\Model\Deployment;
use TYPO3\Surf\Domain\Model\Node;
use TYPO3\Surf\Exception\TaskExecutionException;

/**
 * @Flow\Scope("singleton")
 */
class SynchroCommandController extends CommandController
{

    /**
     * Name of the temporary subdirectory in FLOW_PATH_DATA
     */
    const DATA_DIRECTORY_NAME = 'Synchro';

    /**
     * Name of the exported XML document
     */
    const EXPORT_XML_FILENAME = 'export.xml';

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
            $this->log('Executing database and resources pull');
            $this->runPull();
        } catch (TaskExecutionException $e) {
            $this->log('An error occurred during the pull: '.$e->getMessage(), LOG_ERR);
        }
    }

    /**
     * @param string $deploymentName The deployment name
     * @param string $configurationPath Path for deployment configuration files
     *
     * @return void
     */
    public function simulatePullCommand($deploymentName, $configurationPath = null)
    {
        try {
            $this->initializeDeployment($deploymentName, $configurationPath, LOG_DEBUG);
            $this->deployment->setDryRun(true);
            $this->log('Simulating database and resources pull');
            $this->runPull();
        } catch (TaskExecutionException $e) {
            $this->log('An error occurred during the simulation of the pull: '.$e->getMessage(), LOG_ERR);
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
    public function pushCommand($deploymentName, $configurationPath = null)
    {
        try {
            $this->initializeDeployment($deploymentName, $configurationPath);
            $this->log('Executing database and resources push');
            $this->runPush();
        } catch (TaskExecutionException $e) {
            $this->log('An error occurred during the push: '.$e->getMessage(), LOG_ERR);
        }
    }

    /**
     * @param string $deploymentName The deployment name
     * @param string $configurationPath Path for deployment configuration files
     *
     * @return void
     */
    public function simulatePushCommand($deploymentName, $configurationPath = null)
    {
        try {
            $this->initializeDeployment($deploymentName, $configurationPath, LOG_DEBUG);
            $this->deployment->setDryRun(true);
            $this->log('Simulating database and resources push.');
            $this->runPush();
        } catch (TaskExecutionException $e) {
            $this->log('An error occurred during the simulation of the push: '.$e->getMessage(), LOG_ERR);
        }
    }

    /**
     * @param string $deploymentName The deployment name
     * @param string $configurationPath Path for deployment configuration files
     * @param int $logLevel
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
    protected function runPull()
    {
        $this->setIdentifier();
        $this->setApplication($this->deployment);
        $this->setRemoteNode($this->application);
        $this->setLocalNode();

        // export site on remote node
        $this->createRemoteDataSynchroDirectory();
        $this->exportRemoteSite();

        // download site to local
        $this->createLocalDataSynchroDirectory();
        $this->download(
            $this->getRemoteDataSynchroDirectory(),
            $this->getLocalDataSynchroDirectory()
        );

        // import site
        $this->pruneLocalSite();
        $this->importSiteLocally();
        $this->clearLocalCache();

        $this->response->setExitCode(0);
    }

    /**
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function runPush()
    {
        $this->setIdentifier();
        $this->setApplication($this->deployment);
        $this->setRemoteNode($this->application);
        $this->setLocalNode();

        // export site on local node
        $this->createLocalDataSynchroDirectory();
        $this->exportLocalSite();

        // upload site to remote
        $this->createRemoteDataSynchroDirectory();
        $this->upload(
            $this->getLocalDataSynchroDirectory(),
            $this->getRemoteDataSynchroDirectory()
        );

        // import site remotely
        $this->pruneRemoteSite();
        $this->importSiteRemotely();
        $this->clearRemoteCache();

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
        $this->log('Identifier: '.$this->identifier);
    }

    /**
     * @param \TYPO3\Surf\Domain\Model\Deployment $deployment
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function setApplication(Deployment $deployment)
    {
        $applications = $deployment->getApplications();

        if (count($applications) < 1) {
            throw new TaskExecutionException(
                "There is no application set for the deployment '{$deployment->getName()}'. Please check your TYPO3/Surf configuration."
            );
        }

        $this->application = array_shift($applications);
    }

    /**
     * @param \TYPO3\Surf\Domain\Model\Application $application
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function setRemoteNode(Application $application)
    {
        $this->requireApplication(__METHOD__);

        $nodes = $application->getNodes();

        if (count($nodes) < 1) {
            throw new TaskExecutionException(
                "There is no node is set for application '{$application->getName()}'. Please check your TYPO3/Surf configuration."
            );
        }

        $this->remoteNode = array_shift($nodes);
    }

    /**
     * @return void
     */
    protected function setLocalNode()
    {
        $this->localNode = new Node('localhost');
        $this->localNode->setHostname('localhost');
    }

    /**
     * @param string $callingMethodName
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function requireIdentifier($callingMethodName)
    {
        if (!$this->identifier) {
            throw new TaskExecutionException(
                'Identifier has to be set before executing method '.$callingMethodName.'.'
            );
        }
    }

    /**
     * @param string $callingMethodName
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function requireApplication($callingMethodName)
    {
        if (!$this->application) {
            throw new TaskExecutionException(
                'Application has to be set before executing method '.$callingMethodName.'.'
            );
        }
    }

    /**
     * @return string Remote context name
     */
    protected function getRemoteContext()
    {
        $this->requireApplication(__METHOD__);

        return $this->application->getContext();
    }

    /**
     * @return string Local context name
     */
    protected function getLocalContext()
    {
        return getenv('FLOW_CONTEXT');
    }

    /**
     * @return string
     */
    protected function getRemoteRootPath()
    {
        $this->requireApplication(__METHOD__);

        return $this->application->getDeploymentPath().'/releases/current/';
    }

    /**
     * @return string
     */
    protected function getLocalRootPath()
    {
        return FLOW_PATH_ROOT;
    }

    /**
     * @return string
     */
    protected function getRemoteDataSynchroDirectory()
    {
        $this->requireIdentifier(__METHOD__);

        return $this->getRemoteRootPath().'Data/'.self::DATA_DIRECTORY_NAME.'/'.$this->identifier.'/';
    }

    /**
     * @return string
     */
    protected function getLocalDataSynchroDirectory()
    {
        $this->requireIdentifier(__METHOD__);

        return FLOW_PATH_DATA.self::DATA_DIRECTORY_NAME.DIRECTORY_SEPARATOR.$this->identifier.DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    protected function getRemoteExportXmlFilename()
    {
        return $this->getRemoteDataSynchroDirectory().self::EXPORT_XML_FILENAME;
    }

    /**
     * @return string
     */
    protected function getLocalExportXmlFilename()
    {
        return $this->getLocalDataSynchroDirectory().self::EXPORT_XML_FILENAME;
    }

    /**
     * Creates Data/Synchro/[identifier] directory on remote node
     *
     * @return void
     */
    protected function createRemoteDataSynchroDirectory()
    {
        $this->requireRemoteNode(__METHOD__);

        $this->log('Creating remote synchro directory');
        $this->shell->executeOrSimulate(
            'mkdir -p -m 777 '.$this->getRemoteDataSynchroDirectory(),
            $this->remoteNode,
            $this->deployment
        );
    }

    /**
     * Creates Data/Synchro/[identifier] directory on local node
     *
     * @return void
     */
    protected function createLocalDataSynchroDirectory()
    {
        $this->requireLocalNode(__METHOD__);

        $this->log('Creating local synchro directory');
        $this->shell->executeOrSimulate(
            'mkdir -p -m 777 '.$this->getLocalDataSynchroDirectory(),
            $this->localNode,
            $this->deployment
        );
    }

    /**
     * @param string $callingMethodName
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function requireRemoteNode($callingMethodName)
    {
        if (!$this->remoteNode) {
            throw new TaskExecutionException(
                'Remote node has to be set before executing method '.$callingMethodName.'.'
            );
        }
    }

    /**
     * @param string $callingMethodName
     *
     * @return void
     *
     * @throws TaskExecutionException
     */
    protected function requireLocalNode($callingMethodName)
    {
        if (!$this->localNode) {
            throw new TaskExecutionException(
                'Local node has to be set before executing method '.$callingMethodName.'.'
            );
        }
    }

    /**
     * Exports the remote node's site using Flow site:export command
     *
     * @throws TaskExecutionException
     */
    protected function exportRemoteSite()
    {
        $this->log('Exporting remote site');
        $this->flowRemoteCommand('site:export --filename '.$this->getRemoteExportXmlFilename());
    }

    /**
     * Exports the local node's site using Flow site:export command
     *
     * @throws TaskExecutionException
     */
    protected function exportLocalSite()
    {
        $this->log('Exporting local site');
        $this->flowLocalCommand('site:export --filename '.$this->getLocalExportXmlFilename());
    }

    /**
     * Downloads files from $remotePath to $localPath using rsync
     *
     * @param string $remotePath
     * @param string $localPath
     */
    protected function download($remotePath, $localPath)
    {
        $cmd = sprintf(
            'rsync  -e "ssh -p %d" -avz %s@%s:%s %s',
            $this->remoteNode->getOption('port'),
            $this->remoteNode->getOption('username'),
            $this->remoteNode->getHostname(),
            $remotePath,
            $localPath
        );
        $this->log('Downloading files to local instance');
        $this->shell->executeOrSimulate($cmd, $this->localNode, $this->deployment);
    }

    /**
     * @param string $localPath
     * @param string $remotePath
     */
    protected function upload($localPath, $remotePath)
    {
        $cmd = sprintf(
            'rsync  -e "ssh -p %d" -avz %s %s@%s:%s',
            $this->remoteNode->getOption('port'),
            $localPath,
            $this->remoteNode->getOption('username'),
            $this->remoteNode->getHostname(),
            $remotePath
        );
        $this->log('Uploading files to remote instance');
        $this->shell->executeOrSimulate($cmd, $this->localNode, $this->deployment);
    }

    /**
     * Imports the site on the local node using Flow site:import command
     *
     * @return void
     */
    protected function importSiteLocally()
    {
        $this->log('Importing site on the local instance');
        $this->flowLocalCommand('site:import --filename '.$this->getLocalExportXmlFilename());
    }

    /**
     * Imports the site on the remote instance using Flow site:import command
     *
     * @return void
     */
    protected function importSiteRemotely()
    {
        $this->log('Importing site on the remote instance');
        $this->flowRemoteCommand('site:import --filename '.$this->getRemoteExportXmlFilename());
    }

    /**
     * @return void
     */
    protected function pruneLocalSite()
    {
        $this->log('Pruning of the local instance');
        $this->flowLocalCommand('site:prune');
    }

    /**
     * @return void
     */
    protected function pruneRemoteSite()
    {
        $this->log('Pruning of the remote instance');
        $this->flowRemoteCommand('site:prune');
    }

    /**
     * @return void
     */
    protected function clearLocalCache()
    {
        $this->log('Clearing cache of the local instance');
        $this->flowLocalCommand('flow:cache:flush --force');
    }

    /**
     * @return void
     */
    protected function clearRemoteCache()
    {
        $this->log('Clearing cache of the remote instance');
        $this->flowRemoteCommand('flow:cache:flush --force');
    }

    /**
     * @param string $command
     */
    protected function flowLocalCommand($command) {
        $cmd = array();
        $context = $this->getLocalContext();
        if ($context) {
            $cmd[] = 'FLOW_CONTEXT='.$context;
        }
        $cmd[] = $this->getLocalRootPath().'flow '.$command;
        $this->shell->executeOrSimulate(implode(' ', $cmd), $this->localNode, $this->deployment);
    }

    /**
     * @param string $command
     */
    protected function flowRemoteCommand($command) {
        $cmd = array();
        $context = $this->getRemoteContext();
        if ($context) {
            $cmd[] = 'FLOW_CONTEXT='.$context;
        }
        $cmd[] = $this->getRemoteRootPath().'flow '.$command;
        $this->shell->executeOrSimulate(implode(' ', $cmd), $this->remoteNode, $this->deployment);
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
