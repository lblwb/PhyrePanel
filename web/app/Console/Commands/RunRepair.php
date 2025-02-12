<?php

namespace app\Console\Commands;

use App\Actions\GetLinuxUser;
use App\ApacheParser;
use App\Jobs\ApacheBuild;
use App\Models\Backup;
use App\Models\Domain;
use App\Models\HostingSubscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RunRepair extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'phyre:run-repair';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        // Find broken domains
//        $findBrokenDomains = Domain::where('status', Domain::STATUS_BROKEN)->get();
//        if ($findBrokenDomains) {
//            foreach ($findBrokenDomains as $brokenDomain) {
//                $brokenDomain->status = Domain::STATUS_ACTIVE;
//                $brokenDomain->saveQuietly();
//            }
//        }

        // Overwrite supervisor config file
        $workersCount = (int) setting('general.supervisor_workers_count');
        $supervisorConf = view('actions.samples.ubuntu.supervisor-conf', [
            'workersCount' => $workersCount
        ])->render();

        // Overwrite supervisor config file
        file_put_contents('/etc/supervisor/conf.d/phyre.conf', $supervisorConf);

        // Restart supervisor
        shell_exec('service supervisor restart');

        // Check supervisor config file
        $checkSupervisorStatus = shell_exec('service supervisor status');
        if (strpos($checkSupervisorStatus, 'active (running)') !== false) {
           $this->info('Supervisor is running');
        } else {
            $this->info('Supervisor is not running. Please check supervisor status');
        }

        $this->fixApacheErrors();

    }

    public function fixApacheErrors()
    {
        $findHostingSubscriptions = HostingSubscription::get();
        if ($findHostingSubscriptions) {
            foreach ($findHostingSubscriptions as $hostingSubscription) {
                $getLinuxUser = new GetLinuxUser();
                $getLinuxUser->setUsername($hostingSubscription->system_username);
                $getLinuxUserStatus = $getLinuxUser->handle();
                if (!$getLinuxUserStatus) {
                    $findDomains = Domain::where('hosting_subscription_id', $hostingSubscription->id)->get();
                    if ($findDomains) {
                        foreach ($findDomains as $domain) {
                            $domain->status = Domain::STATUS_BROKEN;
                            $domain->saveQuietly();
                            $this->error('Turn on maintenance mode: ' . $domain->domain);
                        }
                    }
                    $this->error('User not found: ' . $hostingSubscription->system_username);
                    continue;
                }
            }
        }

        // Rebuild apache config
        $apacheBuild = new ApacheBuild();
        $apacheBuild->handle();

        $checkApacheStatus = shell_exec('service apache2 status');
        if (strpos($checkApacheStatus, 'Syntax error on line') !== false) {

            $this->error('Apache syntax error found');
            $this->error($checkApacheStatus);

            $apacheErrorLine = null;
            preg_match('/Syntax error on line (\d+)/', $checkApacheStatus, $matchApacheErrorLine);
            if (isset($matchApacheErrorLine[1]) && is_numeric($matchApacheErrorLine[1])) {
                $apacheErrorLine = $matchApacheErrorLine[1];
            }

            $apacheBrokenVirtualHosts = [];

            $parser = new ApacheParser();
            $configNode = $parser->parse('/etc/apache2/apache2.conf');
            $configChildren = $configNode->getChildren();
            foreach ($configChildren as $child) {
                if ($child->getName() == 'VirtualHost') {
                    $virtualHost = [
                        'startLine' => $child->getStartLine(),
                        'endLine' => $child->getEndLine(),
                        'content' => $child->getContent()
                    ];
                    $childChildren = $child->getChildren();
                    if (isset($childChildren[0])) {
                        foreach ($childChildren as $childChild) {
                            $virtualHost[$childChild->getName()] = $childChild->getContent();
                        }
                    }
                    if ($child->getStartLine() <= $apacheErrorLine && $child->getEndLine() >= $apacheErrorLine) {
                        $apacheBrokenVirtualHosts[] = $virtualHost;
                    }
                }
            }

            if (count($apacheBrokenVirtualHosts) > 0) {

                $this->error('Broken virtual hosts found');

                foreach ($apacheBrokenVirtualHosts as $brokenVirtualHost) {
                    $this->error('Virtual host found: ' . $brokenVirtualHost['ServerName']);
                    $this->error('Turn on maintenance mode: ' . $brokenVirtualHost['ServerName']);
                    $findDomain = Domain::where('domain', $brokenVirtualHost['ServerName'])->first();
                    if ($findDomain) {
                        $findDomain->status = Domain::STATUS_BROKEN;
                        $findDomain->save();
                    }
                }

                $this->info('Run apache build...');

                $apacheBuild = new ApacheBuild();
                $apacheBuild->handle();
            }
        }

        shell_exec('service apache2 restart');
        $newCheckApacheStatus = shell_exec('service apache2 status');
        if (Str::contains($newCheckApacheStatus, 'active (running)')) {
            $this->info('Apache is running');
        } else {
            $this->info('Apache is not running. Please check apache status');
        }

    }
}
