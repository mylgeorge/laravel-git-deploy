<?php
namespace Mylgeorge\Deploy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Request;
use Mylgeorge\Deploy\Contracts\Deploy;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Process\Process;

class GitDeploy implements Deploy
{

    /**
     * The Logger.
     *
     * @var LoggerInterface
     */
    protected $logger;

    protected $git;

    protected $repo;

    protected $branch;

    protected $responce;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle()
    {
        $this->preHook();
        $this->deploy();
        $this->postHook();

        return $this->responce;
    }


    public function deploy()
    {
        $this->git = !empty(config('deploy.git_path')) ? config('deploy.git_path') : $this->process('which git');
        $this->repo = $this->getRepoDirectory();
        $this->branch = $this->getCurrentBranch();

        $this->setMaintenance('down');
        $this->pull();
        $this->setMaintenance('up');
    }


    public function preHook()
    {
        $this->authenticateSource();
        $this->authenticateSignature();
    }

    //good news!! send events send email post to slack etc
    public function postHook()
    {
//        if (!empty(config('deploy.fire_event'))) {
//            $postdata=$this->collectData();
//            event(new GitDeployed($postdata['commits']));
//            $this->logger->debug('Gitdeploy: Event GitDeployed fired');
//        }

    }

    protected function pull()
    {
        $git = escapeshellcmd($this->git) . ' --git-dir=' . escapeshellarg($this->repo . '/.git') . ' --work-tree=' . escapeshellarg($this->repo);
        if(!empty(config('deploy.git_checkout'))) $this->process($git . ' checkout -f');
        $git_remote = !empty(config('deploy.remote')) ? config('deploy.remote') : 'origin';
        $cmd = $git . ' pull ' . escapeshellarg($git_remote) . ' ' . escapeshellarg($this->branch); // . ' > ' . escapeshellarg($this->repo . '/storage/logs/gitdeploy.log');
        $this->responce= $this->process($cmd);
    }


    protected function authenticateSource()
    {
        if (!empty(config('deploy.allowed_sources'))) {
            $remote_ip = $this->formatIPAddress($_SERVER['REMOTE_ADDR']);
            $allowed_sources = array_map([$this, 'formatIPAddress'], config('deploy.allowed_sources'));
            if (!in_array($remote_ip, $allowed_sources)) {
                $this->logger->error('Request must come from an approved IP', [$remote_ip]);
                throw new UnauthorizedHttpException('', 'Request must come from an approved IP');
            }
        }
    }

    /**
     *  Checks signatures
     */
    protected function authenticateSignature()
    {
        if (!empty(config('deploy.secret'))) {
            $header = config('deploy.secret_header');
            $header_data = Request::header($header);

            /**
             * Check for valid header
             */
            if (!$header_data) {
                $this->logger->error('Could not find header with that name ', [$header]);
                throw new UnauthorizedHttpException('', 'Could not find header with that name ' . $header);
            }

            /**
             * Sanity check for key
             */
            if (empty(config('deploy.secret_key'))) {
                $this->logger->error('Secret was set to true but no secret_key specified in config');
                throw new HttpException(501, 'Secret was set to true but no secret_key specified in config');
            }

            /**
             * Check plain secrets (Gitlab)
             */
            switch (config('deploy.secret_type')) {
                case 'plain':
                    if ($header_data !== config('deploy.secret_key')) {
                        $this->logger->error("{$header} did not match TOKEN", [$header_data]);
                        throw new UnauthorizedHttpException('', "{$header} did not match TOKEN = '{$header_data}'");
                    }
                    break;
                case 'mac':
                    //if (!hash_equals('sha1=' . hash_hmac('sha1', Request::getContent()), config('deploy.secret')))){
                    //  $this->logger->error("{$header} did not match TOKEN", [$header_data]);
                    //  throw new UnauthorizedHttpException('', "{$header} did not match TOKEN = '{$header_data}'");
                    //}
                    //break;
                default:
                    $this->logger->error('Unsupported secret type', [config('deploy.secret_type')]);
                    throw new HttpException(501, 'Unsupported secret type');
                    break;
            }
        }
    }

    /**
     * Collect the posted data
     * */
    protected function collectData()
    {
        $postdata = json_decode(Request::getContent(), TRUE);
        if (empty($postdata)) {
            $this->logger->error('Web hook data does not look valid');
            throw new HttpException(501, 'Web hook data does not look valid');
        }

        return $postdata;
    }

    protected function getRepoDirectory()
    {
        // Check the config's directory
        $repo_dir = config('deploy.repo_path');
        if (!empty($repo_dir) && !file_exists($repo_dir . '/.git/config')) {
            $this->logger->error('Invalid repo path in config');
            throw new HttpException(501, 'Invalid repo path in config');
        }

        // Try to determine Laravel's directory going up paths until we find a .env
        if (empty($repo_dir)) {
            $checked[] = $repo_dir;
            $repo_dir = __DIR__;
            do {
                $repo_dir = dirname($repo_dir);
            } while ($repo_dir !== '/' && !file_exists($repo_dir . '/.env'));
        }

        // So, do we have something valid?
        if ($repo_dir === '/' || !file_exists($repo_dir . '/.git/config')) {
            $this->logger->error('Could not determine the repo path');
            throw new HttpException(501, 'Could not determine the repo path'); 
        }

        return $repo_dir;
    }

    protected function getCurrentBranch()
    {
        $cmd = escapeshellcmd($this->git) . ' --git-dir=' . escapeshellarg($this->repo . '/.git') .
            ' --work-tree=' . escapeshellarg($this->repo) . ' rev-parse --abbrev-ref HEAD';
        $current_branch = trim($this->process($cmd));

        $postdata = $this->collectData();
        // Get branch this webhook is for
        $pushed_branch = explode('/', $postdata['ref']);
        $pushed_branch = trim($pushed_branch[2]);

        // If the refs don't matchthis branch, then no need to do a git pull
        if ($current_branch !== $pushed_branch){
            $this->logger->warning('Pushed refs do not match current branch');
            throw new HttpException(501,"Pushed refs do not match current branch current:{$current_branch} -pushed:{$pushed_branch}");
        }

        return $current_branch;
    }

    protected function process($command)
    {
        $output='';
        $process = new Process($command);

        $process->mustRun(function($type, $buffer) use ($output){
            if (Process::OUT === $type) {
                $this->logger->debug($buffer);
            } else { // $process::ERR === $type
                $this->logger->error($buffer);
            }
        });

        return $process->getOutput();
    }

    protected function setMaintenance($mode)
    {
        if (!empty(config('deploy.maintenance_mode'))) {
            $this->logger->info('GitDeploy: changing maintenance mode to ' . $mode);
            Artisan::call($mode);
        }
    }

    /**
     * Make sure we're comparing like for like IP address formats.
     * Since IPv6 can be supplied in short hand or long hand formats.
     *
     * e.g. ::1 is equalvent to 0000:0000:0000:0000:0000:0000:0000:0001
     *
     * @param  string $ip Input IP address to be formatted
     * @return string   Formatted IP address
     */
    private function formatIPAddress(string $ip)
    {
        return inet_ntop(inet_pton($ip));
    }

}